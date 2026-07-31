<?php
declare(strict_types=1);

/**
 * Build 000051 moderation, reporting, bounded evidence, personal mute, Block,
 * staff capability, and future delivery-policy owner.
 */

const MODERATION_SAFETY_REPORT_ORIGINS = [
    'user', 'profile', 'avatar', 'message', 'room', 'community', 'dm',
    'relationship', 'game', 'gesture', 'media', 'file', 'website-room',
];
const MODERATION_SAFETY_EVIDENCE_TYPES = [
    'message', 'avatar', 'gesture', 'webcam-still', 'room', 'website-room',
    'profile', 'user', 'file-offer', 'e2ee-participant-disclosure',
];
const MODERATION_SAFETY_STAFF_CAPABILITIES = [
    'warn', 'temporarily-restrict', 'remove-from-room', 'suspend-account',
    'review-reports', 'view-moderation-history',
    'undo-eligible-restriction', 'manage-evidence',
    'view-runtime-issues', 'manage-runtime-issues',
    'export-runtime-issues', 'manage-runtime-evidence',
];
const MODERATION_SAFETY_MUTE_DURATIONS = ['until-unmute', '1-hour', '24-hours'];
const MODERATION_SAFETY_MUTE_SCOPES = [
    'text-bubbles', 'gestures-audio', 'notices-unread', 'voice',
    'avatar-webcam-placeholder',
];
const MODERATION_SAFETY_AVATAR_DELIVERY_SETTING = 'moderation_avatar_delivery_mode';
const MODERATION_SAFETY_GESTURE_DELIVERY_SETTING = 'moderation_gesture_delivery_mode';
const MODERATION_SAFETY_AVATAR_DELIVERY_MODES = [
    'server-stored',
    'p2p-plus-built-in-generated',
    'built-in-generated-only',
];
const MODERATION_SAFETY_GESTURE_DELIVERY_MODES = [
    'server-stored-personal-community',
    'p2p-personal-plus-built-in',
    'built-in-only',
];

