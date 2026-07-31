<?php
declare(strict_types=1);

/**
 * Post-Build 000052 opaque-network context and owner-directed manual-ban owner.
 *
 * Network addresses exist only as request-scoped inputs to normalization and
 * keyed derivation. This owner never stores, returns, logs, exports, or accepts
 * a network address.
 */

const NETWORK_MODERATION_SETTING = 'network_manual_bans_enabled';
const NETWORK_MODERATION_REVISION_SETTING = 'network_manual_bans_revision';
const NETWORK_MODERATION_CONTEXT_RETENTION_DAYS = 90;
const NETWORK_MODERATION_PREVIEW_SECONDS = 600;
const NETWORK_MODERATION_DURATION_MINUTES = [60, 360, 1440, 10080, 43200];

function network_moderation_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS network_observation_contexts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                context_key VARCHAR(64) NOT NULL UNIQUE,
                opaque_id VARCHAR(96) NOT NULL,
                context_type VARCHAR(32) NOT NULL,
                context_reference VARCHAR(191) NOT NULL,
                account_user_id INT DEFAULT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                retention_until DATETIME NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                INDEX idx_network_context_opaque (opaque_id, last_seen_at),
                INDEX idx_network_context_account (account_user_id, last_seen_at),
                CONSTRAINT fk_network_context_observation FOREIGN KEY (opaque_id)
                    REFERENCES network_observations(opaque_id) ON DELETE CASCADE,
                CONSTRAINT fk_network_context_account FOREIGN KEY (account_user_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS network_manual_bans (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(96) NOT NULL UNIQUE,
                opaque_id VARCHAR(96) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                reason VARCHAR(500) NOT NULL,
                creator_user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME DEFAULT NULL,
                removed_by_user_id INT DEFAULT NULL,
                removal_reason VARCHAR(500) DEFAULT NULL,
                removed_at DATETIME DEFAULT NULL,
                affected_account_count INT NOT NULL DEFAULT 0,
                affected_account_ids_json LONGTEXT NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                INDEX idx_network_ban_enforcement (opaque_id, status, expires_at),
                CONSTRAINT fk_network_ban_observation FOREIGN KEY (opaque_id)
                    REFERENCES network_observations(opaque_id) ON DELETE RESTRICT,
                CONSTRAINT fk_network_ban_creator FOREIGN KEY (creator_user_id)
                    REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_network_ban_remover FOREIGN KEY (removed_by_user_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS network_ban_previews (
                preview_id VARCHAR(64) PRIMARY KEY,
                owner_user_id INT NOT NULL,
                context_public_id VARCHAR(64) NOT NULL,
                opaque_id VARCHAR(96) NOT NULL,
                duration_minutes INT DEFAULT NULL,
                permanent TINYINT NOT NULL DEFAULT 0,
                reason VARCHAR(500) NOT NULL,
                account_ids_json LONGTEXT NOT NULL,
                impact_sha256 VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_network_preview_owner FOREIGN KEY (owner_user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS network_ban_idempotency (
                request_id VARCHAR(96) PRIMARY KEY,
                operation VARCHAR(32) NOT NULL,
                ban_public_id VARCHAR(64) DEFAULT NULL,
                result_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS network_observation_contexts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            context_key TEXT NOT NULL UNIQUE,
            opaque_id TEXT NOT NULL,
            context_type TEXT NOT NULL,
            context_reference TEXT NOT NULL,
            account_user_id INTEGER DEFAULT NULL,
            first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            retention_until TEXT NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY(opaque_id) REFERENCES network_observations(opaque_id) ON DELETE CASCADE,
            FOREIGN KEY(account_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_network_context_opaque ON network_observation_contexts(opaque_id,last_seen_at)',
        'CREATE INDEX IF NOT EXISTS idx_network_context_account ON network_observation_contexts(account_user_id,last_seen_at)',
        "CREATE TABLE IF NOT EXISTS network_manual_bans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            opaque_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            reason TEXT NOT NULL,
            creator_user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            removed_by_user_id INTEGER DEFAULT NULL,
            removal_reason TEXT DEFAULT NULL,
            removed_at TEXT DEFAULT NULL,
            affected_account_count INTEGER NOT NULL DEFAULT 0,
            affected_account_ids_json TEXT NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY(opaque_id) REFERENCES network_observations(opaque_id) ON DELETE RESTRICT,
            FOREIGN KEY(creator_user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY(removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_network_ban_enforcement ON network_manual_bans(opaque_id,status,expires_at)',
        "CREATE TABLE IF NOT EXISTS network_ban_previews (
            preview_id TEXT PRIMARY KEY,
            owner_user_id INTEGER NOT NULL,
            context_public_id TEXT NOT NULL,
            opaque_id TEXT NOT NULL,
            duration_minutes INTEGER DEFAULT NULL,
            permanent INTEGER NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            account_ids_json TEXT NOT NULL,
            impact_sha256 TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS network_ban_idempotency (
            request_id TEXT PRIMARY KEY,
            operation TEXT NOT NULL,
            ban_public_id TEXT DEFAULT NULL,
            result_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    ];
}

function network_moderation_columns(PDO $pdo, string $table): array
{
    if (!database_migration_table_exists($pdo, $table)) return [];
    if (db_uses_mysql_syntax($pdo)) {
        $rows = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll();
        return array_values(array_map(static fn(array $row): string => (string)$row['Field'], $rows));
    }
    $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    return array_values(array_map(static fn(array $row): string => (string)$row['name'], $rows));
}

function network_moderation_retire_reversible_schema(PDO $pdo): void
{
    $columns = network_moderation_columns($pdo, 'network_observations');
    $reversible = array_intersect(
        ['address_ciphertext', 'address_nonce_b64', 'address_tag_b64'],
        $columns
    );
    if ($reversible) {
        $pdo->exec('DROP TABLE IF EXISTS network_reveal_leases');
        if (db_uses_mysql_syntax($pdo)) {
            $pdo->exec(
                "CREATE TABLE network_observations_opaque_only (
                    opaque_id VARCHAR(96) PRIMARY KEY,
                    key_version INT NOT NULL DEFAULT 1,
                    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    revision BIGINT NOT NULL DEFAULT 1
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE network_observations_opaque_only (
                    opaque_id TEXT PRIMARY KEY,
                    key_version INTEGER NOT NULL DEFAULT 1,
                    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    revision INTEGER NOT NULL DEFAULT 1
                )"
            );
        }
        $pdo->exec(
            'INSERT INTO network_observations_opaque_only
             (opaque_id,key_version,first_seen_at,last_seen_at,revision)
             SELECT opaque_id,key_version,first_seen_at,last_seen_at,revision
             FROM network_observations'
        );
        $pdo->exec('DROP TABLE network_observations');
        if (db_uses_mysql_syntax($pdo)) {
            $pdo->exec('RENAME TABLE network_observations_opaque_only TO network_observations');
        } else {
            $pdo->exec('ALTER TABLE network_observations_opaque_only RENAME TO network_observations');
        }
    }
    if (
        network_moderation_columns($pdo, 'network_reveal_leases') === ['retired_at']
        && (int)$pdo->query('SELECT COUNT(*) FROM network_reveal_leases')->fetchColumn() === 0
    ) {
        return;
    }
    $pdo->exec('DROP TABLE IF EXISTS network_reveal_leases');
    if (db_uses_mysql_syntax($pdo)) {
        $pdo->exec(
            "CREATE TABLE network_reveal_leases (
                retired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } else {
        $pdo->exec(
            'CREATE TABLE network_reveal_leases (
                retired_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}

function network_moderation_install_schema(PDO $pdo): void
{
    foreach (network_moderation_schema_statements($pdo) as $statement) $pdo->exec($statement);
    foreach ([
        NETWORK_MODERATION_SETTING => '0',
        NETWORK_MODERATION_REVISION_SETTING => '1',
    ] as $key => $value) {
        $insert = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)';
        $pdo->prepare($insert)->execute([$key, $value]);
    }
    set_app_setting($pdo, 'network_exact_ip_access_enabled', '0');
    set_app_setting(
        $pdo,
        'network_exact_ip_reveal_minutes',
        (string)NETWORK_PRIVACY_DEFAULT_REVEAL_MINUTES
    );
}

function network_moderation_backfill_contexts(PDO $pdo): void
{
    if (!database_migration_table_exists($pdo, 'runtime_issue_occurrences')) return;
    $columns = network_moderation_columns($pdo, 'runtime_issue_occurrences');
    if (!in_array('opaque_network_id', $columns, true)) return;
    $rows = $pdo->query(
        "SELECT id,reporter_user_id,opaque_network_id,created_at
         FROM runtime_issue_occurrences
         WHERE opaque_network_id IS NOT NULL AND opaque_network_id<>''"
    )->fetchAll();
    foreach ($rows as $row) {
        $opaqueId = trim((string)$row['opaque_network_id']);
        if ($opaqueId === '') continue;
        $exists = $pdo->prepare('SELECT 1 FROM network_observations WHERE opaque_id=?');
        $exists->execute([$opaqueId]);
        if ($exists->fetchColumn() === false) continue;
        network_moderation_record_context(
            $pdo,
            $opaqueId,
            'runtime-occurrence',
            'Runtime occurrence #' . (int)$row['id'],
            (int)($row['reporter_user_id'] ?? 0) ?: null,
            'runtime-occurrence:' . (int)$row['id'],
            (string)$row['created_at']
        );
    }
}

function network_moderation_schema_valid(PDO $pdo): bool
{
    foreach ([
        'network_observations',
        'network_reveal_leases',
        'network_privacy_events',
        'network_observation_contexts',
        'network_manual_bans',
        'network_ban_previews',
        'network_ban_idempotency',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    $observationColumns = network_moderation_columns($pdo, 'network_observations');
    if (array_intersect(
        ['address_ciphertext', 'address_nonce_b64', 'address_tag_b64'],
        $observationColumns
    )) return false;
    if ($observationColumns !== ['opaque_id', 'key_version', 'first_seen_at', 'last_seen_at', 'revision']) {
        return false;
    }
    if (network_moderation_columns($pdo, 'network_reveal_leases') !== ['retired_at']) return false;
    if ((int)$pdo->query('SELECT COUNT(*) FROM network_reveal_leases')->fetchColumn() !== 0) return false;
    return app_setting($pdo, 'network_exact_ip_access_enabled', '') === '0'
        && in_array(app_setting($pdo, NETWORK_MODERATION_SETTING, ''), ['0', '1'], true)
        && (int)app_setting($pdo, NETWORK_MODERATION_REVISION_SETTING, '0') >= 1;
}

function network_moderation_observe(PDO $pdo, string $requestAddress): string
{
    $normalized = network_privacy_normalize_ip($requestAddress);
    if ($normalized === null) {
        throw new NetworkPrivacyException('The network address is invalid.', 'NETWORK_ADDRESS_INVALID', 422);
    }
    $policy = network_privacy_policy();
    $keyVersion = (int)$policy['activeOpaqueKeyVersion'];
    $opaqueId = network_privacy_opaque_identifier_for_version($normalized, $keyVersion);
    $update = $pdo->prepare(
        'UPDATE network_observations
         SET last_seen_at=CURRENT_TIMESTAMP,revision=revision+1
         WHERE opaque_id=?'
    );
    $update->execute([$opaqueId]);
    if ($update->rowCount() < 1) {
        try {
            $pdo->prepare(
                'INSERT INTO network_observations (opaque_id,key_version) VALUES (?,?)'
            )->execute([$opaqueId, $keyVersion]);
        } catch (PDOException $error) {
            $update->execute([$opaqueId]);
            if ($update->rowCount() < 1) throw $error;
        }
    }
    return $opaqueId;
}

function network_moderation_record_context(
    PDO $pdo,
    string $opaqueId,
    string $contextType,
    string $contextReference,
    ?int $accountUserId,
    string $sourceKey,
    ?string $firstSeenAt = null
): string {
    $contextType = trim($contextType);
    if (!in_array(
        $contextType,
        ['authentication', 'application-request', 'runtime-occurrence', 'moderation-record'],
        true
    )) {
        throw new NetworkPrivacyException(
            'Choose a supported opaque-network context.',
            'NETWORK_CONTEXT_TYPE_INVALID',
            422
        );
    }
    $contextReference = trim($contextReference);
    if ($contextReference === '' || mb_strlen($contextReference) > 191) {
        throw new NetworkPrivacyException(
            'A bounded opaque-network context reference is required.',
            'NETWORK_CONTEXT_REFERENCE_INVALID',
            422
        );
    }
    $contextKey = hash('sha256', $contextType . "\n" . $sourceKey);
    $existing = $pdo->prepare(
        'SELECT public_id FROM network_observation_contexts WHERE context_key=? LIMIT 1'
    );
    $existing->execute([$contextKey]);
    $publicId = $existing->fetchColumn();
    $retentionUntil = gmdate(
        'Y-m-d H:i:s',
        time() + (NETWORK_MODERATION_CONTEXT_RETENTION_DAYS * 86400)
    );
    if (is_string($publicId)) {
        $pdo->prepare(
            'UPDATE network_observation_contexts
             SET last_seen_at=CURRENT_TIMESTAMP,retention_until=?,revision=revision+1
             WHERE context_key=?'
        )->execute([$retentionUntil, $contextKey]);
        return $publicId;
    }
    $publicId = 'network-context-' . strtolower(str_replace('-', '', uuid_v4()));
    $pdo->prepare(
        'INSERT INTO network_observation_contexts
         (public_id,context_key,opaque_id,context_type,context_reference,account_user_id,
          first_seen_at,last_seen_at,retention_until)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $publicId,
        $contextKey,
        $opaqueId,
        $contextType,
        $contextReference,
        $accountUserId,
        $firstSeenAt ?: gmdate('Y-m-d H:i:s'),
        $firstSeenAt ?: gmdate('Y-m-d H:i:s'),
        $retentionUntil,
    ]);
    return $publicId;
}

function network_moderation_observe_request(
    PDO $pdo,
    int $accountUserId,
    string $contextType = 'application-request',
    ?string $sourceKey = null,
    ?string $contextReference = null
): array {
    $opaqueId = network_moderation_observe($pdo, network_privacy_client_ip());
    $sourceKey ??= $contextType . ':account:' . $accountUserId . ':day:' . gmdate('Y-m-d');
    $contextReference ??= $contextType === 'authentication'
        ? 'Authentication for selected account #' . $accountUserId
        : 'Application requests for selected account #' . $accountUserId;
    $contextId = network_moderation_record_context(
        $pdo,
        $opaqueId,
        $contextType,
        $contextReference,
        $accountUserId,
        $sourceKey
    );
    return ['opaqueId' => $opaqueId, 'contextId' => $contextId];
}

function network_moderation_observe_request_if_due(
    PDO $pdo,
    int $accountUserId,
    int $minimumIntervalSeconds = 300
): ?array {
    $normalized = network_privacy_normalize_ip(network_privacy_client_ip());
    if ($normalized === null) {
        throw new NetworkPrivacyException(
            'The network address is invalid.',
            'NETWORK_ADDRESS_INVALID',
            422
        );
    }
    $policy = network_privacy_policy();
    $opaqueId = network_privacy_opaque_identifier_for_version(
        $normalized,
        (int)$policy['activeOpaqueKeyVersion']
    );
    $last = $_SESSION['_network_observation'] ?? null;
    $now = time();
    $due = !is_array($last)
        || (int)($last['accountUserId'] ?? 0) !== $accountUserId
        || !hash_equals((string)($last['opaqueId'] ?? ''), $opaqueId)
        || ($now - (int)($last['observedAt'] ?? 0)) >= max(60, $minimumIntervalSeconds);
    if (!$due) return null;
    $result = network_moderation_observe_request($pdo, $accountUserId);
    $_SESSION['_network_observation'] = [
        'accountUserId' => $accountUserId,
        'opaqueId' => $opaqueId,
        'observedAt' => $now,
    ];
    return $result;
}

function network_moderation_enabled(PDO $pdo): bool
{
    return app_setting($pdo, NETWORK_MODERATION_SETTING, '0') === '1';
}

function network_moderation_set_enabled_locked(
    PDO $pdo,
    int $ownerUserId,
    bool $enabled,
    bool $confirmedDisable = false
): array {
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    network_moderation_expire_bans($pdo);
    if (!$enabled) {
        $active = (int)$pdo->query(
            "SELECT COUNT(*) FROM network_manual_bans WHERE status='active'"
        )->fetchColumn();
        if ($active > 0 && !$confirmedDisable) {
            throw new NetworkPrivacyException(
                'Review and confirm that disabling Manual Network Bans stops enforcement for active bans.',
                'NETWORK_MANUAL_BANS_DISABLE_CONFIRMATION_REQUIRED',
                409,
                ['activeBanCount' => $active]
            );
        }
    }
    set_app_setting($pdo, NETWORK_MODERATION_SETTING, $enabled ? '1' : '0');
    $revision = max(1, (int)app_setting($pdo, NETWORK_MODERATION_REVISION_SETTING, '1')) + 1;
    set_app_setting($pdo, NETWORK_MODERATION_REVISION_SETTING, (string)$revision);
    network_privacy_record_event(
        $pdo,
        $ownerUserId,
        'manual_bans_policy_update',
        null,
        null,
        ['revision' => $revision, 'result' => $enabled ? 'enabled' : 'disabled']
    );
    return [
        'enabled' => $enabled,
        'revision' => $revision,
        'activeBanCount' => $enabled ? network_moderation_active_ban_count($pdo) : 0,
    ];
}

function network_moderation_active_ban_count(PDO $pdo): int
{
    network_moderation_expire_bans($pdo);
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM network_manual_bans WHERE status='active'"
    )->fetchColumn();
}

function network_moderation_expire_bans(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE network_manual_bans
         SET status='expired',revision=revision+1
         WHERE status='active' AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP"
    );
}

function network_moderation_policy_projection(PDO $pdo, int $ownerUserId): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    return [
        'enabled' => network_moderation_enabled($pdo),
        'revision' => max(1, (int)app_setting($pdo, NETWORK_MODERATION_REVISION_SETTING, '1')),
        'automaticBanning' => false,
        'addressEntryOrDisplay' => false,
        'requiresRecentAuthentication' => true,
        'durationOptions' => [
            ['minutes' => 60, 'label' => '1 hour'],
            ['minutes' => 360, 'label' => '6 hours'],
            ['minutes' => 1440, 'label' => '24 hours'],
            ['minutes' => 10080, 'label' => '7 days'],
            ['minutes' => 43200, 'label' => '30 days'],
            ['minutes' => null, 'label' => 'Permanent'],
        ],
        'sharedNetworkWarning' => 'A network can be shared by many people and accounts. Correlation is uncertain and does not identify a person or household.',
    ];
}

function network_moderation_account_ids(PDO $pdo, string $opaqueId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT account_user_id
         FROM network_observation_contexts
         WHERE opaque_id=? AND account_user_id IS NOT NULL
           AND retention_until>CURRENT_TIMESTAMP
         ORDER BY account_user_id'
    );
    $statement->execute([$opaqueId]);
    return array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))));
}

function network_moderation_account_projection(PDO $pdo, array $accountIds): array
{
    if (!$accountIds) return [];
    $statement = $pdo->prepare(
        'SELECT id,display_name,username,role FROM users
         WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
         ORDER BY LOWER(display_name),id'
    );
    $statement->execute($accountIds);
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'displayName' => (string)$row['display_name'],
        'username' => (string)($row['username'] ?? ''),
        'role' => (string)$row['role'],
    ], $statement->fetchAll());
}

function network_moderation_contexts_projection(PDO $pdo, int $ownerUserId): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    network_moderation_expire_bans($pdo);
    $rows = $pdo->query(
        "SELECT c.*,u.display_name,u.username,u.role,
                CASE WHEN EXISTS (
                    SELECT 1 FROM network_manual_bans b
                    WHERE b.opaque_id=c.opaque_id AND b.status='active'
                ) THEN 1 ELSE 0 END AS manually_banned
         FROM network_observation_contexts c
         LEFT JOIN users u ON u.id=c.account_user_id
         WHERE c.retention_until>CURRENT_TIMESTAMP
         ORDER BY c.last_seen_at DESC,c.id DESC
         LIMIT 250"
    )->fetchAll();
    $projection = [];
    foreach ($rows as $row) {
        $accountIds = network_moderation_account_ids($pdo, (string)$row['opaque_id']);
        $selectedAccount = (int)($row['account_user_id'] ?? 0);
        $owner = $selectedAccount > 0
            ? 'Selected account ' . (string)($row['display_name'] ?: '#' . $selectedAccount)
            : (string)$row['context_reference'];
        $projection[] = [
            'id' => (string)$row['public_id'],
            'owner' => $owner,
            'contextType' => (string)$row['context_type'],
            'context' => (string)$row['context_reference'],
            'selectedAccount' => $selectedAccount > 0 ? [
                'id' => $selectedAccount,
                'displayName' => (string)($row['display_name'] ?? ''),
                'username' => (string)($row['username'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
            ] : null,
            'firstSeenAt' => (string)$row['first_seen_at'],
            'lastSeenAt' => (string)$row['last_seen_at'],
            'manualBanStatus' => (int)$row['manually_banned'] === 1 ? 'Active' : 'Not active',
            'observedForMultipleAccounts' => count($accountIds) > 1,
            'affectedAccountCount' => count($accountIds),
            'affectedAccounts' => network_moderation_account_projection($pdo, $accountIds),
            'privacyNotice' => 'This keyed identifier is not an address and does not uniquely identify a person or household.',
        ];
    }
    return $projection;
}

function network_moderation_validate_reason(string $reason): string
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) {
        throw new NetworkPrivacyException(
            'A reason of 1 through 500 characters is required.',
            'NETWORK_MANUAL_BAN_REASON_REQUIRED',
            422
        );
    }
    return $reason;
}

