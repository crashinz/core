<?php
declare(strict_types=1);

const RUNTIME_AUDIT_RUN_STATUSES = ['running', 'passed', 'completed', 'failed', 'cancelled'];
const RUNTIME_AUDIT_CHECK_STATUSES = ['passed', 'failed', 'warning', 'blocked'];
const RUNTIME_AUDIT_MAX_CHECKS_PER_RUN = 500;
const RUNTIME_AUDIT_MAX_JSON_BYTES = 65536;
const RUNTIME_AUDIT_MAX_EXPORT_BYTES = 2097152;

final class RuntimeAuditException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'RUNTIME_AUDIT_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = []
    ) {
        parent::__construct($message);
    }
}

function runtime_audit_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS runtime_audit_runs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                environment VARCHAR(24) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'running',
                release_id VARCHAR(96) DEFAULT NULL,
                manifest_sha256 VARCHAR(64) DEFAULT NULL,
                application_version VARCHAR(191) DEFAULT NULL,
                schema_version VARCHAR(96) NOT NULL,
                database_schema_version VARCHAR(96) DEFAULT NULL,
                conditions_json LONGTEXT NOT NULL,
                summary_json LONGTEXT NOT NULL,
                privacy_json LONGTEXT NOT NULL,
                created_by_user_id INT NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_runtime_audit_runs_status (status, started_at),
                CONSTRAINT fk_runtime_audit_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_audit_checks (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                audit_run_id BIGINT NOT NULL,
                check_id VARCHAR(128) NOT NULL,
                description VARCHAR(255) NOT NULL,
                status VARCHAR(24) NOT NULL,
                expected_json LONGTEXT NOT NULL,
                actual_json LONGTEXT NOT NULL,
                duration_ms INT DEFAULT NULL,
                evidence_reference VARCHAR(191) DEFAULT NULL,
                gameplay_json LONGTEXT NOT NULL,
                performance_json LONGTEXT NOT NULL,
                reconnect_json LONGTEXT NOT NULL,
                visual_json LONGTEXT NOT NULL,
                integrity_json LONGTEXT NOT NULL,
                impact_json LONGTEXT NOT NULL,
                privacy_json LONGTEXT NOT NULL,
                closure_json LONGTEXT NOT NULL,
                issue_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_runtime_audit_check (audit_run_id, check_id),
                INDEX idx_runtime_audit_checks_status (audit_run_id, status),
                INDEX idx_runtime_audit_checks_issue (issue_id),
                CONSTRAINT fk_runtime_audit_check_run FOREIGN KEY (audit_run_id) REFERENCES runtime_audit_runs(id) ON DELETE CASCADE,
                CONSTRAINT fk_runtime_audit_check_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_audit_export_audits (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                request_id VARCHAR(128) NOT NULL UNIQUE,
                audit_run_id BIGINT NOT NULL,
                actor_user_id INT NOT NULL,
                artifact_sha256 VARCHAR(64) NOT NULL,
                artifact_byte_size BIGINT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_runtime_audit_export_run (audit_run_id, created_at),
                CONSTRAINT fk_runtime_audit_export_run FOREIGN KEY (audit_run_id) REFERENCES runtime_audit_runs(id) ON DELETE CASCADE,
                CONSTRAINT fk_runtime_audit_export_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS runtime_audit_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            environment TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'running',
            release_id TEXT DEFAULT NULL,
            manifest_sha256 TEXT DEFAULT NULL,
            application_version TEXT DEFAULT NULL,
            schema_version TEXT NOT NULL,
            database_schema_version TEXT DEFAULT NULL,
            conditions_json TEXT NOT NULL,
            summary_json TEXT NOT NULL,
            privacy_json TEXT NOT NULL,
            created_by_user_id INTEGER NOT NULL,
            started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_audit_runs_status ON runtime_audit_runs(status, started_at)',
        "CREATE TABLE IF NOT EXISTS runtime_audit_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            audit_run_id INTEGER NOT NULL,
            check_id TEXT NOT NULL,
            description TEXT NOT NULL,
            status TEXT NOT NULL,
            expected_json TEXT NOT NULL,
            actual_json TEXT NOT NULL,
            duration_ms INTEGER DEFAULT NULL,
            evidence_reference TEXT DEFAULT NULL,
            gameplay_json TEXT NOT NULL,
            performance_json TEXT NOT NULL,
            reconnect_json TEXT NOT NULL,
            visual_json TEXT NOT NULL,
            integrity_json TEXT NOT NULL,
            impact_json TEXT NOT NULL,
            privacy_json TEXT NOT NULL,
            closure_json TEXT NOT NULL,
            issue_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(audit_run_id, check_id),
            FOREIGN KEY(audit_run_id) REFERENCES runtime_audit_runs(id) ON DELETE CASCADE,
            FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_audit_checks_status ON runtime_audit_checks(audit_run_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_runtime_audit_checks_issue ON runtime_audit_checks(issue_id)',
        "CREATE TABLE IF NOT EXISTS runtime_audit_export_audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            audit_run_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            artifact_sha256 TEXT NOT NULL,
            artifact_byte_size INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(audit_run_id) REFERENCES runtime_audit_runs(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_audit_export_run ON runtime_audit_export_audits(audit_run_id, created_at)',
    ];
}

function runtime_audit_install_schema(PDO $pdo): void
{
    foreach (runtime_audit_schema_statements($pdo) as $statement) $pdo->exec($statement);
}

function runtime_audit_schema_valid(PDO $pdo): bool
{
    foreach (['runtime_audit_runs', 'runtime_audit_checks', 'runtime_audit_export_audits'] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return database_migration_has_columns($pdo, 'runtime_audit_runs', [
        'public_id', 'environment', 'status', 'release_id', 'manifest_sha256', 'schema_version',
        'database_schema_version', 'conditions_json', 'summary_json', 'privacy_json', 'started_at', 'completed_at',
    ]) && database_migration_has_columns($pdo, 'runtime_audit_checks', [
        'audit_run_id', 'check_id', 'description', 'status', 'expected_json', 'actual_json', 'duration_ms',
        'gameplay_json', 'performance_json', 'reconnect_json', 'visual_json', 'integrity_json', 'impact_json',
        'privacy_json', 'closure_json', 'issue_id',
    ]);
}

function runtime_audit_clean_string(mixed $value, int $max = 191): string
{
    return runtime_issue_clean_string($value, $max);
}

function runtime_audit_encode(mixed $value): string
{
    $safe = runtime_issue_sanitize_value($value);
    $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > RUNTIME_AUDIT_MAX_JSON_BYTES) {
        throw new RuntimeAuditException('Audit evidence is too large or could not be encoded.', 'RUNTIME_AUDIT_EVIDENCE_INVALID', 400);
    }
    return $json;
}

function runtime_audit_decode(?string $json): mixed
{
    if (!is_string($json) || trim($json) === '') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function runtime_audit_release_context(PDO $pdo): array
{
    $root = dirname(__DIR__);
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'release-manifest.json';
    $manifest = [];
    if (is_file($manifestPath)) {
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($decoded)) $manifest = $decoded;
    }
    try { $migration = database_migration_status($pdo); } catch (Throwable) { $migration = []; }
    return [
        'releaseId' => runtime_audit_clean_string($manifest['release_id'] ?? '', 96) ?: null,
        'manifestSha256' => is_file($manifestPath) ? strtoupper((string)hash_file('sha256', $manifestPath)) : null,
        'manifestPresent' => is_file($manifestPath),
        'manifestInventorySha256' => runtime_audit_clean_string($manifest['inventory_sha256'] ?? '', 96) ?: null,
        'manifestFileCount' => max(0, (int)($manifest['total_files'] ?? 0)),
        'applicationVersion' => runtime_audit_clean_string($manifest['application_version'] ?? chatspace_application_version(), 191),
        'schemaVersion' => CHATSPACE_SCHEMA_VERSION,
        'databaseSchemaVersion' => runtime_audit_clean_string($migration['stored_schema_version'] ?? '', 96) ?: null,
        'requiredSchemaVersion' => runtime_audit_clean_string($migration['required_schema_version'] ?? CHATSPACE_SCHEMA_VERSION, 96),
        'pendingMigrationCount' => max(0, (int)($migration['pending_count'] ?? 0)),
        'releaseComplete' => !empty($migration['release_complete']),
        'databaseEngine' => db_driver($pdo),
    ];
}

function runtime_audit_environment(mixed $value): string
{
    $environment = strtolower(runtime_audit_clean_string($value, 24));
    return in_array($environment, ['local', 'staging', 'production'], true) ? $environment : 'local';
}

function runtime_audit_catalog(): array
{
    return [
        ['id' => 'audit.identity', 'description' => 'Audit identity, build, environment, and database schema are recorded.'],
        ['id' => 'audit.check-results', 'description' => 'Every check has a stable ID, outcome, expected and actual evidence, and duration.'],
        ['id' => 'audit.gameplay-state', 'description' => 'Gameplay phase, turn, seats, action result, revision, and state hashes are captured.'],
        ['id' => 'audit.performance', 'description' => 'Browser, server, database wait, retry, median, maximum, and p95 timing are captured when available.'],
        ['id' => 'audit.reload-reconnect', 'description' => 'Reload, reconnect, state continuity, idempotency, and duplicate action behavior are checked.'],
        ['id' => 'audit.visual-objective', 'description' => 'Objective overlap, clipping, overflow, image, control, frame, and readability checks are captured.'],
        ['id' => 'audit.catalog-deployment', 'description' => 'Catalog, manifest, asset, cache, staging drift, and migration integrity are checked.'],
        ['id' => 'audit.impact', 'description' => 'Affected-user counts, first and last occurrence, frequency, and recovery impact are summarized.'],
        ['id' => 'audit.privacy', 'description' => 'Evidence is sanitized and excludes credentials, private messages, raw uploads, media, and network addresses.'],
        ['id' => 'audit.closure', 'description' => 'Correction, verification method, retest result, and owner-review requirement are recorded.'],
    ];
}

function runtime_audit_project_run(array $row): array
{
    return [
        'id' => (int)$row['id'], 'publicId' => (string)$row['public_id'], 'environment' => (string)$row['environment'],
        'status' => (string)$row['status'], 'releaseId' => $row['release_id'] ?: null,
        'manifestSha256' => $row['manifest_sha256'] ?: null, 'applicationVersion' => $row['application_version'] ?: null,
        'schemaVersion' => (string)$row['schema_version'], 'databaseSchemaVersion' => $row['database_schema_version'] ?: null,
        'conditions' => runtime_audit_decode($row['conditions_json'] ?? null), 'summary' => runtime_audit_decode($row['summary_json'] ?? null),
        'privacy' => runtime_audit_decode($row['privacy_json'] ?? null), 'startedAt' => (string)$row['started_at'],
        'completedAt' => $row['completed_at'] ?: null, 'updatedAt' => (string)$row['updated_at'],
    ];
}

function runtime_audit_project_check(array $row): array
{
    return [
        'id' => (int)$row['id'], 'checkId' => (string)$row['check_id'], 'description' => (string)$row['description'],
        'status' => (string)$row['status'], 'expected' => runtime_audit_decode($row['expected_json'] ?? null),
        'actual' => runtime_audit_decode($row['actual_json'] ?? null),
        'durationMs' => $row['duration_ms'] === null ? null : max(0, (int)$row['duration_ms']),
        'evidenceReference' => $row['evidence_reference'] ?: null,
        'gameplay' => runtime_audit_decode($row['gameplay_json'] ?? null), 'performance' => runtime_audit_decode($row['performance_json'] ?? null),
        'reconnect' => runtime_audit_decode($row['reconnect_json'] ?? null), 'visual' => runtime_audit_decode($row['visual_json'] ?? null),
        'integrity' => runtime_audit_decode($row['integrity_json'] ?? null), 'impact' => runtime_audit_decode($row['impact_json'] ?? null),
        'privacy' => runtime_audit_decode($row['privacy_json'] ?? null), 'closure' => runtime_audit_decode($row['closure_json'] ?? null),
        'issueId' => $row['issue_id'] === null ? null : (int)$row['issue_id'], 'createdAt' => (string)$row['created_at'],
        'updatedAt' => (string)$row['updated_at'],
    ];
}

function runtime_audit_active(PDO $pdo): ?array
{
    try {
        $publicId = trim(app_setting($pdo, 'runtime_audit_active_run', ''));
        if ($publicId === '') return null;
        $statement = $pdo->prepare("SELECT * FROM runtime_audit_runs WHERE public_id=? AND status='running' LIMIT 1");
        $statement->execute([$publicId]);
        $row = $statement->fetch();
        return $row ? runtime_audit_project_run($row) : null;
    } catch (Throwable) {
        return null;
    }
}

function runtime_audit_active_projection(PDO $pdo): ?array
{
    $active = runtime_audit_active($pdo);
    return $active ? ['publicId' => $active['publicId'], 'environment' => $active['environment'], 'status' => $active['status'], 'startedAt' => $active['startedAt']] : null;
}

function runtime_audit_find_row(PDO $pdo, string $publicId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM runtime_audit_runs WHERE public_id=? LIMIT 1');
    $statement->execute([$publicId]);
    return $statement->fetch() ?: null;
}

function runtime_audit_upsert_check(PDO $pdo, int $runId, array $input): array
{
    $checkId = strtolower(runtime_audit_clean_string($input['check_id'] ?? $input['checkId'] ?? '', 128));
    if ($checkId === '' || preg_match('/^[a-z0-9][a-z0-9._:-]{2,127}$/', $checkId) !== 1) {
        throw new RuntimeAuditException('Audit check ID is invalid.', 'RUNTIME_AUDIT_CHECK_ID_INVALID', 400);
    }
    $description = runtime_audit_clean_string($input['description'] ?? $checkId, 255);
    $status = strtolower(runtime_audit_clean_string($input['status'] ?? 'blocked', 24));
    if (!in_array($status, RUNTIME_AUDIT_CHECK_STATUSES, true)) throw new RuntimeAuditException('Audit check status is invalid.', 'RUNTIME_AUDIT_CHECK_STATUS_INVALID', 400);
    $existingStatement = $pdo->prepare('SELECT * FROM runtime_audit_checks WHERE audit_run_id=? AND check_id=? LIMIT 1');
    $existingStatement->execute([$runId, $checkId]);
    $existing = $existingStatement->fetch() ?: null;
    if ($existing && (string)$existing['status'] === 'failed' && $status !== 'failed') return runtime_audit_project_check($existing);
    $fields = [
        'expected_json' => runtime_audit_encode($input['expected'] ?? []), 'actual_json' => runtime_audit_encode($input['actual'] ?? []),
        'gameplay_json' => runtime_audit_encode($input['gameplay'] ?? []), 'performance_json' => runtime_audit_encode($input['performance'] ?? []),
        'reconnect_json' => runtime_audit_encode($input['reconnect'] ?? []), 'visual_json' => runtime_audit_encode($input['visual'] ?? []),
        'integrity_json' => runtime_audit_encode($input['integrity'] ?? []), 'impact_json' => runtime_audit_encode($input['impact'] ?? []),
        'privacy_json' => runtime_audit_encode($input['privacy'] ?? []), 'closure_json' => runtime_audit_encode($input['closure'] ?? []),
    ];
    $duration = isset($input['duration_ms']) || isset($input['durationMs']) ? max(0, min(3600000, (int)($input['duration_ms'] ?? $input['durationMs']))) : null;
    $evidenceReference = runtime_audit_clean_string($input['evidence_reference'] ?? $input['evidenceReference'] ?? '', 191) ?: null;
    $issueId = (int)($input['issue_id'] ?? $input['issueId'] ?? 0);
    $issueId = $issueId > 0 ? $issueId : null;
    if ($issueId !== null) {
        $issue = $pdo->prepare('SELECT 1 FROM runtime_issues WHERE id=? LIMIT 1');
        $issue->execute([$issueId]);
        if (!$issue->fetchColumn()) $issueId = null;
    }
    if ($existing) {
        $pdo->prepare('UPDATE runtime_audit_checks SET description=?,status=?,expected_json=?,actual_json=?,duration_ms=?,evidence_reference=?,gameplay_json=?,performance_json=?,reconnect_json=?,visual_json=?,integrity_json=?,impact_json=?,privacy_json=?,closure_json=?,issue_id=COALESCE(?,issue_id),updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([
            $description, $status, $fields['expected_json'], $fields['actual_json'], $duration, $evidenceReference, $fields['gameplay_json'],
            $fields['performance_json'], $fields['reconnect_json'], $fields['visual_json'], $fields['integrity_json'], $fields['impact_json'],
            $fields['privacy_json'], $fields['closure_json'], $issueId, (int)$existing['id'],
        ]);
        $id = (int)$existing['id'];
    } else {
        $pdo->prepare('INSERT INTO runtime_audit_checks (audit_run_id,check_id,description,status,expected_json,actual_json,duration_ms,evidence_reference,gameplay_json,performance_json,reconnect_json,visual_json,integrity_json,impact_json,privacy_json,closure_json,issue_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $runId, $checkId, $description, $status, $fields['expected_json'], $fields['actual_json'], $duration, $evidenceReference,
            $fields['gameplay_json'], $fields['performance_json'], $fields['reconnect_json'], $fields['visual_json'], $fields['integrity_json'],
            $fields['impact_json'], $fields['privacy_json'], $fields['closure_json'], $issueId,
        ]);
        $id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE runtime_audit_runs SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$runId]);
    $row = $pdo->prepare('SELECT * FROM runtime_audit_checks WHERE id=? LIMIT 1');
    $row->execute([$id]);
    return runtime_audit_project_check($row->fetch());
}

