<?php
declare(strict_types=1);

/**
 * Chat Runtime Framework
 * Build: 000045
 * Owner: RuntimeIssueService
 */

const RUNTIME_ISSUE_STATUSES = ['new', 'confirmed', 'investigating', 'fixed-pending-verification', 'resolved', 'expected', 'ignored', 'regressed'];
const RUNTIME_ISSUE_SEVERITIES = ['info', 'warning', 'error', 'critical'];
const RUNTIME_ISSUE_MAX_EVIDENCE_BYTES = 24576;
const RUNTIME_ISSUE_MAX_OCCURRENCES = 100;
const RUNTIME_ISSUE_MAX_SCREENSHOT_BYTES = 1572864;
const RUNTIME_ISSUE_MAX_SCREENSHOT_WIDTH = 1600;
const RUNTIME_ISSUE_MAX_SCREENSHOT_HEIGHT = 1200;
const RUNTIME_ISSUE_MAX_PAGE_SIZE = 100;
const RUNTIME_ISSUE_MAX_EXPORT_BYTES = 1048576;

final class RuntimeIssueException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'RUNTIME_ISSUE_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = []
    ) {
        parent::__construct($message);
    }
}

function runtime_issue_install_schema(PDO $pdo): void
{
    if (db_uses_mysql_syntax($pdo)) {
        $statements = [
            "CREATE TABLE IF NOT EXISTS runtime_issues (id INT AUTO_INCREMENT PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL UNIQUE, category VARCHAR(64) NOT NULL, component VARCHAR(96) NOT NULL, error_code VARCHAR(96) NOT NULL, normalized_message VARCHAR(512) NOT NULL, title VARCHAR(191) NOT NULL, severity VARCHAR(32) NOT NULL DEFAULT 'error', status VARCHAR(32) NOT NULL DEFAULT 'new', reporter_user_id INT DEFAULT NULL, occurrence_count INT NOT NULL DEFAULT 0, first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issues_status (status, last_seen_at), CONSTRAINT fk_runtime_issues_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_occurrences (id INT AUTO_INCREMENT PRIMARY KEY, issue_id INT NOT NULL, reporter_user_id INT DEFAULT NULL, evidence_json LONGTEXT NOT NULL, build_id VARCHAR(96) DEFAULT NULL, request_correlation VARCHAR(96) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issue_occurrences_issue (issue_id, id), CONSTRAINT fk_runtime_occurrence_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_occurrence_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_status_history (id INT AUTO_INCREMENT PRIMARY KEY, issue_id INT NOT NULL, from_status VARCHAR(32) DEFAULT NULL, to_status VARCHAR(32) NOT NULL, actor_user_id INT DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, verification_reference VARCHAR(191) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issue_history_issue (issue_id, id), CONSTRAINT fk_runtime_history_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_screenshots (id INT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, issue_id INT NOT NULL, occurrence_id INT DEFAULT NULL, owner_user_id INT NOT NULL, storage_name VARCHAR(191) NOT NULL UNIQUE, mime_type VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME DEFAULT NULL, INDEX idx_runtime_issue_screenshots_issue (issue_id, deleted_at), CONSTRAINT fk_runtime_screenshot_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_screenshot_occurrence FOREIGN KEY (occurrence_id) REFERENCES runtime_issue_occurrences(id) ON DELETE SET NULL, CONSTRAINT fk_runtime_screenshot_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS runtime_issues (id INTEGER PRIMARY KEY AUTOINCREMENT, fingerprint TEXT NOT NULL UNIQUE, category TEXT NOT NULL, component TEXT NOT NULL, error_code TEXT NOT NULL, normalized_message TEXT NOT NULL, title TEXT NOT NULL, severity TEXT NOT NULL DEFAULT 'error', status TEXT NOT NULL DEFAULT 'new', reporter_user_id INTEGER DEFAULT NULL, occurrence_count INTEGER NOT NULL DEFAULT 0, first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_occurrences (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL, reporter_user_id INTEGER DEFAULT NULL, evidence_json TEXT NOT NULL, build_id TEXT DEFAULT NULL, request_correlation TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL, from_status TEXT DEFAULT NULL, to_status TEXT NOT NULL, actor_user_id INTEGER DEFAULT NULL, reason TEXT DEFAULT NULL, verification_reference TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_screenshots (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, issue_id INTEGER NOT NULL, occurrence_id INTEGER DEFAULT NULL, owner_user_id INTEGER NOT NULL, storage_name TEXT NOT NULL UNIQUE, mime_type TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, byte_size INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT DEFAULT NULL, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(occurrence_id) REFERENCES runtime_issue_occurrences(id) ON DELETE SET NULL, FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            'CREATE INDEX IF NOT EXISTS idx_runtime_issues_status ON runtime_issues(status, last_seen_at)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_occurrences_issue ON runtime_issue_occurrences(issue_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_history_issue ON runtime_issue_status_history(issue_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_screenshots_issue ON runtime_issue_screenshots(issue_id, deleted_at)',
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
}

function runtime_issue_lifecycle_column_definitions(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            'runtime_issues' => [
                'recurrence_count' => 'INT NOT NULL DEFAULT 0',
                'recurrence_generation' => 'INT NOT NULL DEFAULT 0',
                'first_recurred_at' => 'DATETIME DEFAULT NULL',
                'last_recurred_at' => 'DATETIME DEFAULT NULL',
                'revision' => 'BIGINT NOT NULL DEFAULT 1',
            ],
            'runtime_issue_occurrences' => [
                'context_json' => 'LONGTEXT DEFAULT NULL',
                'opaque_network_id' => 'VARCHAR(96) DEFAULT NULL',
            ],
        ];
    }
    return [
        'runtime_issues' => [
            'recurrence_count' => 'INTEGER NOT NULL DEFAULT 0',
            'recurrence_generation' => 'INTEGER NOT NULL DEFAULT 0',
            'first_recurred_at' => 'TEXT DEFAULT NULL',
            'last_recurred_at' => 'TEXT DEFAULT NULL',
            'revision' => 'INTEGER NOT NULL DEFAULT 1',
        ],
        'runtime_issue_occurrences' => [
            'context_json' => 'TEXT DEFAULT NULL',
            'opaque_network_id' => 'TEXT DEFAULT NULL',
        ],
    ];
}