function network_moderation_validate_duration(mixed $durationMinutes, bool $permanent): ?int
{
    if ($permanent) return null;
    $duration = filter_var($durationMinutes, FILTER_VALIDATE_INT);
    if ($duration === false || !in_array((int)$duration, NETWORK_MODERATION_DURATION_MINUTES, true)) {
        throw new NetworkPrivacyException(
            'Choose a supported temporary duration or Permanent.',
            'NETWORK_MANUAL_BAN_DURATION_INVALID',
            422
        );
    }
    return (int)$duration;
}

function network_moderation_context_row(PDO $pdo, string $contextId): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM network_observation_contexts
         WHERE public_id=? AND retention_until>CURRENT_TIMESTAMP LIMIT 1'
    );
    $statement->execute([trim($contextId)]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new NetworkPrivacyException(
            'The selected account or activity context is unavailable.',
            'NETWORK_CONTEXT_NOT_FOUND',
            404
        );
    }
    return $row;
}

function network_moderation_preview_ban(
    PDO $pdo,
    int $ownerUserId,
    string $contextId,
    string $reason,
    mixed $durationMinutes,
    bool $permanent
): array {
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    if (!network_moderation_enabled($pdo)) {
        throw new NetworkPrivacyException(
            'Enable Manual Network Bans before preparing a ban.',
            'NETWORK_MANUAL_BANS_DISABLED',
            403
        );
    }
    $reason = network_moderation_validate_reason($reason);
    $duration = network_moderation_validate_duration($durationMinutes, $permanent);
    $context = network_moderation_context_row($pdo, $contextId);
    $accountIds = network_moderation_account_ids($pdo, (string)$context['opaque_id']);
    if (in_array($ownerUserId, $accountIds, true)) {
        throw new NetworkPrivacyException(
            'This action would include the Installation Owner account and is blocked to prevent self-lockout.',
            'NETWORK_OWNER_SELF_LOCKOUT_BLOCKED',
            409,
            ['affectedAccountCount' => count($accountIds)]
        );
    }
    $previewId = 'network-preview-' . strtolower(str_replace('-', '', uuid_v4()));
    $impactMaterial = json_encode([
        'context' => (string)$context['public_id'],
        'opaque' => (string)$context['opaque_id'],
        'accounts' => $accountIds,
        'duration' => $duration,
        'permanent' => $permanent,
        'reasonSha256' => strtoupper(hash('sha256', $reason)),
    ], JSON_UNESCAPED_SLASHES);
    $impactSha = strtoupper(hash('sha256', (string)$impactMaterial));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + NETWORK_MODERATION_PREVIEW_SECONDS);
    $pdo->prepare(
        'INSERT INTO network_ban_previews
         (preview_id,owner_user_id,context_public_id,opaque_id,duration_minutes,permanent,
          reason,account_ids_json,impact_sha256,expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $previewId,
        $ownerUserId,
        (string)$context['public_id'],
        (string)$context['opaque_id'],
        $duration,
        $permanent ? 1 : 0,
        $reason,
        json_encode($accountIds, JSON_UNESCAPED_SLASHES),
        $impactSha,
        $expiresAt,
    ]);
    return [
        'previewId' => $previewId,
        'impactSha256' => $impactSha,
        'contextOwner' => (string)$context['context_reference'],
        'affectedAccountCount' => count($accountIds),
        'affectedAccounts' => network_moderation_account_projection($pdo, $accountIds),
        'durationMinutes' => $duration,
        'permanent' => $permanent,
        'expiresAt' => $expiresAt,
        'warning' => 'A shared network can affect several people and accounts. Confirm only after reviewing every listed account.',
    ];
}

