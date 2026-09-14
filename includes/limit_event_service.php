<?php
declare(strict_types=1);
require_once __DIR__ . '/limit_event_details.php';

const LIMIT_EVENT_RETENTION_SETTING = 'limit_event_retention_days';
const LIMIT_EVENT_MAX_METADATA_BYTES = 8192;

function limit_event_schema_statements(PDO $pdo): array {
    if (db_uses_mysql_syntax($pdo)) {
        return ["CREATE TABLE IF NOT EXISTS limit_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(64) NOT NULL UNIQUE,
            fingerprint VARCHAR(64) NOT NULL UNIQUE,
            setting_id VARCHAR(191) NOT NULL,
            limit_name VARCHAR(191) NOT NULL,
            threshold_json TEXT NOT NULL,
            unit VARCHAR(96) DEFAULT NULL,
            scope_kind VARCHAR(48) NOT NULL,
            scope_hash VARCHAR(64) NOT NULL,
            outcome VARCHAR(48) NOT NULL,
            occurrence_count BIGINT NOT NULL DEFAULT 1,
            first_reached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_reached_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            audit_run_public_id VARCHAR(64) DEFAULT NULL,
            diagnostic_issue_id BIGINT DEFAULT NULL,
            metadata_json TEXT NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_limit_events_recent (deleted_at,last_reached_at),
            INDEX idx_limit_events_setting (setting_id,outcome,last_reached_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"];
    }
    return [
        "CREATE TABLE IF NOT EXISTS limit_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            fingerprint TEXT NOT NULL UNIQUE,
            setting_id TEXT NOT NULL,
            limit_name TEXT NOT NULL,
            threshold_json TEXT NOT NULL,
            unit TEXT DEFAULT NULL,
            scope_kind TEXT NOT NULL,
            scope_hash TEXT NOT NULL,
            outcome TEXT NOT NULL,
            occurrence_count INTEGER NOT NULL DEFAULT 1,
            first_reached_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_reached_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            audit_run_public_id TEXT DEFAULT NULL,
            diagnostic_issue_id INTEGER DEFAULT NULL,
            metadata_json TEXT NOT NULL,
            deleted_at TEXT DEFAULT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_limit_events_recent ON limit_events(deleted_at,last_reached_at)',
        'CREATE INDEX IF NOT EXISTS idx_limit_events_setting ON limit_events(setting_id,outcome,last_reached_at)',
    ];
}

function limit_event_install_schema(PDO $pdo): void { foreach (limit_event_schema_statements($pdo) as $statement) $pdo->exec($statement); }

function limit_event_schema_valid(PDO $pdo): bool {
    return database_migration_table_exists($pdo, 'limit_events') && database_migration_has_columns($pdo, 'limit_events', [
        'public_id','fingerprint','setting_id','limit_name','threshold_json','unit','scope_kind','scope_hash','outcome',
        'occurrence_count','first_reached_at','last_reached_at','audit_run_public_id','metadata_json','deleted_at',
    ]);
}

function limit_event_clean(mixed $value, int $maximum = 191): string {
    $value = trim((string)$value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $maximum);
}

function limit_event_scope_hash(string $scope): string {
    try {
        $policy = function_exists('network_privacy_policy') ? network_privacy_policy() : [];
        $version = (string)($policy['activeOpaqueKeyVersion'] ?? '');
        $key = base64_decode((string)($policy['opaqueKeys'][$version] ?? ''), true);
        if (is_string($key) && strlen($key) === 32) return hash_hmac('sha256', 'limit-event:' . $scope, $key);
    } catch (Throwable) {}
    return hash('sha256', 'limit-event:' . $scope);
}

function limit_event_safe_metadata(array $metadata): string {
    // Limit Events are aggregate enforcement evidence, not general diagnostics.
    // An allowlist prevents nested objects, mixed-case keys and arbitrary text
    // from smuggling filenames, addresses, media or credentials into exports.
    $safe = [];
    foreach (['submittedLength','submittedBytes','submittedWidthPx','submittedHeightPx',
        'requestedPixels','deletedCount','participantCount','processedCount',
        'scannedCount','returnedMessages','percent','current','durationMs',
        'retryAfterSeconds'] as $key) {
        $value = $metadata[$key] ?? null;
        if ((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0) $safe[$key] = $value;
    }
    foreach ([
        'channel' => ['room','community','game','link','direct'],
        'ledger' => ['room','community'],
        'assetKind' => ['avatar','nameplate'],
        'control' => ['member-profile-read','game-session-creation','game-session-joining'],
    ] as $key => $allowed) {
        if (in_array($metadata[$key] ?? null, $allowed, true)) $safe[$key] = $metadata[$key];
    }
    if (isset($metadata['pairedMovement']) && is_bool($metadata['pairedMovement'])) $safe['pairedMovement'] = $metadata['pairedMovement'];
    foreach (['roomBatchReached','communityBatchReached'] as $key) {
        if (isset($metadata[$key]) && is_bool($metadata[$key])) $safe[$key] = $metadata[$key];
    }
    $code = $metadata['errorCode'] ?? null;
    if (is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,95}$/D', $code)) $safe['errorCode'] = $code;
    $json = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) && strlen($json) <= LIMIT_EVENT_MAX_METADATA_BYTES ? $json : '{}';
}

function limit_event_record_reached(
    PDO $pdo,
    string $settingId,
    string $scopeKind,
    string $scope,
    string $outcome = 'blocked',
    array $metadata = []
): void {
    try {
        if (!function_exists('corechat_limit_is_enforced')
            || !corechat_limit_is_enforced($pdo, $settingId)) return;
        $definition = function_exists('settings_registry_limit_definition_map')
            ? (settings_registry_limit_definition_map()[$settingId] ?? [])
            : [];
        $fallback = is_numeric($definition['defaultValue'] ?? null)
            ? (float)$definition['defaultValue']
            : (float)app_setting($pdo, $settingId, '0');
        $threshold = function_exists('corechat_limit_value')
            ? corechat_limit_value($pdo, $settingId, $fallback)
            : $fallback;
        if ($threshold === null) return;
        if (is_float($threshold) && floor($threshold) === $threshold) $threshold = (int)$threshold;
        $name = trim((string)($definition['label'] ?? ''));
        if ($name === '') $name = ucwords(str_replace('_', ' ', $settingId));
        limit_event_record(
            $pdo,
            $settingId,
            $name,
            $threshold,
            (string)($definition['unit'] ?? ''),
            $scopeKind,
            $scope,
            $outcome,
            $metadata
        );
    } catch (Throwable) {}
}

function limit_event_record(PDO $pdo, string $settingId, string $limitName, mixed $threshold, string $unit, string $scopeKind, string $scope, string $outcome = 'blocked', array $metadata = []): void {
    try {
        if (!limit_event_schema_valid($pdo)) return;
        $settingId = limit_event_clean($settingId); $outcome = limit_event_clean($outcome, 48) ?: 'blocked'; $scopeKind = limit_event_clean($scopeKind, 48) ?: 'unknown';
        $scopeHash = limit_event_scope_hash($scope); $thresholdJson = json_encode(['value' => $threshold], JSON_UNESCAPED_SLASHES) ?: '{}';
        $fingerprint = hash('sha256', implode('|', [$settingId,$scopeKind,$scopeHash,$outcome,$thresholdJson]));
        $values = ['le_' . bin2hex(random_bytes(16)),$fingerprint,$settingId,limit_event_clean($limitName),$thresholdJson,limit_event_clean($unit,96) ?: null,$scopeKind,$scopeHash,$outcome,trim(app_setting($pdo, 'runtime_audit_active_run', '')) ?: null,limit_event_sample_metadata($pdo, $fingerprint, $settingId, $outcome, $scope, $metadata)];
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO limit_events (public_id,fingerprint,setting_id,limit_name,threshold_json,unit,scope_kind,scope_hash,outcome,audit_run_public_id,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE occurrence_count=occurrence_count+1,last_reached_at=CURRENT_TIMESTAMP,audit_run_public_id=COALESCE(VALUES(audit_run_public_id),audit_run_public_id),metadata_json=VALUES(metadata_json),deleted_at=NULL'
            : 'INSERT INTO limit_events (public_id,fingerprint,setting_id,limit_name,threshold_json,unit,scope_kind,scope_hash,outcome,audit_run_public_id,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(fingerprint) DO UPDATE SET occurrence_count=limit_events.occurrence_count+1,last_reached_at=CURRENT_TIMESTAMP,audit_run_public_id=COALESCE(excluded.audit_run_public_id,limit_events.audit_run_public_id),metadata_json=excluded.metadata_json,deleted_at=NULL';
        $retryAutocommit = db_uses_mysql_syntax($pdo) && !$pdo->inTransaction();
        for ($eventAttempt = 0; ; $eventAttempt++) {
            try {
                $pdo->prepare($sql)->execute($values);
                break;
            } catch (PDOException $error) {
                // A deadlock rolls back this autocommit statement, not a caller-owned transaction.
                if (!$retryAutocommit || $pdo->inTransaction() || $eventAttempt >= 2
                    || (string)$error->getCode() !== '40001'
                    || (int)($error->errorInfo[1] ?? 0) !== 1213) {
                    throw $error;
                }
                usleep(5000 * ($eventAttempt + 1));
            }
        }
    } catch (Throwable) {}
}

function limit_event_project(array $row): array {
    $metadata = json_decode((string)$row['metadata_json'], true);
    return ['publicId'=>(string)$row['public_id'],'settingId'=>(string)$row['setting_id'],'limitName'=>(string)$row['limit_name'],'threshold'=>json_decode((string)$row['threshold_json'],true)?:[],'unit'=>$row['unit']?:null,'scopeKind'=>(string)$row['scope_kind'],'outcome'=>(string)$row['outcome'],'occurrenceCount'=>(int)$row['occurrence_count'],'firstReachedAt'=>(string)$row['first_reached_at'],'lastReachedAt'=>(string)$row['last_reached_at'],'auditRunPublicId'=>$row['audit_run_public_id']?:null,'diagnosticIssueId'=>$row['diagnostic_issue_id']===null?null:(int)$row['diagnostic_issue_id'],'metadata'=>json_decode(limit_event_safe_metadata(is_array($metadata) ? $metadata : []),true)?:[]];
}

function limit_event_filter_sql(array $filters): array {
    $where = ['deleted_at IS NULL'];
    $parameters = [];
    $search = limit_event_clean($filters['search'] ?? '', 100);
    if ($search !== '') {
        $where[] = '(setting_id LIKE ? OR limit_name LIKE ? OR outcome LIKE ?)';
        array_push($parameters, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
    }
    foreach (['outcome' => 48, 'setting_id' => 191, 'scope_kind' => 48, 'audit_run_public_id' => 64] as $key => $maximum) {
        $value = limit_event_clean($filters[$key] ?? '', $maximum);
        if ($value !== '') { $where[] = $key . '=?'; $parameters[] = $value; }
    }
    return ['where' => implode(' AND ', $where), 'parameters' => $parameters];
}

/** Keyset pages bound memory and exclude records created after export begins. */
function limit_event_export_rows(PDO $pdo, array $filters = []): Generator {
    if (!limit_event_schema_valid($pdo)) return;
    $filter = limit_event_filter_sql($filters);
    $cursor = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM limit_events')->fetchColumn() + 1;
    while ($cursor > 1) {
        $statement = $pdo->prepare('SELECT * FROM limit_events WHERE ' . $filter['where'] . ' AND id<? ORDER BY id DESC LIMIT 100');
        $statement->execute([...$filter['parameters'], $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (!$rows) break;
        foreach ($rows as $row) {
            $cursor = (int)$row['id'];
            yield limit_event_project($row);
        }
    }
}

function limit_event_csv_cell(mixed $value): string {
    $value = (string)($value ?? '');
    return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
}

function limit_event_list(PDO $pdo, array $filters = [], int $page = 1, int $pageSize = 25): array {
    if (!limit_event_schema_valid($pdo)) return ['items'=>[],'total'=>0,'page'=>1,'pageSize'=>$pageSize,'pages'=>0];
    $page=max(1,$page);$pageSize=max(5,min(100,$pageSize));$filter=limit_event_filter_sql($filters);$parameters=$filter['parameters'];
    $sqlWhere=' WHERE '.$filter['where'];$count=$pdo->prepare('SELECT COUNT(*) FROM limit_events'.$sqlWhere);$count->execute($parameters);$total=(int)$count->fetchColumn();$offset=($page-1)*$pageSize;
    $list=$pdo->prepare('SELECT * FROM limit_events'.$sqlWhere." ORDER BY last_reached_at DESC,id DESC LIMIT {$pageSize} OFFSET {$offset}");$list->execute($parameters);
    return ['items'=>array_map('limit_event_project',$list->fetchAll()),'total'=>$total,'page'=>$page,'pageSize'=>$pageSize,'pages'=>(int)ceil($total/$pageSize)];
}

function limit_event_snapshot(PDO $pdo): array { try{return limit_event_list($pdo);}catch(Throwable){return ['items'=>[],'total'=>0,'page'=>1,'pageSize'=>25,'pages'=>0];} }
function limit_event_delete(PDO $pdo,string $publicId): bool {$statement=$pdo->prepare('UPDATE limit_events SET deleted_at=CURRENT_TIMESTAMP WHERE public_id=? AND deleted_at IS NULL');$statement->execute([limit_event_clean($publicId,64)]);return $statement->rowCount()>0;}
function limit_event_cleanup(PDO $pdo): int {if(!corechat_limit_is_enforced($pdo,LIMIT_EVENT_RETENTION_SETTING))return 0;$days=max(1,(int)app_setting($pdo,LIMIT_EVENT_RETENTION_SETTING,'90'));$statement=$pdo->prepare('DELETE FROM limit_events WHERE last_reached_at<?');$statement->execute([gmdate('Y-m-d H:i:s',time()-$days*86400)]);$deleted=$statement->rowCount();if($deleted>0)limit_event_record_reached($pdo,LIMIT_EVENT_RETENTION_SETTING,'installation','limit-events-retention','cleaned',['deletedCount'=>$deleted]);return $deleted;}
