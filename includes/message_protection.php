<?php
declare(strict_types=1);

/**
 * Build 000051 message-protection, trusted-device, recovery-envelope, and
 * transition owner. See framework/specification/MESSAGE_PROTECTION_PROTOCOL.md.
 */

const MESSAGE_PROTECTION_PROTOCOL = 'corechat-message-protection-v1';
const MESSAGE_PROTECTION_VERSION = 1;
const MESSAGE_PROTECTION_MODES = ['standard', 'server-encrypted', 'e2ee-private'];
const MESSAGE_PROTECTION_PRIVATE_CHANNELS = ['dm', 'link'];
const MESSAGE_PROTECTION_E2EE_TYPES = ['text'];
const MESSAGE_PROTECTION_RECOVERY_ITERATIONS = 600000;

final class MessageProtectionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MESSAGE_PROTECTION_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function message_protection_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('message_protection_canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = message_protection_canonicalize($item);
    return $value;
}

function message_protection_canonical_json(array $value): string
{
    $json = json_encode(
        message_protection_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) {
        throw new MessageProtectionException(
            'Message protection metadata could not be encoded.',
            'MESSAGE_PROTECTION_ENCODING_FAILED',
            500
        );
    }
    return $json;
}

function message_protection_base64url_decode(string $value): string|false
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) return false;
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    if (!is_string($decoded) || !hash_equals(message_protection_base64url_encode($decoded), $value)) return false;
    return $decoded;
}

function message_protection_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function message_protection_der_length(int $length): string
{
    if ($length < 128) return chr($length);
    $bytes = ltrim(pack('N', $length), "\0");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function message_protection_der_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '') $bytes = "\0";
    if ((ord($bytes[0]) & 0x80) !== 0) $bytes = "\0" . $bytes;
    return "\x02" . message_protection_der_length(strlen($bytes)) . $bytes;
}

function message_protection_p1363_to_der(string $signature): string|false
{
    if (strlen($signature) !== 64) return false;
    $body = message_protection_der_integer(substr($signature, 0, 32))
        . message_protection_der_integer(substr($signature, 32, 32));
    return "\x30" . message_protection_der_length(strlen($body)) . $body;
}

function message_protection_public_jwk_pem(array $jwk): string
{
    $x = message_protection_base64url_decode((string)($jwk['x'] ?? ''));
    $y = message_protection_base64url_decode((string)($jwk['y'] ?? ''));
    if (!is_string($x) || strlen($x) !== 32 || !is_string($y) || strlen($y) !== 32) {
        throw new MessageProtectionException(
            'The signing public key is invalid.',
            'MESSAGE_PROTECTION_DEVICE_KEY_INVALID',
            422
        );
    }
    $algorithm = hex2bin('301306072A8648CE3D020106082A8648CE3D030107');
    $point = "\x04" . $x . $y;
    $bitString = "\x03" . message_protection_der_length(strlen($point) + 1) . "\0" . $point;
    $body = $algorithm . $bitString;
    $der = "\x30" . message_protection_der_length(strlen($body)) . $body;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function message_protection_verify_signature(array $jwk, string $material, string $signatureB64Url): bool
{
    $raw = message_protection_base64url_decode($signatureB64Url);
    if (!is_string($raw)) return false;
    $der = message_protection_p1363_to_der($raw);
    if (!is_string($der)) return false;
    return openssl_verify($material, $der, message_protection_public_jwk_pem($jwk), OPENSSL_ALGO_SHA256) === 1;
}

function message_protection_server_key(int $version = 1, bool $create = true): string
{
    if ($version < 1 || $version > 9999) {
        throw new MessageProtectionException('The server key version is invalid.', 'MESSAGE_PROTECTION_KEY_VERSION_INVALID', 500);
    }
    $directory = security_private_storage_directory('message-protection');
    $path = $directory . DIRECTORY_SEPARATOR . "server-key-v{$version}.bin";
    if (!is_file($path) && $create) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $key = random_bytes(32);
        if (file_put_contents($temporary, $key, LOCK_EX) !== 32 || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new MessageProtectionException(
                'The installation-private message key could not be initialized.',
                'MESSAGE_PROTECTION_KEY_UNAVAILABLE',
                503
            );
        }
        @chmod($path, 0600);
    }
    $key = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new MessageProtectionException(
            'The matching installation-private message key is unavailable.',
            'MESSAGE_PROTECTION_KEY_UNAVAILABLE',
            503,
            ['keyVersion' => $version]
        );
    }
    return $key;
}

