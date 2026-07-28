<?php
declare(strict_types=1);

/**
 * Build 000051 HTTPS, trusted-proxy, opaque-network-identity, and exact-IP
 * privacy owner.
 *
 * The private policy mirror is intentionally available before database
 * initialization so transport and forwarded-header decisions fail closed.
 * Raw network addresses never enter ordinary settings, projections, or logs.
 */

const NETWORK_PRIVACY_POLICY_VERSION = 1;
const NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES = 5;
const NETWORK_PRIVACY_MIN_REVEAL_MINUTES = 1;
const NETWORK_PRIVACY_MAX_REVEAL_MINUTES = 60;

final class NetworkPrivacyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'NETWORK_PRIVACY_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function network_privacy_policy_path(): string
{
    return security_private_storage_directory('network-privacy')
        . DIRECTORY_SEPARATOR . 'policy-v1.json';
}

function network_privacy_default_policy(): array
{
    return [
        'version' => NETWORK_PRIVACY_POLICY_VERSION,
        'revision' => 1,
        'httpsEnforced' => true,
        'hstsDeploymentVerified' => false,
        'exactIpAccessEnabled' => false,
        'reasonRequired' => true,
        'defaultRevealMinutes' => NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES,
        'trustedProxies' => [],
        'testHosts' => [],
        'opaqueHmacKey' => base64_encode(random_bytes(32)),
        'addressEncryptionKey' => base64_encode(random_bytes(32)),
        'updatedAt' => gmdate('Y-m-d H:i:s'),
    ];
}

function network_privacy_validate_private_key(mixed $encoded): bool
{
    if (!is_string($encoded)) return false;
    $decoded = base64_decode($encoded, true);
    return is_string($decoded) && strlen($decoded) === 32;
}

function network_privacy_normalize_ip(string $value): ?string
{
    $value = trim($value, "[] \t\r\n");
    if (!filter_var($value, FILTER_VALIDATE_IP)) return null;
    $packed = @inet_pton($value);
    if ($packed === false) return null;
    $normalized = @inet_ntop($packed);
    if (!is_string($normalized) || $normalized === '') return null;
    if (str_starts_with(strtolower($normalized), '::ffff:')) {
        $mapped = substr($normalized, 7);
        if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $mapped;
    }
    return strtolower($normalized);
}

function network_privacy_normalize_cidr(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (!str_contains($value, '/')) {
        $ip = network_privacy_normalize_ip($value);
        if ($ip === null) return null;
        return $ip . (str_contains($ip, ':') ? '/128' : '/32');
    }
    [$rawIp, $rawPrefix] = array_pad(explode('/', $value, 2), 2, '');
    $ip = network_privacy_normalize_ip($rawIp);
    if ($ip === null || !preg_match('/^\d+$/', $rawPrefix)) return null;
    $prefix = (int)$rawPrefix;
    $maximum = str_contains($ip, ':') ? 128 : 32;
    if ($prefix < 0 || $prefix > $maximum) return null;
    return $ip . '/' . $prefix;
}

function network_privacy_ip_in_cidr(string $ip, string $cidr): bool
{
    $ip = network_privacy_normalize_ip($ip) ?? '';
    $cidr = network_privacy_normalize_cidr($cidr) ?? '';
    if ($ip === '' || $cidr === '') return false;
    [$network, $prefixText] = explode('/', $cidr, 2);
    $ipPacked = @inet_pton($ip);
    $networkPacked = @inet_pton($network);
    if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) return false;
    $prefix = (int)$prefixText;
    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if ($fullBytes > 0
        && !hash_equals(substr($networkPacked, 0, $fullBytes), substr($ipPacked, 0, $fullBytes))) {
        return false;
    }
    if ($remainingBits === 0) return true;
    $mask = (0xff << (8 - $remainingBits)) & 0xff;
    return (ord($networkPacked[$fullBytes]) & $mask) === (ord($ipPacked[$fullBytes]) & $mask);
}

