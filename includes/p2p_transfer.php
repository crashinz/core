<?php
declare(strict_types=1);

/**
 * Post-Build 000055 authenticated direct-file and Send Gesture policy owner.
 *
 * This owner persists bounded offer/status metadata only. File bytes, chunks,
 * hashes, previews, thumbnails, and completed files never enter it.
 */

const P2P_TRANSFER_FILES_ENABLED_SETTING = 'p2p_direct_files_enabled';
const P2P_TRANSFER_SEND_GESTURE_ENABLED_SETTING = 'p2p_send_gesture_enabled';
const P2P_TRANSFER_MAX_FILE_MB_SETTING = 'p2p_transfer_max_file_mb';
const P2P_TRANSFER_MAX_CONCURRENT_SETTING = 'p2p_transfer_max_concurrent';
const P2P_TRANSFER_HOURLY_OFFERS_SETTING = 'p2p_transfer_hourly_offers';
const P2P_TRANSFER_SOURCE_CHECKED_AT_SETTING = 'p2p_transfer_source_checked_at';
const P2P_TRANSFER_TOKEN_SECONDS = 120;
const P2P_TRANSFER_OFFER_SECONDS = 300;
const P2P_TRANSFER_ACCEPTED_SESSION_SECONDS = 86400;
const P2P_TRANSFER_ADAPTATION_VERSION = 'corechat-p2p-transfer/1';
const P2P_TRANSFER_FILEPIZZA_REPOSITORY = 'https://github.com/kern/filepizza';
const P2P_TRANSFER_FILEPIZZA_COMMIT = '3258673e790145ba86637114a35388165a651ff3';
const P2P_TRANSFER_FILEPIZZA_SOURCE_DATE = '2026-01-31T21:48:51Z';
const P2P_TRANSFER_FILEPIZZA_ARCHIVE_SHA256 = '4C54ED394F7EB96954637284EF73BA78C8D1EAB5BDDDC8B5A6458C03E846E83A';
const P2P_TRANSFER_CHEEZYPIZZA_REPOSITORY = 'https://github.com/hariharjeevan/cheezypizza';
const P2P_TRANSFER_CHEEZYPIZZA_COMMIT = 'e88a709c9f9c256dc5739692d0209bf6f2fdca7c';
const P2P_TRANSFER_CHEEZYPIZZA_SOURCE_DATE = '2026-06-19T11:46:26Z';
const P2P_TRANSFER_CHEEZYPIZZA_ARCHIVE_SHA256 = 'D12325337C2758D7E16584D3BA19E3FA2F17F65AEE99490E95BFD05AE17681AC';

final class P2PTransferException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'P2P_TRANSFER_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $facts = []
    ) {
        parent::__construct($message);
    }
}

function p2p_transfer_setting_defaults(): array
{
    return [
        P2P_TRANSFER_FILES_ENABLED_SETTING => '1',
        P2P_TRANSFER_SEND_GESTURE_ENABLED_SETTING => '1',
        P2P_TRANSFER_MAX_FILE_MB_SETTING => '100',
        P2P_TRANSFER_MAX_CONCURRENT_SETTING => '2',
        P2P_TRANSFER_HOURLY_OFFERS_SETTING => '20',
        P2P_TRANSFER_SOURCE_CHECKED_AT_SETTING => '',
    ];
}