function message_protection_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS message_protection_policies (
                conversation_kind VARCHAR(32) NOT NULL, conversation_key VARCHAR(191) NOT NULL,
                mode VARCHAR(32) NOT NULL DEFAULT 'standard', protocol_version INT NOT NULL DEFAULT 1,
                key_epoch BIGINT NOT NULL DEFAULT 1, revision BIGINT NOT NULL DEFAULT 1,
                updated_by_user_id INT DEFAULT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (conversation_kind, conversation_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS message_protection_transitions (
                request_id VARCHAR(128) PRIMARY KEY, conversation_kind VARCHAR(32) NOT NULL,
                conversation_key VARCHAR(191) NOT NULL, actor_user_id INT NOT NULL,
                from_mode VARCHAR(32) NOT NULL, to_mode VARCHAR(32) NOT NULL,
                explanation_hash VARCHAR(64) NOT NULL, confirmed TINYINT(1) NOT NULL,
                status VARCHAR(32) NOT NULL, cursor_id BIGINT NOT NULL DEFAULT 0,
                old_total BIGINT NOT NULL DEFAULT 0, converted_total BIGINT NOT NULL DEFAULT 0,
                remaining_total BIGINT NOT NULL DEFAULT 0, lease_token_hash VARCHAR(64) DEFAULT NULL,
                lease_expires_at DATETIME DEFAULT NULL, error_code VARCHAR(96) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_message_transition_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS message_protection_devices (
                device_id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, label VARCHAR(191) NOT NULL,
                encryption_public_jwk TEXT NOT NULL, signing_public_jwk TEXT NOT NULL,
                fingerprint VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1, approved_by_device_id VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_message_device_fingerprint (user_id, fingerprint),
                INDEX idx_message_device_user (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS message_protection_device_approvals (
                request_id VARCHAR(128) PRIMARY KEY, user_id INT NOT NULL,
                approver_device_id VARCHAR(64) NOT NULL, target_device_id VARCHAR(64) NOT NULL,
                target_revision BIGINT NOT NULL, signature_b64url TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS message_protection_key_envelopes (
                conversation_kind VARCHAR(32) NOT NULL, conversation_key VARCHAR(191) NOT NULL,
                key_epoch BIGINT NOT NULL, recipient_device_id VARCHAR(64) NOT NULL,
                sender_device_id VARCHAR(64) NOT NULL, envelope_json TEXT NOT NULL,
                envelope_sha256 VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (conversation_kind, conversation_key, key_epoch, recipient_device_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS message_protection_recovery (
                user_id INT PRIMARY KEY, protocol_version INT NOT NULL DEFAULT 1,
                recovery_public_jwk TEXT NOT NULL, salt_b64url VARCHAR(64) NOT NULL,
                iterations INT NOT NULL, nonce_b64url VARCHAR(64) NOT NULL,
                ciphertext_b64url TEXT NOT NULL, tag_b64url VARCHAR(64) NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1, updated_by_device_id VARCHAR(64) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS message_protection_policies (
            conversation_kind TEXT NOT NULL, conversation_key TEXT NOT NULL,
            mode TEXT NOT NULL DEFAULT 'standard', protocol_version INTEGER NOT NULL DEFAULT 1,
            key_epoch INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1,
            updated_by_user_id INTEGER DEFAULT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_kind, conversation_key)
        )",
        "CREATE TABLE IF NOT EXISTS message_protection_transitions (
            request_id TEXT PRIMARY KEY, conversation_kind TEXT NOT NULL,
            conversation_key TEXT NOT NULL, actor_user_id INTEGER NOT NULL,
            from_mode TEXT NOT NULL, to_mode TEXT NOT NULL,
            explanation_hash TEXT NOT NULL, confirmed INTEGER NOT NULL,
            status TEXT NOT NULL, cursor_id INTEGER NOT NULL DEFAULT 0,
            old_total INTEGER NOT NULL DEFAULT 0, converted_total INTEGER NOT NULL DEFAULT 0,
            remaining_total INTEGER NOT NULL DEFAULT 0, lease_token_hash TEXT DEFAULT NULL,
            lease_expires_at TEXT DEFAULT NULL, error_code TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT DEFAULT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_message_transition_status ON message_protection_transitions(status, updated_at)',
        "CREATE TABLE IF NOT EXISTS message_protection_devices (
            device_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, label TEXT NOT NULL,
            encryption_public_jwk TEXT NOT NULL, signing_public_jwk TEXT NOT NULL,
            fingerprint TEXT NOT NULL, status TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
            approved_by_device_id TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (user_id, fingerprint)
        )",
        'CREATE INDEX IF NOT EXISTS idx_message_device_user ON message_protection_devices(user_id, status)',
        "CREATE TABLE IF NOT EXISTS message_protection_device_approvals (
            request_id TEXT PRIMARY KEY, user_id INTEGER NOT NULL,
            approver_device_id TEXT NOT NULL, target_device_id TEXT NOT NULL,
            target_revision INTEGER NOT NULL, signature_b64url TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS message_protection_key_envelopes (
            conversation_kind TEXT NOT NULL, conversation_key TEXT NOT NULL,
            key_epoch INTEGER NOT NULL, recipient_device_id TEXT NOT NULL,
            sender_device_id TEXT NOT NULL, envelope_json TEXT NOT NULL,
            envelope_sha256 TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_kind, conversation_key, key_epoch, recipient_device_id)
        )",
        "CREATE TABLE IF NOT EXISTS message_protection_recovery (
            user_id INTEGER PRIMARY KEY, protocol_version INTEGER NOT NULL DEFAULT 1,
            recovery_public_jwk TEXT NOT NULL, salt_b64url TEXT NOT NULL,
            iterations INTEGER NOT NULL, nonce_b64url TEXT NOT NULL,
            ciphertext_b64url TEXT NOT NULL, tag_b64url TEXT NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1, updated_by_device_id TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    ];
}

function message_protection_add_message_columns(PDO $pdo, string $table): void
{
    $definitions = [
        'protection_mode' => "TEXT NOT NULL DEFAULT 'standard'",
        'protection_version' => 'INTEGER NOT NULL DEFAULT 1',
        'protection_key_epoch' => 'INTEGER NOT NULL DEFAULT 1',
        'protection_envelope_json' => 'TEXT DEFAULT NULL',
        'client_message_id' => 'TEXT DEFAULT NULL',
    ];
    $columns = database_migration_columns($pdo, $table);
    foreach ($definitions as $column => $definition) {
        if (in_array($column, $columns, true)) continue;
        if (db_driver($pdo) === 'mysql') {
            $definition = str_replace('INTEGER', 'BIGINT', $definition);
            $definition = preg_replace('/\bTEXT\b/', 'VARCHAR(191)', $definition, 1) ?? $definition;
            if ($column === 'protection_envelope_json') $definition = 'LONGTEXT DEFAULT NULL';
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
    $index = $table === 'messages' ? 'idx_messages_client_protection' : 'idx_community_messages_client_protection';
    try {
        if (db_driver($pdo) === 'mysql') {
            $statement = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name=?");
            $statement->execute([$index]);
            if (!$statement->fetch()) {
                $pdo->exec("CREATE UNIQUE INDEX {$index} ON {$table}(user_id,client_message_id)");
            }
        } else {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table}(user_id,client_message_id)");
        }
    } catch (Throwable $error) {
        if (!str_contains(strtolower($error->getMessage()), 'duplicate')) throw $error;
    }
}

function message_protection_install_schema(PDO $pdo): void
{
    foreach (message_protection_schema_statements($pdo) as $statement) $pdo->exec($statement);
    message_protection_add_message_columns($pdo, 'messages');
    message_protection_add_message_columns($pdo, 'community_messages');
    foreach ([
        'message_protection_default_mode' => 'standard',
        'message_protection_protocol_version' => (string)MESSAGE_PROTECTION_VERSION,
        'message_protection_server_key_version' => '1',
    ] as $key => $value) {
        $insert = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)';
        $pdo->prepare($insert)->execute([$key, $value]);
    }
}

function message_protection_schema_valid(PDO $pdo): bool
{
    foreach ([
        'message_protection_policies', 'message_protection_transitions',
        'message_protection_devices', 'message_protection_device_approvals',
        'message_protection_key_envelopes', 'message_protection_recovery',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    foreach (['messages', 'community_messages'] as $table) {
        if (!database_migration_has_columns($pdo, $table, [
            'protection_mode', 'protection_version', 'protection_key_epoch',
            'protection_envelope_json', 'client_message_id',
        ])) return false;
    }
    return app_setting($pdo, 'message_protection_default_mode', '') === 'standard'
        && app_setting($pdo, 'message_protection_protocol_version', '') === '1';
}

function message_protection_conversation(string $channel, array $payload): array
{
    $channel = strtolower($channel);
    if ($channel === 'room') return ['kind' => 'room', 'key' => (string)(int)($payload['session_id'] ?? 0)];
    if ($channel === 'community') return ['kind' => 'community', 'key' => 'community'];
    if ($channel === 'dm') return ['kind' => 'dm', 'key' => trim((string)($payload['dm_key'] ?? $payload['link_key'] ?? ''))];
    if ($channel === 'link') return ['kind' => 'link', 'key' => trim((string)($payload['link_key'] ?? ''))];
    if ($channel === 'game') return ['kind' => 'game', 'key' => trim((string)($payload['lobby_code'] ?? ''))];
    throw new MessageProtectionException('The message channel is unsupported.', 'MESSAGE_PROTECTION_CHANNEL_UNSUPPORTED', 422);
}

function message_protection_policy(PDO $pdo, string $kind, string $key): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM message_protection_policies WHERE conversation_kind=? AND conversation_key=? LIMIT 1'
    );
    $statement->execute([$kind, $key]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return [
            'conversationKind' => $kind,
            'conversationKey' => $key,
            'mode' => 'standard',
            'protocolVersion' => MESSAGE_PROTECTION_VERSION,
            'keyEpoch' => 1,
            'revision' => 0,
        ];
    }
    return [
        'conversationKind' => (string)$row['conversation_kind'],
        'conversationKey' => (string)$row['conversation_key'],
        'mode' => (string)$row['mode'],
        'protocolVersion' => (int)$row['protocol_version'],
        'keyEpoch' => (int)$row['key_epoch'],
        'revision' => (int)$row['revision'],
    ];
}

function message_protection_encrypt_server_package(array $package, array $context, int $keyVersion = 1): array
{
    $plaintext = message_protection_canonical_json($package);
    $aad = message_protection_canonical_json($context);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        message_protection_server_key($keyVersion),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        $aad
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new MessageProtectionException(
            'The server-encrypted message could not be protected.',
            'MESSAGE_PROTECTION_ENCRYPTION_FAILED',
            500
        );
    }
    return [
        'protocol' => MESSAGE_PROTECTION_PROTOCOL,
        'mode' => 'server-encrypted',
        'keyVersion' => $keyVersion,
        'nonce' => message_protection_base64url_encode($nonce),
        'ciphertext' => message_protection_base64url_encode($ciphertext),
        'tag' => message_protection_base64url_encode($tag),
        'aadSha256' => strtoupper(hash('sha256', $aad)),
        'context' => $context,
    ];
}

function message_protection_decrypt_server_package(array $envelope): array
{
    if (($envelope['protocol'] ?? '') !== MESSAGE_PROTECTION_PROTOCOL
        || ($envelope['mode'] ?? '') !== 'server-encrypted') {
        throw new MessageProtectionException('The protected message envelope is invalid.', 'MESSAGE_PROTECTION_ENVELOPE_INVALID', 500);
    }
    $context = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];
    $aad = message_protection_canonical_json($context);
    if (!hash_equals(strtoupper(hash('sha256', $aad)), strtoupper((string)($envelope['aadSha256'] ?? '')))) {
        throw new MessageProtectionException('The protected message metadata failed integrity validation.', 'MESSAGE_PROTECTION_AAD_INVALID', 500);
    }
    $nonce = message_protection_base64url_decode((string)($envelope['nonce'] ?? ''));
    $ciphertext = message_protection_base64url_decode((string)($envelope['ciphertext'] ?? ''));
    $tag = message_protection_base64url_decode((string)($envelope['tag'] ?? ''));
    if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($ciphertext) || !is_string($tag) || strlen($tag) !== 16) {
        throw new MessageProtectionException('The protected message bytes are invalid.', 'MESSAGE_PROTECTION_ENVELOPE_INVALID', 500);
    }
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        message_protection_server_key((int)($envelope['keyVersion'] ?? 0), false),
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        $aad
    );
    $decoded = is_string($plaintext) ? json_decode($plaintext, true) : null;
    if (!is_array($decoded)) {
        throw new MessageProtectionException('The protected message failed integrity validation.', 'MESSAGE_PROTECTION_DECRYPTION_FAILED', 500);
    }
    return $decoded;
}