function network_privacy_policy_normalize(array $candidate): array
{
    $trusted = [];
    foreach (($candidate['trustedProxies'] ?? []) as $entry) {
        $normalized = network_privacy_normalize_cidr((string)$entry);
        if ($normalized === null) {
            throw new NetworkPrivacyException(
                'A trusted proxy address or CIDR is invalid.',
                'NETWORK_TRUSTED_PROXY_INVALID',
                422
            );
        }
        $trusted[$normalized] = true;
    }
    if (count($trusted) > 64) {
        throw new NetworkPrivacyException(
            'At most 64 trusted proxy entries may be configured.',
            'NETWORK_TRUSTED_PROXY_LIMIT',
            422
        );
    }
    $testHosts = [];
    $configuredTestHosts = defined('CHATSPACE_NETWORK_TEST_HOSTS') && is_array(CHATSPACE_NETWORK_TEST_HOSTS)
        ? CHATSPACE_NETWORK_TEST_HOSTS
        : ($candidate['testHosts'] ?? []);
    foreach ($configuredTestHosts as $host) {
        $host = strtolower(trim((string)$host));
        if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) $testHosts[$host] = true;
    }
    $minutes = (int)($candidate['defaultRevealMinutes'] ?? NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES);
    if ($minutes < NETWORK_PRIVACY_MIN_REVEAL_MINUTES || $minutes > NETWORK_PRIVACY_MAX_REVEAL_MINUTES) {
        throw new NetworkPrivacyException(
            'Exact-IP reveal duration must be from 1 through 60 minutes.',
            'NETWORK_REVEAL_DURATION_INVALID',
            422
        );
    }
    if (!network_privacy_validate_private_key($candidate['opaqueHmacKey'] ?? null)
        || !network_privacy_validate_private_key($candidate['addressEncryptionKey'] ?? null)) {
        throw new NetworkPrivacyException(
            'The private network key authority is invalid.',
            'NETWORK_PRIVATE_KEY_INVALID',
            503
        );
    }
    return [
        'version' => NETWORK_PRIVACY_POLICY_VERSION,
        'revision' => max(1, (int)($candidate['revision'] ?? 1)),
        'httpsEnforced' => true,
        'hstsDeploymentVerified' => (bool)($candidate['hstsDeploymentVerified'] ?? false),
        'exactIpAccessEnabled' => (bool)($candidate['exactIpAccessEnabled'] ?? false),
        'reasonRequired' => true,
        'defaultRevealMinutes' => $minutes,
        'trustedProxies' => array_keys($trusted),
        'testHosts' => array_keys($testHosts),
        'opaqueHmacKey' => (string)$candidate['opaqueHmacKey'],
        'addressEncryptionKey' => (string)$candidate['addressEncryptionKey'],
        'updatedAt' => (string)($candidate['updatedAt'] ?? gmdate('Y-m-d H:i:s')),
    ];
}

function network_privacy_write_policy(array $policy): array
{
    $policy = network_privacy_policy_normalize($policy);
    $path = network_privacy_policy_path();
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)
        || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) !== strlen($json . PHP_EOL)
        || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new NetworkPrivacyException(
            'The private network policy could not be stored atomically.',
            'NETWORK_POLICY_WRITE_FAILED',
            503
        );
    }
    @chmod($path, 0600);
    $GLOBALS['CHATSPACE_NETWORK_PRIVACY_POLICY'] = $policy;
    return $policy;
}

function network_privacy_policy(bool $refresh = false): array
{
    if (!$refresh && is_array($GLOBALS['CHATSPACE_NETWORK_PRIVACY_POLICY'] ?? null)) {
        return $GLOBALS['CHATSPACE_NETWORK_PRIVACY_POLICY'];
    }
    $path = network_privacy_policy_path();
    if (!is_file($path)) return network_privacy_write_policy(network_privacy_default_policy());
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        throw new NetworkPrivacyException(
            'The private network policy is unreadable.',
            'NETWORK_POLICY_INVALID',
            503
        );
    }
    $policy = network_privacy_policy_normalize($decoded);
    $GLOBALS['CHATSPACE_NETWORK_PRIVACY_POLICY'] = $policy;
    return $policy;
}