function p2p_transfer_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS p2p_transfer_offers (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(96) NOT NULL UNIQUE,
                request_sha256 CHAR(64) NOT NULL,
                session_id INT NOT NULL,
                sender_participant_id INT NOT NULL,
                sender_user_id INT NOT NULL,
                recipient_participant_id INT NOT NULL,
                recipient_user_id INT NOT NULL,
                transfer_kind VARCHAR(32) NOT NULL,
                safe_name VARCHAR(255) NOT NULL,
                byte_size BIGINT NOT NULL,
                declared_mime VARCHAR(191) NOT NULL,
                detected_type VARCHAR(64) NOT NULL,
                preview_available TINYINT(1) NOT NULL DEFAULT 0,
                preview_requested_at DATETIME DEFAULT NULL,
                risk_class VARCHAR(32) NOT NULL DEFAULT 'Cannot be inspected',
                risk_detail VARCHAR(500) NOT NULL DEFAULT 'Not scanned for malware.',
                archive_encrypted TINYINT(1) NOT NULL DEFAULT 0,
                archive_active_content TINYINT(1) NOT NULL DEFAULT 0,
                archive_suspicious_paths TINYINT(1) NOT NULL DEFAULT 0,
                archive_extreme_ratio TINYINT(1) NOT NULL DEFAULT 0,
                file_count INT NOT NULL DEFAULT 1,
                manifest_json LONGTEXT NOT NULL,
                manifest_sha256 CHAR(64) NOT NULL,
                cancelled_files_json LONGTEXT NOT NULL,
                requested_delivery VARCHAR(32) NOT NULL DEFAULT 'direct-first',
                final_connection VARCHAR(32) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'offered',
                status_reason VARCHAR(191) DEFAULT NULL,
                sender_epoch VARCHAR(128) NOT NULL,
                recipient_epoch VARCHAR(128) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME DEFAULT NULL,
                transfer_nonce CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_p2p_transfer_recipient (recipient_participant_id,status,expires_at),
                INDEX idx_p2p_transfer_sender (sender_user_id,status,created_at),
                INDEX idx_p2p_transfer_lifecycle (status,expires_at),
                CONSTRAINT fk_p2p_transfer_sender_user FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_p2p_transfer_recipient_user FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS p2p_transfer_signals (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                offer_id BIGINT NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NOT NULL,
                signal_type VARCHAR(32) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX idx_p2p_transfer_signal_delivery (to_user_id,delivered_at,expires_at),
                CONSTRAINT fk_p2p_transfer_signal_offer FOREIGN KEY (offer_id) REFERENCES p2p_transfer_offers(id) ON DELETE CASCADE,
                CONSTRAINT fk_p2p_transfer_signal_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_p2p_transfer_signal_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS p2p_transfer_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                offer_id BIGINT NOT NULL,
                actor_user_id INT NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                privacy_safe_detail VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_p2p_transfer_events (offer_id,created_at),
                CONSTRAINT fk_p2p_transfer_event_offer FOREIGN KEY (offer_id) REFERENCES p2p_transfer_offers(id) ON DELETE CASCADE,
                CONSTRAINT fk_p2p_transfer_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS p2p_transfer_offers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            request_sha256 TEXT NOT NULL,
            session_id INTEGER NOT NULL,
            sender_participant_id INTEGER NOT NULL,
            sender_user_id INTEGER NOT NULL,
            recipient_participant_id INTEGER NOT NULL,
            recipient_user_id INTEGER NOT NULL,
            transfer_kind TEXT NOT NULL,
            safe_name TEXT NOT NULL,
            byte_size INTEGER NOT NULL CHECK (byte_size >= 0),
            declared_mime TEXT NOT NULL,
            detected_type TEXT NOT NULL,
            preview_available INTEGER NOT NULL DEFAULT 0 CHECK (preview_available IN (0,1)),
            preview_requested_at TEXT DEFAULT NULL,
            risk_class TEXT NOT NULL DEFAULT 'Cannot be inspected',
            risk_detail TEXT NOT NULL DEFAULT 'Not scanned for malware.',
            archive_encrypted INTEGER NOT NULL DEFAULT 0 CHECK (archive_encrypted IN (0,1)),
            archive_active_content INTEGER NOT NULL DEFAULT 0 CHECK (archive_active_content IN (0,1)),
            archive_suspicious_paths INTEGER NOT NULL DEFAULT 0 CHECK (archive_suspicious_paths IN (0,1)),
            archive_extreme_ratio INTEGER NOT NULL DEFAULT 0 CHECK (archive_extreme_ratio IN (0,1)),
            file_count INTEGER NOT NULL DEFAULT 1 CHECK (file_count BETWEEN 1 AND 20),
            manifest_json TEXT NOT NULL,
            manifest_sha256 TEXT NOT NULL,
            cancelled_files_json TEXT NOT NULL DEFAULT '[]',
            requested_delivery TEXT NOT NULL DEFAULT 'direct-first',
            final_connection TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'offered',
            status_reason TEXT DEFAULT NULL,
            sender_epoch TEXT NOT NULL,
            recipient_epoch TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at TEXT DEFAULT NULL,
            transfer_nonce TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(recipient_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_p2p_transfer_recipient ON p2p_transfer_offers(recipient_participant_id,status,expires_at)',
        'CREATE INDEX IF NOT EXISTS idx_p2p_transfer_sender ON p2p_transfer_offers(sender_user_id,status,created_at)',
        'CREATE INDEX IF NOT EXISTS idx_p2p_transfer_lifecycle ON p2p_transfer_offers(status,expires_at)',
        "CREATE TABLE IF NOT EXISTS p2p_transfer_signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            offer_id INTEGER NOT NULL,
            from_user_id INTEGER NOT NULL,
            to_user_id INTEGER NOT NULL,
            signal_type TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            delivered_at TEXT DEFAULT NULL,
            FOREIGN KEY(offer_id) REFERENCES p2p_transfer_offers(id) ON DELETE CASCADE,
            FOREIGN KEY(from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(to_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_p2p_transfer_signal_delivery ON p2p_transfer_signals(to_user_id,delivered_at,expires_at)',
        "CREATE TABLE IF NOT EXISTS p2p_transfer_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            offer_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            privacy_safe_detail TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(offer_id) REFERENCES p2p_transfer_offers(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_p2p_transfer_events ON p2p_transfer_events(offer_id,created_at)',
    ];
}

function p2p_transfer_install_schema(PDO $pdo): void
{
    foreach (p2p_transfer_schema_statements($pdo) as $sql) $pdo->exec($sql);
    $columns = database_migration_columns($pdo, 'p2p_transfer_offers');
    if (!in_array('request_id', $columns, true)) {
        $definition = db_uses_mysql_syntax($pdo)
            ? "VARCHAR(96) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
        $pdo->exec("ALTER TABLE p2p_transfer_offers ADD COLUMN request_id {$definition}");
    }
    if (!in_array('request_sha256', $columns, true)) {
        $definition = db_uses_mysql_syntax($pdo)
            ? "CHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''";
        $pdo->exec("ALTER TABLE p2p_transfer_offers ADD COLUMN request_sha256 {$definition}");
    }
    if (!in_array('preview_requested_at', $columns, true)) {
        $pdo->exec('ALTER TABLE p2p_transfer_offers ADD COLUMN preview_requested_at ' . (db_uses_mysql_syntax($pdo) ? 'DATETIME DEFAULT NULL' : 'TEXT DEFAULT NULL'));
    }
    foreach ($pdo->query("SELECT id,public_id FROM p2p_transfer_offers WHERE request_id='' OR request_sha256=''")->fetchAll() as $offer) {
        $requestId = 'legacy-' . (string)$offer['public_id'];
        $pdo->prepare('UPDATE p2p_transfer_offers SET request_id=?,request_sha256=? WHERE id=?')
            ->execute([$requestId,strtoupper(hash('sha256', $requestId)),(int)$offer['id']]);
    }
    if (db_uses_mysql_syntax($pdo)) {
        try {
            $pdo->exec('CREATE UNIQUE INDEX uq_p2p_transfer_request ON p2p_transfer_offers(request_id)');
        } catch (PDOException $error) {
            if (!in_array((string)$error->getCode(), ['42000','42S11'], true)) throw $error;
        }
    } else {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_p2p_transfer_request ON p2p_transfer_offers(request_id)');
    }
}

function p2p_transfer_schema_valid(PDO $pdo): bool
{
    return database_migration_table_exists($pdo, 'p2p_transfer_offers')
        && database_migration_table_exists($pdo, 'p2p_transfer_events')
        && database_migration_table_exists($pdo, 'p2p_transfer_signals')
        && database_migration_has_columns($pdo, 'p2p_transfer_offers', [
            'risk_class','risk_detail','archive_encrypted','archive_active_content',
            'archive_suspicious_paths','archive_extreme_ratio','file_count',
            'manifest_json','manifest_sha256','cancelled_files_json','accepted_at','transfer_nonce',
            'request_id','request_sha256','preview_requested_at',
        ])
        && database_migration_has_columns($pdo, 'p2p_transfer_signals', [
            'offer_id','from_user_id','to_user_id','signal_type','payload_json',
            'expires_at','delivered_at',
        ]);
}

function p2p_transfer_provenance(PDO $pdo, bool $admin = false): array
{
    if (!$admin) return ['visible' => false];
    return [
        'visible' => true,
        'title' => 'File Transfer Source & Version',
        'adaptationVersion' => P2P_TRANSFER_ADAPTATION_VERSION,
        'pinnedSource' => P2P_TRANSFER_CHEEZYPIZZA_COMMIT,
        'sourceDate' => P2P_TRANSFER_CHEEZYPIZZA_SOURCE_DATE,
        'lastUpdateCheckAt' => app_setting($pdo, P2P_TRANSFER_SOURCE_CHECKED_AT_SETTING, ''),
        'reviewAction' => 'Review upstream changes',
        'manualInformationalOnly' => true,
        'automaticDownloadApplyTrust' => false,
        'sources' => [
            ['repository' => P2P_TRANSFER_FILEPIZZA_REPOSITORY, 'commit' => P2P_TRANSFER_FILEPIZZA_COMMIT, 'sourceDate' => P2P_TRANSFER_FILEPIZZA_SOURCE_DATE, 'archiveSha256' => P2P_TRANSFER_FILEPIZZA_ARCHIVE_SHA256, 'license' => 'BSD 3-Clause'],
            ['repository' => P2P_TRANSFER_CHEEZYPIZZA_REPOSITORY, 'commit' => P2P_TRANSFER_CHEEZYPIZZA_COMMIT, 'sourceDate' => P2P_TRANSFER_CHEEZYPIZZA_SOURCE_DATE, 'archiveSha256' => P2P_TRANSFER_CHEEZYPIZZA_ARCHIVE_SHA256, 'license' => 'BSD 3-Clause with upstream notices'],
        ],
        'adaptationSummary' => 'CoreChat owns authenticated signaling and native direct transfer. It adapts bounded chunks, backpressure, acknowledged offsets, and recipient-local assembly without hosted services, public links, or server payload fallback.',
        'offlineRecord' => 'framework/specification/FILE_TRANSFER_SOURCE_AND_VERSION.md',
    ];
}

function p2p_transfer_policy(PDO $pdo, bool $includeCredential = false): array
{
    $serverMedia = server_media_policy($pdo);
    $files = app_setting($pdo, P2P_TRANSFER_FILES_ENABLED_SETTING, '1') === '1'
        && in_array($serverMedia['fileMode'], ['p2p-only','both'], true);
    $gestures = app_setting($pdo, P2P_TRANSFER_SEND_GESTURE_ENABLED_SETTING, '1') === '1'
        && in_array($serverMedia['sendGestureMode'], ['p2p-only','both'], true);
    $transport = p2p_transport_policy($pdo, $includeCredential);
    return [
        'filesEnabled' => $files,
        'sendGestureEnabled' => $gestures,
        'effectiveEnabled' => ($files || $gestures) && !empty($transport['configurationValid']),
        'directFirst' => true,
        'relayAllowed' => !empty($transport['relayAllowed']),
        'iceServers' => $files || $gestures ? $transport['iceServers'] : [],
        'maxFileBytes' => app_setting_bytes($pdo, P2P_TRANSFER_MAX_FILE_MB_SETTING, 100),
        'maxConcurrent' => max(1, min(8, (int)app_setting($pdo, P2P_TRANSFER_MAX_CONCURRENT_SETTING, '2'))),
        'hourlyOffers' => max(1, min(200, (int)app_setting($pdo, P2P_TRANSFER_HOURLY_OFFERS_SETTING, '20'))),
        'offerLifetimeSeconds' => P2P_TRANSFER_OFFER_SECONDS,
        'acceptedSessionLifetimeSeconds' => P2P_TRANSFER_ACCEPTED_SESSION_SECONDS,
        'acceptedSessionDeadlineSliding' => false,
        'authorizationLifetimeSeconds' => P2P_TRANSFER_TOKEN_SECONDS,
        'serverPayloadStorage' => false,
        'silentServerFallback' => false,
        'directWarning' => 'Peer-to-peer transfer: CoreChat will attempt a direct connection to this participant. Your public IP address may be visible to them.',
        'relayWarning' => 'Relayed transfer: CoreChat will connect through the community\'s configured relay instead of connecting directly.',
        'directFailure' => 'Direct connection could not be established.',
    ];
}

function p2p_transfer_private_key(): string
{
    $directory = security_private_storage_directory('p2p-transfer');
    $path = $directory . DIRECTORY_SEPARATOR . 'pair-authorization-key-v1.bin';
    if (!is_file($path)) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = file_put_contents($temporary, random_bytes(32), LOCK_EX) === 32;
        $installed = $written && @rename($temporary, $path);
        if (!$installed && is_file($path)) @unlink($temporary);
        elseif (!$installed) {
            @unlink($temporary);
            throw new P2PTransferException('Direct transfer authorization is unavailable.', 'P2P_TRANSFER_KEY_UNAVAILABLE', 503);
        }
        @chmod($path, 0600);
    }
    $key = file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) throw new P2PTransferException('Direct transfer authorization is unavailable.', 'P2P_TRANSFER_KEY_UNAVAILABLE', 503);
    return $key;
}

function p2p_transfer_blocked(PDO $pdo, int $firstUserId, int $secondUserId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');
    $stmt->execute([$firstUserId,$secondUserId,$secondUserId,$firstUserId]);
    return (bool)$stmt->fetchColumn();
}

function p2p_transfer_epochs_current(PDO $pdo, array $offer): bool
{
    $senderEpoch = media_signal_recipient_epoch($pdo, (int)$offer['session_id'], (int)$offer['sender_participant_id']);
    $recipientEpoch = media_signal_recipient_epoch($pdo, (int)$offer['session_id'], (int)$offer['recipient_participant_id']);
    return is_string($senderEpoch)
        && is_string($recipientEpoch)
        && hash_equals((string)$offer['sender_epoch'], $senderEpoch)
        && hash_equals((string)$offer['recipient_epoch'], $recipientEpoch);
}

function p2p_transfer_participant(PDO $pdo, int $sessionId, int $participantId): array
{
    $stmt = $pdo->prepare('SELECT p.*,u.role,u.username FROM participants p JOIN users u ON u.id=p.user_id WHERE p.session_id=? AND p.id=? LIMIT 1');
    $stmt->execute([$sessionId,$participantId]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new P2PTransferException('The participant is unavailable.', 'P2P_TRANSFER_PARTICIPANT_UNAVAILABLE', 404);
    return $row;
}

function p2p_transfer_require_sender(PDO $pdo, array $sender, string $kind): void
{
    $policy = p2p_transfer_policy($pdo);
    if ($kind === 'gesture' ? empty($policy['sendGestureEnabled']) : empty($policy['filesEnabled'])) {
        throw new P2PTransferException('Direct transfer is disabled by installation policy.', 'P2P_TRANSFER_DISABLED', 403);
    }
    try {
        moderation_trust_require_capability_available($pdo, 'send-direct-p2p-files');
        moderation_identity_require_capability($pdo, (int)$sender['user_id'], 'send-direct-p2p-files');
    } catch (ModerationTrustPolicyException|ModerationIdentityPolicyException $error) {
        throw new P2PTransferException('This account is not authorized to send direct files.', 'P2P_TRANSFER_CAPABILITY_REQUIRED', 403);
    }
}

function p2p_transfer_sender_authorized(PDO $pdo, array $offer): bool
{
    $policy = p2p_transfer_policy($pdo);
    $kind = (string)($offer['transfer_kind'] ?? 'file');
    if ($kind === 'gesture' ? empty($policy['sendGestureEnabled']) : empty($policy['filesEnabled'])) return false;
    try {
        moderation_trust_require_capability_available($pdo, 'send-direct-p2p-files');
        moderation_identity_require_capability($pdo, (int)$offer['sender_user_id'], 'send-direct-p2p-files');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function p2p_transfer_safe_relative_path(string $value): string
{
    $path = trim(str_replace('\\', '/', $value));
    if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
        throw new P2PTransferException('The selected file path is unsafe.', 'P2P_TRANSFER_PATH_INVALID', 422);
    }
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 180
            || preg_match('/[:*?"<>|\x00-\x1F\x7F]/u', $segment)
            || preg_match('/[. ]$/u', $segment)
            || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iu', $segment)
            || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $segment)) {
            throw new P2PTransferException('The selected file path is unsafe.', 'P2P_TRANSFER_PATH_INVALID', 422);
        }
    }
    return implode('/', $segments);
}

