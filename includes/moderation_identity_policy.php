<?php
declare(strict_types=1);

/**
 * Build 000051 identity, legal policy, registration, ownership, role, trust,
 * and content-capability owner.
 */

const MODERATION_IDENTITY_TERMS_VERSION = '1.00';
const MODERATION_IDENTITY_RULES_VERSION = '1.00';
const MODERATION_IDENTITY_REGISTRATION_MODE_SETTING = 'moderation_trust_registration_mode';
const MODERATION_IDENTITY_SETUP_PRESET_SETTING = 'moderation_trust_setup_preset';
const MODERATION_IDENTITY_OWNER_SELECTION_SETTING = 'build_000051_installation_owner_selection';
const MODERATION_IDENTITY_REGISTRATION_MODES = [
    'open',
    'approval',
    'invitation-only',
    'administrator-created-only',
];
const MODERATION_IDENTITY_TRUST_STATES = [
    'pending-approval',
    'trusted',
    'restricted',
    'suspended',
];

final class ModerationIdentityPolicyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MODERATION_IDENTITY_POLICY_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function moderation_identity_policy_documents(): array
{
    return [
        'terms' => [
            'id' => 'terms',
            'title' => 'Terms of Use',
            'version' => MODERATION_IDENTITY_TERMS_VERSION,
            'material' => true,
            'path' => dirname(__DIR__) . '/policies/terms-1.00.md',
        ],
        'community-rules' => [
            'id' => 'community-rules',
            'title' => 'Community Rules',
            'version' => MODERATION_IDENTITY_RULES_VERSION,
            'material' => true,
            'path' => dirname(__DIR__) . '/policies/community-rules-1.00.md',
        ],
    ];
}

function moderation_identity_load_policy_document(string $id): array
{
    $definition = moderation_identity_policy_documents()[$id] ?? null;
    if (!is_array($definition) || !is_file($definition['path']) || !is_readable($definition['path'])) {
        throw new ModerationIdentityPolicyException(
            'The authoritative policy document is unavailable. Account creation and reacceptance are paused.',
            'POLICY_DOCUMENT_UNAVAILABLE',
            503,
            ['documentId' => $id]
        );
    }
    $content = file_get_contents($definition['path']);
    if (!is_string($content) || trim($content) === '' || strlen($content) > 131072) {
        throw new ModerationIdentityPolicyException(
            'The authoritative policy document is invalid. Account creation and reacceptance are paused.',
            'POLICY_DOCUMENT_INVALID',
            503,
            ['documentId' => $id]
        );
    }
    $definition['content'] = str_replace("\r\n", "\n", $content);
    $definition['sha256'] = strtoupper(hash('sha256', $definition['content']));
    return $definition;
}

function moderation_identity_current_policy_bundle(): array
{
    $terms = moderation_identity_load_policy_document('terms');
    $rules = moderation_identity_load_policy_document('community-rules');
    return [
        'schemaId' => 'chatspace.policy-bundle',
        'schemaVersion' => 1,
        'terms' => $terms,
        'communityRules' => $rules,
        'acceptanceFields' => ['userId', 'termsVersion', 'rulesVersion', 'acceptedAt', 'reason'],
    ];
}

function moderation_identity_role_catalog(): array
{
    return [
        'admin' => ['label' => 'Administrator', 'policyAuthority' => true],
        'moderator' => ['label' => 'Moderator', 'policyAuthority' => false],
        'developer' => ['label' => 'Developer', 'policyAuthority' => false],
        'guide' => ['label' => 'Guide', 'policyAuthority' => false],
        'user' => ['label' => 'Standard User', 'policyAuthority' => false],
    ];
}

function moderation_identity_staff_capability_defaults(): array
{
    return [
        'admin' => [
            'warn', 'temporarily-restrict', 'remove-from-room',
            'suspend-account', 'review-reports', 'view-moderation-history',
            'undo-eligible-restriction', 'manage-evidence', 'server-policy',
            'view-runtime-issues', 'manage-runtime-issues',
            'export-runtime-issues', 'manage-runtime-evidence',
        ],
        'moderator' => [
            'warn', 'temporarily-restrict', 'remove-from-room',
            'review-reports', 'view-moderation-history',
        ],
        'developer' => [
            'view-runtime-issues', 'manage-runtime-issues',
            'export-runtime-issues',
        ],
        'guide' => [],
        'user' => [],
    ];
}

function moderation_identity_setup_presets(): array
{
    return [
        'open-community' => [
            'label' => 'Open Community (Original-style)',
            'registrationMode' => 'open',
            'outsideConfirmationMode' => 'disabled',
            'newUserTrust' => 'trusted',
        ],
        'private' => [
            'label' => 'Private',
            'registrationMode' => 'invitation-only',
            'outsideConfirmationMode' => 'disabled',
            'newUserTrust' => 'pending-approval',
        ],
        'small-trusted' => [
            'label' => 'Small Trusted',
            'registrationMode' => 'approval',
            'outsideConfirmationMode' => 'public-only',
            'newUserTrust' => 'pending-approval',
        ],
        'public' => [
            'label' => 'Public',
            'registrationMode' => 'approval',
            'outsideConfirmationMode' => 'every-upload-import',
            'newUserTrust' => 'pending-approval',
        ],
        'custom' => [
            'label' => 'Custom',
            'registrationMode' => null,
            'outsideConfirmationMode' => null,
            'newUserTrust' => null,
        ],
    ];
}

