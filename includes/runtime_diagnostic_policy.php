<?php
declare(strict_types=1);

/**
 * Build 000052 core-owned finite production diagnostic policy and bounded
 * retention-job authority. Engineering/canonical diagnostics are independent.
 */

const RUNTIME_DIAGNOSTIC_MODES = ['off', 'errors-only', 'errors-and-warnings', 'verbose'];
const RUNTIME_DIAGNOSTIC_MODE_SETTING = 'runtime_diagnostic_collection_mode';
const RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING = 'runtime_diagnostic_prior_mode';
const RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING = 'runtime_diagnostic_verbose_until';
const RUNTIME_DIAGNOSTIC_REVISION_SETTING = 'runtime_diagnostic_policy_revision';
const RUNTIME_DIAGNOSTIC_VERBOSE_SECONDS = 3600;
const RUNTIME_DIAGNOSTIC_ROUTINE_RETENTION_DAYS = 30;
const RUNTIME_DIAGNOSTIC_UNRESOLVED_RETENTION_DAYS = 90;
const RUNTIME_DIAGNOSTIC_FINGERPRINT_RETENTION_DAYS = 90;

final class RuntimeDiagnosticPolicyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 409,
        public readonly array $projection = []
    ) {
        parent::__construct($message);
    }
}

function runtime_diagnostic_policy_defaults(): array
{
    return [
        RUNTIME_DIAGNOSTIC_MODE_SETTING => 'errors-only',
        RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING => 'errors-only',
        RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING => '',
        RUNTIME_DIAGNOSTIC_REVISION_SETTING => '1',
    ];
}

function runtime_diagnostic_policy_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS runtime_diagnostic_policy_requests (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(96) NOT NULL UNIQUE,
                request_hash VARCHAR(64) NOT NULL,
                requested_mode VARCHAR(32) NOT NULL,
                result_revision INT NOT NULL,
                actor_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_runtime_diagnostic_policy_requests_created (created_at),
                CONSTRAINT fk_runtime_diagnostic_policy_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_diagnostic_retention (
                issue_id INT PRIMARY KEY,
                retention_class VARCHAR(32) NOT NULL DEFAULT 'routine',
                hold_active TINYINT(1) NOT NULL DEFAULT 0,
                hold_reason VARCHAR(191) DEFAULT NULL,
                retained_until DATETIME DEFAULT NULL,
                fingerprint_until DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_runtime_diagnostic_retention_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_diagnostic_cleanup_jobs (
                job_key VARCHAR(96) PRIMARY KEY,
                owner_token VARCHAR(96) DEFAULT NULL,
                locked_until DATETIME DEFAULT NULL,
                checkpoint_issue_id INT NOT NULL DEFAULT 0,
                last_result VARCHAR(32) DEFAULT NULL,
                last_scanned INT NOT NULL DEFAULT 0,
                last_deleted INT NOT NULL DEFAULT 0,
                last_error VARCHAR(191) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS runtime_diagnostic_policy_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            request_hash TEXT NOT NULL,
            requested_mode TEXT NOT NULL,
            result_revision INTEGER NOT NULL,
            actor_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_runtime_diagnostic_policy_requests_created ON runtime_diagnostic_policy_requests(created_at)',
        "CREATE TABLE IF NOT EXISTS runtime_diagnostic_retention (
            issue_id INTEGER PRIMARY KEY,
            retention_class TEXT NOT NULL DEFAULT 'routine',
            hold_active INTEGER NOT NULL DEFAULT 0,
            hold_reason TEXT DEFAULT NULL,
            retained_until TEXT DEFAULT NULL,
            fingerprint_until TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS runtime_diagnostic_cleanup_jobs (
            job_key TEXT PRIMARY KEY,
            owner_token TEXT DEFAULT NULL,
            locked_until TEXT DEFAULT NULL,
            checkpoint_issue_id INTEGER NOT NULL DEFAULT 0,
            last_result TEXT DEFAULT NULL,
            last_scanned INTEGER NOT NULL DEFAULT 0,
            last_deleted INTEGER NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT DEFAULT NULL
        )",
    ];
}

function runtime_diagnostic_policy_install(PDO $pdo): void
{
    foreach (runtime_diagnostic_policy_schema_statements($pdo) as $statement) $pdo->exec($statement);
    foreach (runtime_diagnostic_policy_defaults() as $key => $value) {
        $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) set_app_setting($pdo, $key, $value);
    }
}