function message_protection_validate_e2ee_envelope(
    PDO $pdo,
    array $envelope,
    array $conversation,
    int $senderUserId,
    string $messageType,
    int $expectedEpoch
): array {
    foreach (['protocol', 'mode', 'conversation', 'clientMessageId', 'senderDeviceId', 'keyEpoch', 'sequence', 'nonce', 'ciphertext', 'tag', 'aadSha256', 'signature'] as $key) {
        if (!array_key_exists($key, $envelope)) {
            throw new MessageProtectionException('The E2EE envelope is incomplete.', 'MESSAGE_PROTECTION_E2EE_ENVELOPE_INVALID', 422);
        }
    }
    if (($envelope['protocol'] ?? '') !== MESSAGE_PROTECTION_PROTOCOL
        || ($envelope['mode'] ?? '') !== 'e2ee-private'
        || !hash_equals($conversation['key'], (string)$envelope['conversation'])
        || (int)($envelope['senderUserId'] ?? 0) !== $senderUserId
        || (int)$envelope['keyEpoch'] !== $expectedEpoch
        || !in_array($messageType, MESSAGE_PROTECTION_E2EE_TYPES, true)) {
        throw new MessageProtectionException('The E2EE envelope context is invalid.', 'MESSAGE_PROTECTION_E2EE_CONTEXT_INVALID', 422);
    }
    $clientMessageId = (string)$envelope['clientMessageId'];
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientMessageId) !== 1
        || (int)$envelope['sequence'] < 1) {
        throw new MessageProtectionException('The E2EE message identity is invalid.', 'MESSAGE_PROTECTION_E2EE_IDENTITY_INVALID', 422);
    }
    foreach (['nonce' => 12, 'tag' => 16] as $field => $bytes) {
        $decoded = message_protection_base64url_decode((string)$envelope[$field]);
        if (!is_string($decoded) || strlen($decoded) !== $bytes) {
            throw new MessageProtectionException('The E2EE envelope bytes are invalid.', 'MESSAGE_PROTECTION_E2EE_ENVELOPE_INVALID', 422);
        }
    }
    if (message_protection_base64url_decode((string)$envelope['ciphertext']) === false
        || message_protection_base64url_decode((string)$envelope['signature']) === false
        || preg_match('/^[A-F0-9]{64}$/', strtoupper((string)$envelope['aadSha256'])) !== 1) {
        throw new MessageProtectionException('The E2EE envelope authentication is invalid.', 'MESSAGE_PROTECTION_E2EE_ENVELOPE_INVALID', 422);
    }
    $aad = [
        'protocol' => MESSAGE_PROTECTION_PROTOCOL,
        'mode' => 'e2ee-private',
        'conversation' => $conversation['key'],
        'clientMessageId' => $clientMessageId,
        'senderUserId' => $senderUserId,
        'senderDeviceId' => (string)$envelope['senderDeviceId'],
        'keyEpoch' => (int)$envelope['keyEpoch'],
        'sequence' => (int)$envelope['sequence'],
        'messageType' => $messageType,
    ];
    $aadJson = message_protection_canonical_json($aad);
    if (!hash_equals(strtoupper(hash('sha256', $aadJson)), strtoupper((string)$envelope['aadSha256']))) {
        throw new MessageProtectionException('The E2EE metadata failed integrity validation.', 'MESSAGE_PROTECTION_E2EE_AAD_INVALID', 422);
    }
    $device = $pdo->prepare(
        "SELECT status,signing_public_jwk FROM message_protection_devices
         WHERE device_id=? AND user_id=? LIMIT 1"
    );
    $device->execute([(string)$envelope['senderDeviceId'], $senderUserId]);
    $deviceRow = $device->fetch();
    if (!is_array($deviceRow) || $deviceRow['status'] !== 'trusted') {
        throw new MessageProtectionException('A trusted sender device is required.', 'MESSAGE_PROTECTION_TRUSTED_DEVICE_REQUIRED', 403);
    }
    $signatureMaterial = $aadJson . "\n"
        . (string)$envelope['nonce'] . '.'
        . (string)$envelope['ciphertext'] . '.'
        . (string)$envelope['tag'];
    $signingJwk = json_decode((string)$deviceRow['signing_public_jwk'], true);
    if (!is_array($signingJwk)
        || !message_protection_verify_signature($signingJwk, $signatureMaterial, (string)$envelope['signature'])) {
        throw new MessageProtectionException('The E2EE signature is invalid.', 'MESSAGE_PROTECTION_E2EE_SIGNATURE_INVALID', 422);
    }
    return $envelope;
}

function message_protection_prepare_message(
    PDO $pdo,
    string $channel,
    string $messageType,
    array $payload
): array {
    $conversation = message_protection_conversation($channel, $payload);
    $policy = message_protection_policy($pdo, $conversation['kind'], $conversation['key']);
    $mode = $policy['mode'];
    $clientMessageId = trim((string)($payload['client_message_id'] ?? ''));
    if ($clientMessageId === '') $clientMessageId = uuid_v4();
    $package = [
        'content' => (string)($payload['content'] ?? ''),
        'originalContent' => $payload['original_content'] ?? null,
        'urlPreview' => $payload['url_preview'] ?? null,
        'replyTo' => $payload['reply_to'] ?? null,
    ];
    if ($mode === 'standard') {
        return [
            'mode' => 'standard', 'version' => MESSAGE_PROTECTION_VERSION,
            'keyEpoch' => $policy['keyEpoch'], 'clientMessageId' => $clientMessageId,
            'storageContent' => $package['content'], 'storageUrlPreview' => $payload['url_preview_json'] ?? null,
            'storageReplyTo' => $payload['reply_to_json'] ?? null, 'envelopeJson' => null,
            'projection' => $package,
        ];
    }
    if ($mode === 'server-encrypted') {
        $context = [
            'protocol' => MESSAGE_PROTECTION_PROTOCOL,
            'mode' => $mode,
            'conversation' => $conversation['key'],
            'clientMessageId' => $clientMessageId,
            'senderUserId' => (int)($payload['user_id'] ?? $payload['participant']['user_id'] ?? 0),
            'senderDeviceId' => null,
            'keyEpoch' => $policy['keyEpoch'],
            'messageType' => $messageType,
        ];
        $envelope = message_protection_encrypt_server_package($package, $context);
        return [
            'mode' => $mode, 'version' => MESSAGE_PROTECTION_VERSION,
            'keyEpoch' => $policy['keyEpoch'], 'clientMessageId' => $clientMessageId,
            'storageContent' => '', 'storageUrlPreview' => null,
            'storageReplyTo' => null, 'envelopeJson' => message_protection_canonical_json($envelope),
            'projection' => $package,
        ];
    }
    if (!in_array($channel, MESSAGE_PROTECTION_PRIVATE_CHANNELS, true)) {
        throw new MessageProtectionException('E2EE is limited to private chats.', 'MESSAGE_PROTECTION_E2EE_PRIVATE_ONLY', 422);
    }
    $envelope = message_protection_validate_e2ee_envelope(
        $pdo,
        is_array($payload['protection_envelope'] ?? null) ? $payload['protection_envelope'] : [],
        $conversation,
        (int)($payload['user_id'] ?? $payload['participant']['user_id'] ?? 0),
        $messageType,
        $policy['keyEpoch']
    );
    return [
        'mode' => $mode, 'version' => MESSAGE_PROTECTION_VERSION,
        'keyEpoch' => $policy['keyEpoch'], 'clientMessageId' => (string)$envelope['clientMessageId'],
        'storageContent' => '', 'storageUrlPreview' => null, 'storageReplyTo' => null,
        'envelopeJson' => message_protection_canonical_json($envelope),
        'projection' => ['content' => null, 'originalContent' => null, 'urlPreview' => null, 'replyTo' => null],
    ];
}

