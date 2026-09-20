<?php
declare(strict_types=1);

/** Optional account TOTP. Login factors never derive private-chat encryption keys. */
final class TwoFactorException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400) { parent::__construct($message); }
}

function two_factor_ready(PDO $pdo): bool
{
    return database_migration_table_exists($pdo, 'account_two_factor');
}

function two_factor_row(PDO $pdo, int $userId, bool $lock = false): ?array
{
    if (!two_factor_ready($pdo)) {
        // A supported predecessor has no MFA. A damaged enrolled schema must not bypass it.
        if (database_migration_read_setting($pdo, 'schema_version') === '2026-09-20-optional-two-factor') {
            throw new TwoFactorException('Two-factor authentication storage is unavailable. Contact the host administrator.', 503);
        }
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM account_two_factor WHERE user_id=?' . ($lock && db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
    return $row ?: null;
}

function two_factor_enabled(PDO $pdo, int $userId): bool
{
    return !empty(two_factor_row($pdo, $userId)['secret_ciphertext']);
}

function two_factor_session_valid(PDO $pdo, int $userId): bool
{
    $row = two_factor_row($pdo, $userId);
    if (empty($row['secret_ciphertext'])) return true;
    $proof = $_SESSION['_two_factor_verified'] ?? [];
    return (int)($proof['userId'] ?? 0) === $userId
        && (int)($proof['revision'] ?? -1) === (int)$row['revision'];
}

function two_factor_mark_verified(int $userId, int $revision): void
{
    $_SESSION['_two_factor_verified'] = ['userId' => $userId, 'revision' => $revision, 'at' => time()];
}

function two_factor_base32(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = 0; $buffer = 0; $out = '';
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte; $bits += 8;
        while ($bits >= 5) { $bits -= 5; $out .= $alphabet[($buffer >> $bits) & 31]; }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits) $out .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $out;
}

function two_factor_decode(string $secret): string
{
    if (!preg_match('/^[A-Z2-7]{16,128}$/D', $secret)) throw new TwoFactorException('Invalid authenticator secret.', 500);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $buffer = 0; $bits = 0; $out = '';
    foreach (str_split($secret) as $char) {
        $buffer = ($buffer << 5) | strpos($alphabet, $char); $bits += 5;
        if ($bits >= 8) { $bits -= 8; $out .= chr(($buffer >> $bits) & 255); }
        $buffer &= (1 << $bits) - 1;
    }
    return $out;
}

