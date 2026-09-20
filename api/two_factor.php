<?php
declare(strict_types=1);
define('CHATSPACE_RESTRICTED_ACCOUNT_ROUTE', true);
require_once __DIR__ . '/../includes/base.php';
security_protect_private_response();
$user = require_user(); $pdo = db();
function two_factor_account_status(PDO $pdo, array $user): array { return two_factor_status($pdo, (int)$user['id']) + account_email_status($pdo, $user); }
if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(two_factor_account_status($pdo, $user));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
$body = input_json(); $action = (string)($body['action'] ?? '');
try {
    if ($action === 'email_send' || $action === 'email_confirm') {
        two_factor_require_password($pdo, $user, (string)($body['password'] ?? ''));
        if ($action === 'email_send') account_email_request($pdo,$user,'verify');
        else account_email_confirm($pdo,$user,'verify',(string)($body['code'] ?? ''));
        json_out(two_factor_account_status($pdo,$user));
    }
    if ($action === 'email_cancel') {
        account_email_cancel($pdo,(int)$user['id']); json_out(two_factor_account_status($pdo,$user));
    }
    if ($action === 'begin') {
        $result = two_factor_begin($pdo, $user, (string)($body['password'] ?? ''));
        $issuer = str_replace(':', '-', 'CoreChat ' . (string)($_SERVER['HTTP_HOST'] ?? 'Chat') . app_base_path());
        $label = $issuer . ':' . str_replace(':', '-', (string)$user['username']);
        $result['uri'] = 'otpauth://totp/' . rawurlencode($label) . '?secret=' . $result['secret'] . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
        json_out($result);
    }
    if ($action === 'activate') {
        $codes = two_factor_activate($pdo, $user, (string)($body['code'] ?? ''));
        json_out(['backupCodes' => $codes] + two_factor_account_status($pdo, $user));
    }
    if ($action === 'disable' || $action === 'backup_codes') {
        $codes = two_factor_manage($pdo, $user, $action, (string)($body['password'] ?? ''), (string)($body['code'] ?? ''));
        json_out(['backupCodes' => $codes] + two_factor_account_status($pdo, $user));
    }
    throw new TwoFactorException('Unknown security action.');
} catch (TwoFactorException|SecurityPolicyViolation $e) { json_out(['error' => $e->getMessage()], $e->httpStatus); }