function runtime_audit_seed_checks(PDO $pdo, int $runId, array $release): void
{
    $privacy = ['evidenceSanitized' => true, 'credentialsIncluded' => false, 'privateMessagesIncluded' => false, 'rawUploadsIncluded' => false, 'rawMediaIncluded' => false, 'rawNetworkAddressesIncluded' => false];
    foreach (runtime_audit_catalog() as $definition) {
        $status = 'blocked'; $actual = ['state' => 'Awaiting audit evidence.']; $integrity = []; $expected = ['recorded' => true];
        if ($definition['id'] === 'audit.identity') { $status = 'passed'; $actual = $release; }
        elseif ($definition['id'] === 'audit.check-results') { $status = 'passed'; $actual = ['supportedStatuses' => RUNTIME_AUDIT_CHECK_STATUSES, 'stableCheckIds' => true]; }
        elseif ($definition['id'] === 'audit.catalog-deployment') {
            // Release markers identify the candidate; they do not measure the
            // live catalog, asset integrity, cache coherence or staged files.
            $status = !empty($release['manifestPresent']) && ($release['pendingMigrationCount'] ?? null) === 0 ? 'blocked' : 'warning';
            $pending = ['catalogLoad', 'assetIntegrity', 'cacheCoherence', 'migrationReadiness', 'stagingParity'];
            $expected = ['verificationScope' => 'measured', 'requiredMeasurements' => $pending];
            $actual = ['verificationScope' => 'metadata-only', 'releaseMetadata' => $release, 'pendingMeasurements' => $pending];
            $integrity = ['verificationScope' => 'metadata-only', 'releaseMetadata' => $release];
        }
        elseif ($definition['id'] === 'audit.privacy') { $status = 'passed'; $actual = $privacy; }
        runtime_audit_upsert_check($pdo, $runId, ['check_id' => $definition['id'], 'description' => $definition['description'], 'status' => $status, 'expected' => $expected, 'actual' => $actual, 'integrity' => $integrity, 'privacy' => $definition['id'] === 'audit.privacy' ? $privacy : []]);
    }
}

