<?php
declare(strict_types=1);

final class SecurityPolicyViolation extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 403)
    {
        parent::__construct($message);
    }
}

function security_request_is_https(): bool
{
    return strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function security_content_security_policy(): string
{
    return "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; "
        . "script-src 'self' 'unsafe-inline' https://www.youtube.com https://www.youtube-nocookie.com https://s.ytimg.com; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; "
        . "media-src 'self' data: blob: https://*.giphy.com https://*.klipy.com https://api.klipy.com https://*.tenor.com https://tenor.googleapis.com https://media.tenor.com; "
        . "font-src 'self'; connect-src 'self' https://api.giphy.com https://*.giphy.com https://api.klipy.com https://*.klipy.com https://tenor.googleapis.com https://*.tenor.com; "
        . "frame-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com; "
        . "child-src 'self' https://www.youtube-nocookie.com https://www.youtube.com https://open.spotify.com https://w.soundcloud.com; "
        . "worker-src 'self' blob:; manifest-src 'self'";
}

function security_send_browser_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(), payment=(), usb=(), browsing-topics=()');
    header('Content-Security-Policy: ' . security_content_security_policy());
}

function security_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => security_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $now = time();
    $_SESSION['_session_started_at'] ??= $now;
    $_SESSION['_session_rotated_at'] ??= $now;
    $_SESSION['_session_last_seen_at'] = $now;
}

function security_bootstrap(): void
{
    security_send_browser_headers();
    security_start_session();
}

function security_mark_authenticated(int $userId): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['user_id'] = $userId;
    $_SESSION['_authenticated_at'] = $now;
    $_SESSION['_session_started_at'] = $now;
    $_SESSION['_session_rotated_at'] = $now;
    $_SESSION['_session_last_seen_at'] = $now;
    unset($_SESSION['_csrf_token'], $_SESSION['session_locked']);
}

function security_mark_recent_authentication(): void
{
    $_SESSION['_authenticated_at'] = time();
}

function security_recent_authentication_valid(int $maxAgeSeconds = 1800): bool
{
    $authenticatedAt = (int)($_SESSION['_authenticated_at'] ?? 0);
    return $authenticatedAt > 0 && (time() - $authenticatedAt) <= max(60, $maxAgeSeconds);
}

function security_require_recent_authentication(int $maxAgeSeconds = 1800): void
{
    if (!security_recent_authentication_valid($maxAgeSeconds)) {
        throw new SecurityPolicyViolation('Please sign in again before performing this sensitive action.', 403);
    }
}

function security_require_recent_authentication_or_json(int $maxAgeSeconds = 1800): void
{
    try {
        security_require_recent_authentication($maxAgeSeconds);
    } catch (SecurityPolicyViolation $error) {
        json_out(['error' => $error->getMessage(), 'reauthentication_required' => true], $error->httpStatus);
    }
}

function security_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string)($params['path'] ?? '/'),
            'domain' => (string)($params['domain'] ?? ''),
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string)($params['samesite'] ?? 'Lax'),
        ]);
    }
    session_destroy();
}

