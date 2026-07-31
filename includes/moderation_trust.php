<?php
declare(strict_types=1);

/**
 * Build 000051 mandatory-core moderation and trust policy owner.
 *
 * The shared Optional-Core master controls only optional-effective workflows.
 * Mandatory and continuity safeguards are deliberately described and enforced
 * independently so disabling presentation or positive-grant workflows cannot
 * weaken safety, privacy, enforcement, protection, retention, or integrity.
 */

const MODERATION_TRUST_MASTER_SETTING = 'moderation_trust_optional_core_enabled';
const MODERATION_TRUST_PROVENANCE_SETTING = 'moderation_trust_optional_core_provenance';
const MODERATION_TRUST_REVISION_SETTING = 'moderation_trust_optional_core_revision';
const MODERATION_TRUST_MASTER_SETTING_ID = 'moderation_trust_optional_core_enabled';
const MODERATION_TRUST_TRANSITION_REQUEST_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

final class ModerationTrustPolicyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MODERATION_TRUST_POLICY_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function moderation_trust_mandatory_invariants(): array
{
    return [
        'authentication',
        'authorization',
        'session-security',
        'csrf',
        'input-and-content-validation',
        'terms-and-community-rules',
        'privacy',
        'personal-block',
        'active-restrictions-and-suspensions',
        'abuse-resistance',
        'report-intake',
        'evidence-protection-and-holds',
        'message-protection-and-recovery',
        'retention-and-data-integrity',
        'account-lifecycle-safety',
        'https-and-trusted-proxy-security',
        'database-migration-and-recovery',
        'tool-logs',
    ];
}

function moderation_trust_subsystem_matrix(): array
{
    return [
        'terms-rules' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
        'registration-modes' => ['classification' => 'optional-effective', 'offBehavior' => 'legacy-compatible-open'],
        'positive-trust-gating' => ['classification' => 'optional-effective', 'offBehavior' => 'ineffective'],
        'capability-requests-and-grants' => ['classification' => 'optional-effective', 'offBehavior' => 'new-work-unavailable'],
        'staff-safety-actions' => ['classification' => 'mandatory', 'offBehavior' => 'available'],
        'reports-cases-and-appeals' => ['classification' => 'continuity', 'offBehavior' => 'intake-and-review-available'],
        'outside-content-confirmations' => ['classification' => 'optional-effective', 'offBehavior' => 'extra-confirmations-ineffective'],
        'personal-mute' => ['classification' => 'continuity', 'offBehavior' => 'effective-and-manageable'],
        'personal-block' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
        'https-proxy-network-safety' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
        'message-protection' => ['classification' => 'continuity', 'offBehavior' => 'selected-protection-remains-enforced'],
        'retention-jobs-and-holds' => ['classification' => 'continuity', 'offBehavior' => 'policy-and-jobs-continue'],
        'evidence-encryption-and-holds' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
        'account-lifecycle-safety' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
        'tool-logs' => ['classification' => 'mandatory', 'offBehavior' => 'unchanged'],
    ];
}

function moderation_trust_capability_catalog(): array
{
    return [
        'upload-avatar' => [
            'label' => 'Upload Avatar',
            'available' => true,
            'implementationOwner' => 'avatar-upload',
        ],
        'upload-personal-gestures' => [
            'label' => 'Upload Personal Gestures',
            'available' => true,
            'implementationOwner' => 'gesture-catalog',
        ],
        'publish-community-gestures' => [
            'label' => 'Publish Community Gestures',
            'available' => true,
            'implementationOwner' => 'gesture-catalog',
        ],
        'create-regular-room' => [
            'label' => 'Create Regular Room',
            'available' => true,
            'implementationOwner' => 'room-owner',
        ],
        'upload-room-background-video' => [
            'label' => 'Upload Room Background/Video',
            'available' => true,
            'implementationOwner' => 'room-media',
        ],
        'import-website-room' => [
            'label' => 'Import Website Room',
            'available' => true,
            'implementationOwner' => 'room-importer',
        ],
        'create-temporary-live-website-room' => [
            'label' => 'Create Temporary Live Website Room',
            'available' => false,
            'implementationOwner' => 'not-yet-available',
        ],
        'send-direct-p2p-files' => [
            'label' => 'Send Direct P2P Files',
            'available' => false,
            'implementationOwner' => 'not-yet-available',
        ],
    ];
}

function moderation_trust_detect_new_install(PDO $pdo): bool
{
    try {
        if (function_exists('database_migration_table_exists')
            && database_migration_table_exists($pdo, 'core_migration_attempts')) {
            $source = $pdo->query(
                "SELECT source_variant
                 FROM core_migration_attempts
                 WHERE status IN ('running','recovering')
                 ORDER BY started_at DESC
                 LIMIT 1"
            )->fetchColumn();
            if (is_string($source) && $source !== '') {
                return in_array($source, ['empty', 'bundled-seed'], true);
            }
        }
        $hasUsers = function_exists('database_migration_table_exists')
            ? database_migration_table_exists($pdo, 'users')
            : true;
        if (!$hasUsers) return true;
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    } catch (Throwable) {
        return false;
    }
}