function moderation_identity_separate_content_controls(): array
{
    return [
        'documents' => ['policyOwner' => 'document-upload-policy', 'separatelyEnforceable' => true],
        'voice-notes' => ['policyOwner' => 'voice-note-policy', 'separatelyEnforceable' => true],
        'links' => ['policyOwner' => 'link-content-policy', 'separatelyEnforceable' => true],
        'remote-avatars' => ['policyOwner' => 'remote-avatar-policy', 'separatelyEnforceable' => true],
        'p2p-avatar-delivery' => ['policyOwner' => 'future-p2p-avatar-owner', 'available' => false],
        'p2p-gesture-delivery' => ['policyOwner' => 'future-p2p-gesture-owner', 'available' => false],
    ];
}

function moderation_identity_preset_registry_values(string $preset): array
{
    $definition = moderation_identity_setup_presets()[$preset] ?? null;
    if (!is_array($definition)) {
        throw new ModerationIdentityPolicyException(
            'Choose a supported community trust preset.',
            'MODERATION_TRUST_PRESET_INVALID',
            400
        );
    }
    if ($preset === 'custom') return [];
    return [
        MODERATION_IDENTITY_REGISTRATION_MODE_SETTING => $definition['registrationMode'],
        MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING => $definition['outsideConfirmationMode'],
    ];
}

function moderation_identity_detect_fresh_default_context(PDO $pdo): bool
{
    try {
        if (function_exists('database_migration_table_exists')
            && database_migration_table_exists($pdo, 'core_migration_attempts')) {
            $attempt = $pdo->query(
                "SELECT source_variant, backup_public_id
                 FROM core_migration_attempts
                 WHERE status IN ('active','recovering')
                 ORDER BY started_at DESC
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($attempt)) {
                $users = database_migration_table_exists($pdo, 'users')
                    ? (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
                    : 0;
                return ($attempt['backup_public_id'] ?? null) === null && $users === 0;
            }
        }
        return moderation_trust_detect_new_install($pdo);
    } catch (Throwable) {
        return false;
    }
}

function moderation_identity_apply_open_community_fresh_default(PDO $pdo, ?bool $freshInstall = null): void
{
    $freshInstall ??= moderation_identity_detect_fresh_default_context($pdo);
    if (!$freshInstall) return;

    $preset = app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, "\0");
    $registration = app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, "\0");
    $outside = app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, "\0");
    if ($preset !== 'small-trusted' || $registration !== 'approval' || $outside !== 'public-only') return;

    set_app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, 'open-community');
    set_app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, 'open');
    set_app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, 'disabled');
}

function moderation_identity_open_community_default_valid(PDO $pdo): bool
{
    $valid = array_key_exists(
        app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, ''),
        moderation_identity_setup_presets()
    )
        && in_array(
            app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, ''),
            MODERATION_IDENTITY_REGISTRATION_MODES,
            true
        )
        && in_array(
            app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, ''),
            MODERATION_ACCOUNT_OUTSIDE_MODES,
            true
        );
    if (!$valid) return false;
    if (!moderation_identity_detect_fresh_default_context($pdo)) return true;
    return app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, '') === 'open-community'
        && app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, '') === 'open'
        && app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, '') === 'disabled';
}