function message_protection_project_row(array $row): array
{
    $mode = (string)($row['protection_mode'] ?? 'standard');
    $row['protection_mode'] = $mode;
    $row['protection_version'] = (int)($row['protection_version'] ?? MESSAGE_PROTECTION_VERSION);
    $row['protection_key_epoch'] = (int)($row['protection_key_epoch'] ?? 1);
    $row['client_message_id'] = $row['client_message_id'] ?? null;
    if ($mode === 'standard') return $row;
    $envelope = json_decode((string)($row['protection_envelope_json'] ?? ''), true);
    if (!is_array($envelope)) {
        throw new MessageProtectionException('The protected message envelope is unavailable.', 'MESSAGE_PROTECTION_ENVELOPE_INVALID', 500);
    }
    if ($mode === 'server-encrypted') {
        $package = message_protection_decrypt_server_package($envelope);
        $row['content'] = (string)($package['content'] ?? '');
        $row['original_content'] = $package['originalContent'] ?? null;
        $row['url_preview_json'] = isset($package['urlPreview'])
            ? json_encode($package['urlPreview'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        $row['reply_to_json'] = isset($package['replyTo'])
            ? json_encode($package['replyTo'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        $row['protection_envelope'] = null;
        return $row;
    }
    $row['content'] = null;
    $row['original_content'] = null;
    $row['url_preview_json'] = null;
    $row['reply_to_json'] = null;
    $row['protection_envelope'] = $envelope;
    return $row;
}

function message_protection_event_payload(array $message, string $table): array
{
    $mode = (string)($message['protection_mode'] ?? 'standard');
    if ($mode === 'standard') return $message;
    $event = $message;
    unset($event['content'], $event['original_content'], $event['url_preview'], $event['reply_to']);
    $event['protected_message_id'] = (int)($message['id'] ?? 0);
    $event['protected_message_table'] = $table;
    if ($mode === 'e2ee-private') $event['protection_envelope'] = $message['protection_envelope'] ?? null;
    return $event;
}

function message_protection_project_event(PDO $pdo, array $payload): array
{
    $id = (int)($payload['protected_message_id'] ?? 0);
    $table = (string)($payload['protected_message_table'] ?? '');
    if ($id < 1 || !in_array($table, ['messages', 'community_messages'], true)) return $payload;
    $statement = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!is_array($row)) return $payload;
    $projected = message_protection_project_row($row);
    $payload['content'] = $projected['content'];
    $payload['url_preview'] = message_url_preview($projected['url_preview_json'] ?? null);
    $payload['reply_to'] = message_url_preview($projected['reply_to_json'] ?? null);
    $payload['protection_mode'] = $projected['protection_mode'];
    $payload['protection_version'] = $projected['protection_version'];
    $payload['protection_key_epoch'] = $projected['protection_key_epoch'];
    $payload['client_message_id'] = $projected['client_message_id'];
    if ($projected['protection_mode'] === 'e2ee-private') {
        $payload['protection_envelope'] = $projected['protection_envelope'];
    }
    unset($payload['protected_message_id'], $payload['protected_message_table']);
    return $payload;
}

function message_protection_device_projection(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT device_id,label,fingerprint,status,revision,approved_by_device_id,
                encryption_public_jwk,signing_public_jwk,created_at,updated_at
         FROM message_protection_devices WHERE user_id=? ORDER BY created_at ASC'
    );
    $statement->execute([$userId]);
    $devices = array_map(static fn(array $row): array => [
        'deviceId' => (string)$row['device_id'],
        'label' => (string)$row['label'],
        'fingerprint' => (string)$row['fingerprint'],
        'status' => (string)$row['status'],
        'revision' => (int)$row['revision'],
        'approvedByDeviceId' => $row['approved_by_device_id'],
        'encryptionPublicJwk' => json_decode((string)$row['encryption_public_jwk'], true),
        'signingPublicJwk' => json_decode((string)$row['signing_public_jwk'], true),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at'],
    ], $statement->fetchAll());
    $recovery = $pdo->prepare('SELECT revision,updated_at FROM message_protection_recovery WHERE user_id=?');
    $recovery->execute([$userId]);
    $recoveryRow = $recovery->fetch();
    return [
        'protocol' => MESSAGE_PROTECTION_PROTOCOL,
        'userId' => $userId,
        'devices' => $devices,
        'recoveryConfigured' => is_array($recoveryRow),
        'recoveryRevision' => is_array($recoveryRow) ? (int)$recoveryRow['revision'] : 0,
        'recoveryUpdatedAt' => is_array($recoveryRow) ? $recoveryRow['updated_at'] : null,
        'lostRecoveryWarning' => 'If every trusted device and the Private Chat Recovery Phrase are lost, affected private-chat history cannot be recovered.',
        'serverCanReadRecoveryMaterial' => false,
    ];
}

function message_protection_validate_public_jwk(array $jwk, string $use): string
{
    if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256'
        || !is_string($jwk['x'] ?? null) || !is_string($jwk['y'] ?? null)
        || strlen((string)message_protection_base64url_decode($jwk['x'])) !== 32
        || strlen((string)message_protection_base64url_decode($jwk['y'])) !== 32) {
        throw new MessageProtectionException(
            "The {$use} public key is invalid.",
            'MESSAGE_PROTECTION_DEVICE_KEY_INVALID',
            422
        );
    }
    return message_protection_canonical_json([
        'crv' => 'P-256', 'kty' => 'EC', 'x' => $jwk['x'], 'y' => $jwk['y'],
    ]);
}

function message_protection_register_device(PDO $pdo, int $userId, array $input): array
{
    security_require_recent_authentication();
    $deviceId = strtolower(trim((string)($input['deviceId'] ?? '')));
    $label = trim((string)($input['label'] ?? ''));
    if (preg_match('/^device-[0-9a-f]{32}$/', $deviceId) !== 1 || $label === '' || mb_strlen($label) > 80) {
        throw new MessageProtectionException('Device ID and label are required.', 'MESSAGE_PROTECTION_DEVICE_INVALID', 422);
    }
    $encryptionJwk = message_protection_validate_public_jwk(
        is_array($input['encryptionPublicJwk'] ?? null) ? $input['encryptionPublicJwk'] : [],
        'encryption'
    );
    $signingJwk = message_protection_validate_public_jwk(
        is_array($input['signingPublicJwk'] ?? null) ? $input['signingPublicJwk'] : [],
        'signing'
    );
    $fingerprint = strtoupper(hash('sha256', $encryptionJwk . "\n" . $signingJwk));
    $count = $pdo->prepare("SELECT COUNT(*) FROM message_protection_devices WHERE user_id=? AND status='trusted'");
    $count->execute([$userId]);
    $status = (int)$count->fetchColumn() === 0 ? 'trusted' : 'pending';
    $pdo->prepare(
        'INSERT INTO message_protection_devices
         (device_id,user_id,label,encryption_public_jwk,signing_public_jwk,fingerprint,status)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$deviceId, $userId, $label, $encryptionJwk, $signingJwk, $fingerprint, $status]);
    log_tool($pdo, $userId, 'message_protection_device_register', $userId, null, "device:{$deviceId}; status:{$status}");
    return ['deviceId' => $deviceId, 'status' => $status, 'fingerprint' => $fingerprint];
}