function two_factor_totp(string $secret, int $step, int $digits = 6): string
{
    $digest = hash_hmac('sha1', pack('N2', intdiv($step, 4294967296), $step & 0xffffffff), two_factor_decode($secret), true);
    $offset = ord($digest[19]) & 15;
    $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function two_factor_matching_step(string $secret, string $code, int $lastStep, ?int $now = null): ?int
{
    if (!preg_match('/^\d{6}$/D', $code)) return null;
    $current = intdiv($now ?? time(), 30);
    foreach ([$current, $current - 1, $current + 1] as $step) {
        if ($step > $lastStep && hash_equals(two_factor_totp($secret, $step), $code)) return $step;
    }
    return null;
}

function two_factor_key(bool $create = false): string
{
    $directory = security_private_storage_directory('two-factor');
    $path = $directory . '/key-v1.bin';
    // Lock the entire read/create sequence: concurrent enrollment cannot replace a key.
    $lock = @fopen($directory . '/key.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new TwoFactorException('Authenticator storage is unavailable.', 503);
    try {
        if (!is_file($path) && $create) {
            $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($temp, random_bytes(32), LOCK_EX) !== 32 || !rename($temp, $path)) {
                @unlink($temp); throw new TwoFactorException('Authenticator storage is unavailable.', 503);
            }
            @chmod($path, 0600);
        }
        $key = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($key) || strlen($key) !== 32) throw new TwoFactorException('The installation authenticator key is unavailable. Contact the host administrator; backup codes can still be used.', 503);
        return $key;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function two_factor_seal(string $secret, int $userId): string
{
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($secret, 'aes-256-gcm', two_factor_key(), OPENSSL_RAW_DATA, $iv, $tag, 'CoreChat-TOTP-v1:' . $userId, 16);
    if ($cipher === false) throw new TwoFactorException('Could not protect authenticator secret.', 503);
    return base64_encode($iv . $tag . $cipher);
}

function two_factor_unseal(string $ciphertext, int $userId): string
{
    $bytes = base64_decode($ciphertext, true);
    if ($bytes === false || strlen($bytes) < 29) throw new TwoFactorException('Authenticator storage is invalid.', 503);
    $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', two_factor_key(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'CoreChat-TOTP-v1:' . $userId);
    if ($plain === false) throw new TwoFactorException('Authenticator storage could not be unlocked.', 503);
    return $plain;
}

function two_factor_status(PDO $pdo, int $userId): array
{
    $row = two_factor_row($pdo, $userId); $remaining = 0;
    if (!empty($row['secret_ciphertext'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM account_two_factor_backup WHERE user_id=?');
        $stmt->execute([$userId]); $remaining = (int)$stmt->fetchColumn();
    }
    return ['available' => two_factor_ready($pdo), 'enabled' => !empty($row['secret_ciphertext']), 'backupCodesRemaining' => $remaining];
}

function two_factor_check_limit(PDO $pdo, int $userId): void
{
    $limit = auth_rate_limit_status($pdo, 'two-factor', (string)$userId);
    if (!$limit['allowed']) throw new TwoFactorException($limit['message'], 429);
}

function two_factor_require_password(PDO $pdo, array $user, string $password): void
{
    two_factor_check_limit($pdo, (int)$user['id']);
    if (!password_verify($password, (string)$user['password_hash'])) {
        auth_rate_record_failure($pdo, 'two-factor', (string)$user['id']);
        throw new TwoFactorException('Current password is not correct.', 403);
    }
}

/** Caller owns the transaction and locked enrollment row. Consume once across all sessions. */
function two_factor_consume_locked(PDO $pdo, array $row, string $code): bool
{
    $userId = (int)$row['user_id']; $code = strtoupper(trim($code));
    if (preg_match('/^(?:[A-F0-9]{5}-){3}[A-F0-9]{5}$/D', $code)) {
        $stmt = $pdo->prepare('DELETE FROM account_two_factor_backup WHERE user_id=? AND code_hash=?');
        $stmt->execute([$userId, hash('sha256', $userId . ':' . $code)]);
        return $stmt->rowCount() === 1;
    }
    $step = two_factor_matching_step(two_factor_unseal($row['secret_ciphertext'], $userId), $code, (int)$row['last_step']);
    if ($step === null) return false;
    $stmt = $pdo->prepare('UPDATE account_two_factor SET last_step=? WHERE user_id=? AND revision=? AND last_step<?');
    $stmt->execute([$step, $userId, (int)$row['revision'], $step]);
    return $stmt->rowCount() === 1;
}

function two_factor_verify(PDO $pdo, int $userId, string $code): void
{
    two_factor_check_limit($pdo, $userId);
    $tx = database_transaction_begin($pdo, true);
    try {
        $row = two_factor_row($pdo, $userId, true);
        $valid = !empty($row['secret_ciphertext']) && two_factor_consume_locked($pdo, $row, $code);
        database_transaction_commit($pdo, $tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo, $tx); throw $e; }
    if (!$valid) {
        auth_rate_record_failure($pdo, 'two-factor', (string)$userId);
        throw new TwoFactorException('Code was not accepted or was already used. Use the next authenticator code or an unused backup code.', 403);
    }
    auth_rate_clear_identifier($pdo, 'two-factor', (string)$userId);
    two_factor_mark_verified($userId, (int)$row['revision']);
}

function two_factor_new_backup_codes(PDO $pdo, int $userId): array
{
    $pdo->prepare('DELETE FROM account_two_factor_backup WHERE user_id=?')->execute([$userId]);
    $insert = $pdo->prepare('INSERT INTO account_two_factor_backup (user_id,code_hash) VALUES (?,?)');
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $code = implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
        $insert->execute([$userId, hash('sha256', $userId . ':' . $code)]); $codes[] = $code;
    }
    return $codes;
}

function two_factor_begin(PDO $pdo, array $user, string $password): array
{
    if (!two_factor_ready($pdo)) throw new TwoFactorException('The host must update its database before setting up 2FA.', 503);
    two_factor_require_password($pdo, $user, $password);
    $row = two_factor_row($pdo, (int)$user['id']);
    if (!empty($row['secret_ciphertext'])) throw new TwoFactorException('2FA is already enabled. Disable the old authenticator before setting up its replacement.', 409);
    $existing = (int)$pdo->query('SELECT COUNT(*) FROM account_two_factor WHERE secret_ciphertext IS NOT NULL')->fetchColumn();
    two_factor_key($existing === 0); // Never silently replace a lost key for enrolled accounts.
    $secret = two_factor_base32(random_bytes(20));
    $_SESSION['_two_factor_enrollment'] = ['userId' => (int)$user['id'], 'secretCiphertext' => two_factor_seal($secret, (int)$user['id']), 'expires' => time() + 600, 'revision' => (int)($row['revision'] ?? 0), 'passwordHash' => hash('sha256', (string)$user['password_hash'])];
    return ['secret' => $secret];
}

function two_factor_activate(PDO $pdo, array $user, string $code): array
{
    $userId = (int)$user['id']; two_factor_check_limit($pdo, $userId);
    $pending = $_SESSION['_two_factor_enrollment'] ?? [];
    if (($pending['userId'] ?? null) !== $userId || ($pending['expires'] ?? 0) < time()
        || !hash_equals((string)($pending['passwordHash'] ?? ''), hash('sha256', (string)$user['password_hash']))) {
        unset($_SESSION['_two_factor_enrollment']); throw new TwoFactorException('Setup expired. Start setup again.', 409);
    }
    $secret = two_factor_unseal($pending['secretCiphertext'], $userId);
    $step = two_factor_matching_step($secret, trim($code), -1);
    if ($step === null) { auth_rate_record_failure($pdo, 'two-factor', (string)$userId); throw new TwoFactorException('Enter a current six-digit code from your authenticator.', 403); }
    $tx = database_transaction_begin($pdo, true);
    try {
        // Lock an existing user even before there is an enrollment row.
        $lock = $pdo->prepare('SELECT id FROM users WHERE id=?' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '')); $lock->execute([$userId]); $lock->fetchAll(); $lock->closeCursor();
        $row = two_factor_row($pdo, $userId, true);
        if (!empty($row['secret_ciphertext']) || (int)($row['revision'] ?? 0) !== $pending['revision']) throw new TwoFactorException('Security settings changed. Start setup again.', 409);
        $revision = $pending['revision'] + 1;
        $cipher = two_factor_seal($secret, $userId);
        if ($row) $pdo->prepare('UPDATE account_two_factor SET secret_ciphertext=?,last_step=?,revision=?,enabled_at=CURRENT_TIMESTAMP WHERE user_id=?')->execute([$cipher,$step,$revision,$userId]);
        else $pdo->prepare('INSERT INTO account_two_factor (secret_ciphertext,last_step,revision,user_id,enabled_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP)')->execute([$cipher,$step,$revision,$userId]);
        $codes = two_factor_new_backup_codes($pdo, $userId);
        database_transaction_commit($pdo, $tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo, $tx); throw $e; }
    unset($_SESSION['_two_factor_enrollment']); two_factor_mark_verified($userId, $revision);
    auth_rate_clear_identifier($pdo, 'two-factor', (string)$userId);
    return $codes;
}

function two_factor_manage(PDO $pdo, array $user, string $action, string $password, string $code): array
{
    $userId = (int)$user['id']; two_factor_require_password($pdo, $user, $password);
    $tx = database_transaction_begin($pdo, true); $valid = false; $codes = [];
    try {
        $row = two_factor_row($pdo, $userId, true);
        $valid = !empty($row['secret_ciphertext']) && two_factor_consume_locked($pdo, $row, $code);
        if ($valid) {
            if ($action === 'disable') {
                $pdo->prepare('UPDATE account_two_factor SET secret_ciphertext=NULL,last_step=-1,revision=revision+1,enabled_at=NULL WHERE user_id=?')->execute([$userId]);
                $pdo->prepare('DELETE FROM account_two_factor_backup WHERE user_id=?')->execute([$userId]);
            } elseif ($action === 'backup_codes') $codes = two_factor_new_backup_codes($pdo, $userId);
            else throw new TwoFactorException('Unknown security action.');
        }
        database_transaction_commit($pdo, $tx);
    } catch (Throwable $e) { database_transaction_rollback($pdo, $tx); throw $e; }
    if (!$valid) { auth_rate_record_failure($pdo, 'two-factor', (string)$userId); throw new TwoFactorException('Code was not accepted or was already used.', 403); }
    auth_rate_clear_identifier($pdo, 'two-factor', (string)$userId);
    unset($_SESSION['_two_factor_enrollment']);
    return $codes;
}
