<?php
declare(strict_types=1);

const ACCOUNT_EMAIL_RECOVERY_SETTING = 'two_factor_email_recovery_enabled';
const ACCOUNT_EMAIL_CODE_SECONDS = 900;
const ACCOUNT_EMAIL_REQUEST_SECONDS = 86400;
const ACCOUNT_EMAIL_WAIT_SECONDS = 86400;

function account_email_ready(PDO $pdo): bool
{
    $ready = database_migration_table_exists($pdo, 'account_email_state');
    if (!$ready && database_migration_read_setting($pdo,'schema_version') === '2026-09-20-two-factor-email-recovery') throw new TwoFactorException('Account recovery storage is unavailable. Contact the host administrator.',503);
    return $ready;
}

function account_email_row(PDO $pdo, string $table, int $userId, ?string $purpose = null, bool $lock = false): ?array
{
    if (!in_array($table, ['account_email_state','account_two_factor_email','account_email_challenges'], true)) throw new LogicException('Unknown email state owner.');
    $sql = 'SELECT * FROM ' . $table . ' WHERE user_id=?' . ($purpose !== null ? ' AND purpose=?' : '') . ($lock && db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql); $stmt->execute($purpose !== null ? [$userId,$purpose] : [$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor(); return $row ?: null;
}

function account_email_lock_user(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]); $user = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
    if (!$user) throw new TwoFactorException('Account is unavailable.', 403);
    return $user;
}

function account_email_verified(PDO $pdo, array $user): bool
{
    if (!account_email_ready($pdo)) return false;
    $row = account_email_row($pdo, 'account_email_state', (int)$user['id']);
    return !empty($row['verified_email']) && hash_equals($row['verified_email'], (string)($user['email'] ?? ''));
}

function account_email_policy(PDO $pdo): bool
{
    return app_setting($pdo, ACCOUNT_EMAIL_RECOVERY_SETTING, '0') === '1';
}

function account_email_eligible(PDO $pdo, array $user): bool
{
    if (!account_email_ready($pdo) || !account_email_verified($pdo, $user)) return false;
    $rule = account_email_row($pdo, 'account_two_factor_email', (int)$user['id']);
    $factor = two_factor_row($pdo, (int)$user['id']);
    return $rule && !empty($factor['secret_ciphertext']) && (int)$rule['mfa_revision'] === (int)$factor['revision'];
}

function account_email_epoch(PDO $pdo, int $userId): int
{
    return account_email_ready($pdo) ? (int)(account_email_row($pdo, 'account_email_state', $userId)['recovery_epoch'] ?? 0) : 0;
}

function account_email_status(PDO $pdo, array $user): array
{
    $eligible = account_email_eligible($pdo, $user);
    $enrolled = account_email_ready($pdo) ? account_email_row($pdo, 'account_two_factor_email', (int)$user['id']) : null;
    $challenge = account_email_ready($pdo) ? account_email_row($pdo, 'account_email_challenges', (int)$user['id'], 'recover') : null;
    $waiting = $eligible && $challenge && $challenge['state'] === 'waiting' && account_email_binding($pdo, $user, $challenge);
    return ['mailConfigured' => account_mail_ready(), 'enrollmentEmailRequired' => account_email_policy($pdo),
        'emailVerified' => account_email_verified($pdo, $user), 'emailRecoveryEligible' => $eligible, 'emailRecoveryEnrolled' => (bool)$enrolled,
        'recoveryPending' => (bool)$waiting, 'recoveryReadyAt' => $waiting ? (int)$challenge['ready_at'] : null];
}

function account_email_binding(PDO $pdo, array $user, array $row): bool
{
    return hash_equals((string)$row['email'], (string)($user['email'] ?? ''))
        && hash_equals((string)$row['password_stamp'], hash('sha256', (string)$user['password_hash']))
        && (int)$row['mfa_revision'] === (int)(two_factor_row($pdo, (int)$user['id'])['revision'] ?? 0);
}