function message_protection_approve_device(PDO $pdo, int $userId, array $input): array
{
    $requestId = trim((string)($input['requestId'] ?? ''));
    $approver = trim((string)($input['approverDeviceId'] ?? ''));
    $target = trim((string)($input['targetDeviceId'] ?? ''));
    $signature = trim((string)($input['signature'] ?? ''));
    $expectedRevision = (int)($input['expectedRevision'] ?? 0);
    if ($requestId === '' || message_protection_base64url_decode($signature) === false) {
        throw new MessageProtectionException('A signed device approval is required.', 'MESSAGE_PROTECTION_DEVICE_APPROVAL_INVALID', 422);
    }
    $replay = $pdo->prepare('SELECT target_device_id FROM message_protection_device_approvals WHERE request_id=?');
    $replay->execute([$requestId]);
    $existing = $replay->fetchColumn();
    if ($existing !== false) return ['deviceId' => (string)$existing, 'status' => 'trusted', 'idempotentReplay' => true];
    $trusted = $pdo->prepare(
        "SELECT signing_public_jwk FROM message_protection_devices
         WHERE device_id=? AND user_id=? AND status='trusted'"
    );
    $trusted->execute([$approver, $userId]);
    $approverSigningJwk = $trusted->fetchColumn();
    if (!is_string($approverSigningJwk)) {
        throw new MessageProtectionException('A trusted device must approve this device.', 'MESSAGE_PROTECTION_TRUSTED_DEVICE_REQUIRED', 403);
    }
    $pending = $pdo->prepare(
        'SELECT device_id,encryption_public_jwk,signing_public_jwk,fingerprint,revision,status
         FROM message_protection_devices WHERE device_id=? AND user_id=?'
    );
    $pending->execute([$target, $userId]);
    $targetRow = $pending->fetch();
    if (!is_array($targetRow) || $targetRow['status'] !== 'pending' || (int)$targetRow['revision'] !== $expectedRevision) {
        throw new MessageProtectionException('The pending device changed. Refresh and try again.', 'MESSAGE_PROTECTION_DEVICE_STALE', 409);
    }
    $material = message_protection_canonical_json([
        'accountId' => $userId,
        'deviceId' => (string)$targetRow['device_id'],
        'encryptionPublicJwk' => json_decode((string)$targetRow['encryption_public_jwk'], true),
        'fingerprint' => (string)$targetRow['fingerprint'],
        'revision' => (int)$targetRow['revision'],
        'signingPublicJwk' => json_decode((string)$targetRow['signing_public_jwk'], true),
    ]);
    $approverJwk = json_decode($approverSigningJwk, true);
    if (!is_array($approverJwk) || !message_protection_verify_signature($approverJwk, $material, $signature)) {
        throw new MessageProtectionException('The device approval signature is invalid.', 'MESSAGE_PROTECTION_DEVICE_APPROVAL_INVALID', 422);
    }
    $pdo->prepare(
        "UPDATE message_protection_devices SET status='trusted',revision=revision+1,
         approved_by_device_id=?,updated_at=CURRENT_TIMESTAMP WHERE device_id=?"
    )->execute([$approver, $target]);
    $pdo->prepare(
        'INSERT INTO message_protection_device_approvals
         (request_id,user_id,approver_device_id,target_device_id,target_revision,signature_b64url)
         VALUES (?,?,?,?,?,?)'
    )->execute([$requestId, $userId, $approver, $target, $expectedRevision, $signature]);
    log_tool($pdo, $userId, 'message_protection_device_approve', $userId, null, "device:{$target}");
    return ['deviceId' => $target, 'status' => 'trusted', 'idempotentReplay' => false];
}