function security_protect_private_response(): void
{
    if (headers_sent()) return;
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function security_outside_content_catalog(): array
{
    return [
        'avatar_upload' => ['auth' => 'user', 'storage' => '/assets/uploads/avatars/', 'archive' => false],
        'registration_avatar' => ['auth' => 'registration', 'storage' => '/assets/uploads/avatars/', 'archive' => false],
        'setup_avatar' => ['auth' => 'setup', 'storage' => '/assets/uploads/avatars/', 'archive' => false],
        'setup_branding' => ['auth' => 'setup', 'storage' => '/assets/uploads/branding/', 'archive' => false],
        'gesture_upload' => ['auth' => 'user', 'storage' => '/assets/uploads/gestures/', 'archive' => 'agst'],
        'chat_file_upload' => ['auth' => 'participant', 'storage' => '/assets/uploads/files/', 'archive' => false],
        'voice_note_upload' => ['auth' => 'participant', 'storage' => '/assets/uploads/voice/', 'archive' => false],
        'room_create' => ['auth' => 'user', 'storage' => null, 'archive' => false],
        'room_background_upload' => ['auth' => 'user', 'storage' => '/assets/uploads/backgrounds/', 'archive' => false],
        'room_import_preview' => ['auth' => 'user', 'storage' => null, 'archive' => false],
        'room_import_create' => ['auth' => 'user', 'storage' => '/assets/uploads/imported-rooms/', 'archive' => false],
        'admin_link_icon_upload' => ['auth' => 'user', 'storage' => '/assets/uploads/link-icons/', 'archive' => false],
        'diagnostic_screenshot' => ['auth' => 'user', 'storage' => 'private', 'archive' => false],
        'database_import' => ['auth' => 'user', 'storage' => 'private', 'archive' => 'backup'],
        'setup_database_import' => ['auth' => 'setup', 'storage' => 'private', 'archive' => 'backup'],
    ];
}

function security_policy_enabled(PDO $pdo, string $key, bool $default = true): bool
{
    try {
        return app_setting($pdo, $key, $default ? '1' : '0') === '1';
    } catch (Throwable) {
        return $default;
    }
}

function security_authorize_outside_content(?PDO $pdo, ?array $actor, string $operation, array $context = []): array
{
    $catalog = security_outside_content_catalog();
    if (!isset($catalog[$operation])) {
        throw new SecurityPolicyViolation('Unknown outside-content operation.', 403);
    }
    $policy = $catalog[$operation];
    $authMode = (string)$policy['auth'];
    if (in_array($authMode, ['user', 'participant'], true) && (int)($actor['id'] ?? $actor['user_id'] ?? 0) < 1) {
        throw new SecurityPolicyViolation('Authentication is required.', 401);
    }
    if ($authMode === 'registration' && !empty($_SESSION['user_id'])) {
        throw new SecurityPolicyViolation('Registration upload context is invalid.', 403);
    }
    if ($authMode === 'setup' && (($context['setup_allowed'] ?? false) !== true)) {
        throw new SecurityPolicyViolation('Setup upload context is invalid.', 403);
    }
    if ($pdo && !security_policy_enabled($pdo, 'outside_content_enabled', true)) {
        throw new SecurityPolicyViolation('Outside content is disabled for this installation.', 403);
    }

    $identifier = (string)((int)($actor['id'] ?? $actor['user_id'] ?? 0));
    if ($identifier === '0') $identifier = session_id() ?: client_ip_address();
    if ($pdo && function_exists('auth_rate_limit_status')) {
        $scope = 'outside:' . $operation;
        $limit = auth_rate_limit_status($pdo, $scope, $identifier);
        if (!$limit['allowed']) throw new SecurityPolicyViolation((string)$limit['message'], 429);
        auth_rate_record_failure($pdo, $scope, $identifier);
    }

    return [
        'operation' => $operation,
        'actor_user_id' => (int)($actor['id'] ?? $actor['user_id'] ?? 0),
        'authentication' => $authMode,
        'trust' => 'build_000048_foundation_pending',
        'capability' => 'build_000048_foundation_pending',
        'terms_rules' => 'build_000048_foundation_pending',
        'confirmation_mode' => 'build_000051_workflow_pending',
        'installation_policy' => 'enabled',
        'storage' => $policy['storage'],
        'archive' => $policy['archive'],
        'audit_context' => array_intersect_key($context, array_flip(['room_id', 'session_id', 'channel', 'source'])),
    ];
}

function security_authorize_outside_content_or_json(?PDO $pdo, ?array $actor, string $operation, array $context = []): array
{
    try {
        return security_authorize_outside_content($pdo, $actor, $operation, $context);
    } catch (SecurityPolicyViolation $error) {
        json_out(['error' => $error->getMessage()], $error->httpStatus);
    }
}

function security_assert_storage_destination(string $operation, string $publicPath): void
{
    $policy = security_outside_content_catalog()[$operation] ?? null;
    if (!$policy) throw new SecurityPolicyViolation('Unknown outside-content operation.', 403);
    $expected = $policy['storage'];
    if (!is_string($expected) || !str_starts_with($publicPath, $expected) || str_contains($publicPath, '..')) {
        throw new SecurityPolicyViolation('Outside content has an invalid storage destination.', 500);
    }
}

function security_image_type_for_mime(string $mime): ?int
{
    return match ($mime) {
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/gif' => IMAGETYPE_GIF,
        'image/webp' => IMAGETYPE_WEBP,
        default => null,
    };
}

function security_valid_image_file(string $path, string $mime): bool
{
    $expected = security_image_type_for_mime($mime);
    if ($expected === null || !is_file($path)) return false;
    $dimensions = @getimagesize($path);
    return is_array($dimensions) && (int)($dimensions[2] ?? 0) === $expected;
}

function security_valid_uploaded_file_signature(string $path, string $mime, string $extension = ''): bool
{
    if (!is_file($path)) return false;
    if (str_starts_with($mime, 'image/')) return security_valid_image_file($path, $mime);
    $prefix = (string)file_get_contents($path, false, null, 0, 32);
    if ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        if (!class_exists('ZipArchive')) return false;
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) return false;
        $valid = $archive->locateName('[Content_Types].xml', ZipArchive::FL_NOCASE) !== false
            && $archive->locateName('word/document.xml', ZipArchive::FL_NOCASE) !== false;
        $archive->close();
        return $valid && strtolower($extension) === 'docx';
    }
    return match ($mime) {
        'application/pdf' => str_starts_with($prefix, '%PDF-'),
        'application/msword' => str_starts_with($prefix, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'application/rtf', 'text/rtf' => str_starts_with(ltrim($prefix), '{\\rtf'),
        'text/plain' => !str_contains($prefix, "\0"),
        'audio/webm', 'video/webm' => str_starts_with($prefix, "\x1A\x45\xDF\xA3"),
        'audio/ogg', 'application/ogg' => str_starts_with($prefix, 'OggS'),
        'audio/mpeg', 'audio/mp3' => str_starts_with($prefix, 'ID3') || (strlen($prefix) >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xE0) === 0xE0),
        'audio/mp4' => strlen($prefix) >= 12 && substr($prefix, 4, 4) === 'ftyp',
        'audio/aac' => strlen($prefix) >= 2 && ord($prefix[0]) === 0xFF && (ord($prefix[1]) & 0xF6) === 0xF0,
        'audio/wav', 'audio/x-wav' => str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WAVE',
        'video/mp4' => strlen($prefix) >= 12 && substr($prefix, 4, 4) === 'ftyp',
        default => false,
    };
}