function moderation_trust_provenance_default(PDO $pdo): bool
{
    $provenance = app_setting($pdo, MODERATION_TRUST_PROVENANCE_SETTING, '');
    if ($provenance === 'new-install') return true;
    if ($provenance === 'upgrade') return false;
    return moderation_trust_detect_new_install($pdo);
}

function moderation_trust_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS moderation_trust_master_transitions (
                request_id VARCHAR(128) PRIMARY KEY,
                from_enabled TINYINT(1) NOT NULL,
                to_enabled TINYINT(1) NOT NULL,
                policy_revision BIGINT NOT NULL,
                settings_revision BIGINT NOT NULL,
                stopped_state_count INT NOT NULL DEFAULT 0,
                actor_user_id INT DEFAULT NULL,
                source VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_moderation_trust_master_revision (policy_revision),
                INDEX idx_moderation_trust_master_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS moderation_trust_master_transitions (
            request_id TEXT PRIMARY KEY,
            from_enabled INTEGER NOT NULL CHECK (from_enabled IN (0,1)),
            to_enabled INTEGER NOT NULL CHECK (to_enabled IN (0,1)),
            policy_revision INTEGER NOT NULL,
            settings_revision INTEGER NOT NULL,
            stopped_state_count INTEGER NOT NULL DEFAULT 0 CHECK (stopped_state_count >= 0),
            actor_user_id INTEGER DEFAULT NULL,
            source TEXT NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_moderation_trust_master_revision ON moderation_trust_master_transitions(policy_revision)',
        'CREATE INDEX IF NOT EXISTS idx_moderation_trust_master_created ON moderation_trust_master_transitions(created_at)',
    ];
}

function moderation_trust_install_foundations(PDO $pdo, ?bool $newInstall = null): void
{
    foreach (moderation_trust_schema_statements($pdo) as $statement) $pdo->exec($statement);

    $newInstall ??= moderation_trust_detect_new_install($pdo);
    $defaultEnabled = $newInstall;
    $provenance = $newInstall ? 'new-install' : 'upgrade';
    $defaults = [
        MODERATION_TRUST_MASTER_SETTING => $defaultEnabled ? '1' : '0',
        MODERATION_TRUST_PROVENANCE_SETTING => $provenance,
        MODERATION_TRUST_REVISION_SETTING => '1',
    ];
    foreach ($defaults as $key => $value) {
        $existing = app_setting($pdo, $key, "\0");
        if ($existing === "\0") set_app_setting($pdo, $key, $value);
    }
}

function moderation_trust_schema_valid(PDO $pdo): bool
{
    try {
        if (function_exists('database_migration_has_columns')) {
            if (!database_migration_has_columns($pdo, 'moderation_trust_master_transitions', [
                'request_id',
                'from_enabled',
                'to_enabled',
                'policy_revision',
                'settings_revision',
                'stopped_state_count',
                'actor_user_id',
                'source',
                'status',
                'created_at',
            ])) return false;
        } else {
            $pdo->query('SELECT request_id, policy_revision, status FROM moderation_trust_master_transitions WHERE 1 = 0');
        }
        $enabled = app_setting($pdo, MODERATION_TRUST_MASTER_SETTING, '');
        $provenance = app_setting($pdo, MODERATION_TRUST_PROVENANCE_SETTING, '');
        $revision = app_setting($pdo, MODERATION_TRUST_REVISION_SETTING, '');
        return in_array($enabled, ['0', '1'], true)
            && in_array($provenance, ['new-install', 'upgrade', 'owner-selected'], true)
            && ctype_digit($revision)
            && (int)$revision >= 1;
    } catch (Throwable) {
        return false;
    }
}

function moderation_trust_active_optional_state(PDO $pdo): array
{
    $counts = [
        'activeRegistrationInvitations' => 0,
        'pendingTrustedReviews' => 0,
        'pendingCapabilityRequests' => 0,
    ];
    if (database_migration_table_exists($pdo, 'registration_invitations')) {
        $counts['activeRegistrationInvitations'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM registration_invitations
             WHERE status='active' AND expires_at>CURRENT_TIMESTAMP"
        )->fetchColumn();
    }
    if (database_migration_table_exists($pdo, 'moderation_cases')) {
        $statement = $pdo->query(
            "SELECT case_type,COUNT(*) AS active_count
             FROM moderation_cases
             WHERE case_type IN ('trusted-review','capability-request')
               AND status IN ('received','under-review')
             GROUP BY case_type"
        );
        foreach ($statement->fetchAll() as $row) {
            $key = (string)$row['case_type'] === 'trusted-review'
                ? 'pendingTrustedReviews'
                : 'pendingCapabilityRequests';
            $counts[$key] = (int)$row['active_count'];
        }
    }
    return [
        'counts' => $counts,
        'total' => array_sum($counts),
        'disposition' => 'preserved-and-made-ineffective-until-reenabled',
    ];
}