function runtime_diagnostic_policy_schema_valid(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT public_id, request_hash, requested_mode, result_revision FROM runtime_diagnostic_policy_requests WHERE 1 = 0');
        $pdo->query('SELECT issue_id, retention_class, hold_active, retained_until, fingerprint_until FROM runtime_diagnostic_retention WHERE 1 = 0');
        $pdo->query('SELECT job_key, owner_token, locked_until, checkpoint_issue_id, last_result FROM runtime_diagnostic_cleanup_jobs WHERE 1 = 0');
        foreach (array_keys(runtime_diagnostic_policy_defaults()) as $key) {
            $stmt = $pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key = ? LIMIT 1');
            $stmt->execute([$key]);
            if (!$stmt->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

function runtime_diagnostic_policy_mode(PDO $pdo, ?int $now = null): array
{
    $now ??= time();
    $stored = app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, 'errors-only');
    if (!in_array($stored, RUNTIME_DIAGNOSTIC_MODES, true)) $stored = 'errors-only';
    $prior = app_setting($pdo, RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING, 'errors-only');
    if (!in_array($prior, ['off', 'errors-only', 'errors-and-warnings'], true)) $prior = 'errors-only';
    $untilText = app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, '');
    $until = $untilText !== '' ? strtotime($untilText . ' UTC') : false;
    $activeVerbose = $stored === 'verbose' && is_int($until) && $until > $now;
    $effective = $stored === 'verbose' && !$activeVerbose ? $prior : $stored;
    return [
        'storedMode' => $stored,
        'effectiveMode' => $effective,
        'priorMode' => $prior,
        'verboseUntil' => $activeVerbose ? gmdate('c', $until) : null,
        'verboseRemainingSeconds' => $activeVerbose ? max(0, $until - $now) : 0,
        'verboseActive' => $activeVerbose,
        'expiredReturnPendingPersistence' => $stored === 'verbose' && !$activeVerbose,
    ];
}

function runtime_diagnostic_policy_projection(PDO $pdo, ?int $now = null): array
{
    $state = runtime_diagnostic_policy_mode($pdo, $now);
    return $state + [
        'schemaId' => 'chatspace.runtime-diagnostic-policy',
        'schemaVersion' => 1,
        'revision' => max(1, (int)app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, '1')),
        'modes' => [
            ['id' => 'off', 'label' => 'Off'],
            ['id' => 'errors-only', 'label' => 'Errors only', 'recommended' => true],
            ['id' => 'errors-and-warnings', 'label' => 'Errors and warnings'],
            ['id' => 'verbose', 'label' => 'Verbose diagnostics', 'temporarySeconds' => RUNTIME_DIAGNOSTIC_VERBOSE_SECONDS],
        ],
        'engineeringDiagnosticsIndependent' => true,
        'retention' => [
            'screenshotsDefaultEnabled' => false,
            'screenshotsDefaultDays' => 0,
            'screenshotEnableProposalDays' => 30,
            'routineStructuredDays' => RUNTIME_DIAGNOSTIC_ROUTINE_RETENTION_DAYS,
            'unresolvedOrSecurityDays' => RUNTIME_DIAGNOSTIC_UNRESOLVED_RETENTION_DAYS,
            'resolvedFingerprintDays' => RUNTIME_DIAGNOSTIC_FINGERPRINT_RETENTION_DAYS,
            'collectionOffDeletesExistingEvidence' => false,
        ],
        'privacy' => 'Credentials, sessions, recovery data, private messages, cryptographic material, SDP/ICE/TURN, media, sensitive bodies, hidden-media reasons, private paths, and unnecessary identity are excluded.',
    ];
}

function runtime_diagnostic_policy_request_id(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,95}$/', $value)) {
        throw new RuntimeDiagnosticPolicyException('A stable diagnostic-policy request ID is required.', 'DIAGNOSTIC_REQUEST_ID_INVALID', 400);
    }
    return $value;
}

