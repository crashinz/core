<?php
declare(strict_types=1);

/** Transport settings are installation configuration, never public app settings. */
function account_mail_config(): array
{
    $read = static fn(string $key, mixed $default): mixed => defined($key) ? constant($key) : $default;
    return [
        'enabled' => $read('CHATSPACE_ACCOUNT_MAIL_ENABLED', false) === true,
        'from' => (string)$read('CHATSPACE_ACCOUNT_MAIL_FROM', ''),
        'url' => rtrim((string)$read('CHATSPACE_ACCOUNT_MAIL_BASE_URL', ''), '/'),
        'transport' => (string)$read('CHATSPACE_ACCOUNT_MAIL_TRANSPORT', 'smtp'),
        'host' => (string)$read('CHATSPACE_ACCOUNT_SMTP_HOST', ''),
        'port' => (int)$read('CHATSPACE_ACCOUNT_SMTP_PORT', 587),
        'tls' => (string)$read('CHATSPACE_ACCOUNT_SMTP_SECURITY', 'tls'),
        'username' => (string)$read('CHATSPACE_ACCOUNT_SMTP_USERNAME', ''),
        'password' => (string)$read('CHATSPACE_ACCOUNT_SMTP_PASSWORD', ''),
    ];
}

function account_mail_ready(): bool
{
    $c = account_mail_config(); $url = parse_url($c['url']);
    if (!$c['enabled'] || !filter_var($c['from'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $c['from'])) return false;
    if (!$url || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) return false;
    if (($url['scheme'] ?? '') !== 'https' && !(($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['127.0.0.1','localhost','[::1]'], true))) return false;
    if ($c['transport'] === 'mail') return function_exists('mail');
    if ($c['transport'] !== 'smtp' || !preg_match('/^[a-zA-Z0-9.:-]+$/D', $c['host']) || $c['port'] < 1 || $c['port'] > 65535) return false;
    return in_array($c['tls'], ['tls','ssl'], true) || ($c['tls'] === '' && in_array($c['host'], ['127.0.0.1','localhost','::1'], true));
}

function account_mail_send(string $recipient, string $subject, string $body): void
{
    if (!account_mail_ready()) throw new TwoFactorException('The host has not configured account email. Contact the host administrator.', 503);
    require_once __DIR__ . '/vendor/phpmailer/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
    $c = account_mail_config();
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8'; $mail->Timeout = 10;
        $mail->XMailer = ''; $mail->SMTPDebug = 0;
        if ($c['transport'] === 'smtp') {
            $mail->isSMTP(); $mail->Host = $c['host']; $mail->Port = $c['port'];
            $mail->getSMTPInstance()->Timelimit = 15;
            $mail->SMTPSecure = $c['tls']; $mail->SMTPAutoTLS = $c['tls'] !== '';
            $mail->SMTPAuth = $c['username'] !== ''; $mail->Username = $c['username']; $mail->Password = $c['password'];
        } else $mail->isMail();
        $mail->setFrom($c['from'], 'CoreChat account security'); $mail->addAddress($recipient);
        $mail->Subject = $subject; $mail->Body = $body; $mail->send();
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // SMTP errors can contain server details; do not expose or log them with recovery data.
        throw new TwoFactorException('The email could not be sent. Try again in one minute or contact the host administrator.', 503);
    }
}