function security_ip_is_public(string $ip): bool
{
    $ip = trim($ip, "[] \t\r\n");
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (str_starts_with(strtolower($ip), '::ffff:')) {
        $mapped = substr($ip, 7);
        return filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function security_ip_matches(string $actual, array $allowed): bool
{
    $actualPacked = @inet_pton(trim($actual, '[]'));
    if ($actualPacked === false) return false;
    foreach ($allowed as $candidate) {
        $candidatePacked = @inet_pton(trim((string)$candidate, '[]'));
        if ($candidatePacked !== false && hash_equals($candidatePacked, $actualPacked)) return true;
    }
    return false;
}

function security_private_storage_directory(string $category): string
{
    $category = preg_replace('/[^a-z0-9-]/', '', strtolower($category)) ?: 'files';
    $publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $base = defined('CHATSPACE_PRIVATE_STORAGE_PATH')
        ? (string)CHATSPACE_PRIVATE_STORAGE_PATH
        : dirname($publicRoot) . DIRECTORY_SEPARATOR . 'chatspace-private';
    $base = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base), DIRECTORY_SEPARATOR);
    $isAbsolute = str_starts_with($base, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $base) === 1;
    if ($base === '' || !$isAbsolute) {
        throw new SecurityPolicyViolation('Private storage must use an absolute path.', 500);
    }
    $publicCompare = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRoot), DIRECTORY_SEPARATOR));
    $baseCompare = strtolower($base);
    if ($baseCompare === $publicCompare || str_starts_with($baseCompare . DIRECTORY_SEPARATOR, $publicCompare . DIRECTORY_SEPARATOR)) {
        throw new SecurityPolicyViolation('Private storage must remain outside the public web root.', 500);
    }
    $directory = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $category;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new SecurityPolicyViolation('Could not create private storage.', 500);
    }
    return $directory;
}