function moderation_trust_active_optional_state_count(PDO $pdo): int
{
    return (int)moderation_trust_active_optional_state($pdo)['total'];
}

function moderation_trust_reconcile_active_optional_state(PDO $pdo): array
{
    $snapshot = moderation_trust_active_optional_state($pdo);
    return [
        'stoppedStateCount' => (int)$snapshot['total'],
        'preservedStateCount' => (int)$snapshot['total'],
        'counts' => $snapshot['counts'],
        'disposition' => $snapshot['disposition'],
    ];
}

function moderation_trust_policy(PDO $pdo): array
{
    $stored = app_setting(
        $pdo,
        MODERATION_TRUST_MASTER_SETTING,
        moderation_trust_detect_new_install($pdo) ? '1' : '0'
    ) === '1';
    $provenance = app_setting(
        $pdo,
        MODERATION_TRUST_PROVENANCE_SETTING,
        moderation_trust_detect_new_install($pdo) ? 'new-install' : 'upgrade'
    );
    $revision = max(1, (int)app_setting($pdo, MODERATION_TRUST_REVISION_SETTING, '1'));
    $capabilities = [];
    foreach (moderation_trust_capability_catalog() as $id => $capability) {
        $capability['id'] = $id;
        $capability['grantable'] = $stored && !empty($capability['available']);
        $capability['denialCode'] = !empty($capability['available'])
            ? ($stored ? null : 'MODERATION_TRUST_OPTIONAL_CORE_DISABLED')
            : 'CAPABILITY_IMPLEMENTATION_UNAVAILABLE';
        $capabilities[] = $capability;
    }
    return [
        'schemaId' => 'chatspace.moderation-trust-policy',
        'schemaVersion' => 1,
        'storedEnabled' => $stored,
        'effectiveEnabled' => $stored,
        'provenance' => $provenance,
        'provenanceDefaultEnabled' => moderation_trust_provenance_default($pdo),
        'revision' => $revision,
        'activeOptionalStateCount' => moderation_trust_active_optional_state_count($pdo),
        'mandatoryInvariants' => moderation_trust_mandatory_invariants(),
        'subsystems' => moderation_trust_subsystem_matrix(),
        'capabilities' => $capabilities,
        'futureUnavailableCapabilityIds' => array_values(array_map(
            static fn(array $capability): string => (string)$capability['id'],
            array_filter($capabilities, static fn(array $capability): bool => empty($capability['available']))
        )),
    ];
}

function moderation_trust_require_optional_enabled(PDO $pdo, string $workflow): void
{
    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $workflow)) {
        throw new ModerationTrustPolicyException(
            'The optional workflow identity is invalid.',
            'MODERATION_TRUST_WORKFLOW_INVALID',
            500
        );
    }
    $policy = moderation_trust_policy($pdo);
    if (empty($policy['effectiveEnabled'])) {
        throw new ModerationTrustPolicyException(
            'This optional moderation and trust workflow is unavailable while Moderation and Trust is disabled.',
            'MODERATION_TRUST_OPTIONAL_CORE_DISABLED',
            403,
            ['workflow' => $workflow, 'revision' => $policy['revision']]
        );
    }
}

function moderation_trust_require_capability_available(PDO $pdo, string $capabilityId): void
{
    $catalog = moderation_trust_capability_catalog();
    if (!isset($catalog[$capabilityId])) {
        throw new ModerationTrustPolicyException(
            'The requested capability is unknown.',
            'CAPABILITY_UNKNOWN',
            404
        );
    }
    if (empty($catalog[$capabilityId]['available'])) {
        throw new ModerationTrustPolicyException(
            'The requested capability is registered for a future implementation and is not available.',
            'CAPABILITY_IMPLEMENTATION_UNAVAILABLE',
            403,
            ['capabilityId' => $capabilityId]
        );
    }
    moderation_trust_require_optional_enabled($pdo, 'capability-grant');
}