function network_privacy_remote_is_trusted(string $remote, array $trusted): bool
{
    foreach ($trusted as $cidr) {
        if (network_privacy_ip_in_cidr($remote, (string)$cidr)) return true;
    }
    return false;
}

function network_privacy_request_context(?array $server = null, ?array $policy = null): array
{
    $server ??= $_SERVER;
    $policy ??= network_privacy_policy();
    $remote = network_privacy_normalize_ip((string)($server['REMOTE_ADDR'] ?? '')) ?? '0.0.0.0';
    $trustedRemote = network_privacy_remote_is_trusted($remote, $policy['trustedProxies']);
    $client = $remote;
    $forwardedUsed = false;
    if ($trustedRemote) {
        $chain = [];
        foreach (explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
            $normalized = network_privacy_normalize_ip($candidate);
            if ($normalized !== null) $chain[] = $normalized;
        }
        $chain[] = $remote;
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            $candidate = $chain[$index];
            if ($index === count($chain) - 1 || network_privacy_remote_is_trusted($candidate, $policy['trustedProxies'])) {
                continue;
            }
            $client = $candidate;
            $forwardedUsed = true;
            break;
        }
    }
    $directHttps = strtolower((string)($server['HTTPS'] ?? '')) === 'on'
        || (string)($server['SERVER_PORT'] ?? '') === '443';
    $scheme = $directHttps ? 'https' : 'http';
    if ($trustedRemote) {
        $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
            $forwardedUsed = true;
        }
    }
    return [
        'clientIp' => $client,
        'directPeerIp' => $remote,
        'scheme' => $scheme,
        'https' => $scheme === 'https',
        'trustedProxy' => $trustedRemote,
        'forwardedUsed' => $forwardedUsed,
    ];
}

function network_privacy_host_without_port(string $host): string
{
    $host = strtolower(trim($host));
    if (str_starts_with($host, '[')) {
        $close = strpos($host, ']');
        return $close === false ? trim($host, '[]') : substr($host, 1, $close - 1);
    }
    if (substr_count($host, ':') === 1) return explode(':', $host, 2)[0];
    return trim($host, '[]');
}

function network_privacy_is_local_or_test_request(array $server, array $context, array $policy): bool
{
    $host = network_privacy_host_without_port((string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
    if (in_array($context['directPeerIp'], ['127.0.0.1', '::1'], true)
        && in_array($host, $policy['testHosts'], true)) {
        return true;
    }
    return false;
}

function network_privacy_transport_decision(
    ?array $server = null,
    ?array $policy = null,
    ?array $context = null
): array {
    $server ??= $_SERVER;
    $policy ??= network_privacy_policy();
    $context ??= network_privacy_request_context($server, $policy);
    if ($context['https'] || network_privacy_is_local_or_test_request($server, $context, $policy)) {
        return ['action' => 'allow', 'status' => 200, 'location' => null];
    }
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD'], true)) {
        $host = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        if ($host === '' || preg_match('/[\r\n]/', $host)) {
            return ['action' => 'reject', 'status' => 426, 'location' => null];
        }
        $uri = (string)($server['REQUEST_URI'] ?? '/');
        if ($uri === '' || !str_starts_with($uri, '/') || preg_match('/[\r\n]/', $uri)) $uri = '/';
        return ['action' => 'redirect', 'status' => 308, 'location' => 'https://' . $host . $uri];
    }
    return ['action' => 'reject', 'status' => 426, 'location' => null];
}

function network_privacy_enforce_transport(): void
{
    if (PHP_SAPI === 'cli') return;
    $decision = network_privacy_transport_decision();
    if ($decision['action'] === 'allow') return;
    if ($decision['action'] === 'redirect') {
        header('Location: ' . $decision['location'], true, 308);
        exit;
    }
    http_response_code((int)$decision['status']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'A secure HTTPS connection is required. No mutation was attempted.';
    exit;
}

function network_privacy_should_send_hsts(): bool
{
    return network_privacy_request_context()['https']
        && network_privacy_policy()['hstsDeploymentVerified'];
}

function network_privacy_client_ip(): string
{
    return network_privacy_request_context()['clientIp'];
}

function network_privacy_opaque_identifier(string $ip): string
{
    $normalized = network_privacy_normalize_ip($ip);
    if ($normalized === null) {
        throw new NetworkPrivacyException('The network address is invalid.', 'NETWORK_ADDRESS_INVALID', 422);
    }
    $key = base64_decode(network_privacy_policy()['opaqueHmacKey'], true);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new NetworkPrivacyException('The network identity key is unavailable.', 'NETWORK_PRIVATE_KEY_INVALID', 503);
    }
    return 'Network ' . strtoupper(substr(hash_hmac('sha256', $normalized, $key), 0, 12));
}

function network_privacy_encrypt_address(string $ip): array
{
    $normalized = network_privacy_normalize_ip($ip);
    if ($normalized === null) {
        throw new NetworkPrivacyException('The network address is invalid.', 'NETWORK_ADDRESS_INVALID', 422);
    }
    $key = base64_decode(network_privacy_policy()['addressEncryptionKey'], true);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $normalized,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'chatspace-network-address-v1'
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new NetworkPrivacyException('The network address could not be protected.', 'NETWORK_ENCRYPTION_FAILED', 503);
    }
    return [
        'ciphertext' => base64_encode($ciphertext),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
    ];
}