function moderation_identity_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS policy_documents (
                document_id VARCHAR(64) NOT NULL, version VARCHAR(32) NOT NULL,
                title VARCHAR(191) NOT NULL, material TINYINT(1) NOT NULL,
                content_sha256 VARCHAR(64) NOT NULL,
                published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (document_id, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS policy_acceptances (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
                terms_version VARCHAR(32) NOT NULL, rules_version VARCHAR(32) NOT NULL,
                accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reason VARCHAR(64) NOT NULL,
                UNIQUE KEY idx_policy_acceptance_version (user_id, terms_version, rules_version, reason),
                INDEX idx_policy_acceptance_user (user_id, accepted_at),
                CONSTRAINT fk_policy_acceptance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS installation_identity (
                singleton_id TINYINT PRIMARY KEY, owner_user_id INT NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_installation_identity_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS installation_owner_history (
                request_id VARCHAR(128) PRIMARY KEY, from_user_id INT DEFAULT NULL,
                to_user_id INT NOT NULL, actor_user_id INT NOT NULL,
                reason VARCHAR(500) NOT NULL, owner_revision BIGINT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_owner_history_revision (owner_revision)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS user_trust (
                user_id INT PRIMARY KEY, trust_state VARCHAR(32) NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                restriction_expires_at DATETIME DEFAULT NULL,
                public_reason VARCHAR(500) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_user_trust_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_trust_state (trust_state, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_capability_catalog (
                capability_id VARCHAR(96) PRIMARY KEY, label VARCHAR(191) NOT NULL,
                available TINYINT(1) NOT NULL, implementation_owner VARCHAR(191) NOT NULL,
                revision BIGINT NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS user_capability_grants (
                user_id INT NOT NULL, capability_id VARCHAR(96) NOT NULL,
                enabled TINYINT(1) NOT NULL, revision BIGINT NOT NULL DEFAULT 1,
                granted_by_user_id INT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, capability_id),
                CONSTRAINT fk_capability_grant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_capability_grant_catalog FOREIGN KEY (capability_id) REFERENCES moderation_capability_catalog(capability_id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS registration_invitations (
                public_id VARCHAR(64) PRIMARY KEY, token_hash VARCHAR(64) NOT NULL UNIQUE,
                email VARCHAR(254) DEFAULT NULL, status VARCHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL, created_by_user_id INT NOT NULL,
                used_by_user_id INT DEFAULT NULL, revision BIGINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at DATETIME DEFAULT NULL,
                INDEX idx_registration_invitation_status (status, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS build_000051_upgrade_previews (
                preview_id VARCHAR(64) PRIMARY KEY, inventory_sha256 VARCHAR(64) NOT NULL,
                owner_user_id INT DEFAULT NULL, user_count INT NOT NULL,
                trusted_count INT NOT NULL, restricted_count INT NOT NULL,
                future_capability_grant_count INT NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS policy_documents (
            document_id TEXT NOT NULL, version TEXT NOT NULL, title TEXT NOT NULL,
            material INTEGER NOT NULL CHECK (material IN (0,1)),
            content_sha256 TEXT NOT NULL, published_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (document_id, version)
        )",
        "CREATE TABLE IF NOT EXISTS policy_acceptances (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            terms_version TEXT NOT NULL, rules_version TEXT NOT NULL,
            accepted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, reason TEXT NOT NULL,
            UNIQUE(user_id, terms_version, rules_version, reason),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_policy_acceptance_user ON policy_acceptances(user_id, accepted_at)',
        "CREATE TABLE IF NOT EXISTS installation_identity (
            singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1),
            owner_user_id INTEGER NOT NULL, revision INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS installation_owner_history (
            request_id TEXT PRIMARY KEY, from_user_id INTEGER DEFAULT NULL,
            to_user_id INTEGER NOT NULL, actor_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL, owner_revision INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_owner_history_revision ON installation_owner_history(owner_revision)',
        "CREATE TABLE IF NOT EXISTS user_trust (
            user_id INTEGER PRIMARY KEY,
            trust_state TEXT NOT NULL CHECK (trust_state IN ('pending-approval','trusted','restricted','suspended')),
            revision INTEGER NOT NULL DEFAULT 1, restriction_expires_at TEXT DEFAULT NULL,
            public_reason TEXT DEFAULT NULL, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_user_trust_state ON user_trust(trust_state, updated_at)',
        "CREATE TABLE IF NOT EXISTS moderation_capability_catalog (
            capability_id TEXT PRIMARY KEY, label TEXT NOT NULL,
            available INTEGER NOT NULL CHECK (available IN (0,1)),
            implementation_owner TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1
        )",
        "CREATE TABLE IF NOT EXISTS user_capability_grants (
            user_id INTEGER NOT NULL, capability_id TEXT NOT NULL,
            enabled INTEGER NOT NULL CHECK (enabled IN (0,1)),
            revision INTEGER NOT NULL DEFAULT 1, granted_by_user_id INTEGER DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, capability_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(capability_id) REFERENCES moderation_capability_catalog(capability_id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS registration_invitations (
            public_id TEXT PRIMARY KEY, token_hash TEXT NOT NULL UNIQUE,
            email TEXT DEFAULT NULL,
            status TEXT NOT NULL CHECK (status IN ('active','used','cancelled','expired')),
            expires_at TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            used_by_user_id INTEGER DEFAULT NULL, revision INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, used_at TEXT DEFAULT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_registration_invitation_status ON registration_invitations(status, expires_at)',
        "CREATE TABLE IF NOT EXISTS build_000051_upgrade_previews (
            preview_id TEXT PRIMARY KEY, inventory_sha256 TEXT NOT NULL,
            owner_user_id INTEGER DEFAULT NULL, user_count INTEGER NOT NULL,
            trusted_count INTEGER NOT NULL, restricted_count INTEGER NOT NULL,
            future_capability_grant_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    ];
}

function moderation_identity_upsert_catalog(PDO $pdo): void
{
    foreach (moderation_trust_capability_catalog() as $id => $capability) {
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO moderation_capability_catalog (capability_id,label,available,implementation_owner,revision) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),available=VALUES(available),implementation_owner=VALUES(implementation_owner)'
            : 'INSERT INTO moderation_capability_catalog (capability_id,label,available,implementation_owner,revision) VALUES (?,?,?,?,1) ON CONFLICT(capability_id) DO UPDATE SET label=excluded.label,available=excluded.available,implementation_owner=excluded.implementation_owner';
        $pdo->prepare($sql)->execute([
            $id, $capability['label'], !empty($capability['available']) ? 1 : 0,
            $capability['implementationOwner'],
        ]);
    }
}

function moderation_identity_upsert_documents(PDO $pdo): void
{
    foreach (moderation_identity_policy_documents() as $id => $_definition) {
        $document = moderation_identity_load_policy_document($id);
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO policy_documents (document_id,version,title,material,content_sha256) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),material=VALUES(material),content_sha256=VALUES(content_sha256)'
            : 'INSERT INTO policy_documents (document_id,version,title,material,content_sha256) VALUES (?,?,?,?,?) ON CONFLICT(document_id,version) DO UPDATE SET title=excluded.title,material=excluded.material,content_sha256=excluded.content_sha256';
        $pdo->prepare($sql)->execute([
            $id, $document['version'], $document['title'],
            !empty($document['material']) ? 1 : 0, $document['sha256'],
        ]);
    }
}

function moderation_identity_selected_upgrade_owner(PDO $pdo): ?int
{
    $existing = moderation_identity_owner($pdo);
    if ($existing !== null) return $existing['userId'];
    $admins = array_map('intval', $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    if (!$admins) return null;
    if (count($admins) === 1) return $admins[0];
    $selected = (int)app_setting($pdo, MODERATION_IDENTITY_OWNER_SELECTION_SETTING, '0');
    if ($selected > 0 && in_array($selected, $admins, true)) return $selected;
    throw new ModerationIdentityPolicyException(
        'Choose the existing Administrator who will become the Installation Owner before applying Build 000051.',
        'INSTALLATION_OWNER_SELECTION_REQUIRED',
        409,
        ['administratorIds' => $admins]
    );
}

function moderation_identity_insert_trust(PDO $pdo, int $userId, string $state): void
{
    if (!in_array($state, MODERATION_IDENTITY_TRUST_STATES, true)) {
        throw new ModerationIdentityPolicyException('The trust state is invalid.', 'TRUST_STATE_INVALID', 500);
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO user_trust (user_id,trust_state,revision) VALUES (?,?,1) ON DUPLICATE KEY UPDATE trust_state=VALUES(trust_state)'
        : 'INSERT INTO user_trust (user_id,trust_state,revision) VALUES (?,?,1) ON CONFLICT(user_id) DO UPDATE SET trust_state=excluded.trust_state';
    $pdo->prepare($sql)->execute([$userId, $state]);
}

function moderation_identity_grant_existing_capabilities(PDO $pdo, int $userId, string $role): void
{
    $ids = $role === 'admin'
        ? ['upload-avatar', 'upload-personal-gestures', 'publish-community-gestures', 'create-regular-room', 'upload-room-background-video', 'import-website-room']
        : ['upload-avatar', 'upload-personal-gestures', 'create-regular-room'];
    foreach ($ids as $id) {
        $capability = moderation_trust_capability_catalog()[$id] ?? null;
        if (!is_array($capability) || empty($capability['available'])) {
            throw new ModerationIdentityPolicyException(
                'An unavailable capability cannot be granted.',
                'CAPABILITY_IMPLEMENTATION_UNAVAILABLE',
                500,
                ['capabilityId' => $id]
            );
        }
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO user_capability_grants (user_id,capability_id,enabled,revision,granted_by_user_id) VALUES (?,?,1,1,NULL) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)'
            : 'INSERT INTO user_capability_grants (user_id,capability_id,enabled,revision,granted_by_user_id) VALUES (?,?,1,1,NULL) ON CONFLICT(user_id,capability_id) DO UPDATE SET enabled=excluded.enabled';
        $pdo->prepare($sql)->execute([$userId, $id]);
    }
}

function moderation_identity_install_schema(PDO $pdo, ?bool $newInstall = null): void
{
    foreach (moderation_identity_schema_statements($pdo) as $statement) $pdo->exec($statement);
    moderation_identity_upsert_documents($pdo);
    moderation_identity_upsert_catalog($pdo);
    $newInstall ??= moderation_trust_detect_new_install($pdo);
    if (app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, "\0") === "\0") {
        set_app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, 'approval');
    }
    if (app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, "\0") === "\0") {
        set_app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, 'small-trusted');
    }
    if ($newInstall) return;
    $ownerUserId = moderation_identity_selected_upgrade_owner($pdo);
    if ($ownerUserId !== null) moderation_identity_ensure_owner($pdo, $ownerUserId, $ownerUserId, 'upgrade-preview-selection');
    $users = $pdo->query('SELECT id, role FROM users ORDER BY id')->fetchAll();
    foreach ($users as $user) {
        $userId = (int)$user['id'];
        $restricted = false;
        if (database_migration_table_exists($pdo, 'community_ejections')) {
            $statement = $pdo->prepare(
                'SELECT 1 FROM community_ejections WHERE user_id = ? AND '
                . active_ejection_sql('community_ejections') . ' LIMIT 1'
            );
            $statement->execute([$userId]);
            $restricted = (bool)$statement->fetchColumn();
        }
        moderation_identity_insert_trust($pdo, $userId, $restricted ? 'restricted' : 'trusted');
        if (!$restricted) moderation_identity_grant_existing_capabilities($pdo, $userId, (string)$user['role']);
    }
    moderation_identity_record_upgrade_preview($pdo, $ownerUserId, $users);
}

function moderation_identity_record_upgrade_preview(PDO $pdo, ?int $ownerUserId, array $users): void
{
    $states = $pdo->query('SELECT trust_state, COUNT(*) AS total FROM user_trust GROUP BY trust_state')->fetchAll();
    $counts = array_column($states, 'total', 'trust_state');
    $inventory = [
        'ownerUserId' => $ownerUserId, 'users' => count($users),
        'trusted' => (int)($counts['trusted'] ?? 0),
        'restricted' => (int)($counts['restricted'] ?? 0),
        'futureCapabilityGrants' => 0,
    ];
    $sha = strtoupper(hash('sha256', json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    try {
        $pdo->prepare(
            'INSERT INTO build_000051_upgrade_previews
             (preview_id,inventory_sha256,owner_user_id,user_count,trusted_count,restricted_count,future_capability_grant_count,status)
             VALUES (?,?,?,?,?,?,0,?)'
        )->execute([
            'upgrade-' . strtolower(substr($sha, 0, 24)), $sha, $ownerUserId,
            count($users), (int)($counts['trusted'] ?? 0),
            (int)($counts['restricted'] ?? 0), 'applied',
        ]);
    } catch (PDOException $error) {
        if (!str_contains(strtolower($error->getMessage()), 'unique')
            && !str_contains(strtolower($error->getMessage()), 'duplicate')) throw $error;
    }
}

function moderation_identity_schema_valid(PDO $pdo): bool
{
    foreach ([
        'policy_documents', 'policy_acceptances', 'installation_identity',
        'installation_owner_history', 'user_trust', 'moderation_capability_catalog',
        'user_capability_grants', 'registration_invitations', 'build_000051_upgrade_previews',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM policy_documents')->fetchColumn() !== 2) return false;
    if ((int)$pdo->query('SELECT COUNT(*) FROM moderation_capability_catalog WHERE available = 0')->fetchColumn() !== 2) return false;
    if ((int)$pdo->query(
        'SELECT COUNT(*) FROM user_capability_grants g JOIN moderation_capability_catalog c ON c.capability_id=g.capability_id WHERE g.enabled=1 AND c.available=0'
    )->fetchColumn() !== 0) return false;
    return in_array(app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, ''), MODERATION_IDENTITY_REGISTRATION_MODES, true);
}

function moderation_identity_owner(PDO $pdo): ?array
{
    if (!database_migration_table_exists($pdo, 'installation_identity')) return null;
    $row = $pdo->query(
        'SELECT i.owner_user_id,i.revision,u.username,u.display_name,u.role
         FROM installation_identity i JOIN users u ON u.id=i.owner_user_id
         WHERE i.singleton_id=1 LIMIT 1'
    )->fetch();
    return is_array($row) ? [
        'userId' => (int)$row['owner_user_id'], 'revision' => (int)$row['revision'],
        'username' => (string)$row['username'], 'displayName' => (string)$row['display_name'],
        'role' => (string)$row['role'],
    ] : null;
}

function moderation_identity_is_owner(PDO $pdo, int $userId): bool
{
    $owner = moderation_identity_owner($pdo);
    return $owner !== null && $owner['userId'] === $userId;
}

function moderation_identity_ensure_owner(PDO $pdo, int $ownerUserId, int $actorUserId, string $reason): void
{
    $user = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    $user->execute([$ownerUserId]);
    $role = $user->fetchColumn();
    if ($role === false) {
        throw new ModerationIdentityPolicyException('The Installation Owner account is unavailable.', 'INSTALLATION_OWNER_INVALID', 409);
    }
    if ((string)$role !== 'admin') $pdo->prepare("UPDATE users SET role='admin' WHERE id=?")->execute([$ownerUserId]);
    $existing = moderation_identity_owner($pdo);
    if ($existing !== null) {
        if ($existing['userId'] !== $ownerUserId) {
            throw new ModerationIdentityPolicyException(
                'Installation ownership already exists and requires the protected transfer workflow.',
                'INSTALLATION_OWNER_TRANSFER_REQUIRED',
                409
            );
        }
        return;
    }
    $pdo->prepare('INSERT INTO installation_identity (singleton_id,owner_user_id,revision) VALUES (1,?,1)')->execute([$ownerUserId]);
    $pdo->prepare(
        'INSERT INTO installation_owner_history (request_id,from_user_id,to_user_id,actor_user_id,reason,owner_revision) VALUES (?,NULL,?,?,?,1)'
    )->execute(['owner-initial-' . $ownerUserId, $ownerUserId, $actorUserId, $reason]);
}

function moderation_identity_transfer_owner(
    PDO $pdo,
    int $actorUserId,
    int $newOwnerUserId,
    int $expectedRevision,
    string $requestId,
    string $reason
): array {
    security_require_recent_authentication();
    $requestId = trim($requestId);
    $reason = trim($reason);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $requestId)) {
        throw new ModerationIdentityPolicyException(
            'A durable ownership-transfer request ID is required.',
            'INSTALLATION_OWNER_REQUEST_INVALID',
            422
        );
    }
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)) {
        throw new ModerationIdentityPolicyException(
            'The ownership-transfer reason must be 3-500 reviewable characters.',
            'INSTALLATION_OWNER_REASON_INVALID',
            422
        );
    }
    $existing = $pdo->prepare(
        'SELECT from_user_id,to_user_id,actor_user_id,reason,owner_revision
         FROM installation_owner_history WHERE request_id=? LIMIT 1'
    );
    $existing->execute([$requestId]);
    $history = $existing->fetch();
    if (is_array($history)) {
        $matches = (int)$history['from_user_id'] === $actorUserId
            && (int)$history['to_user_id'] === $newOwnerUserId
            && (int)$history['actor_user_id'] === $actorUserId
            && hash_equals((string)$history['reason'], $reason);
        if (!$matches) {
            throw new ModerationIdentityPolicyException(
                'The durable ownership-transfer request ID belongs to a different operation.',
                'INSTALLATION_OWNER_REQUEST_CONFLICT',
                409
            );
        }
        return [
            'owner' => moderation_identity_owner($pdo),
            'idempotentReplay' => true,
            'requestId' => $requestId,
        ];
    }
    $current = moderation_identity_owner($pdo);
    if ($current === null || $current['userId'] !== $actorUserId) {
        throw new ModerationIdentityPolicyException(
            'Only the current Installation Owner may transfer ownership.',
            'INSTALLATION_OWNER_REQUIRED',
            403
        );
    }
    if ($expectedRevision !== $current['revision']) {
        throw new ModerationIdentityPolicyException(
            'Installation ownership changed elsewhere.',
            'INSTALLATION_OWNER_STALE',
            409,
            ['owner' => $current]
        );
    }
    if ($newOwnerUserId === $actorUserId) {
        throw new ModerationIdentityPolicyException(
            'Choose a different Administrator as the new Installation Owner.',
            'INSTALLATION_OWNER_UNCHANGED',
            422
        );
    }
    $target = $pdo->prepare('SELECT id,role FROM users WHERE id=? LIMIT 1');
    $target->execute([$newOwnerUserId]);
    $targetRow = $target->fetch();
    if (!is_array($targetRow) || (string)$targetRow['role'] !== 'admin') {
        throw new ModerationIdentityPolicyException(
            'The new Installation Owner must be an existing Administrator.',
            'INSTALLATION_OWNER_TARGET_INVALID',
            422
        );
    }
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $update = $pdo->prepare(
            'UPDATE installation_identity
             SET owner_user_id=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP
             WHERE singleton_id=1 AND owner_user_id=? AND revision=?'
        );
        $update->execute([$newOwnerUserId, $actorUserId, $expectedRevision]);
        if ($update->rowCount() !== 1) {
            throw new ModerationIdentityPolicyException(
                'Installation ownership changed elsewhere.',
                'INSTALLATION_OWNER_STALE',
                409
            );
        }
        $newRevision = $expectedRevision + 1;
        $pdo->prepare(
            'INSERT INTO installation_owner_history
             (request_id,from_user_id,to_user_id,actor_user_id,reason,owner_revision)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $requestId,
            $actorUserId,
            $newOwnerUserId,
            $actorUserId,
            $reason,
            $newRevision,
        ]);
        log_tool(
            $pdo,
            $actorUserId,
            'installation_owner_transfer',
            $newOwnerUserId,
            null,
            'request:' . $requestId . '; revision:' . $newRevision
        );
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return [
        'owner' => moderation_identity_owner($pdo),
        'idempotentReplay' => false,
        'requestId' => $requestId,
    ];
}

function moderation_identity_record_acceptance(PDO $pdo, int $userId, string $reason): void
{
    if (!preg_match('/^[a-z][a-z0-9-]{2,63}$/', $reason)) {
        throw new ModerationIdentityPolicyException('The acceptance reason is invalid.', 'POLICY_ACCEPTANCE_REASON_INVALID', 500);
    }
    moderation_identity_current_policy_bundle();
    try {
        $pdo->prepare(
            'INSERT INTO policy_acceptances (user_id,terms_version,rules_version,reason) VALUES (?,?,?,?)'
        )->execute([$userId, MODERATION_IDENTITY_TERMS_VERSION, MODERATION_IDENTITY_RULES_VERSION, $reason]);
    } catch (PDOException $error) {
        if (!str_contains(strtolower($error->getMessage()), 'unique')
            && !str_contains(strtolower($error->getMessage()), 'duplicate')) throw $error;
    }
}

function moderation_identity_policy_acceptance_current(PDO $pdo, int $userId): bool
{
    if (!database_migration_table_exists($pdo, 'policy_acceptances')) return false;
    $statement = $pdo->prepare(
        'SELECT 1 FROM policy_acceptances WHERE user_id=? AND terms_version=? AND rules_version=? LIMIT 1'
    );
    $statement->execute([$userId, MODERATION_IDENTITY_TERMS_VERSION, MODERATION_IDENTITY_RULES_VERSION]);
    return (bool)$statement->fetchColumn();
}

function moderation_identity_registration_policy(PDO $pdo): array
{
    $master = moderation_trust_policy($pdo);
    $storedMode = app_setting($pdo, MODERATION_IDENTITY_REGISTRATION_MODE_SETTING, 'approval');
    if (!in_array($storedMode, MODERATION_IDENTITY_REGISTRATION_MODES, true)) $storedMode = 'approval';
    $effectiveMode = !empty($master['effectiveEnabled']) ? $storedMode : 'open';
    return [
        'schemaId' => 'chatspace.registration-policy', 'schemaVersion' => 1,
        'storedMode' => $storedMode, 'effectiveMode' => $effectiveMode,
        'masterEnabled' => !empty($master['effectiveEnabled']),
        'selfRegistrationAllowed' => in_array($effectiveMode, ['open', 'approval', 'invitation-only'], true),
        'invitationRequired' => $effectiveMode === 'invitation-only',
        'administratorCreatedOnly' => $effectiveMode === 'administrator-created-only',
        'guestsAllowed' => false,
        'newUserTrust' => $effectiveMode === 'open' ? 'trusted' : 'pending-approval',
        'safeBuiltInAvatar' => 'preset:Default',
    ];
}

function moderation_identity_initialize_user(PDO $pdo, int $userId, string $source, bool $installationOwner = false): array
{
    $userStatement = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
    $userStatement->execute([$userId]);
    $role = $userStatement->fetchColumn();
    if ($role === false) throw new ModerationIdentityPolicyException('The account is unavailable.', 'ACCOUNT_UNAVAILABLE', 404);
    $policy = moderation_identity_registration_policy($pdo);
    $trusted = $installationOwner || (string)$role === 'admin' || $policy['newUserTrust'] === 'trusted';
    moderation_identity_insert_trust($pdo, $userId, $trusted ? 'trusted' : 'pending-approval');
    if ($trusted) moderation_identity_grant_existing_capabilities($pdo, $userId, (string)$role);
    if (!$trusted
        && function_exists('moderation_account_ensure_pending_review')
        && database_migration_table_exists($pdo, 'moderation_cases')) {
        moderation_account_ensure_pending_review($pdo, $userId, 1);
    }
    if (function_exists('moderation_safety_project_default_staff_grants')
        && database_migration_table_exists($pdo, 'user_staff_capability_grants')) {
        moderation_safety_project_default_staff_grants($pdo, $userId);
    }
    if ($installationOwner) moderation_identity_ensure_owner($pdo, $userId, $userId, 'setup-first-administrator');
    return moderation_identity_account_authorization($pdo, $userId);
}

function moderation_identity_account_authorization(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT u.id,u.role,t.trust_state,t.revision,t.restriction_expires_at,t.public_reason
         FROM users u LEFT JOIN user_trust t ON t.user_id=u.id WHERE u.id=? LIMIT 1'
    );
    $statement->execute([$userId]);
    $row = $statement->fetch();
    if (!is_array($row)) throw new ModerationIdentityPolicyException('The account is unavailable.', 'ACCOUNT_UNAVAILABLE', 404);
    $grants = $pdo->prepare(
        'SELECT c.capability_id,c.label,c.available,c.implementation_owner,
                COALESCE(g.enabled,0) AS stored_enabled,COALESCE(g.revision,0) AS grant_revision
         FROM moderation_capability_catalog c
         LEFT JOIN user_capability_grants g ON g.capability_id=c.capability_id AND g.user_id=?
         ORDER BY c.capability_id'
    );
    $grants->execute([$userId]);
    $master = moderation_trust_policy($pdo);
    $trust = (string)($row['trust_state'] ?: 'pending-approval');
    $capabilities = [];
    foreach ($grants->fetchAll() as $grant) {
        $effective = !empty($master['effectiveEnabled']) && $trust === 'trusted'
            && !empty($grant['available']) && !empty($grant['stored_enabled']);
        $capabilities[] = [
            'id' => (string)$grant['capability_id'], 'label' => (string)$grant['label'],
            'available' => (bool)$grant['available'], 'storedEnabled' => (bool)$grant['stored_enabled'],
            'effectiveEnabled' => $effective, 'revision' => (int)$grant['grant_revision'],
            'implementationOwner' => (string)$grant['implementation_owner'],
            'denialCode' => $effective ? null : (
                empty($grant['available']) ? 'CAPABILITY_IMPLEMENTATION_UNAVAILABLE'
                    : ($trust !== 'trusted' ? 'ACCOUNT_TRUST_REQUIRED' : 'CAPABILITY_NOT_GRANTED')
            ),
        ];
    }
    return [
        'userId' => (int)$row['id'], 'role' => (string)$row['role'],
        'isInstallationOwner' => moderation_identity_is_owner($pdo, $userId),
        'trustState' => $trust, 'trustRevision' => (int)($row['revision'] ?: 1),
        'restrictionExpiresAt' => $row['restriction_expires_at'],
        'publicReason' => $row['public_reason'],
        'policyAcceptanceCurrent' => moderation_identity_policy_acceptance_current($pdo, $userId),
        'capabilities' => $capabilities, 'roleDoesNotAuthorizeModeration' => true,
    ];
}

function moderation_identity_require_capability(PDO $pdo, int $userId, string $capabilityId): void
{
    $projection = moderation_identity_account_authorization($pdo, $userId);
    foreach ($projection['capabilities'] as $capability) {
        if ($capability['id'] !== $capabilityId) continue;
        if (!empty($capability['effectiveEnabled'])) return;
        throw new ModerationIdentityPolicyException(
            'This account is not authorized to use that capability.',
            (string)($capability['denialCode'] ?? 'CAPABILITY_DENIED'),
            403,
            ['capabilityId' => $capabilityId, 'trustState' => $projection['trustState']]
        );
    }
    throw new ModerationIdentityPolicyException('The requested capability is unknown.', 'CAPABILITY_UNKNOWN', 404);
}

function moderation_identity_validate_invitation(PDO $pdo, string $token, string $email): ?array
{
    if ($token === '') return null;
    $statement = $pdo->prepare(
        "SELECT * FROM registration_invitations WHERE token_hash=? AND status='active' AND expires_at>CURRENT_TIMESTAMP LIMIT 1"
    );
    $statement->execute([strtoupper(hash('sha256', $token))]);
    $invitation = $statement->fetch();
    if (!is_array($invitation)
        || ((string)$invitation['email'] !== ''
            && !hash_equals(strtolower((string)$invitation['email']), strtolower($email)))) {
        throw new ModerationIdentityPolicyException('The invitation is invalid or expired.', 'INVITATION_INVALID', 409);
    }
    return $invitation;
}

function moderation_identity_register_account(PDO $pdo, array $input, string $source, int $actorUserId = 0): array
{
    if (!in_array($source, ['self-registration', 'administrator-created'], true)) {
        throw new ModerationIdentityPolicyException('The account-creation source is invalid.', 'ACCOUNT_CREATION_SOURCE_INVALID', 500);
    }
    $policy = moderation_identity_registration_policy($pdo);
    if ($source === 'self-registration' && !$policy['selfRegistrationAllowed']) {
        throw new ModerationIdentityPolicyException(
            'Self-registration is not available. Contact an Administrator.',
            'REGISTRATION_MODE_FORBIDS_SELF_SERVICE',
            403
        );
    }
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $username = trim((string)($input['username'] ?? ''));
    $displayName = trim((string)($input['display_name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        throw new ModerationIdentityPolicyException(
            'Use a valid Username, email, and password of at least 8 characters.',
            'ACCOUNT_CREATION_INPUT_INVALID',
            400
        );
    }
    if ($source === 'self-registration' && (empty($input['accept_terms']) || empty($input['accept_rules']))) {
        throw new ModerationIdentityPolicyException(
            'Review and accept the complete current Terms and Community Rules.',
            'POLICY_ACCEPTANCE_REQUIRED',
            428
        );
    }
    moderation_identity_current_policy_bundle();
    $invitationToken = trim((string)($input['invitation_token'] ?? ''));
    if ($invitationToken !== '' && empty($policy['masterEnabled'])) {
        moderation_trust_require_optional_enabled($pdo, 'registration-invitation');
    }
    $invitation = moderation_identity_validate_invitation($pdo, $invitationToken, $email);
    if ($source === 'self-registration' && $policy['invitationRequired'] && $invitation === null) {
        throw new ModerationIdentityPolicyException('A current invitation is required.', 'INVITATION_REQUIRED', 403);
    }
    $role = $source === 'administrator-created' ? (string)($input['role'] ?? 'user') : 'user';
    if (!isset(moderation_identity_role_catalog()[$role])) {
        throw new ModerationIdentityPolicyException('Choose a supported role.', 'ROLE_INVALID', 400);
    }
    $identity = member_profiles_validate_identity($pdo, $username, $displayName);
    $avatar = trim((string)($input['avatar_path'] ?? '')) ?: 'preset:Default';
    $pdo->prepare(
        'INSERT INTO users (email,username,password_hash,display_name,role,avatar_path) VALUES (?,?,?,?,?,?)'
    )->execute([
        $email, $identity['username'], password_hash($password, PASSWORD_DEFAULT),
        $identity['display_name'], $role, $avatar,
    ]);
    $userId = (int)$pdo->lastInsertId();
    member_profiles_initialize_user($pdo, $userId);
    $authorization = moderation_identity_initialize_user($pdo, $userId, $source, false);
    if ($source === 'self-registration') moderation_identity_record_acceptance($pdo, $userId, 'account-creation');
    if ($invitation !== null) {
        $update = $pdo->prepare(
            "UPDATE registration_invitations SET status='used',used_by_user_id=?,used_at=CURRENT_TIMESTAMP,revision=revision+1 WHERE public_id=? AND status='active' AND revision=?"
        );
        $update->execute([$userId, $invitation['public_id'], $invitation['revision']]);
        if ($update->rowCount() !== 1) {
            throw new ModerationIdentityPolicyException('The invitation was already used.', 'INVITATION_REPLAY', 409);
        }
    }
    if ($source === 'administrator-created') {
        log_tool($pdo, $actorUserId > 0 ? $actorUserId : null, 'admin_create_user', $userId, null, 'Created account with role ' . $role . '; current policy acceptance required at first access.');
    }
    return ['userId' => $userId, 'authorization' => $authorization];
}