function p2p_transfer_create_offer(PDO $pdo, array $sender, int $sessionId, array $input): array
{
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        if (db_uses_mysql_syntax($pdo)) {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE');
            $lock->execute([(int)$sender['user_id']]);
            if (!$lock->fetchColumn()) throw new P2PTransferException('The sender is unavailable.', 'P2P_TRANSFER_ACCESS_DENIED', 403);
        }
        $result = p2p_transfer_create_offer_locked($pdo, $sender, $sessionId, $input);
        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function p2p_transfer_create_offer_locked(PDO $pdo, array $sender, int $sessionId, array $input): array
{
    $kind = strtolower(trim((string)($input['kind'] ?? 'file')));
    if (!in_array($kind, ['file','gesture','avatar'], true)) throw new P2PTransferException('The transfer type is invalid.', 'P2P_TRANSFER_KIND_INVALID', 400);
    p2p_transfer_require_sender($pdo, $sender, $kind);
    $recipientId = (int)($input['recipient_participant_id'] ?? 0);
    $recipient = p2p_transfer_participant($pdo, $sessionId, $recipientId);
    if ((int)$recipient['id'] === (int)$sender['id']) throw new P2PTransferException('Choose another participant.', 'P2P_TRANSFER_SELF_DENIED', 422);
    if (p2p_transfer_blocked($pdo, (int)$sender['user_id'], (int)$recipient['user_id'])) {
        throw new P2PTransferException('The transfer is unavailable.', 'P2P_TRANSFER_BLOCKED', 403);
    }
    $policy = p2p_transfer_policy($pdo);
    $rawFiles = is_array($input['files'] ?? null) ? array_values($input['files']) : [[
        'name' => $input['name'] ?? ($kind === 'gesture' ? 'Gesture' : 'file'),
        'size' => $input['size'] ?? 0,
        'declared_mime' => $input['declared_mime'] ?? 'application/octet-stream',
        'detected_type' => $input['detected_type'] ?? 'other',
        'preview_available' => $input['preview_available'] ?? false,
        'risk_class' => $input['risk_class'] ?? 'Cannot be inspected',
        'risk_detail' => $input['risk_detail'] ?? 'Not scanned for malware.',
        'archive_encrypted' => $input['archive_encrypted'] ?? false,
        'archive_active_content' => $input['archive_active_content'] ?? false,
        'archive_suspicious_paths' => $input['archive_suspicious_paths'] ?? false,
        'archive_extreme_ratio' => $input['archive_extreme_ratio'] ?? false,
    ]];
    if (!$rawFiles || count($rawFiles) > 20 || (in_array($kind, ['gesture','avatar'], true) && count($rawFiles) !== 1)) {
        throw new P2PTransferException('Choose between 1 and 20 files for one transfer.', 'P2P_TRANSFER_BATCH_LIMIT', 413);
    }
    $manifestFiles = [];
    $byteSize = 0;
    $riskOrder = ['Low risk' => 0, 'Use caution' => 1, 'Cannot be inspected' => 2, 'Potentially dangerous' => 3];
    $aggregateRisk = 'Low risk';
    $aggregateDetail = 'Not scanned for malware.';
    $seenPaths = [];
    foreach ($rawFiles as $index => $candidate) {
        if (!is_array($candidate)) throw new P2PTransferException('The file manifest is invalid.', 'P2P_TRANSFER_MANIFEST_INVALID', 400);
        $name = p2p_transfer_safe_relative_path((string)($candidate['relative_path'] ?? $candidate['name'] ?? 'file'));
        $normalizedPath = function_exists('normalizer_normalize') ? (normalizer_normalize($name, 4) ?: $name) : $name;
        $pathKey = function_exists('mb_strtolower') ? mb_strtolower($normalizedPath, 'UTF-8') : strtolower($normalizedPath);
        if (isset($seenPaths[$pathKey])) throw new P2PTransferException('The selected files contain duplicate or deceptive paths.', 'P2P_TRANSFER_PATH_COLLISION', 422);
        $seenPaths[$pathKey] = true;
        $size = max(0, (int)($candidate['size'] ?? 0));
        if ($size <= 0 || $size > $policy['maxFileBytes']) throw new P2PTransferException('A file exceeds the configured direct-transfer limit.', 'P2P_TRANSFER_SIZE_LIMIT', 413);
        $declaredMime = trim((string)($candidate['declared_mime'] ?? 'application/octet-stream'));
        if ($declaredMime === '' || strlen($declaredMime) > 191) $declaredMime = 'application/octet-stream';
        $detectedMime = strtolower(trim((string)($candidate['detected_mime'] ?? 'application/octet-stream')));
        if ($detectedMime === '' || strlen($detectedMime) > 191 || !preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $detectedMime)) {
            $detectedMime = 'application/octet-stream';
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $blockedExtensions = ['exe','com','bat','cmd','ps1','vbs','js','jar','msi','scr','lnk','url','desktop','app','apk','html','htm','xhtml','svg'];
        $activeMimes = ['text/html','application/xhtml+xml','image/svg+xml','application/x-msdownload','application/x-msdos-program','application/javascript','text/javascript'];
        $activeContent = in_array($extension, $blockedExtensions, true)
            || in_array(strtolower($declaredMime), $activeMimes, true)
            || in_array($detectedMime, $activeMimes, true);
        if ($activeContent && count($rawFiles) === 1 && $kind !== 'gesture') {
            throw new P2PTransferException('This standalone file type is blocked by policy.', 'P2P_TRANSFER_BLOCKED_TYPE', 415);
        }
        $detectedType = strtolower(trim((string)($candidate['detected_type'] ?? 'other')));
        if (!in_array($detectedType, ['avatar','gesture','image','document','archive','audio','video','other'], true)) $detectedType = 'other';
        if ($kind === 'avatar') {
            $avatarPolicy = avatar_size_policy($pdo);
            $width = filter_var($candidate['image_width'] ?? null, FILTER_VALIDATE_INT);
            $height = filter_var($candidate['image_height'] ?? null, FILTER_VALIDATE_INT);
            $supportedAvatarMimes = ['image/jpeg','image/png','image/gif','image/webp'];
            if ($detectedType !== 'avatar'
                || !in_array($detectedMime, $supportedAvatarMimes, true)
                || $size > (int)$avatarPolicy['avatarMaxBytes']
                || $width === false || $height === false
                || (int)$width < AVATAR_UPLOAD_MIN_DIMENSION_PX
                || (int)$height < AVATAR_UPLOAD_MIN_DIMENSION_PX
                || (int)$width > (int)$avatarPolicy['avatarUploadMaxWidthPx']
                || (int)$height > (int)$avatarPolicy['avatarUploadMaxHeightPx']) {
                throw new P2PTransferException('The prepared avatar does not meet the current avatar policy.', 'P2P_TRANSFER_AVATAR_POLICY_INVALID', 422);
            }
        }
        $riskClass = trim((string)($candidate['risk_class'] ?? 'Cannot be inspected'));
        if (!array_key_exists($riskClass, $riskOrder)) $riskClass = 'Cannot be inspected';
        $doubleExtension = count(array_filter(explode('.', $name), static fn(string $part): bool => $part !== '')) > 2;
        $unicodeDeceptive = (bool)preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $name);
        if ($activeContent || $doubleExtension || $unicodeDeceptive) $riskClass = 'Potentially dangerous';
        $riskDetail = trim(preg_replace('/\s+/u', ' ', (string)($candidate['risk_detail'] ?? 'Not scanned for malware.')) ?? '');
        if ($riskDetail === '' || strlen($riskDetail) > 500) $riskDetail = 'Not scanned for malware.';
        if ($activeContent) $riskDetail = 'Active content is transferred only inside the generated archive. Not scanned for malware.';
        if ($riskOrder[$riskClass] > $riskOrder[$aggregateRisk]) { $aggregateRisk = $riskClass; $aggregateDetail = $riskDetail; }
        $byteSize += $size;
        $manifestFiles[] = [
            'index' => $index,'safeName' => $name,'size' => $size,'declaredMime' => $declaredMime,'detectedMime' => $detectedMime,
            'detectedType' => $detectedType,'riskClass' => $riskClass,'riskDetail' => $riskDetail,
            'previewAvailable' => !empty($candidate['preview_available']),
            'imageWidth' => $kind === 'avatar' ? (int)$candidate['image_width'] : null,
            'imageHeight' => $kind === 'avatar' ? (int)$candidate['image_height'] : null,
            'activeContent' => $activeContent,
            'archive' => [
                'encrypted' => !empty($candidate['archive_encrypted']),
                'activeContent' => !empty($candidate['archive_active_content']),
                'suspiciousPaths' => !empty($candidate['archive_suspicious_paths']),
                'extremeRatio' => !empty($candidate['archive_extreme_ratio']),
            ],
        ];
    }
    $batchLimit = $policy['maxFileBytes'] * min(count($manifestFiles), 10);
    if ($byteSize > $batchLimit) throw new P2PTransferException('The batch exceeds the bounded direct-transfer total.', 'P2P_TRANSFER_BATCH_SIZE_LIMIT', 413);
    $safeName = count($manifestFiles) > 1 ? count($manifestFiles) . ' files' : $manifestFiles[0]['safeName'];
    $declared = count($manifestFiles) > 1 ? 'application/x-corechat-file-batch' : $manifestFiles[0]['declaredMime'];
    $detectedType = count($manifestFiles) > 1 ? 'archive' : $manifestFiles[0]['detectedType'];
    $riskClass = $aggregateRisk;
    $riskDetail = $aggregateDetail;
    $manifest = ['version' => 1,'kind' => $kind,'count' => count($manifestFiles),'totalBytes' => $byteSize,'files' => $manifestFiles];
    $previewAvailable = count($manifestFiles) === 1 && !empty($manifestFiles[0]['previewAvailable'])
        && (in_array((string)$manifestFiles[0]['detectedType'], ['image','avatar','gesture'], true) || $kind === 'gesture');
    $manifestJson = database_migrations_canonical_json($manifest);
    $manifestSha256 = strtoupper(hash('sha256', $manifestJson));
    $requestId = trim((string)($input['request_id'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)) {
        throw new P2PTransferException('A valid transfer request identity is required.', 'P2P_TRANSFER_REQUEST_ID_REQUIRED', 400);
    }
    $requestedDelivery = !empty($policy['relayAllowed']) && !empty($input['relay_only']) ? 'relay-only' : 'direct-first';
    $requestSha256 = strtoupper(hash('sha256', database_migrations_canonical_json([
        'kind' => $kind,
        'manifest' => $manifestSha256,
        'recipientUserId' => (int)$recipient['user_id'],
        'requestedDelivery' => $requestedDelivery,
        'senderUserId' => (int)$sender['user_id'],
        'sessionId' => $sessionId,
    ])));
    $replay = $pdo->prepare('SELECT public_id,request_sha256 FROM p2p_transfer_offers WHERE request_id=? LIMIT 1');
    $replay->execute([$requestId]);
    if ($existing = $replay->fetch()) {
        if (!hash_equals((string)$existing['request_sha256'], $requestSha256)) {
            throw new P2PTransferException('That transfer request identity was already used for different files.', 'P2P_TRANSFER_REQUEST_CONFLICT', 409);
        }
        return p2p_transfer_project_offer(
            p2p_transfer_offer_by_public_id($pdo, (string)$existing['public_id']),
            (int)$sender['user_id'],
            $policy
        ) + ['idempotentReplay' => true];
    }
    $hourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
    $rate = $pdo->prepare('SELECT COUNT(*) FROM p2p_transfer_offers WHERE sender_user_id=? AND created_at>=?');
    $rate->execute([(int)$sender['user_id'],$hourAgo]);
    if ((int)$rate->fetchColumn() >= $policy['hourlyOffers']) throw new P2PTransferException('Try the transfer again later.', 'P2P_TRANSFER_RATE_LIMIT', 429);
    $active = $pdo->prepare("SELECT COUNT(*) FROM p2p_transfer_offers WHERE sender_user_id=? AND status IN ('offered','accepted','connecting','transferring','paused') AND expires_at>=CURRENT_TIMESTAMP");
    $active->execute([(int)$sender['user_id']]);
    if ((int)$active->fetchColumn() >= $policy['maxConcurrent']) throw new P2PTransferException('Finish an active transfer before starting another.', 'P2P_TRANSFER_CONCURRENCY_LIMIT', 409);
    $senderEpoch = trim((string)($input['sender_epoch'] ?? ''));
    $registeredSenderEpoch = media_signal_recipient_epoch($pdo, $sessionId, (int)$sender['id']) ?? '';
    $recipientEpoch = media_signal_recipient_epoch($pdo, $sessionId, $recipientId) ?? '';
    if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $senderEpoch)
        || $registeredSenderEpoch === ''
        || !hash_equals($registeredSenderEpoch, $senderEpoch)
        || $recipientEpoch === '') {
        throw new P2PTransferException('Both participants must have an active transfer connection.', 'P2P_TRANSFER_CLIENT_UNAVAILABLE', 409);
    }
    $publicId = 'pt_' . bin2hex(random_bytes(16));
    $transferNonce = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + P2P_TRANSFER_OFFER_SECONDS);
    $stmt = $pdo->prepare(
        'INSERT INTO p2p_transfer_offers
         (public_id,request_id,request_sha256,session_id,sender_participant_id,sender_user_id,recipient_participant_id,recipient_user_id,transfer_kind,safe_name,byte_size,declared_mime,detected_type,preview_available,risk_class,risk_detail,archive_encrypted,archive_active_content,archive_suspicious_paths,archive_extreme_ratio,file_count,manifest_json,manifest_sha256,cancelled_files_json,requested_delivery,status,sender_epoch,recipient_epoch,transfer_nonce,expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'offered\',?,?,?,?)'
    );
    $stmt->execute([
        $publicId,$requestId,$requestSha256,$sessionId,(int)$sender['id'],(int)$sender['user_id'],$recipientId,(int)$recipient['user_id'],$kind,$safeName,$byteSize,$declared,$detectedType,$previewAvailable ? 1 : 0,
        $riskClass,$riskDetail,
        array_reduce($manifestFiles, static fn(bool $carry,array $file): bool => $carry || !empty($file['archive']['encrypted']), false) ? 1 : 0,
        array_reduce($manifestFiles, static fn(bool $carry,array $file): bool => $carry || !empty($file['archive']['activeContent']) || !empty($file['activeContent']), false) ? 1 : 0,
        array_reduce($manifestFiles, static fn(bool $carry,array $file): bool => $carry || !empty($file['archive']['suspiciousPaths']), false) ? 1 : 0,
        array_reduce($manifestFiles, static fn(bool $carry,array $file): bool => $carry || !empty($file['archive']['extremeRatio']), false) ? 1 : 0,
        count($manifestFiles),$manifestJson,$manifestSha256,'[]',
        $requestedDelivery,$senderEpoch,$recipientEpoch,$transferNonce,$expires,
    ]);
    $offer = p2p_transfer_offer_by_public_id($pdo, $publicId);
    p2p_transfer_event($pdo, $offer, (int)$sender['user_id'], 'offered');
    return p2p_transfer_project_offer($offer, (int)$sender['user_id'], $policy);
}