function runtime_diagnostic_policy_reconcile_expiry_locked(PDO $pdo, int $now): bool
{
    $state = runtime_diagnostic_policy_mode($pdo, $now);
    if (empty($state['expiredReturnPendingPersistence'])) return false;
    set_app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, (string)$state['priorMode']);
    set_app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, '');
    set_app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, (string)(max(1, (int)app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, '1')) + 1));
    return true;
}

function runtime_diagnostic_policy_apply_mode_locked(
    PDO $pdo,
    string $mode,
    int $expectedRevision,
    ?int $now = null
): array {
    $now ??= time();
    if (!in_array($mode, RUNTIME_DIAGNOSTIC_MODES, true)) {
        throw new RuntimeDiagnosticPolicyException('Choose one finite diagnostic collection mode.', 'DIAGNOSTIC_MODE_INVALID', 400);
    }
    $actualRevision = max(1, (int)app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, '1'));
    if ($expectedRevision !== $actualRevision) {
        throw new RuntimeDiagnosticPolicyException('Diagnostic policy changed in another context. Reload and review again.', 'DIAGNOSTIC_REVISION_STALE', 409, runtime_diagnostic_policy_projection($pdo, $now));
    }
    $current = runtime_diagnostic_policy_mode($pdo, $now);
    $nextRevision = $actualRevision;
    if ($mode === 'verbose' && !empty($current['verboseActive'])) return runtime_diagnostic_policy_projection($pdo, $now);
    if ($mode === 'verbose') {
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING, (string)$current['effectiveMode']);
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, 'verbose');
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, gmdate('Y-m-d H:i:s', $now + RUNTIME_DIAGNOSTIC_VERBOSE_SECONDS));
        $nextRevision++;
    } elseif ($current['effectiveMode'] !== $mode || $current['storedMode'] === 'verbose') {
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, $mode);
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING, $mode);
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, '');
        $nextRevision++;
    }
    set_app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, (string)$nextRevision);
    return runtime_diagnostic_policy_projection($pdo, $now);
}