function network_privacy_decrypt_address(array $row): string
{
    $key = base64_decode(network_privacy_policy()['addressEncryptionKey'], true);
    $ciphertext = base64_decode((string)($row['address_ciphertext'] ?? ''), true);
    $nonce = base64_decode((string)($row['address_nonce_b64'] ?? ''), true);
    $tag = base64_decode((string)($row['address_tag_b64'] ?? ''), true);
    if (!is_string($key) || !is_string($ciphertext) || !is_string($nonce) || !is_string($tag)) {
        throw new NetworkPrivacyException('The protected network address is invalid.', 'NETWORK_DECRYPTION_FAILED', 503);
    }
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'chatspace-network-address-v1'
    );
    $normalized = is_string($plaintext) ? network_privacy_normalize_ip($plaintext) : null;
    if ($normalized === null) {
        throw new NetworkPrivacyException('The protected network address failed integrity validation.', 'NETWORK_DECRYPTION_FAILED', 503);
    }
    return $normalized;
}

function network_privacy_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS network_observations (
                opaque_id VARCHAR(32) PRIMARY KEY,
                address_ciphertext TEXT NOT NULL, address_nonce_b64 VARCHAR(64) NOT NULL,
                address_tag_b64 VARCHAR(64) NOT NULL, key_version INT NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revision BIGINT NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS network_reveal_leases (
                owner_user_id INT PRIMARY KEY, opaque_id VARCHAR(32) NOT NULL,
                session_hash VARCHAR(64) NOT NULL, reason_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_network_lease_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_network_lease_observation FOREIGN KEY (opaque_id) REFERENCES network_observations(opaque_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS network_privacy_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE,
                actor_user_id INT NOT NULL, action VARCHAR(64) NOT NULL,
                opaque_id VARCHAR(32) DEFAULT NULL, reason_hash VARCHAR(64) DEFAULT NULL,
                detail_json TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_network_event_actor (actor_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS network_observations (
            opaque_id TEXT PRIMARY KEY,
            address_ciphertext TEXT NOT NULL, address_nonce_b64 TEXT NOT NULL,
            address_tag_b64 TEXT NOT NULL, key_version INTEGER NOT NULL DEFAULT 1,
            first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revision INTEGER NOT NULL DEFAULT 1
        )",
        "CREATE TABLE IF NOT EXISTS network_reveal_leases (
            owner_user_id INTEGER PRIMARY KEY, opaque_id TEXT NOT NULL,
            session_hash TEXT NOT NULL, reason_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(opaque_id) REFERENCES network_observations(opaque_id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS network_privacy_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE,
            actor_user_id INTEGER NOT NULL, action TEXT NOT NULL,
            opaque_id TEXT DEFAULT NULL, reason_hash TEXT DEFAULT NULL,
            detail_json TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_network_event_actor ON network_privacy_events(actor_user_id, created_at)',
    ];
}

function network_privacy_install_schema(PDO $pdo): void
{
    foreach (network_privacy_schema_statements($pdo) as $statement) $pdo->exec($statement);
    foreach ([
        'network_https_enforced' => '1',
        'network_hsts_deployment_verified' => '0',
        'network_exact_ip_access_enabled' => '0',
        'network_exact_ip_reveal_minutes' => (string)NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES,
        'network_trusted_proxy_count' => '0',
    ] as $key => $value) {
        $insert = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)';
        $pdo->prepare($insert)->execute([$key, $value]);
    }
}

function network_privacy_schema_valid(PDO $pdo): bool
{
    foreach (['network_observations', 'network_reveal_leases', 'network_privacy_events'] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    $minutes = (int)app_setting($pdo, 'network_exact_ip_reveal_minutes', '0');
    return app_setting($pdo, 'network_https_enforced', '') === '1'
        && in_array(app_setting($pdo, 'network_exact_ip_access_enabled', ''), ['0', '1'], true)
        && $minutes >= NETWORK_PRIVACY_MIN_REVEAL_MINUTES
        && $minutes <= NETWORK_PRIVACY_MAX_REVEAL_MINUTES;
}

function network_privacy_require_owner(PDO $pdo, int $userId): void
{
    if (!moderation_identity_is_owner($pdo, $userId)) {
        throw new NetworkPrivacyException(
            'Installation Owner access is required.',
            'NETWORK_INSTALLATION_OWNER_REQUIRED',
            403
        );
    }
}

function network_privacy_mask_cidr(string $cidr): string
{
    $normalized = network_privacy_normalize_cidr($cidr) ?? '';
    if ($normalized === '') return 'Invalid entry';
    [$ip, $prefix] = explode('/', $normalized, 2);
    if (str_contains($ip, ':')) {
        $groups = explode(':', $ip);
        return ($groups[0] ?? '0') . ':' . ($groups[1] ?? '0') . ':…/' . $prefix;
    }
    $parts = explode('.', $ip);
    return ($parts[0] ?? '0') . '.' . ($parts[1] ?? '0') . '.×.×/' . $prefix;
}

function network_privacy_policy_projection(PDO $pdo, int $ownerUserId): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    $policy = network_privacy_policy(true);
    return [
        'revision' => $policy['revision'],
        'httpsEnforced' => true,
        'hstsDeploymentVerified' => $policy['hstsDeploymentVerified'],
        'exactIpAccessEnabled' => $policy['exactIpAccessEnabled'],
        'reasonRequired' => true,
        'defaultRevealMinutes' => $policy['defaultRevealMinutes'],
        'trustedProxyCount' => count($policy['trustedProxies']),
        'trustedProxiesMasked' => array_map('network_privacy_mask_cidr', $policy['trustedProxies']),
        'trustedProxyValuesStoredPrivately' => true,
        'ordinaryViewsUseOpaqueIdentifiers' => true,
    ];
}

function network_privacy_record_event(
    PDO $pdo,
    int $actorUserId,
    string $action,
    ?string $opaqueId,
    ?string $reason,
    array $detail = []
): void {
    $reasonHash = $reason === null ? null : strtoupper(hash('sha256', trim($reason)));
    $safeDetail = array_intersect_key($detail, array_flip(['revision', 'durationMinutes', 'proxyCount', 'result']));
    $pdo->prepare(
        'INSERT INTO network_privacy_events (public_id,actor_user_id,action,opaque_id,reason_hash,detail_json)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        'network-event-' . strtolower(str_replace('-', '', uuid_v4())),
        $actorUserId,
        $action,
        $opaqueId,
        $reasonHash,
        json_encode($safeDetail, JSON_UNESCAPED_SLASHES),
    ]);
    log_tool(
        $pdo,
        $actorUserId,
        'network_privacy_' . $action,
        null,
        null,
        trim(implode('; ', array_filter([
            $opaqueId,
            $reasonHash === null ? null : 'reason-sha256:' . $reasonHash,
            isset($safeDetail['durationMinutes']) ? 'duration-minutes:' . $safeDetail['durationMinutes'] : null,
            isset($safeDetail['revision']) ? 'revision:' . $safeDetail['revision'] : null,
        ])))
    );
}

function network_privacy_update_policy(PDO $pdo, int $ownerUserId, array $input): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    $policy = network_privacy_policy(true);
    if ((int)($input['expectedRevision'] ?? 0) !== (int)$policy['revision']) {
        throw new NetworkPrivacyException(
            'Network privacy settings changed elsewhere. Refresh and try again.',
            'NETWORK_POLICY_STALE',
            409,
            ['currentRevision' => $policy['revision']]
        );
    }
    $candidate = $policy;
    $candidate['revision'] = $policy['revision'] + 1;
    $candidate['hstsDeploymentVerified'] = (bool)($input['hstsDeploymentVerified'] ?? false);
    $candidate['exactIpAccessEnabled'] = (bool)($input['exactIpAccessEnabled'] ?? false);
    $candidate['defaultRevealMinutes'] = (int)($input['defaultRevealMinutes'] ?? NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES);
    if (array_key_exists('trustedProxies', $input)) {
        if (!is_array($input['trustedProxies'])) {
            throw new NetworkPrivacyException('Trusted proxies must be a list.', 'NETWORK_TRUSTED_PROXY_INVALID', 422);
        }
        $candidate['trustedProxies'] = $input['trustedProxies'];
    }
    $candidate['updatedAt'] = gmdate('Y-m-d H:i:s');
    $stored = network_privacy_write_policy($candidate);
    set_app_setting($pdo, 'network_https_enforced', '1');
    set_app_setting($pdo, 'network_hsts_deployment_verified', $stored['hstsDeploymentVerified'] ? '1' : '0');
    set_app_setting($pdo, 'network_exact_ip_access_enabled', $stored['exactIpAccessEnabled'] ? '1' : '0');
    set_app_setting($pdo, 'network_exact_ip_reveal_minutes', (string)$stored['defaultRevealMinutes']);
    set_app_setting($pdo, 'network_trusted_proxy_count', (string)count($stored['trustedProxies']));
    network_privacy_record_event($pdo, $ownerUserId, 'policy_update', null, null, [
        'revision' => $stored['revision'],
        'proxyCount' => count($stored['trustedProxies']),
        'result' => 'stored',
    ]);
    return network_privacy_policy_projection($pdo, $ownerUserId);
}