function p2p_transfer_offer_by_public_id(PDO $pdo, string $publicId, bool $lock = false): array
{
    $sql = 'SELECT o.*,COALESCE(sp.display_name,su.display_name) AS sender_name,COALESCE(rp.display_name,ru.display_name) AS recipient_name FROM p2p_transfer_offers o JOIN users su ON su.id=o.sender_user_id JOIN users ru ON ru.id=o.recipient_user_id LEFT JOIN participants sp ON sp.id=o.sender_participant_id LEFT JOIN participants rp ON rp.id=o.recipient_participant_id WHERE o.public_id=? LIMIT 1';
    if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$publicId]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new P2PTransferException('The transfer offer is unavailable.', 'P2P_TRANSFER_NOT_FOUND', 404);
    return $row;
}

function p2p_transfer_event(PDO $pdo, array $offer, int $actorUserId, string $type, string $detail = ''): void
{
    $allowed = ['offered','accepted','declined','cancelled','connecting','direct','relayed','transferring','paused','resumed','file-cancelled','completed','failed','preview-requested','preview-declined','reported','evidence-submitted','resume-authorized','resume-verified'];
    if (!in_array($type, $allowed, true)) throw new P2PTransferException('The transfer status is invalid.', 'P2P_TRANSFER_STATUS_INVALID', 400);
    $pdo->prepare('INSERT INTO p2p_transfer_events (offer_id,actor_user_id,event_type,privacy_safe_detail) VALUES (?,?,?,?)')
        ->execute([(int)$offer['id'],$actorUserId,$type,substr(trim($detail),0,255)]);
}