/** Reserve under the account lock before sending; two requests cannot both send. */
function account_email_request(PDO $pdo, array $user, string $purpose): void
{
    if (!in_array($purpose, ['verify','recover'], true)) throw new TwoFactorException('Unknown email request.');
    if (!account_email_ready($pdo) || !account_mail_ready()) throw new TwoFactorException('The host must configure account email and update its database first.', 503);
    $userId = (int)$user['id']; two_factor_check_limit($pdo, $userId);
    $code = implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
    $codeHash = hash('sha256', $code); $cancel = bin2hex(random_bytes(32)); $finish = bin2hex(random_bytes(32)); $now = time();
    $tx = database_transaction_begin($pdo, true);
    try {
        $fresh = account_email_lock_user($pdo, $userId);
        if (!hash_equals((string)$user['password_hash'], (string)$fresh['password_hash'])) throw new TwoFactorException('Account security changed. Sign in again.', 403);
        $user = $fresh;
        if ($purpose === 'recover' && !account_email_eligible($pdo, $user)) throw new TwoFactorException('Email recovery was not enabled for this authenticator enrollment. Use an authenticator or backup code.', 403);
        if (!filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new TwoFactorException('Set a valid private account email first.', 409);
        $prior = account_email_row($pdo, 'account_email_challenges', $userId, $purpose, true);
        if ($prior && $prior['state'] === 'waiting' && account_email_binding($pdo, $user, $prior) && $now <= (int)$prior['ready_at'] + 604800) throw new TwoFactorException('Recovery is already waiting. Return after the displayed time or cancel it.', 409);
        $retryAt = $prior ? (int)$prior['requested_at'] + ($prior['state'] === 'failed' ? 60 : ACCOUNT_EMAIL_REQUEST_SECONDS) : 0;
        if ($now < $retryAt) throw new TwoFactorException('Another email can be requested after ' . gmdate('Y-m-d H:i', $retryAt) . ' UTC.', 429);
        $pdo->prepare('DELETE FROM account_email_challenges WHERE user_id=? AND purpose=?')->execute([$userId,$purpose]);
        $pdo->prepare('INSERT INTO account_email_challenges (user_id,purpose,email,code_hash,cancel_hash,finish_hash,requested_at,expires_at,attempts,ready_at,state,mfa_revision,password_stamp) VALUES (?,?,?,?,?,?,?,?,0,0,?,?,?)')->execute([
            $userId,$purpose,$user['email'],$codeHash,hash('sha256',$cancel),hash('sha256',$finish),$now,$now+ACCOUNT_EMAIL_CODE_SECONDS,'sending',
            (int)(two_factor_row($pdo,$userId)['revision'] ?? 0),hash('sha256',$user['password_hash'])]);
        database_transaction_commit($pdo, $tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo,$tx); throw $e; }
    $cancelUrl = account_mail_config()['url'] . '/two-factor-recovery-cancel.php?account=' . $userId . '&token=' . $cancel;
    $finishUrl = account_mail_config()['url'] . '/login.php?email_recovery_account=' . $userId . '&email_recovery_token=' . $finish;
    $body = 'Account: ' . $user['username'] . "\nWebsite: " . account_mail_config()['url'] . "\n\n";
    $body .= $purpose === 'verify' ? "Verify your private account email with this code:\n\n" : "A request was made to recover your account after losing your authenticator. This does NOT disable 2FA immediately. Enter this code on the sign-in screen to start a 24-hour waiting period:\n\n";
    $body .= $code . "\n\nThis code expires in 15 minutes and works once. Never give it to another person.\n";
    $body .= $purpose === 'recover' ? "Keep this recovery link. After verifying the code and waiting 24 hours, use it to sign in with your password and finish recovery (within seven days):\n" . $finishUrl . "\n\nIf you did not request this, cancel it here:\n" . $cancelUrl . "\nYour authenticator and backup codes still work.\n" : "If you did not request this, ignore this message.\n";
    try { account_mail_send($user['email'], $purpose === 'verify' ? 'Verify your CoreChat account email' : 'CoreChat authenticator recovery requested', $body); }
    catch (Throwable $e) {
        $pdo->prepare("UPDATE account_email_challenges SET state='failed',code_hash='' WHERE user_id=? AND purpose=? AND code_hash=? AND state='sending'")->execute([$userId,$purpose,$codeHash]);
        throw $e;
    }
    $pdo->prepare("UPDATE account_email_challenges SET state='sent' WHERE user_id=? AND purpose=? AND code_hash=? AND state='sending'")->execute([$userId,$purpose,$codeHash]);
}

function account_email_confirm(PDO $pdo, array $user, string $purpose, string $code): void
{
    if (!in_array($purpose, ['verify','recover'], true) || !account_email_ready($pdo)) throw new TwoFactorException('Email verification is unavailable.', 503);
    $id = (int)$user['id']; two_factor_check_limit($pdo,$id); $valid = false;
    $tx = database_transaction_begin($pdo,true);
    try {
        $user = account_email_lock_user($pdo,$id);
        $row = account_email_row($pdo,'account_email_challenges',$id,$purpose,true);
        if ($row && $row['state'] === 'sent' && (int)$row['expires_at'] >= time() && (int)$row['attempts'] < 5 && account_email_binding($pdo,$user,$row)
            && ($purpose !== 'recover' || account_email_eligible($pdo,$user))) {
            $pdo->prepare('UPDATE account_email_challenges SET attempts=attempts+1 WHERE user_id=? AND purpose=?')->execute([$id,$purpose]);
            $valid = hash_equals($row['code_hash'],hash('sha256',strtoupper(trim($code))));
            if ($valid) {
                $pdo->prepare('UPDATE account_email_challenges SET code_hash=?,state=?,ready_at=? WHERE user_id=? AND purpose=?')->execute(['',$purpose === 'recover' ? 'waiting' : 'used',$purpose === 'recover' ? time()+ACCOUNT_EMAIL_WAIT_SECONDS : 0,$id,$purpose]);
                if ($purpose === 'verify') {
                    $existing = account_email_row($pdo,'account_email_state',$id,null,true);
                    if ($existing) $pdo->prepare('UPDATE account_email_state SET verified_email=?,verified_at=? WHERE user_id=?')->execute([$user['email'],time(),$id]);
                    else $pdo->prepare('INSERT INTO account_email_state (user_id,verified_email,verified_at,recovery_epoch) VALUES (?,?,?,0)')->execute([$id,$user['email'],time()]);
                }
            }
        }
        database_transaction_commit($pdo,$tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo,$tx); throw $e; }
    if (!$valid) { auth_rate_record_failure($pdo,'two-factor',(string)$id); throw new TwoFactorException('Email code is invalid, expired, already used, or its five attempts have been used. Check the latest email and request limit.',403); }
    if ($purpose === 'recover') $_SESSION['_email_recovery_finish'] = ['userId'=>$id,'hash'=>$row['finish_hash']];
    auth_rate_clear_identifier($pdo,'two-factor',(string)$id);
}

/** Called only after the ordinary password step; never authenticate by an email URL. */
function account_email_finish_recovery(PDO $pdo, array $user): void
{
    $id = (int)$user['id']; $tx = database_transaction_begin($pdo,true);
    try {
        $fresh = account_email_lock_user($pdo,$id);
        if (!hash_equals($user['password_hash'],$fresh['password_hash'])) throw new TwoFactorException('Sign in again with your current password.',403);
        $row = account_email_row($pdo,'account_email_challenges',$id,'recover',true);
        if (!$row || $row['state'] !== 'waiting' || !account_email_binding($pdo,$fresh,$row) || !account_email_eligible($pdo,$fresh)) throw new TwoFactorException('There is no valid pending email recovery.',403);
        $proof = $_SESSION['_email_recovery_finish'] ?? [];
        if ((int)($proof['userId'] ?? 0) !== $id || empty($row['finish_hash']) || !hash_equals($row['finish_hash'],(string)($proof['hash'] ?? ''))) throw new TwoFactorException('Open the recovery link in your original email, then sign in with your password to finish.',403);
        if (time() < (int)$row['ready_at']) throw new TwoFactorException('Recovery can finish after ' . gmdate('Y-m-d H:i',(int)$row['ready_at']) . ' UTC. Your authenticator and backup codes still work.',409);
        if (time() > (int)$row['ready_at'] + 604800) throw new TwoFactorException('The recovery completion period expired. Request recovery again.',409);
        $pdo->prepare('UPDATE account_two_factor SET secret_ciphertext=NULL,last_step=-1,revision=revision+1,enabled_at=NULL WHERE user_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM account_two_factor_backup WHERE user_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM account_two_factor_email WHERE user_id=?')->execute([$id]);
        $pdo->prepare("UPDATE account_email_challenges SET state='used',code_hash='',cancel_hash='',finish_hash='' WHERE user_id=?")->execute([$id]);
        $pdo->prepare('UPDATE account_email_state SET recovery_epoch=recovery_epoch+1 WHERE user_id=?')->execute([$id]);
        database_transaction_commit($pdo,$tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo,$tx); throw $e; }
    try { account_mail_send($user['email'],'CoreChat authenticator recovery completed',"Your email recovery finished. Two-factor authentication is now off, old backup codes are invalid, and previous sessions have been signed out. Sign in with your password and set up a new authenticator.\nWebsite: " . account_mail_config()['url']); }
    catch (TwoFactorException $e) { /* Completion is committed even if the informational notice cannot be delivered. */ }
}

/** Token-bearing GET only displays confirmation; mutation requires POST and CSRF. */
function account_email_cancel(PDO $pdo, int $userId, ?string $token = null): void
{
    $tx = database_transaction_begin($pdo,true);
    try {
        account_email_lock_user($pdo,$userId);
        $row = account_email_row($pdo,'account_email_challenges',$userId,'recover',true);
        if (!$row || !in_array($row['state'],['sending','sent','waiting'],true) || ($token !== null && (strlen($token) !== 64 || !hash_equals($row['cancel_hash'],hash('sha256',$token))))) throw new TwoFactorException('This recovery request is unavailable or has already ended.',403);
        $pdo->prepare("UPDATE account_email_challenges SET state='cancelled',code_hash='',cancel_hash='',finish_hash='' WHERE user_id=? AND purpose='recover'")->execute([$userId]);
        database_transaction_commit($pdo,$tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo,$tx); throw $e; }
}

function account_email_invalidate(PDO $pdo, int $userId): void
{
    if (!account_email_ready($pdo)) return;
    $pdo->prepare('UPDATE account_email_state SET verified_email=NULL,verified_at=NULL WHERE user_id=?')->execute([$userId]);
    $pdo->prepare("UPDATE account_email_challenges SET state='cancelled',code_hash='',cancel_hash='',finish_hash='' WHERE user_id=?")->execute([$userId]);
}