function network_privacy_observe(PDO $pdo, string $ip): string
{
    $normalized = network_privacy_normalize_ip($ip);
    if ($normalized === null) {
        throw new NetworkPrivacyException('The network address is invalid.', 'NETWORK_ADDRESS_INVALID', 422);
    }
    $opaqueId = network_privacy_opaque_identifier($normalized);
    $existing = $pdo->prepare('SELECT opaque_id FROM network_observations WHERE opaque_id=? LIMIT 1');
    $existing->execute([$opaqueId]);
    if ($existing->fetchColumn() !== false) {
        $pdo->prepare(
            'UPDATE network_observations SET last_seen_at=CURRENT_TIMESTAMP,revision=revision+1 WHERE opaque_id=?'
        )->execute([$opaqueId]);
        return $opaqueId;
    }
    $encrypted = network_privacy_encrypt_address($normalized);
    $pdo->prepare(
        'INSERT INTO network_observations
         (opaque_id,address_ciphertext,address_nonce_b64,address_tag_b64,key_version)
         VALUES (?,?,?,?,1)'
    )->execute([$opaqueId, $encrypted['ciphertext'], $encrypted['nonce'], $encrypted['tag']]);
    return $opaqueId;
}

function network_privacy_session_hash(): string
{
    return strtoupper(hash('sha256', session_id() !== '' ? session_id() : 'no-session'));
}