function runtime_diagnostic_policy_update(
    PDO $pdo,
    string $mode,
    mixed $expectedRevision,
    string $requestId,
    bool $confirmed,
    int $actorUserId,
    ?int $now = null
): array {
    $now ??= time();
    if (!in_array($mode, RUNTIME_DIAGNOSTIC_MODES, true)) {
        throw new RuntimeDiagnosticPolicyException('Choose one finite diagnostic collection mode.', 'DIAGNOSTIC_MODE_INVALID', 400);
    }
    if (!$confirmed) {
        throw new RuntimeDiagnosticPolicyException('Review and confirm the diagnostic collection change.', 'DIAGNOSTIC_CONFIRMATION_REQUIRED', 409, runtime_diagnostic_policy_projection($pdo, $now));
    }
    if (filter_var($expectedRevision, FILTER_VALIDATE_INT) === false || (int)$expectedRevision < 1) {
        throw new RuntimeDiagnosticPolicyException('A current diagnostic-policy revision is required.', 'DIAGNOSTIC_REVISION_REQUIRED', 400);
    }
    $expectedRevision = (int)$expectedRevision;
    $requestId = runtime_diagnostic_policy_request_id($requestId);
    $requestHash = hash('sha256', $mode . "\n" . $expectedRevision);
    $transaction = database_transaction_begin($pdo, true);
    try {
        $existing = $pdo->prepare('SELECT request_hash FROM runtime_diagnostic_policy_requests WHERE public_id = ? LIMIT 1');
        $existing->execute([$requestId]);
        $priorRequest = $existing->fetchColumn();
        if ($priorRequest !== false) {
            if (!hash_equals((string)$priorRequest, $requestHash)) {
                throw new RuntimeDiagnosticPolicyException('That request ID was already used for another diagnostic action.', 'DIAGNOSTIC_REQUEST_ID_REUSED', 409);
            }
            database_transaction_commit($pdo, $transaction);
            return ['ok' => true, 'idempotent' => true, 'diagnosticPolicy' => runtime_diagnostic_policy_projection($pdo, $now)];
        }
        $actualRevision = max(1, (int)app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, '1'));
        if ($actualRevision !== $expectedRevision) {
            throw new RuntimeDiagnosticPolicyException('Diagnostic policy changed in another context. Reload and review again.', 'DIAGNOSTIC_REVISION_STALE', 409, runtime_diagnostic_policy_projection($pdo, $now));
        }
        $current = runtime_diagnostic_policy_mode($pdo, $now);
        $effective = (string)$current['effectiveMode'];
        $nextRevision = $actualRevision;
        if ($mode === 'verbose' && !empty($current['verboseActive'])) {
            // A repeated request never extends the existing 60-minute lease.
        } elseif ($mode === 'verbose') {
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING, $effective === 'verbose' ? 'errors-only' : $effective);
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, 'verbose');
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, gmdate('Y-m-d H:i:s', $now + RUNTIME_DIAGNOSTIC_VERBOSE_SECONDS));
            $nextRevision++;
        } elseif ($effective !== $mode || $current['storedMode'] === 'verbose') {
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_MODE_SETTING, $mode);
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_PRIOR_MODE_SETTING, $mode);
            set_app_setting($pdo, RUNTIME_DIAGNOSTIC_VERBOSE_UNTIL_SETTING, '');
            $nextRevision++;
        }
        set_app_setting($pdo, RUNTIME_DIAGNOSTIC_REVISION_SETTING, (string)$nextRevision);
        if ($nextRevision !== $actualRevision) {
            set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)(settings_registry_revision($pdo) + 1));
        }
        $pdo->prepare('INSERT INTO runtime_diagnostic_policy_requests (public_id, request_hash, requested_mode, result_revision, actor_user_id) VALUES (?,?,?,?,?)')
            ->execute([$requestId, $requestHash, $mode, $nextRevision, $actorUserId > 0 ? $actorUserId : null]);
        log_tool(
            $pdo,
            $actorUserId > 0 ? $actorUserId : null,
            'admin_runtime_diagnostic_policy_update',
            null,
            null,
            'Diagnostic collection mode ' . $mode . '; policy revision ' . $actualRevision . ' to ' . $nextRevision . '; no diagnostic payload logged.'
        );
        database_transaction_commit($pdo, $transaction);
        return ['ok' => true, 'idempotent' => $nextRevision === $actualRevision, 'diagnosticPolicy' => runtime_diagnostic_policy_projection($pdo, $now)];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function runtime_diagnostic_policy_allows(PDO $pdo, string $severity, bool $engineeringDiagnostic = false): bool
{
    if ($engineeringDiagnostic) return true;
    $mode = runtime_diagnostic_policy_mode($pdo)['effectiveMode'];
    $severity = strtolower($severity);
    return match ($mode) {
        'off' => false,
        'errors-only' => in_array($severity, ['error', 'critical'], true),
        'errors-and-warnings' => in_array($severity, ['warning', 'error', 'critical'], true),
        'verbose' => in_array($severity, ['info', 'warning', 'error', 'critical'], true),
        default => false,
    };
}

function runtime_diagnostic_retention_class(array $identity): string
{
    return ($identity['severity'] ?? '') === 'critical'
        || preg_match('/security|authentication|authorization|privacy/i', (string)($identity['category'] ?? ''))
        ? 'security'
        : 'routine';
}