function runtime_audit_start(PDO $pdo, int $actorUserId, array $input): array
{
    if (!runtime_audit_schema_valid($pdo)) throw new RuntimeAuditException('The audit-run database migration is required.', 'RUNTIME_AUDIT_SCHEMA_REQUIRED', 503);
    if (runtime_audit_active($pdo)) throw new RuntimeAuditException('An audit run is already active.', 'RUNTIME_AUDIT_ALREADY_ACTIVE', 409, ['activeAuditRun' => runtime_audit_active_projection($pdo)]);
    $release = runtime_audit_release_context($pdo);
    $publicId = bin2hex(random_bytes(16));
    $environment = runtime_audit_environment($input['environment'] ?? 'local');
    $conditions = ['initiatedFrom' => runtime_audit_clean_string($input['initiated_from'] ?? 'admin-errors-diagnostics', 96), 'requestedConditions' => runtime_issue_sanitize_value(is_array($input['conditions'] ?? null) ? $input['conditions'] : []), 'browserReloadRequired' => true];
    $privacy = ['schemaId' => 'corechat.runtime-audit-privacy', 'schemaVersion' => 1, 'evidenceSanitized' => true, 'credentialsIncluded' => false, 'privateMessagesIncluded' => false, 'rawUploadsIncluded' => false, 'rawMediaIncluded' => false, 'rawNetworkAddressesIncluded' => false];
    $transaction = database_transaction_begin($pdo, true);
    try {
        $pdo->prepare('INSERT INTO runtime_audit_runs (public_id,environment,status,release_id,manifest_sha256,application_version,schema_version,database_schema_version,conditions_json,summary_json,privacy_json,created_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $publicId, $environment, 'running', $release['releaseId'], $release['manifestSha256'], $release['applicationVersion'], CHATSPACE_SCHEMA_VERSION,
            $release['databaseSchemaVersion'], runtime_audit_encode($conditions), runtime_audit_encode(['checkCounts' => []]), runtime_audit_encode($privacy), $actorUserId,
        ]);
        $runId = (int)$pdo->lastInsertId();
        runtime_audit_seed_checks($pdo, $runId, $release);
        set_app_setting($pdo, 'runtime_audit_active_run', $publicId);
        log_tool($pdo, $actorUserId, 'runtime_audit_run_started', null, null, 'Audit run ' . $publicId . '; environment ' . $environment . '.');
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) { database_transaction_rollback($pdo, $transaction); throw $error; }
    return runtime_audit_detail($pdo, $publicId) ?? [];
}