function network_moderation_idempotent_result(
    PDO $pdo,
    string $requestId,
    string $operation
): ?array {
    $statement = $pdo->prepare(
        'SELECT result_json FROM network_ban_idempotency
         WHERE request_id=? AND operation=? LIMIT 1'
    );
    $statement->execute([$requestId, $operation]);
    $json = $statement->fetchColumn();
    if (!is_string($json)) return null;
    $result = json_decode($json, true);
    return is_array($result) ? array_replace($result, ['idempotentReplay' => true]) : null;
}

function network_moderation_store_idempotent_result(
    PDO $pdo,
    string $requestId,
    string $operation,
    ?string $banId,
    array $result
): void {
    $pdo->prepare(
        'INSERT INTO network_ban_idempotency
         (request_id,operation,ban_public_id,result_json) VALUES (?,?,?,?)'
    )->execute([
        $requestId,
        $operation,
        $banId,
        json_encode($result, JSON_UNESCAPED_SLASHES),
    ]);
}

function network_moderation_apply_ban(
    PDO $pdo,
    int $ownerUserId,
    string $previewId,
    string $impactSha256,
    string $requestId,
    bool $confirmed
): array {
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    if (!$confirmed) {
        throw new NetworkPrivacyException(
            'Deliberate confirmation is required.',
            'NETWORK_MANUAL_BAN_CONFIRMATION_REQUIRED',
            409
        );
    }
    if (!preg_match('/^[A-Za-z0-9._:-]{12,96}$/', $requestId)) {
        throw new NetworkPrivacyException(
            'A durable request identifier is required.',
            'NETWORK_MANUAL_BAN_REQUEST_INVALID',
            422
        );
    }
    $replay = network_moderation_idempotent_result($pdo, $requestId, 'apply');
    if ($replay !== null) return $replay;
    if (!network_moderation_enabled($pdo)) {
        throw new NetworkPrivacyException(
            'Manual Network Bans were disabled before confirmation.',
            'NETWORK_MANUAL_BANS_DISABLED',
            409
        );
    }
    $transaction = database_transaction_begin($pdo, true);
    try {
        $sql = 'SELECT * FROM network_ban_previews WHERE preview_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute([$previewId]);
        $preview = $statement->fetch();
        if (is_array($preview) && $preview['used_at'] !== null) {
            $lockedReplay = network_moderation_idempotent_result($pdo, $requestId, 'apply');
            if ($lockedReplay !== null) {
                database_transaction_commit($pdo, $transaction);
                return $lockedReplay;
            }
        }
        if (!is_array($preview)
            || (int)$preview['owner_user_id'] !== $ownerUserId
            || $preview['used_at'] !== null
            || strtotime((string)$preview['expires_at'] . ' UTC') <= time()
            || !hash_equals((string)$preview['impact_sha256'], strtoupper(trim($impactSha256)))) {
            throw new NetworkPrivacyException(
                'The reviewed impact expired or changed. Prepare a new preview.',
                'NETWORK_MANUAL_BAN_PREVIEW_STALE',
                409
            );
        }
        $accountIds = network_moderation_account_ids($pdo, (string)$preview['opaque_id']);
        $storedAccountIds = json_decode((string)$preview['account_ids_json'], true);
        $storedAccountIds = is_array($storedAccountIds) ? array_map('intval', $storedAccountIds) : [];
        if ($accountIds !== $storedAccountIds || in_array($ownerUserId, $accountIds, true)) {
            throw new NetworkPrivacyException(
                'The affected-account impact changed. Prepare a new preview.',
                'NETWORK_MANUAL_BAN_IMPACT_CHANGED',
                409
            );
        }
        if (db_uses_mysql_syntax($pdo)) {
            $opaqueLock = $pdo->prepare(
                'SELECT opaque_id FROM network_observations WHERE opaque_id=? FOR UPDATE'
            );
            $opaqueLock->execute([(string)$preview['opaque_id']]);
            if ($opaqueLock->fetchColumn() === false) {
                throw new NetworkPrivacyException(
                    'The selected opaque-network observation is unavailable.',
                    'NETWORK_CONTEXT_NOT_FOUND',
                    404
                );
            }
        }
        $activeSql =
            "SELECT public_id FROM network_manual_bans
             WHERE opaque_id=? AND status='active'
               AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
             LIMIT 1";
        if (db_uses_mysql_syntax($pdo)) $activeSql .= ' FOR UPDATE';
        $active = $pdo->prepare($activeSql);
        $active->execute([(string)$preview['opaque_id']]);
        if ($active->fetchColumn() !== false) {
            throw new NetworkPrivacyException(
                'An active manual ban already covers the selected network context.',
                'NETWORK_MANUAL_BAN_ALREADY_ACTIVE',
                409
            );
        }
        $banId = 'network-ban-' . strtolower(str_replace('-', '', uuid_v4()));
        $expiresAt = !empty($preview['permanent'])
            ? null
            : gmdate('Y-m-d H:i:s', time() + ((int)$preview['duration_minutes'] * 60));
        $pdo->prepare(
            'INSERT INTO network_manual_bans
             (public_id,request_id,opaque_id,reason,creator_user_id,expires_at,
              affected_account_count,affected_account_ids_json)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $banId,
            $requestId,
            (string)$preview['opaque_id'],
            (string)$preview['reason'],
            $ownerUserId,
            $expiresAt,
            count($accountIds),
            json_encode($accountIds, JSON_UNESCAPED_SLASHES),
        ]);
        $pdo->prepare(
            'UPDATE network_ban_previews SET used_at=CURRENT_TIMESTAMP WHERE preview_id=?'
        )->execute([$previewId]);
        foreach ($accountIds as $accountId) {
            retention_lifecycle_revoke_sessions(
                $pdo,
                $ownerUserId,
                $accountId,
                $requestId . ':account:' . $accountId,
                'Manual opaque-network ban application'
            );
        }
        $result = [
            'banId' => $banId,
            'status' => 'active',
            'affectedAccountCount' => count($accountIds),
            'expiresAt' => $expiresAt,
            'idempotentReplay' => false,
        ];
        network_moderation_store_idempotent_result($pdo, $requestId, 'apply', $banId, $result);
        network_privacy_record_event(
            $pdo,
            $ownerUserId,
            'manual_ban_apply',
            (string)$preview['opaque_id'],
            (string)$preview['reason'],
            ['durationMinutes' => $preview['duration_minutes'], 'result' => 'active']
        );
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function network_moderation_remove_ban(
    PDO $pdo,
    int $ownerUserId,
    string $banId,
    string $reason,
    string $requestId,
    bool $confirmed
): array {
    network_privacy_require_owner($pdo, $ownerUserId);
    security_require_recent_authentication();
    if (!$confirmed) {
        throw new NetworkPrivacyException(
            'Deliberate confirmation is required.',
            'NETWORK_MANUAL_BAN_REMOVAL_CONFIRMATION_REQUIRED',
            409
        );
    }
    $reason = network_moderation_validate_reason($reason);
    if (!preg_match('/^[A-Za-z0-9._:-]{12,96}$/', $requestId)) {
        throw new NetworkPrivacyException(
            'A durable request identifier is required.',
            'NETWORK_MANUAL_BAN_REQUEST_INVALID',
            422
        );
    }
    $replay = network_moderation_idempotent_result($pdo, $requestId, 'remove');
    if ($replay !== null) return $replay;
    $transaction = database_transaction_begin($pdo, true);
    try {
        $sql = 'SELECT * FROM network_manual_bans WHERE public_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute([$banId]);
        $ban = $statement->fetch();
        if (!is_array($ban)) {
            throw new NetworkPrivacyException(
                'The selected manual ban was not found.',
                'NETWORK_MANUAL_BAN_NOT_FOUND',
                404
            );
        }
        if ((string)$ban['status'] !== 'active') {
            $lockedReplay = network_moderation_idempotent_result($pdo, $requestId, 'remove');
            if ($lockedReplay !== null) {
                database_transaction_commit($pdo, $transaction);
                return $lockedReplay;
            }
        }
        if ((string)$ban['status'] === 'active') {
            $pdo->prepare(
                "UPDATE network_manual_bans
                 SET status='removed',removed_by_user_id=?,removal_reason=?,
                     removed_at=CURRENT_TIMESTAMP,revision=revision+1
                 WHERE public_id=? AND status='active'"
            )->execute([$ownerUserId, $reason, $banId]);
        }
        $result = [
            'banId' => $banId,
            'status' => (string)$ban['status'] === 'active' ? 'removed' : (string)$ban['status'],
            'idempotentReplay' => false,
        ];
        network_moderation_store_idempotent_result($pdo, $requestId, 'remove', $banId, $result);
        network_privacy_record_event(
            $pdo,
            $ownerUserId,
            'manual_ban_remove',
            (string)$ban['opaque_id'],
            $reason,
            ['result' => $result['status']]
        );
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function network_moderation_bans_projection(PDO $pdo, int $ownerUserId): array
{
    network_privacy_require_owner($pdo, $ownerUserId);
    network_moderation_expire_bans($pdo);
    $rows = $pdo->query(
        'SELECT b.*,creator.display_name AS creator_name,remover.display_name AS remover_name
         FROM network_manual_bans b
         JOIN users creator ON creator.id=b.creator_user_id
         LEFT JOIN users remover ON remover.id=b.removed_by_user_id
         ORDER BY b.created_at DESC,b.id DESC
         LIMIT 250'
    )->fetchAll();
    return array_map(static function (array $row) use ($pdo): array {
        $accountIds = json_decode((string)$row['affected_account_ids_json'], true);
        $accountIds = is_array($accountIds) ? array_map('intval', $accountIds) : [];
        return [
            'id' => (string)$row['public_id'],
            'status' => (string)$row['status'],
            'reason' => (string)$row['reason'],
            'creator' => (string)$row['creator_name'],
            'createdAt' => (string)$row['created_at'],
            'expiresAt' => $row['expires_at'],
            'removedBy' => $row['remover_name'],
            'removalReason' => $row['removal_reason'],
            'removedAt' => $row['removed_at'],
            'affectedAccountCount' => (int)$row['affected_account_count'],
            'affectedAccounts' => network_moderation_account_projection($pdo, $accountIds),
            'privacyNotice' => 'This restriction uses a keyed opaque network identity; no address is retained or displayed.',
        ];
    }, $rows);
}

function network_moderation_assert_request_allowed(
    PDO $pdo,
    int $userId,
    string $requestAddress
): void {
    if (!network_moderation_enabled($pdo) || moderation_identity_is_owner($pdo, $userId)) return;
    foreach (network_privacy_opaque_identifier_candidates($requestAddress) as $opaqueId) {
        $statement = $pdo->prepare(
            "SELECT public_id FROM network_manual_bans
             WHERE opaque_id=? AND status='active'
               AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)
             LIMIT 1"
        );
        $statement->execute([$opaqueId]);
        if ($statement->fetchColumn() !== false) {
            throw new NetworkPrivacyException(
                'Access from this network is restricted by an Installation Owner moderation action.',
                'NETWORK_MANUAL_BAN_ENFORCED',
                403
            );
        }
    }
}