function p2p_transfer_project_offer(array $offer, int $actorUserId, array $policy): array
{
    $sender = (int)$offer['sender_user_id'] === $actorUserId;
    $manifest = json_decode((string)($offer['manifest_json'] ?? ''), true);
    if (!is_array($manifest)) $manifest = ['version' => 1,'kind' => (string)$offer['transfer_kind'],'count' => 1,'totalBytes' => (int)$offer['byte_size'],'files' => []];
    $cancelledFiles = json_decode((string)($offer['cancelled_files_json'] ?? '[]'), true);
    if (!is_array($cancelledFiles)) $cancelledFiles = [];
    $projection = [
        'id' => (string)$offer['public_id'],
        'kind' => (string)$offer['transfer_kind'],
        'sender' => ['participantId' => (int)$offer['sender_participant_id'], 'name' => (string)$offer['sender_name']],
        'recipient' => ['participantId' => (int)$offer['recipient_participant_id'], 'name' => (string)$offer['recipient_name']],
        'safeName' => (string)$offer['safe_name'],
        'size' => (int)$offer['byte_size'],
        'fileCount' => (int)($offer['file_count'] ?? 1),
        'manifest' => $manifest,
        'manifestSha256' => (string)($offer['manifest_sha256'] ?? ''),
        'cancelledFiles' => array_values(array_unique(array_map('intval', $cancelledFiles))),
        'declaredMime' => (string)$offer['declared_mime'],
        'detectedType' => (string)$offer['detected_type'],
        'previewAvailable' => (bool)$offer['preview_available'],
        'previewRequested' => !empty($offer['preview_requested_at']),
        'riskClass' => (string)$offer['risk_class'],
        'riskDetail' => (string)$offer['risk_detail'],
        'archive' => [
            'encrypted' => (bool)$offer['archive_encrypted'],
            'activeContent' => (bool)$offer['archive_active_content'],
            'suspiciousPaths' => (bool)$offer['archive_suspicious_paths'],
            'extremeRatio' => (bool)$offer['archive_extreme_ratio'],
        ],
        'deliveryMethod' => (string)$offer['requested_delivery'],
        'warning' => (string)$offer['requested_delivery'] === 'relay-only' ? $policy['relayWarning'] : $policy['directWarning'],
        'finalStatus' => $offer['final_connection'] === 'relayed' ? 'Relayed connection' : ($offer['final_connection'] === 'direct' ? 'Direct connection' : null),
        'status' => (string)$offer['status'],
        'statusReason' => $offer['status_reason'],
        'acceptedAt' => !empty($offer['accepted_at']) ? str_replace(' ', 'T', (string)$offer['accepted_at']) . 'Z' : null,
        'expiresAt' => str_replace(' ', 'T', (string)$offer['expires_at']) . 'Z',
        'actorIsSender' => $sender,
        'acceptRequired' => (string)$offer['status'] === 'offered' && !$sender,
        'serverPayloadStorage' => false,
    ];
    if ((string)$offer['status'] === 'offered' && !empty($offer['preview_requested_at'])) {
        $projection['authorization'] = p2p_transfer_issue_token($offer, 'preview');
        $projection['signalKey'] = p2p_transfer_signal_key($offer);
    } elseif (in_array((string)$offer['status'], ['accepted','connecting','transferring','paused'], true)) {
        $projection['authorization'] = p2p_transfer_issue_token($offer, 'transfer');
        $projection['signalKey'] = p2p_transfer_signal_key($offer);
    }
    return $projection;
}

function p2p_transfer_signal_key(array $offer): string
{
    $material = 'corechat-p2p-signal-v1' . "\0"
        . (string)$offer['public_id'] . "\0"
        . (string)$offer['transfer_nonce'] . "\0"
        . (string)$offer['manifest_sha256'];
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $material, p2p_transfer_private_key(), true)), '+/', '-_'), '=');
}

function p2p_transfer_issue_token(array $offer, string $scope = 'transfer'): string
{
    if (!in_array($scope, ['transfer','preview'], true)) throw new P2PTransferException('The transfer authorization scope is invalid.', 'P2P_TRANSFER_SIGNAL_UNAUTHORIZED', 403);
    $claims = [
        'v' => 3,
        'offer' => (string)$offer['public_id'],
        'senderUser' => (int)$offer['sender_user_id'],
        'recipientUser' => (int)$offer['recipient_user_id'],
        'transferNonce' => (string)$offer['transfer_nonce'],
        'manifest' => (string)$offer['manifest_sha256'],
        'scope' => $scope,
        'exp' => min(time() + P2P_TRANSFER_TOKEN_SECONDS, strtotime((string)$offer['expires_at']) ?: time()),
    ];
    $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, p2p_transfer_private_key(), true)), '+/', '-_'), '=');
    return $payload . '.' . $signature;
}

function p2p_transfer_decode_token_claims(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], p2p_transfer_private_key(), true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $parts[1])) return null;
    $encoded = strtr($parts[0], '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    $json = base64_decode($encoded, true);
    $claims = is_string($json) ? json_decode($json, true) : null;
    return is_array($claims) ? $claims : null;
}

function p2p_transfer_resume_binding(array $offer, string $contentSha256, int $offset): string
{
    $material = database_migrations_canonical_json([
        'declaredMime' => (string)$offer['declared_mime'],
        'detectedType' => (string)$offer['detected_type'],
        'fileSha256' => $contentSha256,
        'manifestSha256' => (string)$offer['manifest_sha256'],
        'offer' => (string)$offer['public_id'],
        'offset' => $offset,
        'originSession' => (int)$offer['session_id'],
        'recipientParticipant' => (int)$offer['recipient_participant_id'],
        'recipientEpoch' => (string)$offer['recipient_epoch'],
        'recipientUser' => (int)$offer['recipient_user_id'],
        'safeName' => (string)$offer['safe_name'],
        'senderParticipant' => (int)$offer['sender_participant_id'],
        'senderEpoch' => (string)$offer['sender_epoch'],
        'senderUser' => (int)$offer['sender_user_id'],
        'size' => (int)$offer['byte_size'],
        'transferNonce' => (string)$offer['transfer_nonce'],
    ]);
    return strtoupper(hash_hmac('sha256', $material, p2p_transfer_private_key()));
}

function p2p_transfer_issue_resume_token(array $offer, string $contentSha256, int $offset): string
{
    $expires = min(time() + P2P_TRANSFER_TOKEN_SECONDS, strtotime((string)$offer['expires_at']) ?: time());
    $claims = [
        'v' => 4,
        'offer' => (string)$offer['public_id'],
        'senderUser' => (int)$offer['sender_user_id'],
        'recipientUser' => (int)$offer['recipient_user_id'],
        'senderParticipant' => (int)$offer['sender_participant_id'],
        'recipientParticipant' => (int)$offer['recipient_participant_id'],
        'originSession' => (int)$offer['session_id'],
        'senderEpoch' => (string)$offer['sender_epoch'],
        'recipientEpoch' => (string)$offer['recipient_epoch'],
        'transferNonce' => (string)$offer['transfer_nonce'],
        'safeName' => (string)$offer['safe_name'],
        'size' => (int)$offer['byte_size'],
        'declaredMime' => (string)$offer['declared_mime'],
        'detectedType' => (string)$offer['detected_type'],
        'manifest' => (string)$offer['manifest_sha256'],
        'offset' => $offset,
        'binding' => p2p_transfer_resume_binding($offer, $contentSha256, $offset),
        'nonce' => bin2hex(random_bytes(12)),
        'exp' => $expires,
    ];
    $payload = rtrim(strtr(base64_encode(json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, p2p_transfer_private_key(), true)), '+/', '-_'), '=');
    return $payload . '.' . $signature;
}