function runtime_audit_record_checks(PDO $pdo, string $publicId, int $actorUserId, array $checks): array
{
    $row = runtime_audit_find_row($pdo, $publicId);
    if (!$row) throw new RuntimeAuditException('Audit run not found.', 'RUNTIME_AUDIT_NOT_FOUND', 404);
    if ((string)$row['status'] !== 'running') throw new RuntimeAuditException('Only a running audit can accept checks.', 'RUNTIME_AUDIT_NOT_RUNNING', 409);
    $checks = array_values($checks);
    if (!$checks || count($checks) > 25) throw new RuntimeAuditException('Provide between one and twenty-five audit checks.', 'RUNTIME_AUDIT_CHECK_BATCH_INVALID', 400);
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM runtime_audit_checks WHERE audit_run_id=?');
    $countStatement->execute([(int)$row['id']]);
    $currentCount = (int)$countStatement->fetchColumn();
    $transaction = database_transaction_begin($pdo, true);
    try {
        $results = [];
        foreach ($checks as $check) if (is_array($check)) $results[] = runtime_audit_upsert_check($pdo, (int)$row['id'], $check);
        if (count($results) + $currentCount > RUNTIME_AUDIT_MAX_CHECKS_PER_RUN) throw new RuntimeAuditException('The audit run reached its bounded check limit.', 'RUNTIME_AUDIT_CHECK_LIMIT', 409);
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) { database_transaction_rollback($pdo, $transaction); throw $error; }
    return ['accepted' => true, 'auditRunPublicId' => $publicId, 'checks' => $results, 'recordedByUserId' => $actorUserId];
}