function network_privacy_reveal(
    PDO $pdo,
    int $ownerUserId,
    string $opaqueId,
    string $reason,
    int $durationMinutes
): array {
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    $policy = network_privacy_policy(true);
    if (!$policy['exactIpAccessEnabled']) {
        throw new NetworkPrivacyException(
            'Exact IP Access is disabled.',
            'NETWORK_EXACT_IP_ACCESS_DISABLED',
            403
        );
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) {
        throw new NetworkPrivacyException(
            'A reason of 1 through 500 characters is required.',
            'NETWORK_REVEAL_REASON_REQUIRED',
            422
        );
    }
    if ($durationMinutes < NETWORK_PRIVACY_MIN_REVEAL_MINUTES
        || $durationMinutes > NETWORK_PRIVACY_MAX_REVEAL_MINUTES) {
        throw new NetworkPrivacyException(
            'Exact-IP reveal duration must be from 1 through 60 minutes.',
            'NETWORK_REVEAL_DURATION_INVALID',
            422
        );
    }
    $row = $pdo->prepare('SELECT * FROM network_observations WHERE opaque_id=? LIMIT 1');
    $row->execute([$opaqueId]);
    $observation = $row->fetch();
    if (!is_array($observation)) {
        throw new NetworkPrivacyException('The opaque network identity was not found.', 'NETWORK_IDENTITY_NOT_FOUND', 404);
    }
    $sessionHash = network_privacy_session_hash();
    $lease = $pdo->prepare('SELECT * FROM network_reveal_leases WHERE owner_user_id=? LIMIT 1');
    $lease->execute([$ownerUserId]);
    $active = $lease->fetch();
    $now = time();
    if (is_array($active) && strtotime((string)$active['expires_at'] . ' UTC') > $now) {
        if (!hash_equals((string)$active['opaque_id'], $opaqueId)
            || !hash_equals((string)$active['session_hash'], $sessionHash)) {
            throw new NetworkPrivacyException(
                'Hide the active exact-IP reveal before opening another.',
                'NETWORK_REVEAL_LEASE_ACTIVE',
                409,
                ['expiresAt' => $active['expires_at']]
            );
        }
        return [
            'opaqueId' => $opaqueId,
            'exactIp' => network_privacy_decrypt_address($observation),
            'expiresAt' => (string)$active['expires_at'],
            'nonExtendable' => true,
            'idempotentReplay' => true,
        ];
    }
    $pdo->prepare('DELETE FROM network_reveal_leases WHERE owner_user_id=?')->execute([$ownerUserId]);
    $expiresAt = gmdate('Y-m-d H:i:s', $now + ($durationMinutes * 60));
    $pdo->prepare(
        'INSERT INTO network_reveal_leases
         (owner_user_id,opaque_id,session_hash,reason_hash,expires_at)
         VALUES (?,?,?,?,?)'
    )->execute([$ownerUserId, $opaqueId, $sessionHash, strtoupper(hash('sha256', $reason)), $expiresAt]);
    network_privacy_record_event($pdo, $ownerUserId, 'exact_ip_reveal', $opaqueId, $reason, [
        'durationMinutes' => $durationMinutes,
        'result' => 'revealed',
    ]);
    return [
        'opaqueId' => $opaqueId,
        'exactIp' => network_privacy_decrypt_address($observation),
        'expiresAt' => $expiresAt,
        'nonExtendable' => true,
        'idempotentReplay' => false,
    ];
}

function network_privacy_hide(PDO $pdo, int $ownerUserId): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    $statement = $pdo->prepare('SELECT opaque_id FROM network_reveal_leases WHERE owner_user_id=? LIMIT 1');
    $statement->execute([$ownerUserId]);
    $opaqueId = $statement->fetchColumn();
    $pdo->prepare('DELETE FROM network_reveal_leases WHERE owner_user_id=?')->execute([$ownerUserId]);
    network_privacy_record_event(
        $pdo,
        $ownerUserId,
        'exact_ip_hide',
        $opaqueId === false ? null : (string)$opaqueId,
        null,
        ['result' => 'hidden']
    );
    return ['hidden' => true];
}