function runtime_diagnostic_retention_track_issue(PDO $pdo, int $issueId, array $identity, ?int $now = null): void
{
    $now ??= time();
    $class = runtime_diagnostic_retention_class($identity);
    $days = $class === 'security' ? RUNTIME_DIAGNOSTIC_UNRESOLVED_RETENTION_DAYS : RUNTIME_DIAGNOSTIC_ROUTINE_RETENTION_DAYS;
    $until = gmdate('Y-m-d H:i:s', $now + ($days * 86400));
    if (db_uses_mysql_syntax($pdo)) {
        $pdo->prepare(
            'INSERT INTO runtime_diagnostic_retention (issue_id, retention_class, retained_until, fingerprint_until)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE retention_class = VALUES(retention_class),
                retained_until = GREATEST(COALESCE(retained_until, VALUES(retained_until)), VALUES(retained_until)),
                fingerprint_until = GREATEST(COALESCE(fingerprint_until, VALUES(fingerprint_until)), VALUES(fingerprint_until)),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([$issueId, $class, $until, gmdate('Y-m-d H:i:s', $now + (RUNTIME_DIAGNOSTIC_FINGERPRINT_RETENTION_DAYS * 86400))]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO runtime_diagnostic_retention (issue_id, retention_class, retained_until, fingerprint_until)
         VALUES (?,?,?,?)
         ON CONFLICT(issue_id) DO UPDATE SET
            retention_class = excluded.retention_class,
            retained_until = CASE WHEN runtime_diagnostic_retention.retained_until IS NULL OR runtime_diagnostic_retention.retained_until < excluded.retained_until THEN excluded.retained_until ELSE runtime_diagnostic_retention.retained_until END,
            fingerprint_until = CASE WHEN runtime_diagnostic_retention.fingerprint_until IS NULL OR runtime_diagnostic_retention.fingerprint_until < excluded.fingerprint_until THEN excluded.fingerprint_until ELSE runtime_diagnostic_retention.fingerprint_until END,
            updated_at = CURRENT_TIMESTAMP'
    )->execute([$issueId, $class, $until, gmdate('Y-m-d H:i:s', $now + (RUNTIME_DIAGNOSTIC_FINGERPRINT_RETENTION_DAYS * 86400))]);
}

function runtime_diagnostic_retention_projection(PDO $pdo): array
{
    $counts = ['routine' => 0, 'security' => 0, 'holds' => 0, 'expired' => 0];
    try {
        foreach ($pdo->query('SELECT retention_class, hold_active, retained_until FROM runtime_diagnostic_retention')->fetchAll() as $row) {
            $class = $row['retention_class'] === 'security' ? 'security' : 'routine';
            $counts[$class]++;
            if (!empty($row['hold_active'])) $counts['holds']++;
            if (empty($row['hold_active']) && $row['retained_until'] !== null && (string)$row['retained_until'] < gmdate('Y-m-d H:i:s')) $counts['expired']++;
        }
    } catch (Throwable) {
        // Migration compatibility reports zero safe aggregates until installed.
    }
    $job = null;
    try {
        $job = $pdo->query("SELECT job_key, locked_until, checkpoint_issue_id, last_result, last_scanned, last_deleted, last_error, updated_at, completed_at FROM runtime_diagnostic_cleanup_jobs WHERE job_key = 'runtime-diagnostic-retention' LIMIT 1")->fetch() ?: null;
    } catch (Throwable) {
        $job = null;
    }
    if ($job) {
        $job = [
            'status' => $job['last_result'] ?: 'pending',
            'leaseActive' => $job['locked_until'] !== null && (string)$job['locked_until'] > gmdate('Y-m-d H:i:s'),
            'checkpointIssueId' => (int)$job['checkpoint_issue_id'],
            'lastScanned' => (int)$job['last_scanned'],
            'lastDeleted' => (int)$job['last_deleted'],
            'lastError' => $job['last_error'] ?: null,
            'updatedAt' => $job['updated_at'],
            'completedAt' => $job['completed_at'],
        ];
    }
    return ['counts' => $counts, 'job' => $job, 'cleanupRunsOnGet' => false];
}

function runtime_diagnostic_retention_preview(PDO $pdo): array
{
    $batch = operational_capacity_projection($pdo)['values']['capacity_diagnostic_cleanup_batch_size'];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM runtime_diagnostic_retention r
         JOIN runtime_issues i ON i.id = r.issue_id
         WHERE r.hold_active = 0 AND r.retained_until IS NOT NULL AND r.retained_until < ?
           AND i.status NOT IN ('investigating', 'fixed-pending-verification')"
    );
    $stmt->execute([gmdate('Y-m-d H:i:s')]);
    return [
        'eligibleIssueCount' => (int)$stmt->fetchColumn(),
        'nextBatchMaximum' => (int)$batch,
        'holdsPreserved' => true,
        'investigationsPreserved' => true,
        'requiresRecentAuthentication' => true,
        'requiresConfirmation' => true,
    ];
}

function runtime_diagnostic_retention_set_hold(
    PDO $pdo,
    int $issueId,
    bool $active,
    string $reason,
    int $actorUserId,
    int $expectedRevision
): array
{
    $reason = runtime_issue_clean_string($reason, 191);
    if ($active && $reason === '') throw new RuntimeDiagnosticPolicyException('An evidence hold requires a bounded reason.', 'DIAGNOSTIC_HOLD_REASON_REQUIRED', 400);
    $transaction = database_transaction_begin($pdo, true);
    $nextRevision = $expectedRevision;
    $idempotent = false;
    try {
        $issueSql = 'SELECT revision FROM runtime_issues WHERE id=? LIMIT 1';
        $holdSql = 'SELECT hold_active,hold_reason FROM runtime_diagnostic_retention WHERE issue_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) {
            $issueSql .= ' FOR UPDATE';
            $holdSql .= ' FOR UPDATE';
        }
        $issue = $pdo->prepare($issueSql);
        $issue->execute([$issueId]);
        $actualRevision = $issue->fetchColumn();
        if ($actualRevision === false) {
            throw new RuntimeDiagnosticPolicyException('Runtime issue not found.', 'DIAGNOSTIC_ISSUE_NOT_FOUND', 404);
        }
        if ((int)$actualRevision !== $expectedRevision) {
            throw new RuntimeDiagnosticPolicyException(
                'The runtime issue changed elsewhere. Reload before changing its hold.',
                'DIAGNOSTIC_HOLD_REVISION_STALE',
                409,
                ['currentRevision' => (int)$actualRevision]
            );
        }
        $hold = $pdo->prepare($holdSql);
        $hold->execute([$issueId]);
        $current = $hold->fetch();
        if (!is_array($current)) {
            throw new RuntimeDiagnosticPolicyException('Runtime issue retention is unavailable.', 'DIAGNOSTIC_RETENTION_NOT_FOUND', 409);
        }
        $sameState = (bool)$current['hold_active'] === $active;
        $sameReason = !$active || hash_equals((string)($current['hold_reason'] ?? ''), $reason);
        if ($sameState && $sameReason) {
            $idempotent = true;
        } else {
            $stmt = $pdo->prepare(
                'UPDATE runtime_diagnostic_retention
                    SET hold_active=?,hold_reason=?,updated_at=CURRENT_TIMESTAMP
                  WHERE issue_id=?'
            );
            $stmt->execute([$active ? 1 : 0, $active ? $reason : null, $issueId]);
            $revision = $pdo->prepare(
                'UPDATE runtime_issues
                    SET revision=revision+1,updated_at=CURRENT_TIMESTAMP
                  WHERE id=? AND revision=?'
            );
            $revision->execute([$issueId, $expectedRevision]);
            if ($revision->rowCount() !== 1) {
                throw new RuntimeDiagnosticPolicyException(
                    'The runtime issue changed elsewhere.',
                    'DIAGNOSTIC_HOLD_REVISION_STALE',
                    409
                );
            }
            $nextRevision++;
            log_tool(
                $pdo,
                $actorUserId,
                $active ? 'admin_runtime_diagnostic_hold_apply' : 'admin_runtime_diagnostic_hold_release',
                null,
                null,
                'Diagnostic evidence hold ' . ($active ? 'applied' : 'released')
                . '; issue ' . $issueId . '; revision ' . $expectedRevision . ' to '
                . $nextRevision . '; no evidence payload logged.'
            );
        }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    return runtime_diagnostic_retention_projection($pdo) + [
        'issueId' => $issueId,
        'issueRevision' => $nextRevision,
        'holdActive' => $active,
        'idempotentReplay' => $idempotent,
    ];
}

function runtime_diagnostic_cleanup_acquire(PDO $pdo, string $ownerToken, int $leaseSeconds = 60): bool
{
    $now = gmdate('Y-m-d H:i:s');
    $until = gmdate('Y-m-d H:i:s', time() + max(15, min(300, $leaseSeconds)));
    try {
        $pdo->prepare("INSERT INTO runtime_diagnostic_cleanup_jobs (job_key, owner_token, locked_until, last_result) VALUES ('runtime-diagnostic-retention', ?, ?, 'running')")
            ->execute([$ownerToken, $until]);
        return true;
    } catch (PDOException) {
        $stmt = $pdo->prepare(
            "UPDATE runtime_diagnostic_cleanup_jobs SET owner_token = ?, locked_until = ?, last_result = 'running', last_error = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE job_key = 'runtime-diagnostic-retention' AND (locked_until IS NULL OR locked_until < ?)"
        );
        $stmt->execute([$ownerToken, $until, $now]);
        return $stmt->rowCount() === 1;
    }
}

function runtime_diagnostic_retention_run_cleanup(PDO $pdo, int $actorUserId, bool $confirmed): array
{
    if (!$confirmed) throw new RuntimeDiagnosticPolicyException('Review and confirm the exact diagnostic cleanup impact.', 'DIAGNOSTIC_CLEANUP_CONFIRMATION_REQUIRED', 409, runtime_diagnostic_retention_preview($pdo));
    $ownerToken = bin2hex(random_bytes(24));
    if (!runtime_diagnostic_cleanup_acquire($pdo, $ownerToken)) {
        throw new RuntimeDiagnosticPolicyException('Diagnostic cleanup is already leased by another worker.', 'DIAGNOSTIC_CLEANUP_LEASED', 409);
    }
    $batch = (int)operational_capacity_projection($pdo)['values']['capacity_diagnostic_cleanup_batch_size'];
    $scan = $pdo->prepare(
        "SELECT r.issue_id, r.fingerprint_until
           FROM runtime_diagnostic_retention r
           JOIN runtime_issues i ON i.id = r.issue_id
          WHERE r.hold_active = 0 AND r.retained_until IS NOT NULL AND r.retained_until < ?
            AND i.status NOT IN ('investigating', 'fixed-pending-verification')
          ORDER BY r.issue_id ASC LIMIT " . max(1, min(120, $batch))
    );
    $scanned = 0;
    $deleted = 0;
    try {
        $scan->execute([gmdate('Y-m-d H:i:s')]);
        foreach ($scan->fetchAll() as $row) {
            $scanned++;
            $issueId = (int)$row['issue_id'];
            runtime_issue_delete_screenshots_for_issue($pdo, $issueId, $actorUserId);
            $pdo->prepare('DELETE FROM runtime_issue_occurrences WHERE issue_id = ?')->execute([$issueId]);
            if ($row['fingerprint_until'] !== null && (string)$row['fingerprint_until'] < gmdate('Y-m-d H:i:s')) {
                $pdo->prepare('DELETE FROM runtime_issues WHERE id = ?')->execute([$issueId]);
            }
            $deleted++;
        }
        $pdo->prepare(
            "UPDATE runtime_diagnostic_cleanup_jobs SET owner_token = NULL, locked_until = NULL,
                checkpoint_issue_id = ?, last_result = 'pass', last_scanned = ?, last_deleted = ?,
                last_error = NULL, updated_at = CURRENT_TIMESTAMP, completed_at = CURRENT_TIMESTAMP
             WHERE job_key = 'runtime-diagnostic-retention' AND owner_token = ?"
        )->execute([$scanned ? $issueId : 0, $scanned, $deleted, $ownerToken]);
        log_tool($pdo, $actorUserId, 'admin_runtime_diagnostic_cleanup', null, null, 'Bounded diagnostic cleanup scanned ' . $scanned . '; cleaned ' . $deleted . '; batch maximum ' . $batch . '; holds and active investigations preserved.');
        return ['ok' => true, 'scanned' => $scanned, 'deleted' => $deleted, 'batchMaximum' => $batch, 'retention' => runtime_diagnostic_retention_projection($pdo)];
    } catch (Throwable $error) {
        $pdo->prepare(
            "UPDATE runtime_diagnostic_cleanup_jobs SET owner_token = NULL, locked_until = NULL,
                last_result = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP
             WHERE job_key = 'runtime-diagnostic-retention' AND owner_token = ?"
        )->execute([runtime_issue_clean_string($error->getMessage(), 191), $ownerToken]);
        throw $error;
    }
}