function runtime_audit_record_browser_checks(PDO $pdo, int $actorUserId, array $input): array
{
    $active = runtime_audit_active($pdo);
    if (!$active) return ['accepted' => false, 'reason' => 'No audit run is active.'];
    $requested = runtime_audit_clean_string($input['audit_run_id'] ?? $input['auditRunId'] ?? '', 64);
    if ($requested === '' || !hash_equals((string)$active['publicId'], $requested)) throw new RuntimeAuditException('The browser audit run is no longer active.', 'RUNTIME_AUDIT_RUN_CHANGED', 409);
    $checks = is_array($input['checks'] ?? null) ? array_values($input['checks']) : [];
    foreach ($checks as $check) {
        $checkId = strtolower(runtime_audit_clean_string(is_array($check) ? ($check['check_id'] ?? $check['checkId'] ?? '') : '', 128));
        if (preg_match('/^(?:game|browser)\.[a-z0-9._:-]+$/', $checkId) !== 1) throw new RuntimeAuditException('Browser audit checks must use the game or browser namespace.', 'RUNTIME_AUDIT_BROWSER_CHECK_INVALID', 400);
    }
    return runtime_audit_record_checks($pdo, (string)$active['publicId'], $actorUserId, $checks);
}

function runtime_audit_attach_issue(PDO $pdo, int $reporterUserId, int $issueId, int $occurrenceId, array $identity, array $evidence): ?array
{
    try {
        $active = runtime_audit_active($pdo);
        if (!$active) return null;
        $checkId = 'issue.' . strtolower(preg_replace('/[^a-z0-9._:-]+/i', '-', (string)$identity['error_code'])) . '.' . substr((string)$identity['fingerprint'], 0, 12);
        $result = runtime_audit_record_checks($pdo, (string)$active['publicId'], $reporterUserId, [[
            'check_id' => $checkId, 'description' => (string)$identity['title'], 'status' => 'failed', 'expected' => ['runtimeFailure' => false],
            'actual' => ['runtimeFailure' => true, 'category' => $identity['category'], 'component' => $identity['component'], 'errorCode' => $identity['error_code']],
            'evidence_reference' => 'runtime-issue-occurrence:' . $occurrenceId, 'issue_id' => $issueId,
            'impact' => ['occurrenceCount' => 1, 'recoveryRequiredUserAction' => (bool)($evidence['recoveryRequiredUserAction'] ?? false)], 'privacy' => ['evidenceSanitized' => true],
        ]]);
        return $result['checks'][0] ?? null;
    } catch (Throwable) { return null; }
}