function message_protection_store_recovery(PDO $pdo, int $userId, array $input): array
{
    security_require_recent_authentication();
    $deviceId = trim((string)($input['deviceId'] ?? ''));
    $trusted = $pdo->prepare("SELECT 1 FROM message_protection_devices WHERE device_id=? AND user_id=? AND status='trusted'");
    $trusted->execute([$deviceId, $userId]);
    if (!$trusted->fetchColumn()) {
        throw new MessageProtectionException('A trusted device is required.', 'MESSAGE_PROTECTION_TRUSTED_DEVICE_REQUIRED', 403);
    }
    $iterations = (int)($input['iterations'] ?? 0);
    if ($iterations !== MESSAGE_PROTECTION_RECOVERY_ITERATIONS) {
        throw new MessageProtectionException('The recovery derivation parameters are invalid.', 'MESSAGE_PROTECTION_RECOVERY_PARAMETERS_INVALID', 422);
    }
    $publicJwk = message_protection_validate_public_jwk(
        is_array($input['recoveryPublicJwk'] ?? null) ? $input['recoveryPublicJwk'] : [],
        'recovery'
    );
    foreach (['salt' => 16, 'nonce' => 12, 'tag' => 16] as $field => $bytes) {
        $decoded = message_protection_base64url_decode((string)($input[$field] ?? ''));
        if (!is_string($decoded) || strlen($decoded) !== $bytes) {
            throw new MessageProtectionException('The recovery envelope is invalid.', 'MESSAGE_PROTECTION_RECOVERY_ENVELOPE_INVALID', 422);
        }
    }
    if (message_protection_base64url_decode((string)($input['ciphertext'] ?? '')) === false) {
        throw new MessageProtectionException('The recovery envelope is invalid.', 'MESSAGE_PROTECTION_RECOVERY_ENVELOPE_INVALID', 422);
    }
    $existing = $pdo->prepare('SELECT revision FROM message_protection_recovery WHERE user_id=?');
    $existing->execute([$userId]);
    $revision = (int)($existing->fetchColumn() ?: 0);
    if ((int)($input['expectedRevision'] ?? 0) !== $revision) {
        throw new MessageProtectionException('Recovery settings changed elsewhere.', 'MESSAGE_PROTECTION_RECOVERY_STALE', 409);
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO message_protection_recovery
           (user_id,protocol_version,recovery_public_jwk,salt_b64url,iterations,nonce_b64url,ciphertext_b64url,tag_b64url,revision,updated_by_device_id)
           VALUES (?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE recovery_public_jwk=VALUES(recovery_public_jwk),salt_b64url=VALUES(salt_b64url),
           iterations=VALUES(iterations),nonce_b64url=VALUES(nonce_b64url),ciphertext_b64url=VALUES(ciphertext_b64url),
           tag_b64url=VALUES(tag_b64url),revision=VALUES(revision),updated_by_device_id=VALUES(updated_by_device_id),updated_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO message_protection_recovery
           (user_id,protocol_version,recovery_public_jwk,salt_b64url,iterations,nonce_b64url,ciphertext_b64url,tag_b64url,revision,updated_by_device_id)
           VALUES (?,?,?,?,?,?,?,?,?,?)
           ON CONFLICT(user_id) DO UPDATE SET recovery_public_jwk=excluded.recovery_public_jwk,
           salt_b64url=excluded.salt_b64url,iterations=excluded.iterations,nonce_b64url=excluded.nonce_b64url,
           ciphertext_b64url=excluded.ciphertext_b64url,tag_b64url=excluded.tag_b64url,
           revision=excluded.revision,updated_by_device_id=excluded.updated_by_device_id,updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([
        $userId, MESSAGE_PROTECTION_VERSION, $publicJwk, (string)$input['salt'],
        $iterations, (string)$input['nonce'], (string)$input['ciphertext'],
        (string)$input['tag'], $revision + 1, $deviceId,
    ]);
    log_tool($pdo, $userId, 'message_protection_recovery_replace', $userId, null, 'recovery-envelope-revision:' . ($revision + 1));
    return ['configured' => true, 'revision' => $revision + 1, 'serverCanReadRecoveryMaterial' => false];
}

function message_protection_store_key_envelope(PDO $pdo, int $userId, array $input): array
{
    $kind = trim((string)($input['conversationKind'] ?? ''));
    $key = trim((string)($input['conversationKey'] ?? ''));
    $epoch = (int)($input['keyEpoch'] ?? 0);
    $senderDeviceId = trim((string)($input['senderDeviceId'] ?? ''));
    $recipientDeviceId = trim((string)($input['recipientDeviceId'] ?? ''));
    $envelope = is_array($input['envelope'] ?? null) ? $input['envelope'] : [];
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    if (!in_array($kind, MESSAGE_PROTECTION_PRIVATE_CHANNELS, true) || $epoch < 1) {
        throw new MessageProtectionException('The private-chat key envelope is invalid.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_INVALID', 422);
    }
    $sender = $pdo->prepare(
        "SELECT signing_public_jwk FROM message_protection_devices
         WHERE device_id=? AND user_id=? AND status='trusted'"
    );
    $sender->execute([$senderDeviceId, $userId]);
    $senderSigningJwk = $sender->fetchColumn();
    $recipient = $pdo->prepare("SELECT user_id FROM message_protection_devices WHERE device_id=? AND status='trusted'");
    $recipient->execute([$recipientDeviceId]);
    $recipientUserId = (int)($recipient->fetchColumn() ?: 0);
    if (!is_string($senderSigningJwk) || $recipientUserId < 1) {
        throw new MessageProtectionException('Trusted sender and recipient devices are required.', 'MESSAGE_PROTECTION_TRUSTED_DEVICE_REQUIRED', 403);
    }
    message_protection_authorize_conversation($pdo, $recipientUserId, $kind, $key);
    foreach (['ephemeralPublicJwk', 'salt', 'nonce', 'ciphertext', 'tag', 'signature'] as $field) {
        if (!array_key_exists($field, $envelope)) {
            throw new MessageProtectionException('The private-chat key envelope is incomplete.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_INVALID', 422);
        }
    }
    message_protection_validate_public_jwk(
        is_array($envelope['ephemeralPublicJwk']) ? $envelope['ephemeralPublicJwk'] : [],
        'ephemeral'
    );
    foreach (['salt' => 16, 'nonce' => 12, 'tag' => 16] as $field => $bytes) {
        $decoded = message_protection_base64url_decode((string)$envelope[$field]);
        if (!is_string($decoded) || strlen($decoded) !== $bytes) {
            throw new MessageProtectionException('The private-chat key envelope is invalid.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_INVALID', 422);
        }
    }
    if (message_protection_base64url_decode((string)$envelope['ciphertext']) === false
        || message_protection_base64url_decode((string)$envelope['signature']) === false) {
        throw new MessageProtectionException('The private-chat key envelope is invalid.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_INVALID', 422);
    }
    $signatureMaterial = message_protection_canonical_json([
        'conversationKind' => $kind,
        'conversationKey' => $key,
        'keyEpoch' => $epoch,
        'recipientDeviceId' => $recipientDeviceId,
        'senderDeviceId' => $senderDeviceId,
        'ephemeralPublicJwk' => $envelope['ephemeralPublicJwk'],
        'salt' => (string)$envelope['salt'],
        'nonce' => (string)$envelope['nonce'],
        'ciphertext' => (string)$envelope['ciphertext'],
        'tag' => (string)$envelope['tag'],
    ]);
    $signingJwk = json_decode($senderSigningJwk, true);
    if (!is_array($signingJwk)
        || !message_protection_verify_signature($signingJwk, $signatureMaterial, (string)$envelope['signature'])) {
        throw new MessageProtectionException('The private-chat key envelope signature is invalid.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_INVALID', 422);
    }
    $json = message_protection_canonical_json($envelope);
    $sha256 = strtoupper(hash('sha256', $json));
    $existing = $pdo->prepare(
        'SELECT envelope_sha256 FROM message_protection_key_envelopes
         WHERE conversation_kind=? AND conversation_key=? AND key_epoch=? AND recipient_device_id=?'
    );
    $existing->execute([$kind, $key, $epoch, $recipientDeviceId]);
    $existingHash = $existing->fetchColumn();
    if ($existingHash !== false) {
        if (!hash_equals((string)$existingHash, $sha256)) {
            throw new MessageProtectionException('A different key envelope already exists.', 'MESSAGE_PROTECTION_KEY_ENVELOPE_CONFLICT', 409);
        }
        return ['stored' => true, 'idempotentReplay' => true, 'sha256' => $sha256];
    }
    $pdo->prepare(
        'INSERT INTO message_protection_key_envelopes
         (conversation_kind,conversation_key,key_epoch,recipient_device_id,sender_device_id,envelope_json,envelope_sha256)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$kind, $key, $epoch, $recipientDeviceId, $senderDeviceId, $json, $sha256]);
    return ['stored' => true, 'idempotentReplay' => false, 'sha256' => $sha256];
}

function message_protection_key_envelopes(
    PDO $pdo,
    int $userId,
    string $deviceId,
    string $kind,
    string $key
): array {
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    $device = $pdo->prepare("SELECT 1 FROM message_protection_devices WHERE device_id=? AND user_id=? AND status='trusted'");
    $device->execute([$deviceId, $userId]);
    if (!$device->fetchColumn()) {
        throw new MessageProtectionException('A trusted device is required.', 'MESSAGE_PROTECTION_TRUSTED_DEVICE_REQUIRED', 403);
    }
    $statement = $pdo->prepare(
        'SELECT key_epoch,sender_device_id,envelope_json,envelope_sha256
         FROM message_protection_key_envelopes
         WHERE conversation_kind=? AND conversation_key=? AND recipient_device_id=?
         ORDER BY key_epoch ASC'
    );
    $statement->execute([$kind, $key, $deviceId]);
    return array_map(static fn(array $row): array => [
        'keyEpoch' => (int)$row['key_epoch'],
        'senderDeviceId' => (string)$row['sender_device_id'],
        'envelope' => json_decode((string)$row['envelope_json'], true),
        'sha256' => (string)$row['envelope_sha256'],
    ], $statement->fetchAll());
}

function message_protection_conversation_devices(
    PDO $pdo,
    int $userId,
    string $kind,
    string $key
): array {
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    $participantUserIds = [];
    if ($kind === 'dm') {
        $parts = explode(':', $key);
        if (($parts[0] ?? '') === 'dm') {
            $participantUserIds = array_values(array_unique([
                (int)($parts[1] ?? 0),
                (int)($parts[2] ?? 0),
            ]));
        }
    } elseif ($kind === 'link') {
        $statement = $pdo->prepare(
            "SELECT DISTINCT p.user_id
             FROM avatar_relationships ar
             JOIN avatar_relationship_members arm ON arm.relationship_id=ar.id
             JOIN participants p ON p.id=arm.participant_id
             WHERE ar.conversation_public_id=? AND ar.status='active'
               AND arm.membership_status='active' AND p.user_id IS NOT NULL"
        );
        $statement->execute([$key]);
        $participantUserIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
    $participantUserIds = array_values(array_filter(
        array_unique($participantUserIds),
        static fn(int $id): bool => $id > 0
    ));
    if ($participantUserIds === []) return [];
    $placeholders = implode(',', array_fill(0, count($participantUserIds), '?'));
    $statement = $pdo->prepare(
        "SELECT device_id,user_id,encryption_public_jwk,signing_public_jwk,fingerprint
         FROM message_protection_devices
         WHERE status='trusted' AND user_id IN ({$placeholders})
         ORDER BY user_id ASC,created_at ASC"
    );
    $statement->execute($participantUserIds);
    return array_map(static fn(array $row): array => [
        'deviceId' => (string)$row['device_id'],
        'userId' => (int)$row['user_id'],
        'encryptionPublicJwk' => json_decode((string)$row['encryption_public_jwk'], true),
        'signingPublicJwk' => json_decode((string)$row['signing_public_jwk'], true),
        'fingerprint' => (string)$row['fingerprint'],
    ], $statement->fetchAll());
}

function message_protection_authorize_conversation(PDO $pdo, int $userId, string $kind, string $key): void
{
    if ($kind === 'dm') {
        $parts = explode(':', $key);
        if (($parts[0] ?? '') === 'dm' && in_array($userId, [(int)($parts[1] ?? 0), (int)($parts[2] ?? 0)], true)) return;
    }
    if ($kind === 'link') {
        $statement = $pdo->prepare(
            "SELECT 1 FROM avatar_relationships ar
             JOIN avatar_relationship_members arm ON arm.relationship_id=ar.id
             JOIN participants p ON p.id=arm.participant_id
             WHERE ar.conversation_public_id=? AND ar.status='active'
               AND arm.membership_status='active' AND p.user_id=? LIMIT 1"
        );
        $statement->execute([$key, $userId]);
        if ($statement->fetchColumn()) return;
    }
    if (in_array($kind, ['room', 'community', 'game'], true) && moderation_identity_is_owner($pdo, $userId)) return;
    throw new MessageProtectionException('Conversation protection access is denied.', 'MESSAGE_PROTECTION_CONVERSATION_DENIED', 403);
}

function message_protection_request_transition(PDO $pdo, int $userId, array $input): array
{
    security_require_recent_authentication();
    $kind = trim((string)($input['conversationKind'] ?? ''));
    $key = trim((string)($input['conversationKey'] ?? ''));
    $toMode = trim((string)($input['toMode'] ?? ''));
    $requestId = trim((string)($input['requestId'] ?? ''));
    $explanation = trim((string)($input['explanation'] ?? ''));
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    if (!in_array($toMode, MESSAGE_PROTECTION_MODES, true)
        || ($toMode === 'e2ee-private' && !in_array($kind, MESSAGE_PROTECTION_PRIVATE_CHANNELS, true))) {
        throw new MessageProtectionException('The requested message-protection mode is unavailable.', 'MESSAGE_PROTECTION_MODE_UNAVAILABLE', 422);
    }
    if ($requestId === '' || $explanation === '' || empty($input['confirmed'])) {
        throw new MessageProtectionException(
            'Explanation and explicit confirmation are required.',
            'MESSAGE_PROTECTION_CONFIRMATION_REQUIRED',
            422
        );
    }
    $existing = $pdo->prepare('SELECT * FROM message_protection_transitions WHERE request_id=?');
    $existing->execute([$requestId]);
    $replay = $existing->fetch();
    if (is_array($replay)) return ['requestId' => $requestId, 'status' => $replay['status'], 'idempotentReplay' => true];
    $policy = message_protection_policy($pdo, $kind, $key);
    if ((int)($input['expectedRevision'] ?? 0) !== $policy['revision']) {
        throw new MessageProtectionException('The message-protection policy changed elsewhere.', 'MESSAGE_PROTECTION_POLICY_STALE', 409);
    }
    $table = $kind === 'room' ? 'messages' : 'community_messages';
    $where = $kind === 'room' ? 'session_id=?' : ($kind === 'community' ? "scope='community'" : 'scope=? AND link_key=?');
    $params = $kind === 'room' ? [(int)$key] : ($kind === 'community' ? [] : [$kind, $key]);
    $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $count->execute($params);
    $oldTotal = (int)$count->fetchColumn();
    $status = $toMode === 'e2ee-private' || $toMode === $policy['mode'] ? 'complete' : 'preparing';
    $remaining = $status === 'complete' ? 0 : $oldTotal;
    $pdo->prepare(
        'INSERT INTO message_protection_transitions
         (request_id,conversation_kind,conversation_key,actor_user_id,from_mode,to_mode,
          explanation_hash,confirmed,status,old_total,converted_total,remaining_total,completed_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $requestId, $kind, $key, $userId, $policy['mode'], $toMode,
        strtoupper(hash('sha256', $explanation)), 1, $status, $oldTotal, 0, $remaining,
        $status === 'complete' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    if ($status === 'complete') message_protection_set_policy($pdo, $kind, $key, $toMode, $userId, $policy['revision']);
    log_tool(
        $pdo,
        $userId,
        'message_protection_transition_request',
        $userId,
        null,
        "request:{$requestId}; {$policy['mode']}->{$toMode}; old-total:{$oldTotal}"
    );
    return [
        'requestId' => $requestId,
        'status' => $status,
        'fromMode' => $policy['mode'],
        'toMode' => $toMode,
        'oldCoverageCount' => $oldTotal,
        'convertedCount' => 0,
        'remainingCount' => $remaining,
        'oldContentConverted' => false,
        'idempotentReplay' => false,
    ];
}

function message_protection_set_policy(
    PDO $pdo,
    string $kind,
    string $key,
    string $mode,
    int $actorUserId,
    int $expectedRevision
): void {
    $current = message_protection_policy($pdo, $kind, $key);
    if ($current['revision'] !== $expectedRevision) {
        throw new MessageProtectionException(
            'The message-protection policy changed elsewhere.',
            'MESSAGE_PROTECTION_POLICY_STALE',
            409
        );
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO message_protection_policies
           (conversation_kind,conversation_key,mode,protocol_version,key_epoch,revision,updated_by_user_id)
           VALUES (?,?,?,?,1,1,?)
           ON DUPLICATE KEY UPDATE mode=VALUES(mode),protocol_version=VALUES(protocol_version),
           revision=revision+1,updated_by_user_id=VALUES(updated_by_user_id),updated_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO message_protection_policies
           (conversation_kind,conversation_key,mode,protocol_version,key_epoch,revision,updated_by_user_id)
           VALUES (?,?,?,?,1,1,?)
           ON CONFLICT(conversation_kind,conversation_key) DO UPDATE SET mode=excluded.mode,
           protocol_version=excluded.protocol_version,revision=message_protection_policies.revision+1,
           updated_by_user_id=excluded.updated_by_user_id,updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$kind, $key, $mode, MESSAGE_PROTECTION_VERSION, $actorUserId]);
}

function message_protection_transition_route(array $transition): array
{
    $kind = (string)$transition['conversation_kind'];
    $key = (string)$transition['conversation_key'];
    if ($kind === 'room') {
        return ['table' => 'messages', 'where' => 'session_id=?', 'params' => [(int)$key]];
    }
    if ($kind === 'community') {
        return ['table' => 'community_messages', 'where' => "scope='community'", 'params' => []];
    }
    if (in_array($kind, MESSAGE_PROTECTION_PRIVATE_CHANNELS, true)) {
        return [
            'table' => 'community_messages',
            'where' => 'scope=? AND link_key=?',
            'params' => [$kind, $key],
        ];
    }
    throw new MessageProtectionException(
        'The transition conversation is unsupported.',
        'MESSAGE_PROTECTION_CHANNEL_UNSUPPORTED',
        422
    );
}

function message_protection_convert_transition_row(
    array $row,
    string $fromMode,
    string $toMode,
    string $conversationKey
): array {
    if ($fromMode === 'standard' && $toMode === 'server-encrypted') {
        $package = [
            'content' => (string)($row['content'] ?? ''),
            'originalContent' => $row['original_content'] ?? null,
            'urlPreview' => message_url_preview($row['url_preview_json'] ?? null),
            'replyTo' => message_url_preview($row['reply_to_json'] ?? null),
        ];
        $context = [
            'protocol' => MESSAGE_PROTECTION_PROTOCOL,
            'mode' => 'server-encrypted',
            'conversation' => $conversationKey,
            'clientMessageId' => (string)($row['client_message_id'] ?? ''),
            'senderUserId' => (int)($row['user_id'] ?? 0),
            'senderDeviceId' => null,
            'keyEpoch' => (int)($row['protection_key_epoch'] ?? 1),
            'messageType' => (string)($row['message_type'] ?? 'text'),
        ];
        return [
            'content' => '',
            'original_content' => null,
            'url_preview_json' => null,
            'reply_to_json' => null,
            'protection_envelope_json' => message_protection_canonical_json(
                message_protection_encrypt_server_package($package, $context)
            ),
        ];
    }
    if ($fromMode === 'server-encrypted' && $toMode === 'standard') {
        $envelope = json_decode((string)($row['protection_envelope_json'] ?? ''), true);
        if (!is_array($envelope)) {
            throw new MessageProtectionException(
                'The protected transition row is invalid.',
                'MESSAGE_PROTECTION_ENVELOPE_INVALID',
                500
            );
        }
        $package = message_protection_decrypt_server_package($envelope);
        return [
            'content' => (string)($package['content'] ?? ''),
            'original_content' => $package['originalContent'] ?? null,
            'url_preview_json' => isset($package['urlPreview'])
                ? message_protection_canonical_json((array)$package['urlPreview'])
                : null,
            'reply_to_json' => isset($package['replyTo'])
                ? message_protection_canonical_json((array)$package['replyTo'])
                : null,
            'protection_envelope_json' => null,
        ];
    }
    throw new MessageProtectionException(
        'This history transition is unavailable.',
        'MESSAGE_PROTECTION_TRANSITION_UNAVAILABLE',
        422
    );
}

function message_protection_run_transition_batch(
    PDO $pdo,
    int $userId,
    string $requestId,
    int $batchSize = 100
): array {
    $batchSize = max(1, min(500, $batchSize));
    $statement = $pdo->prepare('SELECT * FROM message_protection_transitions WHERE request_id=? LIMIT 1');
    $statement->execute([$requestId]);
    $transition = $statement->fetch();
    if (!is_array($transition)) {
        throw new MessageProtectionException('The transition was not found.', 'MESSAGE_PROTECTION_TRANSITION_NOT_FOUND', 404);
    }
    $kind = (string)$transition['conversation_kind'];
    $key = (string)$transition['conversation_key'];
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    if (!in_array((string)$transition['status'], ['preparing', 'interrupted', 'migrating', 'validating'], true)) {
        return message_protection_transition_projection($pdo, $userId, $kind, $key);
    }
    $token = bin2hex(random_bytes(24));
    $tokenHash = strtoupper(hash('sha256', $token));
    $now = gmdate('Y-m-d H:i:s');
    $expires = gmdate('Y-m-d H:i:s', time() + 30);
    $lease = $pdo->prepare(
        "UPDATE message_protection_transitions
         SET lease_token_hash=?,lease_expires_at=?,status='migrating',error_code=NULL,updated_at=CURRENT_TIMESTAMP
         WHERE request_id=? AND (lease_token_hash IS NULL OR lease_expires_at<? OR lease_token_hash=?)"
    );
    $lease->execute([$tokenHash, $expires, $requestId, $now, $tokenHash]);
    if ($lease->rowCount() !== 1) {
        throw new MessageProtectionException(
            'The transition is already owned by another worker.',
            'MESSAGE_PROTECTION_TRANSITION_LEASED',
            409
        );
    }
    $route = message_protection_transition_route($transition);
    $fromMode = (string)$transition['from_mode'];
    $toMode = (string)$transition['to_mode'];
    try {
        $pdo->beginTransaction();
        $select = $pdo->prepare(
            "SELECT * FROM {$route['table']}
             WHERE {$route['where']} AND protection_mode=? AND id>?
             ORDER BY id ASC LIMIT {$batchSize}"
        );
        $select->execute([...$route['params'], $fromMode, (int)$transition['cursor_id']]);
        $rows = $select->fetchAll();
        $lastId = (int)$transition['cursor_id'];
        $converted = 0;
        $update = $pdo->prepare(
            "UPDATE {$route['table']}
             SET content=?,original_content=?,url_preview_json=?,reply_to_json=?,protection_mode=?,
                 protection_version=?,protection_envelope_json=?
             WHERE id=? AND protection_mode=?"
        );
        foreach ($rows as $row) {
            $values = message_protection_convert_transition_row($row, $fromMode, $toMode, $key);
            $update->execute([
                $values['content'],
                $values['original_content'],
                $values['url_preview_json'],
                $values['reply_to_json'],
                $toMode,
                MESSAGE_PROTECTION_VERSION,
                $values['protection_envelope_json'],
                (int)$row['id'],
                $fromMode,
            ]);
            if ($update->rowCount() !== 1) {
                throw new MessageProtectionException(
                    'A transition row changed concurrently.',
                    'MESSAGE_PROTECTION_TRANSITION_ROW_STALE',
                    409
                );
            }
            $lastId = (int)$row['id'];
            $converted++;
        }
        $remainingStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM {$route['table']} WHERE {$route['where']} AND protection_mode=?"
        );
        $remainingStatement->execute([...$route['params'], $fromMode]);
        $remaining = (int)$remainingStatement->fetchColumn();
        $nextStatus = $remaining === 0 ? 'validating' : 'migrating';
        $pdo->prepare(
            'UPDATE message_protection_transitions
             SET cursor_id=?,converted_total=converted_total+?,remaining_total=?,status=?,
                 lease_token_hash=NULL,lease_expires_at=NULL,updated_at=CURRENT_TIMESTAMP
             WHERE request_id=? AND lease_token_hash=?'
        )->execute([$lastId, $converted, $remaining, $nextStatus, $requestId, $tokenHash]);
        if ($remaining === 0) {
            $policy = message_protection_policy($pdo, $kind, $key);
            if ($policy['mode'] !== $fromMode) {
                throw new MessageProtectionException(
                    'The message-protection policy changed during transition.',
                    'MESSAGE_PROTECTION_POLICY_STALE',
                    409
                );
            }
            message_protection_set_policy($pdo, $kind, $key, $toMode, $userId, $policy['revision']);
            $pdo->prepare(
                "UPDATE message_protection_transitions
                 SET status='complete',remaining_total=0,completed_at=CURRENT_TIMESTAMP,
                     updated_at=CURRENT_TIMESTAMP WHERE request_id=?"
            )->execute([$requestId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $error instanceof MessageProtectionException
            ? $error->errorCode
            : 'MESSAGE_PROTECTION_TRANSITION_FAILED';
        $pdo->prepare(
            "UPDATE message_protection_transitions
             SET status='interrupted',error_code=?,lease_token_hash=NULL,lease_expires_at=NULL,
                 updated_at=CURRENT_TIMESTAMP WHERE request_id=? AND lease_token_hash=?"
        )->execute([$code, $requestId, $tokenHash]);
        throw $error;
    }
    return message_protection_transition_projection($pdo, $userId, $kind, $key);
}

function message_protection_transition_projection(PDO $pdo, int $userId, string $kind, string $key): array
{
    message_protection_authorize_conversation($pdo, $userId, $kind, $key);
    $policy = message_protection_policy($pdo, $kind, $key);
    $transition = $pdo->prepare(
        'SELECT request_id,from_mode,to_mode,status,old_total,converted_total,remaining_total,error_code,updated_at,completed_at
         FROM message_protection_transitions WHERE conversation_kind=? AND conversation_key=?
         ORDER BY created_at DESC LIMIT 1'
    );
    $transition->execute([$kind, $key]);
    $latest = $transition->fetch();
    return [
        'policy' => $policy,
        'transition' => is_array($latest) ? [
            'requestId' => $latest['request_id'],
            'fromMode' => $latest['from_mode'],
            'toMode' => $latest['to_mode'],
            'status' => $latest['status'],
            'oldCoverageCount' => (int)$latest['old_total'],
            'convertedCount' => (int)$latest['converted_total'],
            'remainingCount' => (int)$latest['remaining_total'],
            'errorCode' => $latest['error_code'],
            'updatedAt' => $latest['updated_at'],
            'completedAt' => $latest['completed_at'],
        ] : null,
        'modes' => MESSAGE_PROTECTION_MODES,
        'e2eePrivateOnly' => true,
        'knownLimitations' => [
            'E2EE supports text and reply metadata in DM and active relationship chats.',
            'Old content keeps its truthful per-message mode until a verified transition completes.',
            'The server cannot recover E2EE history without a trusted device or the exact Private Chat Recovery Phrase.',
        ],
    ];
}