function p2p_transfer_verify_token(string $token, array $offer, string $requiredScope = 'transfer'): bool
{
    $claims = p2p_transfer_decode_token_claims($token);
    return is_array($claims)
        && (int)($claims['v'] ?? 0) === 3
        && (int)($claims['exp'] ?? 0) >= time()
        && hash_equals((string)$offer['public_id'], (string)($claims['offer'] ?? ''))
        && (int)($claims['senderUser'] ?? 0) === (int)$offer['sender_user_id']
        && (int)($claims['recipientUser'] ?? 0) === (int)$offer['recipient_user_id']
        && hash_equals($requiredScope, (string)($claims['scope'] ?? ''))
        && hash_equals((string)$offer['transfer_nonce'], (string)($claims['transferNonce'] ?? ''))
        && hash_equals((string)$offer['manifest_sha256'], (string)($claims['manifest'] ?? ''));
}

function p2p_transfer_verify_resume_token(string $token, array $offer, ?string $contentSha256 = null): ?array
{
    $claims = p2p_transfer_decode_token_claims($token);
    if (!is_array($claims)
        || (int)($claims['v'] ?? 0) !== 4
        || (int)($claims['exp'] ?? 0) < time()
        || !hash_equals((string)$offer['public_id'], (string)($claims['offer'] ?? ''))
        || (int)($claims['senderUser'] ?? 0) !== (int)$offer['sender_user_id']
        || (int)($claims['recipientUser'] ?? 0) !== (int)$offer['recipient_user_id']
        || (int)($claims['senderParticipant'] ?? 0) !== (int)$offer['sender_participant_id']
        || (int)($claims['recipientParticipant'] ?? 0) !== (int)$offer['recipient_participant_id']
        || (int)($claims['originSession'] ?? 0) !== (int)$offer['session_id']
        || !hash_equals((string)$offer['sender_epoch'], (string)($claims['senderEpoch'] ?? ''))
        || !hash_equals((string)$offer['recipient_epoch'], (string)($claims['recipientEpoch'] ?? ''))
        || !hash_equals((string)$offer['transfer_nonce'], (string)($claims['transferNonce'] ?? ''))
        || !hash_equals((string)$offer['safe_name'], (string)($claims['safeName'] ?? ''))
        || (int)($claims['size'] ?? -1) !== (int)$offer['byte_size']
        || !hash_equals((string)$offer['declared_mime'], (string)($claims['declaredMime'] ?? ''))
        || !hash_equals((string)$offer['detected_type'], (string)($claims['detectedType'] ?? ''))
        || !hash_equals((string)$offer['manifest_sha256'], (string)($claims['manifest'] ?? ''))
        || !preg_match('/^[A-F0-9]{64}$/', (string)($claims['binding'] ?? ''))
        || !preg_match('/^[a-f0-9]{24}$/', (string)($claims['nonce'] ?? ''))) {
        return null;
    }
    $offset = (int)($claims['offset'] ?? -1);
    if ($offset < 0 || $offset >= (int)$offer['byte_size']) return null;
    if ($contentSha256 !== null) {
        if (!preg_match('/^[A-F0-9]{64}$/', $contentSha256)
            || !hash_equals(p2p_transfer_resume_binding($offer, $contentSha256, $offset), (string)$claims['binding'])) {
            return null;
        }
    }
    return $claims;
}

function p2p_transfer_signal_payload(string $type, array $input): array
{
    if (!in_array($type, ['offer','answer','ice','resume-request'], true)) {
        throw new P2PTransferException('The transfer signal type is invalid.', 'P2P_TRANSFER_SIGNAL_INVALID', 400);
    }
    if (in_array($type, ['offer','answer','ice'], true)) {
        if (isset($input['description']) || isset($input['candidate'])) {
            throw new P2PTransferException('Readable browser signaling is not accepted.', 'P2P_TRANSFER_SIGNAL_PLAINTEXT_DENIED', 400);
        }
        $sealed = is_array($input['sealed'] ?? null) ? $input['sealed'] : [];
        $version = (int)($sealed['v'] ?? 0);
        $iv = (string)($sealed['iv'] ?? '');
        $ciphertext = (string)($sealed['ciphertext'] ?? '');
        $ciphertextLength = strlen($ciphertext);
        if ($version !== 1
            || !preg_match('/^[A-Za-z0-9_-]{16}$/', $iv)
            || $ciphertextLength < 24
            || $ciphertextLength > 300000
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $ciphertext)) {
            throw new P2PTransferException('The protected transfer signal is invalid.', 'P2P_TRANSFER_SIGNAL_INVALID', 400);
        }
        return ['sealed' => ['v' => 1, 'iv' => $iv, 'ciphertext' => $ciphertext]];
    }
    $resumeAuthorization = trim((string)($input['resume_authorization'] ?? ''));
    $resumeOffset = (int)($input['resume_offset'] ?? -1);
    if ($resumeAuthorization === '' || strlen($resumeAuthorization) > 4096 || $resumeOffset < 0) {
        throw new P2PTransferException('The transfer resume request is invalid.', 'P2P_TRANSFER_SIGNAL_INVALID', 400);
    }
    return ['resumeAuthorization' => $resumeAuthorization, 'resumeOffset' => $resumeOffset];
}

function p2p_transfer_signal_create(PDO $pdo, array $actor, string $publicId, string $type, array $input): array
{
    $offer = p2p_transfer_offer_by_public_id($pdo, $publicId);
    $actorUserId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    $sender = $actorUserId === (int)$offer['sender_user_id'];
    $recipient = $actorUserId === (int)$offer['recipient_user_id'];
    if (!$sender && !$recipient) throw new P2PTransferException('The transfer is unavailable.', 'P2P_TRANSFER_ACCESS_DENIED', 403);
    $previewSignaling = (string)$offer['status'] === 'offered' && !empty($offer['preview_requested_at']);
    if ((!in_array((string)$offer['status'], ['accepted','connecting','transferring','paused'], true) && !$previewSignaling)
        || strtotime((string)$offer['expires_at']) < time()
        || p2p_transfer_blocked($pdo, (int)$offer['sender_user_id'], (int)$offer['recipient_user_id'])) {
        throw new P2PTransferException('The transfer session is unavailable.', 'P2P_TRANSFER_SIGNAL_UNAVAILABLE', 409);
    }
    if (!p2p_transfer_sender_authorized($pdo, $offer)) {
        throw new P2PTransferException('The transfer is no longer authorized.', 'P2P_TRANSFER_CAPABILITY_REQUIRED', 403);
    }
    $authorization = trim((string)($input['transfer_authorization'] ?? ''));
    if (!p2p_transfer_verify_token($authorization, $offer, $previewSignaling ? 'preview' : 'transfer')) {
        throw new P2PTransferException('The transfer authorization is invalid.', 'P2P_TRANSFER_SIGNAL_UNAUTHORIZED', 403);
    }
    if (($type === 'offer' && !$sender) || ($type === 'answer' && !$recipient)) {
        throw new P2PTransferException('The transfer signal direction is invalid.', 'P2P_TRANSFER_SIGNAL_UNAUTHORIZED', 403);
    }
    $payload = p2p_transfer_signal_payload($type, $input);
    if ($previewSignaling && !in_array($type, ['offer','answer','ice'], true)) {
        throw new P2PTransferException('The preview signal type is invalid.', 'P2P_TRANSFER_SIGNAL_UNAUTHORIZED', 403);
    }
    if ($type === 'resume-request') {
        $claims = p2p_transfer_verify_resume_token((string)$payload['resumeAuthorization'], $offer);
        if (!$recipient || !is_array($claims) || (int)$claims['offset'] !== (int)$payload['resumeOffset']) {
            throw new P2PTransferException('The transfer resume authorization is invalid.', 'P2P_TRANSFER_SIGNAL_UNAUTHORIZED', 403);
        }
    }
    $toUserId = $sender ? (int)$offer['recipient_user_id'] : (int)$offer['sender_user_id'];
    $expires = gmdate('Y-m-d H:i:s', min(time() + P2P_TRANSFER_TOKEN_SECONDS, strtotime((string)$offer['expires_at']) ?: time()));
    $pdo->prepare('INSERT INTO p2p_transfer_signals (offer_id,from_user_id,to_user_id,signal_type,payload_json,expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$offer['id'],$actorUserId,$toUserId,$type,database_migrations_canonical_json($payload),$expires]);
    return ['queued' => true, 'type' => $type, 'expiresAt' => $expires];
}

function p2p_transfer_signal_poll(PDO $pdo, array $actor): array
{
    $userId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE expires_at<CURRENT_TIMESTAMP OR delivered_at IS NOT NULL')->execute();
    $stmt = $pdo->prepare("SELECT s.*,o.public_id FROM p2p_transfer_signals s JOIN p2p_transfer_offers o ON o.id=s.offer_id WHERE s.to_user_id=? AND s.delivered_at IS NULL AND s.expires_at>=CURRENT_TIMESTAMP AND (o.status IN ('accepted','connecting','transferring','paused') OR (o.status='offered' AND o.preview_requested_at IS NOT NULL)) AND o.expires_at>=CURRENT_TIMESTAMP ORDER BY s.id LIMIT 100");
    $stmt->execute([$userId]);
    $signals = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string)$row['payload_json'], true);
        if (!is_array($payload)) continue;
        $signals[] = [
            'id' => (int)$row['id'],
            'transferId' => (string)$row['public_id'],
            'fromUserId' => (int)$row['from_user_id'],
            'type' => (string)$row['signal_type'],
            'data' => $payload,
        ];
    }
    return $signals;
}