function runtime_audit_summary(PDO $pdo, int $runId): array
{
    $statement = $pdo->prepare('SELECT status,COUNT(*) AS total FROM runtime_audit_checks WHERE audit_run_id=? GROUP BY status');
    $statement->execute([$runId]);
    $counts = array_fill_keys(RUNTIME_AUDIT_CHECK_STATUSES, 0);
    foreach ($statement->fetchAll() as $row) $counts[(string)$row['status']] = (int)$row['total'];
    $impact = $pdo->prepare('SELECT COUNT(DISTINCT c.issue_id) AS linked_issues,COUNT(DISTINCT o.reporter_user_id) AS affected_reporters,MIN(c.created_at) AS first_occurrence,MAX(c.updated_at) AS last_occurrence FROM runtime_audit_checks c LEFT JOIN runtime_issue_occurrences o ON o.issue_id=c.issue_id WHERE c.audit_run_id=?');
    $impact->execute([$runId]);
    $impactRow = $impact->fetch() ?: [];
    return ['checkCounts' => $counts, 'totalChecks' => array_sum($counts), 'linkedIssueCount' => max(0, (int)($impactRow['linked_issues'] ?? 0)), 'affectedReporterCount' => max(0, (int)($impactRow['affected_reporters'] ?? 0)), 'firstOccurrenceAt' => $impactRow['first_occurrence'] ?? null, 'lastOccurrenceAt' => $impactRow['last_occurrence'] ?? null];
}