function runtime_issue_lifecycle_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS runtime_issue_export_audits (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(128) NOT NULL UNIQUE,
                issue_id INT NOT NULL,
                actor_user_id INT NOT NULL,
                export_kind VARCHAR(48) NOT NULL,
                artifact_sha256 VARCHAR(64) NOT NULL,
                artifact_byte_size BIGINT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_runtime_issue_export_issue (issue_id, created_at),
                CONSTRAINT fk_runtime_issue_export_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_runtime_issue_export_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_deletion_requests (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(128) NOT NULL UNIQUE,
                issue_id INT NOT NULL,
                requested_by_user_id INT NOT NULL,
                confirmation_id VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                revision BIGINT NOT NULL DEFAULT 1,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                INDEX idx_runtime_issue_deletion_issue (issue_id, status),
                CONSTRAINT fk_runtime_issue_deletion_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_runtime_issue_deletion_actor FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_handoffs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(128) NOT NULL UNIQUE,
                issue_id INT NOT NULL,
                generated_by_user_id INT NOT NULL,
                schema_version INT NOT NULL,
                bundle_sha256 VARCHAR(64) NOT NULL,
                bundle_byte_size BIGINT NOT NULL,
                status VARCHAR(48) NOT NULL DEFAULT 'pending-hosted-application',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_runtime_issue_handoff_issue (issue_id, created_at),
                CONSTRAINT fk_runtime_issue_handoff_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
                CONSTRAINT fk_runtime_issue_handoff_actor FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS runtime_issue_export_audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            issue_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            export_kind TEXT NOT NULL,
            artifact_sha256 TEXT NOT NULL,
            artifact_byte_size INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_issue_export_issue ON runtime_issue_export_audits(issue_id, created_at)',
        "CREATE TABLE IF NOT EXISTS runtime_issue_deletion_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            issue_id INTEGER NOT NULL,
            requested_by_user_id INTEGER NOT NULL,
            confirmation_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            revision INTEGER NOT NULL DEFAULT 1,
            requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT DEFAULT NULL,
            FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
            FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_issue_deletion_issue ON runtime_issue_deletion_requests(issue_id, status)',
        "CREATE TABLE IF NOT EXISTS runtime_issue_handoffs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            issue_id INTEGER NOT NULL,
            generated_by_user_id INTEGER NOT NULL,
            schema_version INTEGER NOT NULL,
            bundle_sha256 TEXT NOT NULL,
            bundle_byte_size INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending-hosted-application',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE,
            FOREIGN KEY(generated_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_issue_handoff_issue ON runtime_issue_handoffs(issue_id, created_at)',
    ];
}

function runtime_issue_install_lifecycle_schema(PDO $pdo): void
{
    foreach (runtime_issue_lifecycle_column_definitions($pdo) as $table => $definitions) {
        $columns = array_fill_keys(database_migration_columns($pdo, $table), true);
        foreach ($definitions as $column => $definition) {
            if (isset($columns[$column])) continue;
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            $columns[$column] = true;
        }
    }
    foreach (runtime_issue_lifecycle_schema_statements($pdo) as $statement) $pdo->exec($statement);
}

function runtime_issue_lifecycle_schema_valid(PDO $pdo): bool
{
    foreach (runtime_issue_lifecycle_column_definitions($pdo) as $table => $definitions) {
        if (!database_migration_has_columns($pdo, $table, array_keys($definitions))) return false;
    }
    foreach ([
        'runtime_issue_export_audits',
        'runtime_issue_deletion_requests',
        'runtime_issue_handoffs',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return true;
}

function runtime_issue_clean_string(mixed $value, int $max = 512): string
{
    $text = trim((string)$value);
    $text = preg_replace('#https?://\S+#i', '[url]', $text) ?? $text;
    $text = preg_replace('/\b(?:[A-Za-z]:\\\\|\/(?:Users|home|tmp)\/)\S+/i', '[private-path]', $text) ?? $text;
    $text = preg_replace('/\b(?:cookie|authorization|password|secret|token|csrf)\s*[:=]\s*\S+/i', '$1=[redacted]', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
    if (preg_match_all('/./us', $text, $characters) === false) return substr($text, 0, $max);
    return implode('', array_slice($characters[0], 0, $max));
}

function runtime_issue_sanitize_value(mixed $value, string $key = '', int $depth = 0): mixed
{
    if (preg_match('/authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i', $key)) return '[redacted]';
    if ($depth >= 5) return '[truncated]';
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) return runtime_issue_clean_string($value);
    if (!is_array($value)) return runtime_issue_clean_string(get_debug_type($value), 80);
    $result = [];
    $count = 0;
    foreach ($value as $childKey => $childValue) {
        if ($count++ >= 64) { $result['__truncated'] = true; break; }
        $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string)$childKey) ?: 'value';
        if (preg_match('/authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i', $safeKey)) continue;
        $result[$safeKey] = runtime_issue_sanitize_value($childValue, $safeKey, $depth + 1);
    }
    return $result;
}

function runtime_issue_sanitize_evidence(array $evidence): array
{
    $sanitized = runtime_issue_sanitize_value($evidence);
    $json = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > RUNTIME_ISSUE_MAX_EVIDENCE_BYTES) return ['truncated' => true, 'original_bytes' => is_string($json) ? strlen($json) : null];
    return is_array($sanitized) ? $sanitized : [];
}

function runtime_issue_operation_id(mixed $value, string $label = 'operation'): string
{
    $value = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $value)) {
        throw new RuntimeIssueException(
            'A stable ' . $label . ' request ID is required.',
            'RUNTIME_ISSUE_REQUEST_ID_INVALID',
            400
        );
    }
    return $value;
}

function runtime_issue_client_hint(mixed $value, int $max = 96): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = preg_replace('/[^A-Za-z0-9._:-]/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') return null;
    return runtime_issue_clean_string($value, $max);
}

function runtime_issue_public_id(string $prefix): string
{
    return $prefix . '-' . strtolower(str_replace('-', '', uuid_v4()));
}

function runtime_issue_capability_projection(PDO $pdo, int $userId): array
{
    $ids = [
        'view' => 'view-runtime-issues',
        'manage' => 'manage-runtime-issues',
        'export' => 'export-runtime-issues',
        'evidence' => 'manage-runtime-evidence',
    ];
    $result = [];
    foreach ($ids as $key => $capabilityId) {
        $result[$key] = moderation_safety_has_staff_capability($pdo, $userId, $capabilityId);
    }
    $result['manualNetworkBanOwner'] = moderation_identity_is_owner($pdo, $userId);
    return $result;
}

function runtime_issue_require_capability(PDO $pdo, int $userId, string $capabilityId): void
{
    moderation_safety_require_staff_capability($pdo, $userId, $capabilityId);
}

function runtime_issue_server_context(PDO $pdo): array
{
    $migration = [];
    try {
        $status = database_migration_status($pdo);
        $migration = [
            'kind' => runtime_issue_clean_string($status['kind'] ?? 'unknown', 32),
            'storedSchemaVersion' => runtime_issue_clean_string($status['stored_schema_version'] ?? '', 96) ?: null,
            'requiredSchemaVersion' => runtime_issue_clean_string($status['required_schema_version'] ?? CHATSPACE_SCHEMA_VERSION, 96),
            'pendingCount' => max(0, (int)($status['pending_count'] ?? 0)),
            'releaseComplete' => !empty($status['release_complete']),
        ];
    } catch (Throwable) {
        $migration = [
            'kind' => 'unavailable',
            'storedSchemaVersion' => null,
            'requiredSchemaVersion' => CHATSPACE_SCHEMA_VERSION,
            'pendingCount' => null,
            'releaseComplete' => false,
        ];
    }
    return [
        'schemaId' => 'chatspace.runtime-issue-context',
        'schemaVersion' => 1,
        'runtime' => [
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'sapi' => runtime_issue_clean_string(PHP_SAPI, 32),
            'databaseEngine' => db_driver($pdo),
        ],
        'build' => [
            'schemaVersion' => CHATSPACE_SCHEMA_VERSION,
            'releaseIdentity' => 'private-development',
        ],
        'migration' => $migration,
    ];
}

function runtime_issue_server_network_identifier(PDO $pdo): ?string
{
    try {
        $ip = network_privacy_client_ip();
        return $ip === '' ? null : network_privacy_observe($pdo, $ip);
    } catch (Throwable) {
        return null;
    }
}