function p2p_transfer_signal_acknowledge(PDO $pdo, array $actor, int $signalId): bool
{
    $userId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    if ($signalId <= 0) throw new P2PTransferException('The transfer signal identity is invalid.', 'P2P_TRANSFER_SIGNAL_INVALID', 400);
    $stmt = $pdo->prepare('UPDATE p2p_transfer_signals SET delivered_at=COALESCE(delivered_at,CURRENT_TIMESTAMP) WHERE id=? AND to_user_id=?');
    $stmt->execute([$signalId,$userId]);
    return $stmt->rowCount() === 1;
}

function p2p_transfer_update(PDO $pdo, array $actor, string $publicId, string $action, array $input = []): array
{
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $result = p2p_transfer_update_locked($pdo, $actor, $publicId, $action, $input);
        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function p2p_transfer_update_locked(PDO $pdo, array $actor, string $publicId, string $action, array $input = []): array
{
    $offer = p2p_transfer_offer_by_public_id($pdo, $publicId, true);
    $actorUserId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    $sender = $actorUserId === (int)$offer['sender_user_id'];
    $recipient = $actorUserId === (int)$offer['recipient_user_id'];
    if (!$sender && !$recipient) throw new P2PTransferException('The transfer is unavailable.', 'P2P_TRANSFER_ACCESS_DENIED', 403);
    if (!in_array((string)$offer['status'], ['completed','declined','failed','cancelled'], true)
        && p2p_transfer_blocked($pdo, (int)$offer['sender_user_id'], (int)$offer['recipient_user_id'])) {
        $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Transfer unavailable',updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$offer['id']]);
        throw new P2PTransferException('The transfer is unavailable.', 'P2P_TRANSFER_BLOCKED', 403);
    }
    if (!in_array((string)$offer['status'], ['completed','declined','failed','cancelled'], true)
        && !p2p_transfer_sender_authorized($pdo, $offer)) {
        $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Authorization ended',updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$offer['id']]);
        $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
        throw new P2PTransferException('The transfer is no longer authorized.', 'P2P_TRANSFER_CAPABILITY_REQUIRED', 403);
    }
    if (strtotime((string)$offer['expires_at']) < time() && !in_array((string)$offer['status'], ['completed','declined','failed','cancelled'], true)) {
        $accepted = !empty($offer['accepted_at']);
        $reason = $accepted ? 'Transfer expired' : 'Offer expired';
        $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$reason,(int)$offer['id']]);
        throw new P2PTransferException($accepted ? 'The transfer session expired.' : 'The transfer offer expired.', 'P2P_TRANSFER_EXPIRED', 409);
    }
    if ((string)$offer['status'] === 'offered'
        && !p2p_transfer_epochs_current($pdo, $offer)) {
        $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Original browser connection ended',updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$offer['id']]);
        throw new P2PTransferException('The original browser connection ended. Create a new offer.', 'P2P_TRANSFER_EPOCH_STALE', 409);
    }
    if ($action === 'resume-authorize') {
        if (!$recipient || !in_array((string)$offer['status'], ['connecting','transferring','paused'], true)) {
            throw new P2PTransferException('This transfer cannot be resumed.', 'P2P_TRANSFER_RESUME_STATE_INVALID', 409);
        }
        $offset = (int)($input['resume_offset'] ?? -1);
        $contentSha256 = strtoupper(trim((string)($input['content_sha256'] ?? '')));
        if ($offset < 0 || $offset >= (int)$offer['byte_size']
            || !preg_match('/^[A-F0-9]{64}$/', $contentSha256)) {
            throw new P2PTransferException('The retained partial transfer state is invalid.', 'P2P_TRANSFER_RESUME_BINDING_INVALID', 409);
        }
        $token = p2p_transfer_issue_resume_token($offer, $contentSha256, $offset);
        p2p_transfer_event($pdo, $offer, $actorUserId, 'resume-authorized', 'Receiver confirmed contiguous offset ' . $offset . '.');
        return p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo)) + [
            'resumeAuthorization' => $token,
            'resumeOffset' => $offset,
        ];
    }
    if ($action === 'resume-verify') {
        if (!$sender || !in_array((string)$offer['status'], ['connecting','transferring','paused'], true)) {
            throw new P2PTransferException('This transfer cannot be resumed.', 'P2P_TRANSFER_RESUME_STATE_INVALID', 409);
        }
        $token = trim((string)($input['resume_authorization'] ?? ''));
        $contentSha256 = strtoupper(trim((string)($input['content_sha256'] ?? '')));
        $claims = p2p_transfer_verify_resume_token($token, $offer, $contentSha256);
        if (!is_array($claims)) {
            throw new P2PTransferException('The retained local file does not match the partial transfer.', 'P2P_TRANSFER_RESUME_BINDING_INVALID', 409);
        }
        p2p_transfer_event($pdo, $offer, $actorUserId, 'resume-verified', 'Sender verified contiguous offset ' . (int)$claims['offset'] . '.');
        return p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo)) + [
            'resumeAuthorization' => $token,
            'resumeOffset' => (int)$claims['offset'],
        ];
    }
    $nextStatus = null;
    $event = $action;
    $finalConnection = null;
    $reason = '';
    if ($action === 'accept' && $recipient && (string)$offer['status'] === 'offered') { $nextStatus = 'accepted'; $event = 'accepted'; }
    elseif ($action === 'decline' && $recipient && (string)$offer['status'] === 'offered') { $nextStatus = 'declined'; $event = 'declined'; }
    elseif ($action === 'cancel' && ($sender || $recipient) && in_array((string)$offer['status'], ['offered','accepted','connecting','transferring','paused'], true)) { $nextStatus = 'cancelled'; $event = 'cancelled'; }
    elseif ($action === 'connecting' && $sender && (string)$offer['status'] === 'accepted') $nextStatus = 'connecting';
    elseif ($action === 'direct' && $sender && (string)$offer['status'] === 'connecting') { $nextStatus = 'transferring'; $finalConnection = 'direct'; }
    elseif ($action === 'relayed' && $sender && (string)$offer['status'] === 'connecting') { $nextStatus = 'transferring'; $finalConnection = 'relayed'; }
    elseif ($action === 'pause' && ($sender || $recipient) && (string)$offer['status'] === 'transferring') { $nextStatus = 'paused'; $event = 'paused'; }
    elseif ($action === 'resume' && ($sender || $recipient) && (string)$offer['status'] === 'paused') { $nextStatus = 'transferring'; $event = 'resumed'; }
    elseif ($action === 'cancel-current' && ($sender || $recipient) && in_array((string)$offer['status'], ['transferring','paused'], true)) {
        $fileIndex = (int)($input['file_index'] ?? -1);
        if ($fileIndex < 0 || $fileIndex >= (int)$offer['file_count']) throw new P2PTransferException('The batch file identity is invalid.', 'P2P_TRANSFER_FILE_INDEX_INVALID', 400);
        $cancelled = json_decode((string)($offer['cancelled_files_json'] ?? '[]'), true);
        if (!is_array($cancelled)) $cancelled = [];
        $cancelled[] = $fileIndex;
        $cancelled = array_values(array_unique(array_map('intval', $cancelled)));
        sort($cancelled, SORT_NUMERIC);
        $pdo->prepare('UPDATE p2p_transfer_offers SET cancelled_files_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([database_migrations_canonical_json($cancelled),(int)$offer['id']]);
        $offer = p2p_transfer_offer_by_public_id($pdo, $publicId);
        p2p_transfer_event($pdo, $offer, $actorUserId, 'file-cancelled', 'Batch file index ' . $fileIndex . ' cancelled.');
        return p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo)) + ['cancelledFileIndex' => $fileIndex];
    }
    elseif ($action === 'complete' && $recipient && (string)$offer['status'] === 'transferring') { $nextStatus = 'completed'; $event = 'completed'; }
    elseif ($action === 'fail' && ($sender || $recipient) && in_array((string)$offer['status'], ['accepted','connecting','transferring','paused'], true)) {
        $nextStatus = 'failed';
        $event = 'failed';
        $reason = trim((string)($input['reason'] ?? ''));
        if ($reason === '' || $reason === 'ice-failed') $reason = p2p_transfer_policy($pdo)['directFailure'];
    } elseif ($action === 'preview-request' && $recipient && (string)$offer['status'] === 'offered' && !empty($offer['preview_available'])) {
        $pdo->prepare('UPDATE p2p_transfer_offers SET preview_requested_at=COALESCE(preview_requested_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([(int)$offer['id']]);
        $offer = p2p_transfer_offer_by_public_id($pdo, $publicId);
        p2p_transfer_event($pdo, $offer, $actorUserId, 'preview-requested');
        return p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo));
    } elseif ($action === 'report' && $recipient) {
        $reportReason = trim(preg_replace('/\s+/u', ' ', (string)($input['reason'] ?? '')) ?? '');
        if (strlen($reportReason) < 8 || strlen($reportReason) > 2000) {
            throw new P2PTransferException('Enter a specific report reason.', 'P2P_TRANSFER_REPORT_REASON_REQUIRED', 422);
        }
        $ownsTransaction = !$pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
                else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            }
            $report = moderation_safety_submit_report($pdo, $actorUserId, [
                'origin_type' => 'file',
                'origin_reference' => (string)$offer['public_id'],
                'reported_user_id' => (int)$offer['sender_user_id'],
                'reason' => $reportReason,
                'evidence_type' => 'file-offer',
                'evidence' => [
                    'transferId' => (string)$offer['public_id'],
                    'safeName' => (string)$offer['safe_name'],
                    'sizeBytes' => (int)$offer['byte_size'],
                    'declaredMime' => (string)$offer['declared_mime'],
                    'detectedType' => (string)$offer['detected_type'],
                    'deliveryMethod' => (string)$offer['requested_delivery'],
                    'finalConnection' => $offer['final_connection'],
                    'payloadSubmitted' => false,
                ],
            ]);
            p2p_transfer_event($pdo, $offer, $actorUserId, 'reported', 'Recipient submitted a privacy-safe transfer report; payload not included.');
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof P2PTransferException) throw $error;
            if ($error instanceof ModerationSafetyException) {
                throw new P2PTransferException($error->getMessage(), $error->errorCode, $error->httpStatus);
            }
            throw $error;
        }
        return p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo)) + [
            'reported' => true,
            'payloadSubmitted' => false,
            'reportReference' => $report['reference'],
        ];
    } else {
        throw new P2PTransferException('The transfer action is not valid for its current state.', 'P2P_TRANSFER_STATE_CONFLICT', 409);
    }
    if ($nextStatus === 'accepted') {
        $deadline = gmdate('Y-m-d H:i:s', time() + P2P_TRANSFER_ACCEPTED_SESSION_SECONDS);
        $pdo->prepare('UPDATE p2p_transfer_offers SET status=?,status_reason=NULL,accepted_at=CURRENT_TIMESTAMP,expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'offered\'')
            ->execute([$nextStatus,$deadline,(int)$offer['id']]);
    } else {
        $pdo->prepare('UPDATE p2p_transfer_offers SET status=?,status_reason=?,final_connection=COALESCE(?,final_connection),updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$nextStatus,$reason !== '' ? $reason : null,$finalConnection,(int)$offer['id']]);
    }
    $offer = p2p_transfer_offer_by_public_id($pdo, $publicId);
    if (in_array((string)$nextStatus, ['accepted','declined','cancelled','completed','failed'], true)) {
        $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
    }
    p2p_transfer_event($pdo, $offer, $actorUserId, $event, $reason);
    $projection = p2p_transfer_project_offer($offer, $actorUserId, p2p_transfer_policy($pdo));
    if ($nextStatus === 'accepted') $projection['authorization'] = p2p_transfer_issue_token($offer, 'transfer');
    return $projection;
}