function runtime_audit_finish(PDO $pdo, int $actorUserId, string $publicId, array $input): array
{
    $row = runtime_audit_find_row($pdo, $publicId);
    if (!$row) throw new RuntimeAuditException('Audit run not found.', 'RUNTIME_AUDIT_NOT_FOUND', 404);
    if ((string)$row['status'] !== 'running') return runtime_audit_detail($pdo, $publicId) ?? [];
    $transaction = database_transaction_begin($pdo, true);
    try {
        runtime_audit_upsert_check($pdo, (int)$row['id'], [
            'check_id' => 'audit.closure', 'description' => 'Correction, verification method, retest result, and owner-review requirement are recorded.',
            'status' => trim((string)($input['retest_result'] ?? '')) !== '' ? 'passed' : 'warning', 'expected' => ['closureRecorded' => true], 'actual' => ['closureRecorded' => true],
            'closure' => ['correctionReference' => runtime_audit_clean_string($input['correction_reference'] ?? '', 191) ?: null, 'verificationMethod' => runtime_audit_clean_string($input['verification_method'] ?? 'Admin audit run', 191), 'retestResult' => runtime_audit_clean_string($input['retest_result'] ?? 'Completed; owner review pending.', 255), 'ownerReviewRequired' => !array_key_exists('owner_review_required', $input) || !empty($input['owner_review_required'])],
        ]);
        $summary = runtime_audit_summary($pdo, (int)$row['id']);
        $status = ($summary['checkCounts']['failed'] ?? 0) > 0 ? 'failed' : ((($summary['checkCounts']['warning'] ?? 0) + ($summary['checkCounts']['blocked'] ?? 0)) > 0 ? 'completed' : 'passed');
        $pdo->prepare('UPDATE runtime_audit_runs SET status=?,summary_json=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status, runtime_audit_encode($summary), (int)$row['id']]);
        $activeId = trim(app_setting($pdo, 'runtime_audit_active_run', ''));
        if ($activeId !== '' && hash_equals($activeId, $publicId)) set_app_setting($pdo, 'runtime_audit_active_run', '');
        log_tool($pdo, $actorUserId, 'runtime_audit_run_completed', null, null, 'Audit run ' . $publicId . '; status ' . $status . '; checks ' . $summary['totalChecks'] . '.');
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) { database_transaction_rollback($pdo, $transaction); throw $error; }
    return runtime_audit_detail($pdo, $publicId) ?? [];
}

function runtime_audit_list(PDO $pdo, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $rows = $pdo->query('SELECT * FROM runtime_audit_runs ORDER BY started_at DESC,id DESC LIMIT ' . $limit)->fetchAll();
    $runs = [];
    foreach ($rows as $row) { $projected = runtime_audit_project_run($row); $projected['summary'] = runtime_audit_summary($pdo, (int)$row['id']); $runs[] = $projected; }
    return ['runs' => $runs, 'activeAuditRun' => runtime_audit_active_projection($pdo)];
}

function runtime_audit_detail(PDO $pdo, string $publicId): ?array
{
    $row = runtime_audit_find_row($pdo, $publicId);
    if (!$row) return null;
    $run = runtime_audit_project_run($row); $run['summary'] = runtime_audit_summary($pdo, (int)$row['id']);
    $checks = $pdo->prepare('SELECT * FROM runtime_audit_checks WHERE audit_run_id=? ORDER BY created_at,id'); $checks->execute([(int)$row['id']]);
    return ['run' => $run, 'checks' => array_map('runtime_audit_project_check', $checks->fetchAll()), 'activeAuditRun' => runtime_audit_active_projection($pdo)];
}

function runtime_audit_export_preview(PDO $pdo, string $publicId): array
{
    $detail = runtime_audit_detail($pdo, $publicId);
    if (!$detail) throw new RuntimeAuditException('Audit run not found.', 'RUNTIME_AUDIT_NOT_FOUND', 404);
    $run = $detail['run'];
    return ['previewToken' => strtoupper(hash('sha256', implode("\n", ['corechat.runtime-audit-export.v1', $publicId, $run['updatedAt'], (string)$run['summary']['totalChecks']]))), 'checkCount' => $run['summary']['totalChecks'], 'linkedIssueCount' => $run['summary']['linkedIssueCount'], 'includes' => ['audit identity and exact conditions', 'passed, failed, warning, and blocked checks', 'sanitized gameplay, performance, reconnect, visual, integrity, impact, and closure evidence', 'linked issue IDs and evidence references', 'privacy report'], 'excludes' => ['passwords, tokens, cookies, and CSRF values', 'private messages and content', 'raw uploads, media, and screenshot pixels', 'raw network addresses', 'database credentials and private filesystem paths']];
}

function runtime_audit_bundle(PDO $pdo, string $publicId): array
{
    $detail = runtime_audit_detail($pdo, $publicId);
    if (!$detail) throw new RuntimeAuditException('Audit run not found.', 'RUNTIME_AUDIT_NOT_FOUND', 404);
    return ['schemaId' => 'corechat.runtime-audit-bundle', 'schemaVersion' => 1, 'generatedAt' => $detail['run']['completedAt'] ?: $detail['run']['updatedAt'], 'auditRun' => $detail['run'], 'checks' => $detail['checks'], 'privacyReport' => $detail['run']['privacy']];
}

function runtime_audit_export(PDO $pdo, int $actorUserId, string $publicId, string $requestId, string $previewToken): array
{
    $requestId = runtime_audit_clean_string($requestId, 128);
    if ($requestId === '' || preg_match('/^[A-Za-z0-9._:-]{12,128}$/', $requestId) !== 1) throw new RuntimeAuditException('Audit export request ID is invalid.', 'RUNTIME_AUDIT_EXPORT_REQUEST_INVALID', 400);
    $preview = runtime_audit_export_preview($pdo, $publicId);
    if ($previewToken === '' || !hash_equals((string)$preview['previewToken'], strtoupper($previewToken))) throw new RuntimeAuditException('Review the current audit export before downloading it.', 'RUNTIME_AUDIT_EXPORT_PREVIEW_REQUIRED', 409);
    $row = runtime_audit_find_row($pdo, $publicId);
    if (!$row) throw new RuntimeAuditException('Audit run not found.', 'RUNTIME_AUDIT_NOT_FOUND', 404);
    $artifact = runtime_audit_bundle($pdo, $publicId);
    $json = json_encode($artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > RUNTIME_AUDIT_MAX_EXPORT_BYTES) throw new RuntimeAuditException('Audit export exceeds the bounded artifact size.', 'RUNTIME_AUDIT_EXPORT_TOO_LARGE', 409);
    $hash = strtoupper(hash('sha256', $json)); $bytes = strlen($json);
    $existing = $pdo->prepare('SELECT * FROM runtime_audit_export_audits WHERE request_id=? LIMIT 1'); $existing->execute([$requestId]); $prior = $existing->fetch() ?: null;
    if ($prior && ((int)$prior['audit_run_id'] !== (int)$row['id'] || !hash_equals((string)$prior['artifact_sha256'], $hash))) throw new RuntimeAuditException('Audit export request ID was already used for different content.', 'RUNTIME_AUDIT_EXPORT_REQUEST_CONFLICT', 409);
    if (!$prior) {
        $pdo->prepare('INSERT INTO runtime_audit_export_audits (public_id,request_id,audit_run_id,actor_user_id,artifact_sha256,artifact_byte_size) VALUES (?,?,?,?,?,?)')->execute([bin2hex(random_bytes(16)), $requestId, (int)$row['id'], $actorUserId, $hash, $bytes]);
        log_tool($pdo, $actorUserId, 'runtime_audit_bundle_export', null, null, 'Audit run ' . $publicId . '; sha256 ' . $hash . '; bytes ' . $bytes . '; exported payload not retained.');
    }
    return ['artifact' => $artifact, 'download' => ['sha256' => $hash, 'byteSize' => $bytes, 'requestId' => $requestId, 'idempotentReplay' => (bool)$prior, 'payloadRetainedByAudit' => false]];
}