function runtime_issue_identity(array $input): array
{
    $category = strtolower(runtime_issue_clean_string($input['category'] ?? 'runtime', 64));
    $component = strtolower(runtime_issue_clean_string($input['component'] ?? 'application', 96));
    $code = strtoupper(runtime_issue_clean_string($input['error_code'] ?? $input['code'] ?? 'ERROR', 96));
    $message = strtolower(runtime_issue_clean_string($input['message'] ?? 'Runtime failure', 512));
    $message = preg_replace('/\b\d{2,}\b/', '#', $message) ?? $message;
    $message = preg_replace('/:\d+:\d+\b/', ':#:#', $message) ?? $message;
    $title = runtime_issue_clean_string($input['title'] ?? $input['message'] ?? $code, 191);
    $severity = strtolower(runtime_issue_clean_string($input['severity'] ?? 'error', 32));
    if (!in_array($severity, RUNTIME_ISSUE_SEVERITIES, true)) $severity = 'error';
    return ['fingerprint' => hash('sha256', implode("\n", [$category, $component, $code, $message])), 'category' => $category ?: 'runtime', 'component' => $component ?: 'application', 'error_code' => $code ?: 'ERROR', 'normalized_message' => $message ?: 'runtime failure', 'title' => $title ?: 'Runtime failure', 'severity' => $severity];
}