final class ModerationSafetyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MODERATION_SAFETY_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function moderation_safety_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS user_staff_capability_grants (
                user_id INT NOT NULL, capability_id VARCHAR(96) NOT NULL,
                enabled TINYINT(1) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
                granted_by_user_id INT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, capability_id),
                CONSTRAINT fk_staff_cap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_reports (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE,
                reporter_user_id INT NOT NULL, reported_user_id INT DEFAULT NULL,
                origin_type VARCHAR(48) NOT NULL, origin_reference VARCHAR(191) NOT NULL,
                reason VARCHAR(2000) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'received',
                case_id BIGINT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reporter_rate (reporter_user_id, created_at),
                INDEX idx_report_case (case_id),
                CONSTRAINT fk_reporter_user FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_report_case FOREIGN KEY (case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_evidence (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE,
                report_id BIGINT NOT NULL, evidence_type VARCHAR(48) NOT NULL,
                source_fingerprint VARCHAR(64) NOT NULL, ciphertext MEDIUMTEXT NOT NULL,
                nonce_b64 VARCHAR(64) NOT NULL, tag_b64 VARCHAR(64) NOT NULL,
                schema_version INT NOT NULL DEFAULT 1, safety_hold TINYINT(1) NOT NULL DEFAULT 0,
                hold_reason VARCHAR(500) DEFAULT NULL,
                retained_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_evidence_report (report_id),
                CONSTRAINT fk_evidence_report FOREIGN KEY (report_id) REFERENCES moderation_reports(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS personal_mutes (
                muter_user_id INT NOT NULL, muted_user_id INT NOT NULL,
                scopes_json TEXT NOT NULL, expires_at DATETIME DEFAULT NULL,
                revision BIGINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (muter_user_id, muted_user_id),
                CONSTRAINT fk_personal_mute_actor FOREIGN KEY (muter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_personal_mute_target FOREIGN KEY (muted_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_actions (
                request_id VARCHAR(128) PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE,
                actor_user_id INT NOT NULL, target_user_id INT NOT NULL,
                action_type VARCHAR(64) NOT NULL, public_reason VARCHAR(500) NOT NULL,
                internal_note TEXT DEFAULT NULL, duration_minutes INT DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL, target_revision_before BIGINT NOT NULL,
                target_revision_after BIGINT NOT NULL, status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_moderation_action_target (target_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_case_assignments (
                case_id BIGINT PRIMARY KEY, assignee_user_id INT NOT NULL,
                lease_token_hash VARCHAR(64) NOT NULL, lease_expires_at DATETIME NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_case_assignment_case FOREIGN KEY (case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS user_staff_capability_grants (
            user_id INTEGER NOT NULL, capability_id TEXT NOT NULL,
            enabled INTEGER NOT NULL CHECK (enabled IN (0,1)),
            revision INTEGER NOT NULL DEFAULT 1, granted_by_user_id INTEGER DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, capability_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS moderation_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE,
            reporter_user_id INTEGER NOT NULL, reported_user_id INTEGER DEFAULT NULL,
            origin_type TEXT NOT NULL, origin_reference TEXT NOT NULL,
            reason TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'received',
            case_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_reporter_rate ON moderation_reports(reporter_user_id, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_report_case ON moderation_reports(case_id)',
        "CREATE TABLE IF NOT EXISTS moderation_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE,
            report_id INTEGER NOT NULL, evidence_type TEXT NOT NULL,
            source_fingerprint TEXT NOT NULL, ciphertext TEXT NOT NULL,
            nonce_b64 TEXT NOT NULL, tag_b64 TEXT NOT NULL,
            schema_version INTEGER NOT NULL DEFAULT 1,
            safety_hold INTEGER NOT NULL DEFAULT 0 CHECK (safety_hold IN (0,1)),
            hold_reason TEXT DEFAULT NULL, retained_until TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(report_id) REFERENCES moderation_reports(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_evidence_report ON moderation_evidence(report_id)',
        "CREATE TABLE IF NOT EXISTS personal_mutes (
            muter_user_id INTEGER NOT NULL, muted_user_id INTEGER NOT NULL,
            scopes_json TEXT NOT NULL, expires_at TEXT DEFAULT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (muter_user_id, muted_user_id),
            FOREIGN KEY(muter_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(muted_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS moderation_actions (
            request_id TEXT PRIMARY KEY, public_id TEXT NOT NULL UNIQUE,
            actor_user_id INTEGER NOT NULL, target_user_id INTEGER NOT NULL,
            action_type TEXT NOT NULL, public_reason TEXT NOT NULL,
            internal_note TEXT DEFAULT NULL, duration_minutes INTEGER DEFAULT NULL,
            expires_at TEXT DEFAULT NULL, target_revision_before INTEGER NOT NULL,
            target_revision_after INTEGER NOT NULL, status TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_moderation_action_target ON moderation_actions(target_user_id, created_at)',
        "CREATE TABLE IF NOT EXISTS moderation_case_assignments (
            case_id INTEGER PRIMARY KEY, assignee_user_id INTEGER NOT NULL,
            lease_token_hash TEXT NOT NULL, lease_expires_at TEXT NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
        )",
    ];
}

function moderation_safety_delivery_policy_catalog(): array
{
    return [
        'avatar' => [
            'setting' => MODERATION_SAFETY_AVATAR_DELIVERY_SETTING,
            'modes' => MODERATION_SAFETY_AVATAR_DELIVERY_MODES,
            'default' => 'server-stored',
            'available' => ['server-stored' => true, 'p2p-plus-built-in-generated' => false, 'built-in-generated-only' => true],
        ],
        'gesture' => [
            'setting' => MODERATION_SAFETY_GESTURE_DELIVERY_SETTING,
            'modes' => MODERATION_SAFETY_GESTURE_DELIVERY_MODES,
            'default' => 'server-stored-personal-community',
            'available' => ['server-stored-personal-community' => true, 'p2p-personal-plus-built-in' => false, 'built-in-only' => true],
        ],
        'p2pPersonalLocalMatchOnlyDefault' => true,
        'p2pOnDemandSharingEnabled' => false,
        'futureGates' => [
            'cross-device-limits', 'recipient-validation', 'network-opt-out',
            'direct-first-turn-policy', 'real-safari-certification',
        ],
    ];
}

function moderation_safety_install_schema(PDO $pdo): void
{
    foreach (moderation_safety_schema_statements($pdo) as $statement) $pdo->exec($statement);
    foreach (moderation_safety_delivery_policy_catalog() as $key => $policy) {
        if (!in_array($key, ['avatar', 'gesture'], true)) continue;
        if (app_setting($pdo, $policy['setting'], "\0") === "\0") {
            set_app_setting($pdo, $policy['setting'], $policy['default']);
        }
    }
    moderation_safety_project_default_staff_grants($pdo);
}

function moderation_safety_delivery_policy(PDO $pdo, string $kind): array
{
    $definition = moderation_safety_delivery_policy_catalog()[$kind] ?? null;
    if (!is_array($definition)) {
        throw new ModerationSafetyException('The delivery policy is unknown.', 'DELIVERY_POLICY_UNKNOWN', 404);
    }
    $stored = app_setting($pdo, $definition['setting'], $definition['default']);
    if (!in_array($stored, $definition['modes'], true)) $stored = $definition['default'];
    $available = !empty($definition['available'][$stored]);
    return [
        'kind' => $kind,
        'storedMode' => $stored,
        'effectiveMode' => $available ? $stored : $definition['default'],
        'available' => $available,
        'denialCode' => $available ? null : 'DELIVERY_MODE_IMPLEMENTATION_UNAVAILABLE',
        'upgradeDefault' => $definition['default'],
    ];
}

function moderation_safety_schema_valid(PDO $pdo): bool
{
    foreach ([
        'user_staff_capability_grants', 'moderation_reports', 'moderation_evidence',
        'personal_mutes', 'moderation_actions', 'moderation_case_assignments',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    $catalog = moderation_safety_delivery_policy_catalog();
    return app_setting($pdo, MODERATION_SAFETY_AVATAR_DELIVERY_SETTING, '') === $catalog['avatar']['default']
        && app_setting($pdo, MODERATION_SAFETY_GESTURE_DELIVERY_SETTING, '') === $catalog['gesture']['default'];
}

function moderation_safety_project_default_staff_grants(PDO $pdo, ?int $onlyUserId = null): void
{
    $sql = 'SELECT id,role FROM users';
    $params = [];
    if ($onlyUserId !== null) {
        $sql .= ' WHERE id=?';
        $params[] = $onlyUserId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $defaults = moderation_identity_staff_capability_defaults();
    foreach ($statement->fetchAll() as $user) {
        $role = (string)($user['role'] ?? 'user');
        foreach (($defaults[$role] ?? []) as $capabilityId) {
            if (!in_array($capabilityId, MODERATION_SAFETY_STAFF_CAPABILITIES, true)) continue;
            $insert = db_uses_mysql_syntax($pdo)
                ? 'INSERT IGNORE INTO user_staff_capability_grants (user_id,capability_id,enabled,revision) VALUES (?,?,1,1)'
                : 'INSERT OR IGNORE INTO user_staff_capability_grants (user_id,capability_id,enabled,revision) VALUES (?,?,1,1)';
            $pdo->prepare($insert)->execute([(int)$user['id'], $capabilityId]);
        }
    }
}

function moderation_safety_has_staff_capability(PDO $pdo, int $userId, string $capabilityId): bool
{
    if (!in_array($capabilityId, MODERATION_SAFETY_STAFF_CAPABILITIES, true)) return false;
    $statement = $pdo->prepare(
        'SELECT enabled FROM user_staff_capability_grants WHERE user_id=? AND capability_id=?'
    );
    $statement->execute([$userId, $capabilityId]);
    return (int)($statement->fetchColumn() ?: 0) === 1;
}

function moderation_safety_require_staff_capability(PDO $pdo, int $userId, string $capabilityId): void
{
    if (!moderation_safety_has_staff_capability($pdo, $userId, $capabilityId)) {
        throw new ModerationSafetyException(
            'This moderation capability is not authorized for the current account.',
            'MODERATION_CAPABILITY_REQUIRED',
            403,
            ['capabilityId' => $capabilityId]
        );
    }
}

function moderation_safety_report_public_id(): string
{
    return 'report-' . strtolower(str_replace('-', '', uuid_v4()));
}

function moderation_safety_evidence_public_id(): string
{
    return 'evidence-' . strtolower(str_replace('-', '', uuid_v4()));
}

function moderation_safety_evidence_key(bool $create = true): string
{
    $directory = security_private_storage_directory('moderation-evidence');
    $path = $directory . DIRECTORY_SEPARATOR . 'vault-key-v1.bin';
    if (!is_file($path) && $create) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $bytes = random_bytes(32);
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== 32 || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new ModerationSafetyException('The protected evidence key could not be initialized.', 'EVIDENCE_KEY_UNAVAILABLE', 503);
        }
        @chmod($path, 0600);
    }
    $key = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($key) || strlen($key) !== 32) {
        throw new ModerationSafetyException('The protected evidence key is unavailable.', 'EVIDENCE_KEY_UNAVAILABLE', 503);
    }
    return $key;
}

function moderation_safety_encrypt_evidence(array $evidence): array
{
    $plaintext = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($plaintext) > 1048576) {
        throw new ModerationSafetyException('Report evidence exceeds the bounded limit.', 'REPORT_EVIDENCE_TOO_LARGE', 413);
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', moderation_safety_evidence_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'chatspace-evidence-v1');
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new ModerationSafetyException('Report evidence encryption failed.', 'REPORT_EVIDENCE_ENCRYPTION_FAILED', 500);
    }
    return [
        'ciphertext' => base64_encode($ciphertext),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
        'fingerprint' => strtoupper(hash('sha256', $plaintext)),
    ];
}

function moderation_safety_decrypt_evidence(array $row): array
{
    $plaintext = openssl_decrypt(
        base64_decode((string)$row['ciphertext'], true) ?: '',
        'aes-256-gcm',
        moderation_safety_evidence_key(false),
        OPENSSL_RAW_DATA,
        base64_decode((string)$row['nonce_b64'], true) ?: '',
        base64_decode((string)$row['tag_b64'], true) ?: '',
        'chatspace-evidence-v1'
    );
    if (!is_string($plaintext)) {
        throw new ModerationSafetyException('Protected report evidence failed integrity validation.', 'REPORT_EVIDENCE_INTEGRITY_FAILED', 500);
    }
    $decoded = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !hash_equals((string)$row['source_fingerprint'], strtoupper(hash('sha256', $plaintext)))) {
        throw new ModerationSafetyException('Protected report evidence failed integrity validation.', 'REPORT_EVIDENCE_INTEGRITY_FAILED', 500);
    }
    return $decoded;
}

function moderation_safety_validate_evidence(string $type, array $evidence): array
{
    if (!in_array($type, MODERATION_SAFETY_EVIDENCE_TYPES, true)) {
        throw new ModerationSafetyException('The evidence type is not supported.', 'REPORT_EVIDENCE_TYPE_INVALID', 400);
    }
    if ($type === 'webcam-still') {
        if (!empty($evidence['audio']) || !empty($evidence['continuous']) || !isset($evidence['intentionalStill'])) {
            throw new ModerationSafetyException('Webcam evidence is limited to one intentional still without audio.', 'WEBCAM_EVIDENCE_BOUNDARY', 400);
        }
    }
    if ($type === 'message') {
        $context = (array)($evidence['context'] ?? []);
        if (count($context) > 5 || (int)($evidence['precedingCount'] ?? 0) > 2 || (int)($evidence['followingCount'] ?? 0) > 2) {
            throw new ModerationSafetyException('Message evidence exceeds the exact bounded context.', 'MESSAGE_EVIDENCE_CONTEXT_BOUNDARY', 400);
        }
    }
    if ($type === 'e2ee-participant-disclosure' && empty($evidence['participantDisclosed'])) {
        throw new ModerationSafetyException('E2EE evidence requires participant disclosure.', 'E2EE_PARTICIPANT_DISCLOSURE_REQUIRED', 400);
    }
    foreach (['conversationBrowse', 'cookies', 'credentials', 'continuousCapture', 'audioCapture', 'serverKey', 'decryptionBackdoor'] as $prohibited) {
        if (!empty($evidence[$prohibited])) {
            throw new ModerationSafetyException('Report evidence crosses a prohibited privacy boundary.', 'REPORT_EVIDENCE_PRIVACY_BOUNDARY', 400);
        }
    }
    return $evidence;
}

function moderation_safety_submit_report(PDO $pdo, int $reporterUserId, array $input): array
{
    $origin = trim((string)($input['origin_type'] ?? ''));
    if (!in_array($origin, MODERATION_SAFETY_REPORT_ORIGINS, true)) {
        throw new ModerationSafetyException('Choose a supported report origin.', 'REPORT_ORIGIN_INVALID', 400);
    }
    $originReference = trim((string)($input['origin_reference'] ?? ''));
    if ($originReference === '' || strlen($originReference) > 191) {
        throw new ModerationSafetyException('A bounded exact report reference is required.', 'REPORT_REFERENCE_INVALID', 400);
    }
    $reason = moderation_account_bounded_text($input['reason'] ?? '', 2000, 'Report reason', true);
    $hour = $pdo->prepare('SELECT COUNT(*) FROM moderation_reports WHERE reporter_user_id=? AND created_at>=?');
    $hour->execute([$reporterUserId, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$hour->fetchColumn() >= 10) throw new ModerationSafetyException('Report limit reached for this hour.', 'REPORT_HOURLY_LIMIT', 429);
    $day = $pdo->prepare('SELECT COUNT(*) FROM moderation_reports WHERE reporter_user_id=? AND created_at>=?');
    $day->execute([$reporterUserId, gmdate('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$day->fetchColumn() >= 50) throw new ModerationSafetyException('Report limit reached for this day.', 'REPORT_DAILY_LIMIT', 429);

    $publicId = moderation_safety_report_public_id();
    $casePublicId = moderation_account_case_public_id();
    $pdo->prepare(
        "INSERT INTO moderation_cases
         (public_id,subject_user_id,case_type,status,public_reason,private_note,enforcement_reference,revision)
         VALUES (?,?,'report','received',NULL,NULL,?,1)"
    )->execute([$casePublicId, (int)($input['reported_user_id'] ?? $reporterUserId), $publicId]);
    $caseId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO moderation_reports
         (public_id,reporter_user_id,reported_user_id,origin_type,origin_reference,reason,status,case_id)
         VALUES (?,?,?,?,?,?,\'received\',?)'
    )->execute([
        $publicId, $reporterUserId,
        (int)($input['reported_user_id'] ?? 0) ?: null,
        $origin, $originReference, $reason, $caseId,
    ]);
    $reportId = (int)$pdo->lastInsertId();
    $evidence = (array)($input['evidence'] ?? []);
    if ($evidence) {
        $type = (string)($input['evidence_type'] ?? $origin);
        $bounded = moderation_safety_validate_evidence($type, $evidence);
        $sealed = moderation_safety_encrypt_evidence($bounded);
        $pdo->prepare(
            'INSERT INTO moderation_evidence
             (public_id,report_id,evidence_type,source_fingerprint,ciphertext,nonce_b64,tag_b64,schema_version)
             VALUES (?,?,?,?,?,?,?,1)'
        )->execute([
            moderation_safety_evidence_public_id(), $reportId, $type,
            $sealed['fingerprint'], $sealed['ciphertext'], $sealed['nonce'], $sealed['tag'],
        ]);
    }
    log_tool($pdo, $reporterUserId, 'moderation_report_submitted', null, null, "Report: {$publicId}; origin: {$origin}");
    return ['reference' => $publicId, 'status' => 'Received'];
}

function moderation_safety_reporter_projection(PDO $pdo, int $reporterUserId): array
{
    $statement = $pdo->prepare(
        'SELECT public_id,status,created_at,updated_at
         FROM moderation_reports WHERE reporter_user_id=? ORDER BY id DESC LIMIT 100'
    );
    $statement->execute([$reporterUserId]);
    return array_map(static function (array $row): array {
        $row['status'] = match ((string)$row['status']) {
            'received' => 'Received',
            'under-review' => 'Under Review',
            default => 'Closed',
        };
        return $row;
    }, $statement->fetchAll());
}

function moderation_safety_evidence_access(PDO $pdo, int $actorUserId, string $publicId, string $operation, string $reason): ?array
{
    moderation_safety_require_staff_capability($pdo, $actorUserId, 'manage-evidence');
    $reason = moderation_account_bounded_text($reason, 500, 'Evidence access reason', true);
    $statement = $pdo->prepare(
        'SELECT e.*,r.case_id FROM moderation_evidence e
         JOIN moderation_reports r ON r.id=e.report_id WHERE e.public_id=? LIMIT 1'
    );
    $statement->execute([$publicId]);
    $row = $statement->fetch();
    if (!is_array($row)) throw new ModerationSafetyException('Evidence was not found.', 'REPORT_EVIDENCE_NOT_FOUND', 404);
    if (!in_array($operation, ['access', 'export', 'resolve', 'hold', 'delete'], true)) {
        throw new ModerationSafetyException('Evidence operation is invalid.', 'REPORT_EVIDENCE_OPERATION_INVALID', 400);
    }
    if ($operation === 'hold') {
        $pdo->prepare('UPDATE moderation_evidence SET safety_hold=1,hold_reason=? WHERE id=?')->execute([$reason, (int)$row['id']]);
    } elseif ($operation === 'delete') {
        if (!empty($row['safety_hold'])) throw new ModerationSafetyException('Evidence on safety hold cannot be deleted.', 'REPORT_EVIDENCE_HOLD_ACTIVE', 409);
        $pdo->prepare('DELETE FROM moderation_evidence WHERE id=?')->execute([(int)$row['id']]);
    }
    log_tool($pdo, $actorUserId, 'moderation_evidence_' . $operation, null, null, "Evidence: {$publicId}; reason recorded");
    if (in_array($operation, ['access', 'export'], true)) return moderation_safety_decrypt_evidence($row);
    return null;
}

function moderation_safety_set_mute(PDO $pdo, int $muterUserId, int $mutedUserId, string $duration, array $scopes): array
{
    if ($muterUserId === $mutedUserId || !in_array($duration, MODERATION_SAFETY_MUTE_DURATIONS, true)) {
        throw new ModerationSafetyException('Mute target or duration is invalid.', 'PERSONAL_MUTE_INVALID', 400);
    }
    $scopes = array_values(array_unique(array_intersect(MODERATION_SAFETY_MUTE_SCOPES, array_map('strval', $scopes))));
    if (!$scopes) $scopes = MODERATION_SAFETY_MUTE_SCOPES;
    $expiresAt = match ($duration) {
        '1-hour' => gmdate('Y-m-d H:i:s', time() + 3600),
        '24-hours' => gmdate('Y-m-d H:i:s', time() + 86400),
        default => null,
    };
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO personal_mutes (muter_user_id,muted_user_id,scopes_json,expires_at,revision) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE scopes_json=VALUES(scopes_json),expires_at=VALUES(expires_at),revision=revision+1,updated_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO personal_mutes (muter_user_id,muted_user_id,scopes_json,expires_at,revision) VALUES (?,?,?,?,1) ON CONFLICT(muter_user_id,muted_user_id) DO UPDATE SET scopes_json=excluded.scopes_json,expires_at=excluded.expires_at,revision=revision+1,updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([
        $muterUserId, $mutedUserId,
        json_encode($scopes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $expiresAt,
    ]);
    return [
        'muted' => true, 'private' => true, 'targetNotNotified' => true,
        'scopes' => $scopes, 'expiresAt' => $expiresAt,
        'gameSystemTruthHidden' => false, 'oneMessageRevealAllowed' => true,
    ];
}

function moderation_safety_unmute(PDO $pdo, int $muterUserId, int $mutedUserId): void
{
    $pdo->prepare('DELETE FROM personal_mutes WHERE muter_user_id=? AND muted_user_id=?')
        ->execute([$muterUserId, $mutedUserId]);
}

function moderation_safety_mute_projection(PDO $pdo, int $muterUserId): array
{
    $statement = $pdo->prepare(
        'SELECT m.muted_user_id,u.username,u.display_name,m.scopes_json,m.expires_at,m.revision
         FROM personal_mutes m JOIN users u ON u.id=m.muted_user_id
         WHERE m.muter_user_id=? AND (m.expires_at IS NULL OR m.expires_at>CURRENT_TIMESTAMP)
         ORDER BY u.display_name'
    );
    $statement->execute([$muterUserId]);
    return array_map(static function (array $row): array {
        $row['scopes'] = json_decode((string)$row['scopes_json'], true) ?: [];
        unset($row['scopes_json']);
        $row['revision'] = (int)$row['revision'];
        return $row;
    }, $statement->fetchAll());
}

function moderation_safety_set_block(PDO $pdo, int $blockerUserId, int $blockedUserId, bool $blocked): array
{
    if ($blockerUserId === $blockedUserId || $blockedUserId < 1) {
        throw new ModerationSafetyException('A different account is required.', 'BLOCK_TARGET_INVALID', 400);
    }
    if ($blocked) {
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO user_blocks (blocker_user_id,blocked_user_id) VALUES (?,?)';
        $pdo->prepare($sql)->execute([$blockerUserId, $blockedUserId]);
    } else {
        $pdo->prepare('DELETE FROM user_blocks WHERE blocker_user_id=? AND blocked_user_id=?')
            ->execute([$blockerUserId, $blockedUserId]);
    }
    log_tool($pdo, $blockerUserId, $blocked ? 'block_user' : 'unblock_user', $blockedUserId, null, $blocked ? 'User block' : 'User unblock');
    return [
        'blocked' => $blocked,
        'strongerThanMute' => true,
        'prevents' => ['dm', 'relationship', 'invitation', 'direct-file', 'directed-media'],
        'unblockRestoresEligibilityOnly' => !$blocked,
        'historyOrRelationshipRestored' => false,
    ];
}

function moderation_safety_duration_minutes(string $action, mixed $value): ?int
{
    if ($action === 'warn') return null;
    if ($action === 'temporarily-restrict') {
        $minutes = (int)$value;
        if ($minutes < 5 || $minutes > 525600) throw new ModerationSafetyException('Restriction duration must be 5 minutes through 365 days.', 'MODERATION_DURATION_INVALID', 400);
        return $minutes;
    }
    if ($action === 'suspend-account') {
        if ($value === 'indefinite') return null;
        $minutes = (int)$value;
        if (!in_array($minutes, [1440, 10080, 43200], true)) {
            throw new ModerationSafetyException('Suspension duration is invalid.', 'MODERATION_DURATION_INVALID', 400);
        }
        return $minutes;
    }
    return null;
}

function moderation_safety_apply_action(PDO $pdo, int $actorUserId, array $input): array
{
    $requestId = moderation_account_validate_request_id($input['request_id'] ?? null);
    $replay = $pdo->prepare('SELECT public_id,target_revision_after FROM moderation_actions WHERE request_id=?');
    $replay->execute([$requestId]);
    $existing = $replay->fetch();
    if (is_array($existing)) return ['publicId' => $existing['public_id'], 'revision' => (int)$existing['target_revision_after'], 'idempotentReplay' => true];
    $targetUserId = (int)($input['target_user_id'] ?? 0);
    $action = (string)($input['action_type'] ?? '');
    $capability = [
        'warn' => 'warn',
        'temporarily-restrict' => 'temporarily-restrict',
        'suspend-account' => 'suspend-account',
        'undo-eligible-restriction' => 'undo-eligible-restriction',
    ][$action] ?? null;
    if ($capability === null) throw new ModerationSafetyException('Moderation action is invalid.', 'MODERATION_ACTION_INVALID', 400);
    moderation_safety_require_staff_capability($pdo, $actorUserId, $capability);
    if ($targetUserId < 1 || $targetUserId === $actorUserId) throw new ModerationSafetyException('Self-moderation is prohibited.', 'MODERATION_SELF_GUARD', 403);
    if (moderation_identity_is_owner($pdo, $targetUserId)) {
        throw new ModerationSafetyException('The Installation Owner is protected by the last-owner safety boundary.', 'MODERATION_OWNER_GUARD', 403);
    }
    $reason = moderation_account_bounded_text($input['public_reason'] ?? '', 500, 'Public reason', true);
    $internal = moderation_account_bounded_text($input['internal_note'] ?? '', 2000, 'Internal note', false);
    $authorization = moderation_identity_account_authorization($pdo, $targetUserId);
    $expectedRevision = filter_var($input['expected_revision'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedRevision === false || (int)$expectedRevision !== $authorization['trustRevision']) {
        throw new ModerationSafetyException('The target state changed. Refresh before acting.', 'MODERATION_TARGET_STALE', 409, ['currentRevision' => $authorization['trustRevision']]);
    }
    $minutes = moderation_safety_duration_minutes($action, $input['duration'] ?? null);
    $expiresAt = $minutes !== null ? gmdate('Y-m-d H:i:s', time() + $minutes * 60) : null;
    $newState = $authorization['trustState'];
    if ($action === 'temporarily-restrict') $newState = 'restricted';
    if ($action === 'suspend-account') $newState = 'suspended';
    if ($action === 'undo-eligible-restriction') {
        if (!in_array($authorization['trustState'], ['restricted', 'suspended'], true)) {
            throw new ModerationSafetyException('There is no eligible active restriction to undo.', 'MODERATION_UNDO_NOT_ELIGIBLE', 409);
        }
        $newState = 'trusted';
        $expiresAt = null;
    }
    $newRevision = $authorization['trustRevision'] + 1;
    if ($action !== 'warn') {
        $update = $pdo->prepare(
            'UPDATE user_trust SET trust_state=?,restriction_expires_at=?,public_reason=?,revision=?,updated_at=CURRENT_TIMESTAMP
             WHERE user_id=? AND revision=?'
        );
        $update->execute([$newState, $expiresAt, $reason, $newRevision, $targetUserId, $authorization['trustRevision']]);
        if ($update->rowCount() !== 1) throw new ModerationSafetyException('The target state changed. Refresh before acting.', 'MODERATION_TARGET_STALE', 409);
        if ($newState === 'suspended') {
            $pdo->prepare('UPDATE participants SET last_seen_at=NULL WHERE user_id=?')->execute([$targetUserId]);
        }
    } else {
        $newRevision = $authorization['trustRevision'];
    }
    $publicId = 'action-' . strtolower(str_replace('-', '', uuid_v4()));
    $pdo->prepare(
        'INSERT INTO moderation_actions
         (request_id,public_id,actor_user_id,target_user_id,action_type,public_reason,internal_note,duration_minutes,expires_at,target_revision_before,target_revision_after,status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,\'applied\')'
    )->execute([
        $requestId, $publicId, $actorUserId, $targetUserId, $action, $reason,
        $internal !== '' ? $internal : null, $minutes, $expiresAt,
        $authorization['trustRevision'], $newRevision,
    ]);
    moderation_account_create_notice($pdo, $targetUserId, $action, $reason, [], $expiresAt);
    log_tool($pdo, $actorUserId, 'moderation_action_' . $action, $targetUserId, null, "Action: {$publicId}; revision: {$newRevision}");
    return ['publicId' => $publicId, 'revision' => $newRevision, 'idempotentReplay' => false];
}

function moderation_safety_admin_users(PDO $pdo, string $search, string $sort, int $page, int $perPage): array
{
    $search = trim($search);
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;
    $allowedSort = ['name' => 'u.display_name,u.id', 'newest' => 'u.created_at DESC,u.id DESC', 'trust' => 't.trust_state,u.display_name'];
    $order = $allowedSort[$sort] ?? $allowedSort['name'];
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = ' WHERE LOWER(u.username) LIKE ? OR LOWER(u.display_name) LIKE ?';
        $needle = '%' . strtolower($search) . '%';
        $params = [$needle, $needle];
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM users u' . $where);
    $count->execute($params);
    $statement = $pdo->prepare(
        "SELECT u.id,u.username,u.display_name,u.role,u.created_at,
                COALESCE(t.trust_state,'pending-approval') AS trust_state,
                COALESCE(t.revision,1) AS trust_revision,
                CASE WHEN EXISTS (
                    SELECT 1 FROM participants p WHERE p.user_id=u.id AND p.last_seen_at IS NOT NULL
                ) THEN 1 ELSE 0 END AS online
         FROM users u LEFT JOIN user_trust t ON t.user_id=u.id{$where}
         ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}"
    );
    $statement->execute($params);
    return ['users' => $statement->fetchAll(), 'page' => $page, 'perPage' => $perPage, 'total' => (int)$count->fetchColumn()];
}