function moderation_trust_existing_transition(PDO $pdo, string $requestId): ?array
{
    $statement = $pdo->prepare(
        'SELECT request_id, from_enabled, to_enabled, policy_revision, settings_revision,
                stopped_state_count, actor_user_id, source, status, created_at
         FROM moderation_trust_master_transitions
         WHERE request_id = ?
         LIMIT 1'
    );
    $statement->execute([$requestId]);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    return [
        'requestId' => (string)$row['request_id'],
        'fromEnabled' => (bool)$row['from_enabled'],
        'toEnabled' => (bool)$row['to_enabled'],
        'policyRevision' => (int)$row['policy_revision'],
        'settingsRevision' => (int)$row['settings_revision'],
        'stoppedStateCount' => (int)$row['stopped_state_count'],
        'actorUserId' => $row['actor_user_id'] === null ? null : (int)$row['actor_user_id'],
        'source' => (string)$row['source'],
        'status' => (string)$row['status'],
        'createdAt' => (string)$row['created_at'],
    ];
}

function moderation_trust_update_locked(
    PDO $pdo,
    bool $enabled,
    int $expectedPolicyRevision,
    int $settingsRevision,
    bool $impactConfirmed,
    int $actorUserId,
    string $requestId,
    string $source
): array {
    if (!preg_match(MODERATION_TRUST_TRANSITION_REQUEST_PATTERN, $requestId)) {
        throw new ModerationTrustPolicyException(
            'A stable request identity is required.',
            'MODERATION_TRUST_REQUEST_ID_REQUIRED',
            400
        );
    }
    if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/', $source)) {
        throw new ModerationTrustPolicyException(
            'The transition source is invalid.',
            'MODERATION_TRUST_SOURCE_INVALID',
            400
        );
    }
    $existing = moderation_trust_existing_transition($pdo, $requestId);
    if ($existing !== null) {
        if ($existing['toEnabled'] !== $enabled) {
            throw new ModerationTrustPolicyException(
                'That request identity was already used for a different transition.',
                'MODERATION_TRUST_REQUEST_REPLAY_CONFLICT',
                409,
                $existing
            );
        }
        return ['ok' => true, 'idempotent' => true, 'transition' => $existing, 'policy' => moderation_trust_policy($pdo)];
    }

    $lockSql = 'SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $lockSql .= ' FOR UPDATE';
    $lock = $pdo->prepare($lockSql);
    $lock->execute([MODERATION_TRUST_REVISION_SETTING]);
    $actualRevision = max(1, (int)($lock->fetchColumn() ?: 1));
    if ($actualRevision !== $expectedPolicyRevision) {
        throw new ModerationTrustPolicyException(
            'Moderation and Trust changed. Refresh and try again.',
            'MODERATION_TRUST_POLICY_STALE',
            409,
            ['revision' => $actualRevision]
        );
    }

    $current = app_setting($pdo, MODERATION_TRUST_MASTER_SETTING, '0') === '1';
    if ($current === $enabled) {
        return ['ok' => true, 'idempotent' => true, 'transition' => null, 'policy' => moderation_trust_policy($pdo)];
    }

    $activeCount = moderation_trust_active_optional_state_count($pdo);
    if ($current && !$enabled && $activeCount > 0 && !$impactConfirmed) {
        throw new ModerationTrustPolicyException(
            'Review and confirm the active optional workflows that will stop.',
            'MODERATION_TRUST_DISABLE_IMPACT_CONFIRMATION_REQUIRED',
            409,
            ['activeOptionalStateCount' => $activeCount, 'revision' => $actualRevision]
        );
    }

    $reconciliation = $current && !$enabled
        ? moderation_trust_reconcile_active_optional_state($pdo)
        : [
            'stoppedStateCount' => 0,
            'preservedStateCount' => 0,
            'counts' => [],
            'disposition' => 'not-applicable',
        ];
    $stopped = (int)$reconciliation['stoppedStateCount'];
    $nextRevision = $actualRevision + 1;
    set_app_setting($pdo, MODERATION_TRUST_MASTER_SETTING, $enabled ? '1' : '0');
    set_app_setting($pdo, MODERATION_TRUST_PROVENANCE_SETTING, 'owner-selected');
    set_app_setting($pdo, MODERATION_TRUST_REVISION_SETTING, (string)$nextRevision);
    $insert = $pdo->prepare(
        'INSERT INTO moderation_trust_master_transitions
         (request_id, from_enabled, to_enabled, policy_revision, settings_revision,
          stopped_state_count, actor_user_id, source, status)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $requestId,
        $current ? 1 : 0,
        $enabled ? 1 : 0,
        $nextRevision,
        $settingsRevision,
        $stopped,
        $actorUserId > 0 ? $actorUserId : null,
        $source,
        'completed',
    ]);
    $transition = moderation_trust_existing_transition($pdo, $requestId);
    return [
        'ok' => true,
        'idempotent' => false,
        'stoppedStateCount' => $stopped,
        'reconciliation' => $reconciliation,
        'transition' => $transition,
        'policy' => moderation_trust_policy($pdo),
    ];
}