function runtime_issue_submit(PDO $pdo, int $reporterUserId, array $input): array
{
    $recent = $pdo->prepare('SELECT COUNT(*) FROM runtime_issue_occurrences WHERE reporter_user_id = ? AND created_at >= ?');
    $recent->execute([$reporterUserId, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ((int)$recent->fetchColumn() >= 10) throw new RuntimeException('Diagnostic report rate limit reached.');
    $identity = runtime_issue_identity($input);
    $deliberateUserReport = (string)$identity['category'] === 'user-report'
        && (string)$identity['error_code'] === 'USER_REPORT';
    if (!$deliberateUserReport && !runtime_diagnostic_policy_allows($pdo, (string)$identity['severity'])) {
        return [
            'accepted' => false,
            'collectionMode' => runtime_diagnostic_policy_mode($pdo)['effectiveMode'],
            'reason' => 'The finite production diagnostic policy did not admit this severity.',
        ];
    }
    $evidence = runtime_issue_sanitize_evidence(is_array($input['evidence'] ?? null) ? $input['evidence'] : []);
    $buildId = runtime_issue_client_hint($input['build_id'] ?? null);
    $correlation = runtime_issue_client_hint($input['request_correlation'] ?? null);
    $context = runtime_issue_server_context($pdo);
    $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contextJson)) {
        throw new RuntimeIssueException(
            'Server diagnostic context could not be encoded safely.',
            'RUNTIME_ISSUE_CONTEXT_INVALID',
            500
        );
    }
    $opaqueNetworkId = runtime_issue_server_network_identifier($pdo);
    $transaction = database_transaction_begin($pdo, true);
    try {
        $lookupSql = 'SELECT * FROM runtime_issues WHERE fingerprint = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $lookupSql .= ' FOR UPDATE';
        $lookup = $pdo->prepare($lookupSql);
        $lookup->execute([$identity['fingerprint']]);
        $issue = $lookup->fetch();
        if (!$issue) {
            try {
                $pdo->prepare('INSERT INTO runtime_issues (fingerprint, category, component, error_code, normalized_message, title, severity, reporter_user_id, occurrence_count) VALUES (?,?,?,?,?,?,?,?,1)')->execute([$identity['fingerprint'], $identity['category'], $identity['component'], $identity['error_code'], $identity['normalized_message'], $identity['title'], $identity['severity'], $reporterUserId]);
                $issueId = (int)$pdo->lastInsertId();
            } catch (PDOException $error) {
                $lookup->execute([$identity['fingerprint']]);
                $issue = $lookup->fetch();
                if (!$issue) throw $error;
                $issueId = (int)$issue['id'];
                runtime_issue_record_existing_occurrence($pdo, $issue);
            }
        } else {
            $issueId = (int)$issue['id'];
            runtime_issue_record_existing_occurrence($pdo, $issue);
        }
        $occurrence = $pdo->prepare(
            'INSERT INTO runtime_issue_occurrences
             (issue_id, reporter_user_id, evidence_json, build_id, request_correlation, context_json, opaque_network_id)
             VALUES (?,?,?,?,?,?,?)'
        );
        $occurrence->execute([
            $issueId,
            $reporterUserId,
            json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $buildId,
            $correlation,
            $contextJson,
            $opaqueNetworkId,
        ]);
        $occurrenceId = (int)$pdo->lastInsertId();
        if (is_string($opaqueNetworkId) && $opaqueNetworkId !== '') {
            network_moderation_record_context(
                $pdo,
                $opaqueNetworkId,
                'runtime-occurrence',
                'Runtime occurrence #' . $occurrenceId,
                $reporterUserId,
                'runtime-occurrence:' . $occurrenceId
            );
        }
        runtime_diagnostic_retention_track_issue($pdo, $issueId, $identity);
        $ids = $pdo->prepare('SELECT id FROM runtime_issue_occurrences WHERE issue_id = ? ORDER BY id DESC');
        $ids->execute([$issueId]);
        $stale = array_slice(array_map('intval', array_column($ids->fetchAll(), 'id')), RUNTIME_ISSUE_MAX_OCCURRENCES);
        if ($stale) $pdo->prepare('DELETE FROM runtime_issue_occurrences WHERE id IN (' . implode(',', array_fill(0, count($stale), '?')) . ')')->execute($stale);
        $current = $pdo->prepare('SELECT status, revision, occurrence_count, recurrence_count, recurrence_generation FROM runtime_issues WHERE id = ?');
        $current->execute([$issueId]);
        $projection = $current->fetch() ?: [];
        database_transaction_commit($pdo, $transaction);
        return [
            'accepted' => true,
            'issue_id' => $issueId,
            'occurrence_id' => $occurrenceId,
            'fingerprint' => $identity['fingerprint'],
            'status' => $projection['status'] ?? 'new',
            'revision' => (int)($projection['revision'] ?? 1),
            'occurrenceCount' => (int)($projection['occurrence_count'] ?? 1),
            'recurrenceCount' => (int)($projection['recurrence_count'] ?? 0),
            'recurrenceGeneration' => (int)($projection['recurrence_generation'] ?? 0),
        ];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function runtime_issue_record_existing_occurrence(PDO $pdo, array $issue): void
{
    $issueId = (int)$issue['id'];
    if ((string)$issue['status'] === 'resolved') {
        $updated = $pdo->prepare(
            "UPDATE runtime_issues
                SET status='regressed',
                    occurrence_count=occurrence_count+1,
                    recurrence_count=recurrence_count+1,
                    recurrence_generation=recurrence_generation+1,
                    first_recurred_at=COALESCE(first_recurred_at,CURRENT_TIMESTAMP),
                    last_recurred_at=CURRENT_TIMESTAMP,
                    last_seen_at=CURRENT_TIMESTAMP,
                    updated_at=CURRENT_TIMESTAMP,
                    revision=revision+1
              WHERE id=? AND revision=? AND status='resolved'"
        );
        $updated->execute([$issueId, (int)($issue['revision'] ?? 1)]);
        if ($updated->rowCount() !== 1) {
            throw new RuntimeIssueException(
                'The matching issue changed while recurrence was recorded.',
                'RUNTIME_ISSUE_RECURRENCE_CONFLICT',
                409
            );
        }
        $pdo->prepare(
            'INSERT INTO runtime_issue_status_history
             (issue_id, from_status, to_status, reason)
             VALUES (?,?,?,?)'
        )->execute([$issueId, 'resolved', 'regressed', 'New matching occurrence after resolution']);
        return;
    }
    $pdo->prepare(
        'UPDATE runtime_issues
            SET occurrence_count=occurrence_count+1,
                last_seen_at=CURRENT_TIMESTAMP,
                updated_at=CURRENT_TIMESTAMP,
                revision=revision+1
          WHERE id=?'
    )->execute([$issueId]);
}

function runtime_issue_project_row(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'fingerprint' => (string)$row['fingerprint'],
        'category' => (string)$row['category'],
        'component' => (string)$row['component'],
        'errorCode' => (string)$row['error_code'],
        'title' => (string)$row['title'],
        'severity' => (string)$row['severity'],
        'status' => (string)$row['status'],
        'revision' => max(1, (int)($row['revision'] ?? 1)),
        'occurrenceCount' => max(0, (int)$row['occurrence_count']),
        'recurrenceCount' => max(0, (int)($row['recurrence_count'] ?? 0)),
        'recurrenceGeneration' => max(0, (int)($row['recurrence_generation'] ?? 0)),
        'firstRecurredAt' => $row['first_recurred_at'] ?? null,
        'lastRecurredAt' => $row['last_recurred_at'] ?? null,
        'firstSeenAt' => $row['first_seen_at'],
        'lastSeenAt' => $row['last_seen_at'],
        'updatedAt' => $row['updated_at'],
    ];
}

function runtime_issue_list(PDO $pdo, ?string $status = null, int $limit = 200): array
{
    return runtime_issue_query($pdo, $status, null, 1, min($limit, RUNTIME_ISSUE_MAX_PAGE_SIZE))['issues'];
}

function runtime_issue_query(
    PDO $pdo,
    ?string $status,
    ?string $severity,
    int $page,
    int $perPage
): array {
    if ($status !== null && $status !== '' && !in_array($status, RUNTIME_ISSUE_STATUSES, true)) {
        throw new RuntimeIssueException('Choose a supported issue state.', 'RUNTIME_ISSUE_STATUS_INVALID', 400);
    }
    if ($severity !== null && $severity !== '' && !in_array($severity, RUNTIME_ISSUE_SEVERITIES, true)) {
        throw new RuntimeIssueException('Choose a supported issue severity.', 'RUNTIME_ISSUE_SEVERITY_INVALID', 400);
    }
    $page = max(1, $page);
    $perPage = max(1, min(RUNTIME_ISSUE_MAX_PAGE_SIZE, $perPage));
    $where = [];
    $params = [];
    if ($status !== null && $status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($severity !== null && $severity !== '') {
        $where[] = 'severity = ?';
        $params[] = $severity;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM runtime_issues' . $whereSql);
    $count->execute($params);
    $total = max(0, (int)$count->fetchColumn());
    $offset = ($page - 1) * $perPage;
    $rows = $pdo->prepare(
        'SELECT * FROM runtime_issues' . $whereSql
        . ' ORDER BY last_seen_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $rows->execute($params);
    $statusTotals = array_fill_keys(RUNTIME_ISSUE_STATUSES, 0);
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM runtime_issues GROUP BY status')->fetchAll() as $row) {
        if (isset($statusTotals[(string)$row['status']])) $statusTotals[(string)$row['status']] = (int)$row['total'];
    }
    $severityTotals = array_fill_keys(RUNTIME_ISSUE_SEVERITIES, 0);
    foreach ($pdo->query('SELECT severity, COUNT(*) AS total FROM runtime_issues GROUP BY severity')->fetchAll() as $row) {
        if (isset($severityTotals[(string)$row['severity']])) $severityTotals[(string)$row['severity']] = (int)$row['total'];
    }
    return [
        'issues' => array_map('runtime_issue_project_row', $rows->fetchAll()),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'pageCount' => max(1, (int)ceil($total / $perPage)),
        'totals' => [
            'all' => array_sum($statusTotals),
            'byStatus' => $statusTotals,
            'bySeverity' => $severityTotals,
        ],
    ];
}

function runtime_issue_status_catalog(): array
{
    return [
        ['id' => 'new', 'label' => 'New'],
        ['id' => 'confirmed', 'label' => 'Confirmed', 'acknowledgement' => true],
        ['id' => 'investigating', 'label' => 'Investigating'],
        ['id' => 'fixed-pending-verification', 'label' => 'Fixed pending verification'],
        ['id' => 'resolved', 'label' => 'Resolved'],
        ['id' => 'expected', 'label' => 'Expected'],
        ['id' => 'ignored', 'label' => 'Ignored with reason', 'reasonRequired' => true],
        ['id' => 'regressed', 'label' => 'Regressed'],
    ];
}

function runtime_issue_detail(PDO $pdo, int $issueId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM runtime_issues WHERE id = ? LIMIT 1');
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch();
    if (!$issue) return null;
    $occurrences = $pdo->prepare('SELECT id, evidence_json, build_id, request_correlation, context_json, opaque_network_id, created_at FROM runtime_issue_occurrences WHERE issue_id = ? ORDER BY id DESC LIMIT 100');
    $occurrences->execute([$issueId]);
    $history = $pdo->prepare('SELECT h.*, u.display_name AS actor_name FROM runtime_issue_status_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.issue_id = ? ORDER BY h.id DESC');
    $history->execute([$issueId]);
    $screenshots = $pdo->prepare('SELECT public_id, mime_type, width, height, byte_size, created_at FROM runtime_issue_screenshots WHERE issue_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    $screenshots->execute([$issueId]);
    $projectedOccurrences = [];
    foreach ($occurrences->fetchAll() as $row) {
        $decoded = json_decode((string)$row['evidence_json'], true);
        $context = json_decode((string)($row['context_json'] ?? ''), true);
        $projectedOccurrences[] = [
            'id' => (int)$row['id'],
            'evidence' => is_array($decoded) ? $decoded : [],
            'context' => is_array($context) ? runtime_issue_sanitize_value($context) : null,
            'networkContext' => $row['opaque_network_id']
                ? [
                    'owner' => 'This runtime occurrence and its reporting account',
                    'recorded' => true,
                    'addressRetainedOrDisplayed' => false,
                    'notice' => 'The keyed correlation is uncertain on shared networks and does not identify a person or household.',
                ]
                : null,
            'clientBuildHint' => $row['build_id'],
            'clientRequestCorrelation' => $row['request_correlation'],
            'createdAt' => $row['created_at'],
        ];
    }
    $retention = $pdo->prepare('SELECT retention_class, hold_active, hold_reason, retained_until, fingerprint_until, updated_at FROM runtime_diagnostic_retention WHERE issue_id = ? LIMIT 1');
    $retention->execute([$issueId]);
    $retentionRow = $retention->fetch() ?: null;
    return [
        'issue' => runtime_issue_project_row($issue),
        'statusCatalog' => runtime_issue_status_catalog(),
        'occurrences' => $projectedOccurrences,
        'history' => array_map(fn(array $row): array => [
            'fromStatus' => $row['from_status'],
            'toStatus' => $row['to_status'],
            'actorName' => $row['actor_name'] ?: 'System',
            'reason' => $row['reason'],
            'verificationReference' => $row['verification_reference'],
            'createdAt' => $row['created_at'],
        ], $history->fetchAll()),
        'screenshots' => array_map(fn(array $row): array => [
            'publicId' => $row['public_id'],
            'mimeType' => $row['mime_type'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'byteSize' => (int)$row['byte_size'],
            'createdAt' => $row['created_at'],
        ], $screenshots->fetchAll()),
        'retention' => is_array($retentionRow) ? [
            'class' => (string)$retentionRow['retention_class'],
            'holdActive' => !empty($retentionRow['hold_active']),
            'holdReason' => $retentionRow['hold_reason'] ?: null,
            'retainedUntil' => $retentionRow['retained_until'],
            'fingerprintUntil' => $retentionRow['fingerprint_until'],
            'updatedAt' => $retentionRow['updated_at'],
        ] : null,
    ];
}

function runtime_issue_update_status(
    PDO $pdo,
    int $issueId,
    string $status,
    int $actorUserId,
    string $reason,
    string $verificationReference,
    int $expectedRevision
): array
{
    if (!in_array($status, RUNTIME_ISSUE_STATUSES, true)) {
        throw new RuntimeIssueException('Choose a supported issue state.', 'RUNTIME_ISSUE_STATUS_INVALID', 400);
    }
    $reason = runtime_issue_clean_string($reason, 512);
    $verificationReference = runtime_issue_clean_string($verificationReference, 191);
    if ($status === 'ignored' && $reason === '') {
        throw new RuntimeIssueException('Ignored issues require a reason.', 'RUNTIME_ISSUE_REASON_REQUIRED', 400);
    }
    if (in_array($status, ['fixed-pending-verification', 'resolved'], true) && $verificationReference === '') {
        throw new RuntimeIssueException('Verification evidence is required for this state.', 'RUNTIME_ISSUE_VERIFICATION_REQUIRED', 400);
    }
    $transaction = database_transaction_begin($pdo, true);
    try {
        $sql = 'SELECT status, revision FROM runtime_issues WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$issueId]);
        $current = $stmt->fetch();
        if (!is_array($current)) {
            throw new RuntimeIssueException('Issue not found.', 'RUNTIME_ISSUE_NOT_FOUND', 404);
        }
        $from = (string)$current['status'];
        $revision = (int)$current['revision'];
        if ($revision !== $expectedRevision) {
            throw new RuntimeIssueException(
                'The issue changed elsewhere. Reload before updating it.',
                'RUNTIME_ISSUE_REVISION_STALE',
                409,
                ['currentRevision' => $revision]
            );
        }
        if ($from !== $status) {
            $update = $pdo->prepare(
                'UPDATE runtime_issues
                    SET status=?, revision=revision+1, updated_at=CURRENT_TIMESTAMP
                  WHERE id=? AND revision=?'
            );
            $update->execute([$status, $issueId, $revision]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeIssueException(
                    'The issue changed elsewhere. Reload before updating it.',
                    'RUNTIME_ISSUE_REVISION_STALE',
                    409
                );
            }
            $pdo->prepare('INSERT INTO runtime_issue_status_history (issue_id, from_status, to_status, actor_user_id, reason, verification_reference) VALUES (?,?,?,?,?,?)')->execute([$issueId, $from, $status, $actorUserId, $reason ?: null, $verificationReference ?: null]);
            log_tool(
                $pdo,
                $actorUserId,
                'runtime_issue_status_update',
                null,
                null,
                'Issue ' . $issueId . '; state ' . $from . ' to ' . $status
                . '; revision ' . $revision . ' to ' . ($revision + 1)
            );
        }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    if ($status === 'resolved' && !runtime_diagnostic_retention_hold_active($pdo, $issueId)) {
        runtime_issue_delete_screenshots_for_issue($pdo, $issueId, $actorUserId);
    }
    return runtime_issue_detail($pdo, $issueId) ?? [];
}

function runtime_diagnostic_retention_hold_active(PDO $pdo, int $issueId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT hold_active FROM runtime_diagnostic_retention WHERE issue_id = ? LIMIT 1');
        $stmt->execute([$issueId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function runtime_issue_private_root(): string
{
    $publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $configured = defined('CHATSPACE_PRIVATE_STORAGE_PATH') ? (string)CHATSPACE_PRIVATE_STORAGE_PATH : dirname($publicRoot) . DIRECTORY_SEPARATOR . 'chatspace-private';
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
    $public = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRoot), DIRECTORY_SEPARATOR));
    $candidate = strtolower($root);
    if ($root === '' || $candidate === $public || str_starts_with($candidate . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private diagnostic storage must be outside the public application root.');
    return $root . DIRECTORY_SEPARATOR . 'runtime-issue-screenshots';
}

function runtime_issue_store_screenshot(PDO $pdo, int $issueId, int $occurrenceId, int $ownerUserId, string $dataUrl): array
{
    if (app_setting($pdo, 'diagnostic_screenshots_enabled', '0') !== '1') throw new RuntimeException('Diagnostic screenshots are disabled.');
    $retention = (int)app_setting($pdo, 'diagnostic_screenshot_retention_days', '0');
    if ($retention < 1 || $retention > 365) throw new RuntimeException('Choose screenshot retention before enabling capture.');
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) throw new InvalidArgumentException('Only censored PNG screenshots are accepted.');
    $bytes = base64_decode($match[1], true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > RUNTIME_ISSUE_MAX_SCREENSHOT_BYTES) throw new InvalidArgumentException('Screenshot exceeds the allowed size.');
    $size = @getimagesizefromstring($bytes);
    if (!$size || ($size['mime'] ?? '') !== 'image/png') throw new InvalidArgumentException('Screenshot is not a valid PNG.');
    [$width, $height] = array_map('intval', $size);
    if ($width < 1 || $height < 1 || $width > RUNTIME_ISSUE_MAX_SCREENSHOT_WIDTH || $height > RUNTIME_ISSUE_MAX_SCREENSHOT_HEIGHT) throw new InvalidArgumentException('Screenshot dimensions are outside the allowed range.');
    $recent = $pdo->prepare('SELECT COUNT(*) FROM runtime_issue_screenshots WHERE owner_user_id = ? AND created_at >= ?');
    $recent->execute([$ownerUserId, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$recent->fetchColumn() >= 3) throw new RuntimeException('Diagnostic screenshot rate limit reached.');
    $check = $pdo->prepare('SELECT id FROM runtime_issue_occurrences WHERE id = ? AND issue_id = ? AND reporter_user_id = ? LIMIT 1');
    $check->execute([$occurrenceId, $issueId, $ownerUserId]);
    if (!$check->fetchColumn()) throw new InvalidArgumentException('Screenshot occurrence does not belong to the issue.');
    $directory = runtime_issue_private_root();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Could not create private diagnostic storage.');
    $publicId = bin2hex(random_bytes(16));
    $storageName = bin2hex(random_bytes(24)) . '.png';
    $path = $directory . DIRECTORY_SEPARATOR . $storageName;
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Could not store censored screenshot.');
    try {
        $pdo->prepare('INSERT INTO runtime_issue_screenshots (public_id, issue_id, occurrence_id, owner_user_id, storage_name, mime_type, width, height, byte_size) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$publicId, $issueId, $occurrenceId, $ownerUserId, $storageName, 'image/png', $width, $height, strlen($bytes)]);
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
    return ['publicId' => $publicId, 'width' => $width, 'height' => $height, 'byteSize' => strlen($bytes)];
}

function runtime_issue_screenshot_record(PDO $pdo, string $publicId): ?array
{
    $stmt = $pdo->prepare('SELECT s.*, i.status AS issue_status FROM runtime_issue_screenshots s JOIN runtime_issues i ON i.id = s.issue_id WHERE s.public_id = ? AND s.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$publicId]);
    return $stmt->fetch() ?: null;
}

function runtime_issue_delete_screenshot(PDO $pdo, string $publicId, int $actorUserId, bool $isAdmin): bool
{
    $record = runtime_issue_screenshot_record($pdo, $publicId);
    if (!$record) return false;
    if (!$isAdmin && (int)$record['owner_user_id'] !== $actorUserId) throw new RuntimeException('Screenshot deletion is not authorized.');
    $path = runtime_issue_private_root() . DIRECTORY_SEPARATOR . basename((string)$record['storage_name']);
    if (is_file($path)) @unlink($path);
    $pdo->prepare('UPDATE runtime_issue_screenshots SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$record['id']]);
    return true;
}

function runtime_issue_delete_screenshots_for_issue(PDO $pdo, int $issueId, int $actorUserId): void
{
    $stmt = $pdo->prepare('SELECT public_id FROM runtime_issue_screenshots WHERE issue_id = ? AND deleted_at IS NULL');
    $stmt->execute([$issueId]);
    foreach ($stmt->fetchAll() as $row) runtime_issue_delete_screenshot($pdo, (string)$row['public_id'], $actorUserId, true);
}

function runtime_issue_cleanup_screenshots(PDO $pdo): int
{
    $days = (int)app_setting($pdo, 'diagnostic_screenshot_retention_days', '0');
    if ($days < 1 || $days > 365) return 0;
    $stmt = $pdo->prepare(
        "SELECT s.public_id
           FROM runtime_issue_screenshots s
           JOIN runtime_issues i ON i.id = s.issue_id
           LEFT JOIN runtime_diagnostic_retention r ON r.issue_id = s.issue_id
          WHERE s.deleted_at IS NULL AND s.created_at < ?
            AND COALESCE(r.hold_active, 0) = 0
            AND i.status NOT IN ('investigating', 'fixed-pending-verification')"
    );
    $stmt->execute([gmdate('Y-m-d H:i:s', time() - ($days * 86400))]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) if (runtime_issue_delete_screenshot($pdo, (string)$row['public_id'], 0, true)) $count++;
    return $count;
}

function runtime_issue_support_bundle(PDO $pdo, int $issueId, ?string $generatedAt = null): array
{
    $detail = runtime_issue_detail($pdo, $issueId);
    if (!$detail) throw new RuntimeException('Issue not found.');
    return [
        'schemaId' => 'chatspace.runtime-issue-support-bundle',
        'schemaVersion' => 1,
        'generatedAt' => $generatedAt ?? gmdate('c'),
        'privacy' => [
            'chatContentsIncluded' => false,
            'credentialsIncluded' => false,
            'sdpIceIncluded' => false,
            'rawMediaIncluded' => false,
            'screenshotsIncluded' => false,
            'rawNetworkAddressesIncluded' => false,
        ],
        'issue' => $detail['issue'],
        'occurrences' => $detail['occurrences'],
        'history' => $detail['history'],
        'retention' => $detail['retention'],
        'screenshotMetadata' => $detail['screenshots'],
    ];
}

function runtime_issue_handoff_bundle(PDO $pdo, int $issueId, ?string $generatedAt = null): array
{
    $detail = runtime_issue_detail($pdo, $issueId);
    if (!$detail) {
        throw new RuntimeIssueException('Issue not found.', 'RUNTIME_ISSUE_NOT_FOUND', 404);
    }
    $contexts = [];
    foreach ($detail['occurrences'] as $occurrence) {
        $contexts[] = [
            'context' => $occurrence['context'],
            'networkContext' => $occurrence['networkContext'],
            'clientBuildHint' => $occurrence['clientBuildHint'],
            'createdAt' => $occurrence['createdAt'],
        ];
    }
    return [
        'schemaId' => 'chatspace.runtime-issue-handoff',
        'schemaVersion' => 1,
        'generatedAt' => $generatedAt ?? gmdate('c'),
        'lifecycle' => 'resolved/pending-hosted-application',
        'status' => 'pending-hosted-application',
        'privacy' => [
            'payloadContentIncluded' => false,
            'screenshotPixelsIncluded' => false,
            'networkAddressDataIncluded' => false,
            'credentialsIncluded' => false,
            'ownerValuesIncluded' => false,
        ],
        'issue' => $detail['issue'],
        'history' => $detail['history'],
        'contextSummaries' => $contexts,
        'retention' => $detail['retention'],
        'screenshotMetadata' => $detail['screenshots'],
    ];
}

function runtime_issue_export_preview(PDO $pdo, int $issueId, string $kind): array
{
    if (!in_array($kind, ['support-bundle', 'hosted-pending-handoff'], true)) {
        throw new RuntimeIssueException('Choose a supported export.', 'RUNTIME_ISSUE_EXPORT_KIND_INVALID', 400);
    }
    $detail = runtime_issue_detail($pdo, $issueId);
    if (!$detail) {
        throw new RuntimeIssueException('Issue not found.', 'RUNTIME_ISSUE_NOT_FOUND', 404);
    }
    $issue = $detail['issue'];
    $previewToken = strtoupper(hash('sha256', implode("\n", [
        'chatspace.runtime-issue-export-preview.v1',
        $kind,
        (string)$issue['id'],
        (string)$issue['fingerprint'],
        (string)$issue['revision'],
    ])));
    return [
        'kind' => $kind,
        'issueId' => $issue['id'],
        'fingerprint' => $issue['fingerprint'],
        'revision' => $issue['revision'],
        'previewToken' => $previewToken,
        'schemaId' => $kind === 'support-bundle'
            ? 'chatspace.runtime-issue-support-bundle'
            : 'chatspace.runtime-issue-handoff',
        'schemaVersion' => 1,
        'includes' => [
            'stable issue identity and lifecycle',
            'aggregate occurrence and recurrence facts',
            'sanitized server runtime, build, and schema context',
            'privacy-bounded network context without an address or identifier',
            'bounded history and verification references',
            'retention and hold metadata',
            'censored screenshot metadata',
        ],
        'excludes' => [
            'screenshot pixels', 'raw network addresses', 'proxy chains',
            'credentials', 'sessions', 'private messages or request bodies',
            'raw media', 'configuration', 'private filesystem paths',
        ],
        'explicitActionRequired' => true,
        'contentFreeAuditOnly' => true,
    ];
}

function runtime_issue_export(
    PDO $pdo,
    int $issueId,
    int $actorUserId,
    string $kind,
    string $requestId,
    string $previewToken
): array {
    $requestId = runtime_issue_operation_id($requestId, 'export');
    $preview = runtime_issue_export_preview($pdo, $issueId, $kind);
    if (!hash_equals((string)$preview['previewToken'], strtoupper(trim($previewToken)))) {
        throw new RuntimeIssueException(
            'The export preview is stale. Review the current content inventory again.',
            'RUNTIME_ISSUE_EXPORT_PREVIEW_STALE',
            409,
            ['preview' => $preview]
        );
    }
    $generatedAt = (string)(runtime_issue_detail($pdo, $issueId)['issue']['updatedAt'] ?? gmdate('c'));
    $artifact = $kind === 'support-bundle'
        ? runtime_issue_support_bundle($pdo, $issueId, $generatedAt)
        : runtime_issue_handoff_bundle($pdo, $issueId, $generatedAt);
    $encoded = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || strlen($encoded) > RUNTIME_ISSUE_MAX_EXPORT_BYTES) {
        throw new RuntimeIssueException(
            'The sanitized export exceeds the bounded artifact size.',
            'RUNTIME_ISSUE_EXPORT_TOO_LARGE',
            413
        );
    }
    $hash = strtoupper(hash('sha256', $encoded));
    $bytes = strlen($encoded);
    $idempotent = false;
    $transaction = database_transaction_begin($pdo, true);
    try {
    if ($kind === 'support-bundle') {
        $existing = $pdo->prepare(
            'SELECT issue_id,actor_user_id,export_kind,artifact_sha256,artifact_byte_size
               FROM runtime_issue_export_audits WHERE request_id=? LIMIT 1'
            . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '')
        );
        $existing->execute([$requestId]);
        $row = $existing->fetch();
        if (is_array($row)) {
            $matches = (int)$row['issue_id'] === $issueId
                && (int)$row['actor_user_id'] === $actorUserId
                && hash_equals((string)$row['export_kind'], $kind)
                && hash_equals((string)$row['artifact_sha256'], $hash)
                && (int)$row['artifact_byte_size'] === $bytes;
            if (!$matches) {
                throw new RuntimeIssueException(
                    'That export request ID belongs to another artifact.',
                    'RUNTIME_ISSUE_EXPORT_REQUEST_CONFLICT',
                    409
                );
            }
            $idempotent = true;
        } else {
            $pdo->prepare(
                'INSERT INTO runtime_issue_export_audits
                 (public_id,request_id,issue_id,actor_user_id,export_kind,artifact_sha256,artifact_byte_size)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                runtime_issue_public_id('runtime-export'),
                $requestId,
                $issueId,
                $actorUserId,
                $kind,
                $hash,
                $bytes,
            ]);
        }
    } else {
        $existing = $pdo->prepare(
            'SELECT issue_id,generated_by_user_id,schema_version,bundle_sha256,bundle_byte_size,status
               FROM runtime_issue_handoffs WHERE request_id=? LIMIT 1'
            . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '')
        );
        $existing->execute([$requestId]);
        $row = $existing->fetch();
        if (is_array($row)) {
            $matches = (int)$row['issue_id'] === $issueId
                && (int)$row['generated_by_user_id'] === $actorUserId
                && (int)$row['schema_version'] === 1
                && hash_equals((string)$row['bundle_sha256'], $hash)
                && (int)$row['bundle_byte_size'] === $bytes
                && hash_equals((string)$row['status'], 'pending-hosted-application');
            if (!$matches) {
                throw new RuntimeIssueException(
                    'That handoff request ID belongs to another artifact.',
                    'RUNTIME_ISSUE_HANDOFF_REQUEST_CONFLICT',
                    409
                );
            }
            $idempotent = true;
        } else {
            $pdo->prepare(
                'INSERT INTO runtime_issue_handoffs
                 (public_id,request_id,issue_id,generated_by_user_id,schema_version,bundle_sha256,bundle_byte_size,status)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                runtime_issue_public_id('runtime-handoff'),
                $requestId,
                $issueId,
                $actorUserId,
                1,
                $hash,
                $bytes,
                'pending-hosted-application',
            ]);
        }
    }
    if (!$idempotent) {
        log_tool(
            $pdo,
            $actorUserId,
            $kind === 'support-bundle' ? 'runtime_issue_support_bundle_export' : 'runtime_issue_handoff_export',
            null,
            null,
            'Issue ' . $issueId . '; request ' . $requestId . '; sha256 ' . $hash
            . '; bytes ' . $bytes . '; no exported payload retained.'
        );
    }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    return [
        'artifact' => $artifact,
        'download' => [
            'kind' => $kind,
            'sha256' => $hash,
            'byteSize' => $bytes,
            'requestId' => $requestId,
            'idempotentReplay' => $idempotent,
            'payloadRetainedByAudit' => false,
        ],
    ];
}

function runtime_issue_deletion_preview(PDO $pdo, int $issueId): array
{
    $detail = runtime_issue_detail($pdo, $issueId);
    if (!$detail) {
        throw new RuntimeIssueException('Issue not found.', 'RUNTIME_ISSUE_NOT_FOUND', 404);
    }
    $issue = $detail['issue'];
    $evidence = $pdo->prepare(
        'SELECT COUNT(*) AS occurrence_count, COALESCE(SUM(LENGTH(evidence_json)),0) AS evidence_bytes
           FROM runtime_issue_occurrences WHERE issue_id=?'
    );
    $evidence->execute([$issueId]);
    $evidenceRow = $evidence->fetch() ?: [];
    $screenshots = $pdo->prepare(
        'SELECT COUNT(*) AS screenshot_count, COALESCE(SUM(byte_size),0) AS screenshot_bytes
           FROM runtime_issue_screenshots WHERE issue_id=? AND deleted_at IS NULL'
    );
    $screenshots->execute([$issueId]);
    $screenshotRow = $screenshots->fetch() ?: [];
    $confirmationId = strtoupper(hash('sha256', implode("\n", [
        'chatspace.runtime-issue-evidence-delete.v1',
        (string)$issueId,
        (string)$issue['fingerprint'],
        (string)$issue['revision'],
    ])));
    return [
        'issueId' => $issueId,
        'fingerprint' => $issue['fingerprint'],
        'revision' => $issue['revision'],
        'status' => $issue['status'],
        'holdActive' => !empty($detail['retention']['holdActive']),
        'eligible' => empty($detail['retention']['holdActive'])
            && !in_array($issue['status'], ['investigating', 'fixed-pending-verification'], true),
        'impact' => [
            'occurrences' => (int)($evidenceRow['occurrence_count'] ?? 0),
            'structuredEvidenceBytes' => (int)($evidenceRow['evidence_bytes'] ?? 0),
            'screenshots' => (int)($screenshotRow['screenshot_count'] ?? 0),
            'screenshotBytes' => (int)($screenshotRow['screenshot_bytes'] ?? 0),
            'statusHistoryPreserved' => true,
            'aggregateCountsPreserved' => true,
            'minimalFingerprintPreserved' => true,
        ],
        'confirmationId' => $confirmationId,
        'requiresRecentAuthentication' => true,
        'requiresExplicitConfirmation' => true,
    ];
}

function runtime_issue_delete_evidence(
    PDO $pdo,
    int $issueId,
    int $actorUserId,
    int $expectedRevision,
    string $fingerprint,
    string $requestId,
    string $confirmationId,
    bool $confirmed
): array {
    if (!$confirmed) {
        throw new RuntimeIssueException(
            'Review and confirm the exact evidence-deletion impact.',
            'RUNTIME_ISSUE_DELETION_CONFIRMATION_REQUIRED',
            409,
            ['preview' => runtime_issue_deletion_preview($pdo, $issueId)]
        );
    }
    $requestId = runtime_issue_operation_id($requestId, 'evidence-deletion');
    $fingerprint = strtolower(trim($fingerprint));
    $confirmationId = strtoupper(trim($confirmationId));
    $existing = $pdo->prepare(
        'SELECT issue_id,requested_by_user_id,confirmation_id,status,revision
           FROM runtime_issue_deletion_requests WHERE request_id=? LIMIT 1'
    );
    $existing->execute([$requestId]);
    $prior = $existing->fetch();
    if (is_array($prior)) {
        $matches = (int)$prior['issue_id'] === $issueId
            && (int)$prior['requested_by_user_id'] === $actorUserId
            && (int)$prior['revision'] === $expectedRevision
            && hash_equals((string)$prior['confirmation_id'], $confirmationId);
        if (!$matches || (string)$prior['status'] !== 'completed') {
            throw new RuntimeIssueException(
                'That evidence-deletion request ID belongs to another operation.',
                'RUNTIME_ISSUE_DELETION_REQUEST_CONFLICT',
                409
            );
        }
        return [
            'ok' => true,
            'idempotentReplay' => true,
            'requestId' => $requestId,
            'issue' => runtime_issue_detail($pdo, $issueId)['issue'] ?? null,
        ];
    }
    $transaction = database_transaction_begin($pdo, true);
    $storageNames = [];
    try {
        $sql = 'SELECT fingerprint,status,revision FROM runtime_issues WHERE id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $statement = $pdo->prepare($sql);
        $statement->execute([$issueId]);
        $issue = $statement->fetch();
        if (!is_array($issue)) {
            throw new RuntimeIssueException('Issue not found.', 'RUNTIME_ISSUE_NOT_FOUND', 404);
        }
        $replay = $pdo->prepare(
            'SELECT issue_id,requested_by_user_id,confirmation_id,status,revision
               FROM runtime_issue_deletion_requests WHERE request_id=? LIMIT 1'
            . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '')
        );
        $replay->execute([$requestId]);
        $completed = $replay->fetch();
        if (is_array($completed)) {
            $matches = (int)$completed['issue_id'] === $issueId
                && (int)$completed['requested_by_user_id'] === $actorUserId
                && (int)$completed['revision'] === $expectedRevision
                && hash_equals((string)$completed['confirmation_id'], $confirmationId)
                && (string)$completed['status'] === 'completed';
            if (!$matches) {
                throw new RuntimeIssueException(
                    'That evidence-deletion request ID belongs to another operation.',
                    'RUNTIME_ISSUE_DELETION_REQUEST_CONFLICT',
                    409
                );
            }
            database_transaction_commit($pdo, $transaction);
            return [
                'ok' => true,
                'idempotentReplay' => true,
                'requestId' => $requestId,
                'issue' => runtime_issue_detail($pdo, $issueId)['issue'] ?? null,
            ];
        }
        if ((int)$issue['revision'] !== $expectedRevision
            || !hash_equals((string)$issue['fingerprint'], $fingerprint)) {
            throw new RuntimeIssueException(
                'The issue changed elsewhere. Review the current deletion impact again.',
                'RUNTIME_ISSUE_DELETION_STALE',
                409,
                ['preview' => runtime_issue_deletion_preview($pdo, $issueId)]
            );
        }
        $preview = runtime_issue_deletion_preview($pdo, $issueId);
        if (!hash_equals((string)$preview['confirmationId'], $confirmationId)) {
            throw new RuntimeIssueException(
                'The evidence-deletion confirmation is stale.',
                'RUNTIME_ISSUE_DELETION_CONFIRMATION_STALE',
                409,
                ['preview' => $preview]
            );
        }
        if (!$preview['eligible']) {
            throw new RuntimeIssueException(
                'Evidence under a hold or active investigation cannot be deleted.',
                'RUNTIME_ISSUE_DELETION_PRESERVED',
                409,
                ['preview' => $preview]
            );
        }
        $names = $pdo->prepare(
            'SELECT storage_name FROM runtime_issue_screenshots
              WHERE issue_id=? AND deleted_at IS NULL'
        );
        $names->execute([$issueId]);
        $storageNames = array_map(
            static fn(array $row): string => basename((string)$row['storage_name']),
            $names->fetchAll()
        );
        $pdo->prepare(
            'UPDATE runtime_issue_screenshots
                SET deleted_at=CURRENT_TIMESTAMP
              WHERE issue_id=? AND deleted_at IS NULL'
        )->execute([$issueId]);
        $pdo->prepare('DELETE FROM runtime_issue_occurrences WHERE issue_id=?')->execute([$issueId]);
        $update = $pdo->prepare(
            "UPDATE runtime_issues
                SET normalized_message='[evidence deleted]',
                    title='Runtime issue (evidence deleted)',
                    reporter_user_id=NULL,
                    revision=revision+1,
                    updated_at=CURRENT_TIMESTAMP
              WHERE id=? AND revision=?"
        );
        $update->execute([$issueId, $expectedRevision]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeIssueException(
                'The issue changed elsewhere.',
                'RUNTIME_ISSUE_DELETION_STALE',
                409
            );
        }
        $pdo->prepare(
            'INSERT INTO runtime_issue_deletion_requests
             (public_id,request_id,issue_id,requested_by_user_id,confirmation_id,status,revision,completed_at)
             VALUES (?,?,?,?,?,\'completed\',?,CURRENT_TIMESTAMP)'
        )->execute([
            runtime_issue_public_id('runtime-delete'),
            $requestId,
            $issueId,
            $actorUserId,
            $confirmationId,
            $expectedRevision,
        ]);
        log_tool(
            $pdo,
            $actorUserId,
            'runtime_issue_evidence_delete',
            null,
            null,
            'Issue ' . $issueId . '; request ' . $requestId . '; prior revision '
            . $expectedRevision . '; content-free audit.'
        );
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    $root = runtime_issue_private_root();
    $fileFailures = 0;
    foreach ($storageNames as $storageName) {
        $path = $root . DIRECTORY_SEPARATOR . $storageName;
        if (is_file($path) && !@unlink($path)) $fileFailures++;
    }
    return [
        'ok' => true,
        'idempotentReplay' => false,
        'requestId' => $requestId,
        'fileCleanupPending' => $fileFailures,
        'issue' => runtime_issue_detail($pdo, $issueId)['issue'] ?? null,
    ];
}

function runtime_issue_install_server_capture(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return;
        try {
            runtime_issue_submit(db(), $userId, [
                'category' => 'server',
                'component' => basename((string)($_SERVER['SCRIPT_NAME'] ?? 'php-runtime')),
                'error_code' => 'PHP_FATAL_' . (int)$error['type'],
                'message' => (string)($error['message'] ?? 'Fatal PHP failure'),
                'severity' => 'critical',
                'evidence' => ['errorType' => (int)$error['type'], 'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')],
            ]);
        } catch (Throwable) {
            // Diagnostics must never replace or suppress the original fatal failure.
        }
    });
}