function security_resolve_public_host(string $host, ?callable $resolver = null): array
{
    $host = strtolower(trim($host, "[] \t\r\n"));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new SecurityPolicyViolation('That host is not allowed for remote requests.', 400);
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } elseif ($resolver) {
        $ips = $resolver($host);
    } else {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = (string)$record['ip'];
                if (!empty($record['ipv6'])) $ips[] = (string)$record['ipv6'];
            }
        }
        if (!$ips) $ips = gethostbynamel($host) ?: [];
    }
    $ips = array_values(array_unique(array_filter(array_map('strval', is_array($ips) ? $ips : []))));
    if (!$ips) throw new SecurityPolicyViolation('That host could not be resolved safely.', 400);
    foreach ($ips as $ip) {
        if (!security_ip_is_public($ip)) {
            throw new SecurityPolicyViolation('Private, loopback, link-local, or reserved remote targets are not allowed.', 400);
        }
    }
    usort($ips, static fn(string $a, string $b): int => (str_contains($a, ':') <=> str_contains($b, ':')));
    return $ips;
}

function security_remote_target(string $url, ?callable $resolver = null): array
{
    $url = trim($url);
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        throw new SecurityPolicyViolation('That URL does not look valid.', 400);
    }
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new SecurityPolicyViolation('Only HTTP and HTTPS URLs are allowed.', 400);
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new SecurityPolicyViolation('Remote URLs cannot contain credentials.', 400);
    }
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
        throw new SecurityPolicyViolation('Only standard HTTP and HTTPS ports are allowed.', 400);
    }
    $host = strtolower((string)$parts['host']);
    $ips = security_resolve_public_host($host, $resolver);
    return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ips' => $ips, 'pinned_ip' => $ips[0]];
}

function security_fetch_remote_url(string $url, int $maxBytes, string $accept, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new SecurityPolicyViolation('Secure remote fetching requires the PHP cURL extension.', 503);
    }
    $redirects = max(0, min(5, (int)($options['redirects'] ?? 3)));
    $referer = (string)($options['referer'] ?? '');
    $userAgent = (string)($options['user_agent'] ?? 'ChatSpaceCE-RemoteFetch/1.0');
    $timeout = max(2, min(30, (int)($options['timeout'] ?? 16)));
    $current = $url;
    for ($hop = 0; $hop <= $redirects; $hop++) {
        $target = security_remote_target($current, $options['resolver'] ?? null);
        $body = '';
        $responseHeaders = [];
        $ch = curl_init($target['url']);
        if (!$ch) throw new SecurityPolicyViolation('Could not initialize a secure remote request.', 503);
        $resolveIp = str_contains($target['pinned_ip'], ':') ? '[' . $target['pinned_ip'] . ']' : $target['pinned_ip'];
        $headers = ['Accept: ' . $accept, 'Cache-Control: no-cache'];
        if ($referer !== '') $headers[] = 'Referer: ' . $referer;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolveIp],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = trim($header);
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        if (defined('CURLOPT_REDIR_PROTOCOLS')) curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $primaryIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        if (!security_ip_matches($primaryIp, $target['ips']) || !security_ip_is_public($primaryIp)) {
            throw new SecurityPolicyViolation('Remote address changed during the request.', 400);
        }
        if (!$ok || $errno) {
            if (strlen($body) >= $maxBytes) throw new SecurityPolicyViolation('The remote file is too large.', 400);
            throw new SecurityPolicyViolation('Could not fetch that remote URL safely.', 502);
        }
        $location = '';
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Location:') === 0) $location = trim(substr($header, 9));
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            if ($hop >= $redirects) throw new SecurityPolicyViolation('The remote URL redirected too many times.', 400);
            $current = absolutize_preview_url($location, $target['url']);
            $referer = $target['url'];
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new SecurityPolicyViolation('The remote server returned HTTP ' . $status . '.', 400);
        }
        if ($body === '') throw new SecurityPolicyViolation('Remote URL returned no content.', 400);
        return [
            'body' => $body,
            'url' => $effective !== '' ? $effective : $target['url'],
            'content_type' => $contentType,
            'status' => $status,
            'primary_ip' => $primaryIp,
        ];
    }
    throw new SecurityPolicyViolation('The remote URL could not be fetched safely.', 400);
}