function p2p_transfer_poll_account(PDO $pdo, array $actor): array
{
    $userId = (int)($actor['user_id'] ?? $actor['id'] ?? 0);
    $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Offer expired',updated_at=CURRENT_TIMESTAMP WHERE status='offered' AND expires_at<CURRENT_TIMESTAMP")->execute();
    $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Transfer expired',updated_at=CURRENT_TIMESTAMP WHERE status IN ('accepted','connecting','transferring','paused') AND expires_at<CURRENT_TIMESTAMP")->execute();
    $stmt = $pdo->prepare("SELECT o.*,COALESCE(sp.display_name,su.display_name) AS sender_name,COALESCE(rp.display_name,ru.display_name) AS recipient_name FROM p2p_transfer_offers o JOIN users su ON su.id=o.sender_user_id JOIN users ru ON ru.id=o.recipient_user_id LEFT JOIN participants sp ON sp.id=o.sender_participant_id LEFT JOIN participants rp ON rp.id=o.recipient_participant_id WHERE (o.sender_user_id=? OR o.recipient_user_id=?) AND o.created_at>=? ORDER BY o.id DESC LIMIT 50");
    $stmt->execute([$userId,$userId,gmdate('Y-m-d H:i:s', time() - (P2P_TRANSFER_ACCEPTED_SESSION_SECONDS + 3600))]);
    $policy = p2p_transfer_policy($pdo);
    $offers = [];
    foreach ($stmt->fetchAll() as $offer) {
        if (!in_array((string)$offer['status'], ['completed','declined','failed','cancelled'], true)
            && (p2p_transfer_blocked($pdo, (int)$offer['sender_user_id'], (int)$offer['recipient_user_id'])
                || !p2p_transfer_sender_authorized($pdo, $offer))) {
            $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Authorization ended',updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([(int)$offer['id']]);
            $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
            $offer['status'] = 'failed';
            $offer['status_reason'] = 'Authorization ended';
        }
        if ((string)$offer['status'] === 'offered'
            && !p2p_transfer_epochs_current($pdo, $offer)) {
            $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Original browser connection ended',updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([(int)$offer['id']]);
            $offer = p2p_transfer_offer_by_public_id($pdo, (string)$offer['public_id']);
        }
        if (in_array((string)$offer['status'], ['offered','accepted','connecting','transferring','paused'], true)
            && (p2p_transfer_blocked($pdo, (int)$offer['sender_user_id'], (int)$offer['recipient_user_id'])
                || !p2p_transfer_sender_authorized($pdo, $offer))) {
            $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason='Authorization ended',updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([(int)$offer['id']]);
            $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
            $offer = p2p_transfer_offer_by_public_id($pdo, (string)$offer['public_id']);
        }
        $offers[] = p2p_transfer_project_offer($offer, $userId, $policy);
    }
    return $offers;
}

function p2p_transfer_terminate_user(PDO $pdo, int $userId, string $reason = 'Authorization ended'): int
{
    if ($userId <= 0 || !database_migration_table_exists($pdo, 'p2p_transfer_offers')) return 0;
    $safeReason = substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 191);
    if ($safeReason === '') $safeReason = 'Authorization ended';
    $stmt = $pdo->prepare("SELECT * FROM p2p_transfer_offers WHERE (sender_user_id=? OR recipient_user_id=?) AND status IN ('offered','accepted','connecting','transferring','paused')");
    $stmt->execute([$userId,$userId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $offer) {
        $update = $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('offered','accepted','connecting','transferring','paused')");
        $update->execute([$safeReason,(int)$offer['id']]);
        if ($update->rowCount() !== 1) continue;
        $count++;
        p2p_transfer_event($pdo, $offer, $userId, 'failed', $safeReason);
        $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
    }
    return $count;
}

function p2p_transfer_terminate_pair(PDO $pdo, int $firstUserId, int $secondUserId, string $reason = 'Participant block'): int
{
    if ($firstUserId <= 0 || $secondUserId <= 0 || !database_migration_table_exists($pdo, 'p2p_transfer_offers')) return 0;
    $safeReason = substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 191) ?: 'Participant block';
    $stmt = $pdo->prepare("SELECT * FROM p2p_transfer_offers WHERE ((sender_user_id=? AND recipient_user_id=?) OR (sender_user_id=? AND recipient_user_id=?)) AND status IN ('offered','accepted','connecting','transferring','paused')");
    $stmt->execute([$firstUserId,$secondUserId,$secondUserId,$firstUserId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $offer) {
        $update = $pdo->prepare("UPDATE p2p_transfer_offers SET status='failed',status_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('offered','accepted','connecting','transferring','paused')");
        $update->execute([$safeReason,(int)$offer['id']]);
        if ($update->rowCount() !== 1) continue;
        $count++;
        p2p_transfer_event($pdo, $offer, $firstUserId, 'failed', $safeReason);
        $pdo->prepare('DELETE FROM p2p_transfer_signals WHERE offer_id=?')->execute([(int)$offer['id']]);
    }
    return $count;
}

function p2p_transfer_record_source_check(PDO $pdo, array $actor): array
{
    if ((string)($actor['role'] ?? '') !== 'admin') throw new P2PTransferException('Administrator authorization is required.', 'P2P_TRANSFER_ADMIN_REQUIRED', 403);
    security_require_recent_authentication();
    $checkedAt = gmdate('Y-m-d H:i:s');
    set_app_setting($pdo, P2P_TRANSFER_SOURCE_CHECKED_AT_SETTING, $checkedAt);
    log_tool($pdo, (int)$actor['id'], 'p2p_transfer_source_reviewed', null, null, 'Pinned source metadata reviewed manually; no source downloaded or applied.');
    return p2p_transfer_provenance($pdo, true);
}
