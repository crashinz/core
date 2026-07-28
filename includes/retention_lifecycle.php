<?php
declare(strict_types=1);

/**
 * Build 000051 Part 7 retention and non-destructive account-lifecycle owner.
 * Irreversible account deletion remains exclusively owned by Build 000053.
 */

const RETENTION_LIFECYCLE_DOMAINS = [
    'room-community',
    'dm',
    'relationship',
    'game',
    'resolved-report-evidence',
];
const RETENTION_LIFECYCLE_MESSAGE_DEFAULT_DAYS = 30;
const RETENTION_LIFECYCLE_EVIDENCE_DEFAULT_DAYS = 90;
const RETENTION_LIFECYCLE_MIN_DAYS = 1;
const RETENTION_LIFECYCLE_MAX_DAYS = 3650;
const RETENTION_LIFECYCLE_BACKUP_DISCLOSURE =
    'Deleted messages may remain in protected backups until those backups reach their configured expiration date.';

final class RetentionLifecycleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'RETENTION_LIFECYCLE_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function retention_lifecycle_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS retention_policies (
                domain_key VARCHAR(64) PRIMARY KEY, retention_days INT DEFAULT NULL,
                keep_forever TINYINT(1) NOT NULL DEFAULT 0, revision BIGINT NOT NULL DEFAULT 1,
                updated_by_user_id INT DEFAULT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS retention_requests (
                request_id VARCHAR(128) PRIMARY KEY, domain_key VARCHAR(64) NOT NULL,
                actor_user_id INT NOT NULL, from_days INT DEFAULT NULL, from_forever TINYINT(1) NOT NULL,
                to_days INT DEFAULT NULL, to_forever TINYINT(1) NOT NULL,
                expected_policy_revision BIGINT NOT NULL, preview_total BIGINT NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL, cursor_id BIGINT NOT NULL DEFAULT 0,
                deleted_messages BIGINT NOT NULL DEFAULT 0, deleted_reactions BIGINT NOT NULL DEFAULT 0,
                deleted_files BIGINT NOT NULL DEFAULT 0, deleted_evidence BIGINT NOT NULL DEFAULT 0,
                lease_token_hash VARCHAR(64) DEFAULT NULL, lease_expires_at DATETIME DEFAULT NULL,
                error_code VARCHAR(96) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME DEFAULT NULL,
                INDEX idx_retention_request_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS retention_holds (
                hold_id VARCHAR(64) PRIMARY KEY, subject_type VARCHAR(64) NOT NULL,
                subject_key VARCHAR(191) NOT NULL, reason VARCHAR(500) NOT NULL,
                created_by_user_id INT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
                revision BIGINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                released_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_retention_hold_subject (subject_type, subject_key, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS retention_file_cleanup (
                public_path VARCHAR(500) PRIMARY KEY, request_id VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending', error_code VARCHAR(96) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_retention_file_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS account_lifecycle_foundations (
                user_id INT PRIMARY KEY, opaque_identity VARCHAR(96) NOT NULL UNIQUE,
                session_generation BIGINT NOT NULL DEFAULT 1, sessions_revoked_at DATETIME DEFAULT NULL,
                suspension_revision BIGINT NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_lifecycle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS account_lifecycle_idempotency (
                request_id VARCHAR(128) PRIMARY KEY, operation VARCHAR(96) NOT NULL,
                user_id INT NOT NULL, result_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lifecycle_idempotency_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS retention_policies (
            domain_key TEXT PRIMARY KEY, retention_days INTEGER DEFAULT NULL,
            keep_forever INTEGER NOT NULL DEFAULT 0 CHECK (keep_forever IN (0,1)),
            revision INTEGER NOT NULL DEFAULT 1, updated_by_user_id INTEGER DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS retention_requests (
            request_id TEXT PRIMARY KEY, domain_key TEXT NOT NULL,
            actor_user_id INTEGER NOT NULL, from_days INTEGER DEFAULT NULL,
            from_forever INTEGER NOT NULL, to_days INTEGER DEFAULT NULL,
            to_forever INTEGER NOT NULL, expected_policy_revision INTEGER NOT NULL,
            preview_total INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL,
            cursor_id INTEGER NOT NULL DEFAULT 0, deleted_messages INTEGER NOT NULL DEFAULT 0,
            deleted_reactions INTEGER NOT NULL DEFAULT 0, deleted_files INTEGER NOT NULL DEFAULT 0,
            deleted_evidence INTEGER NOT NULL DEFAULT 0, lease_token_hash TEXT DEFAULT NULL,
            lease_expires_at TEXT DEFAULT NULL, error_code TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT DEFAULT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_retention_request_status ON retention_requests(status, updated_at)',
        "CREATE TABLE IF NOT EXISTS retention_holds (
            hold_id TEXT PRIMARY KEY, subject_type TEXT NOT NULL, subject_key TEXT NOT NULL,
            reason TEXT NOT NULL, created_by_user_id INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
            revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            released_at TEXT DEFAULT NULL
        )",
        'CREATE UNIQUE INDEX IF NOT EXISTS uq_retention_hold_subject ON retention_holds(subject_type,subject_key,active)',
        "CREATE TABLE IF NOT EXISTS retention_file_cleanup (
            public_path TEXT PRIMARY KEY, request_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', error_code TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_retention_file_status ON retention_file_cleanup(status,updated_at)',
        "CREATE TABLE IF NOT EXISTS account_lifecycle_foundations (
            user_id INTEGER PRIMARY KEY, opaque_identity TEXT NOT NULL UNIQUE,
            session_generation INTEGER NOT NULL DEFAULT 1, sessions_revoked_at TEXT DEFAULT NULL,
            suspension_revision INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS account_lifecycle_idempotency (
            request_id TEXT PRIMARY KEY, operation TEXT NOT NULL, user_id INTEGER NOT NULL,
            result_json TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_lifecycle_idempotency_user ON account_lifecycle_idempotency(user_id,created_at)',
    ];
}

function retention_lifecycle_private_key(): string
{
    $directory = security_private_storage_directory('account-lifecycle');
    $path = $directory . DIRECTORY_SEPARATOR . 'opaque-identity-key.bin';
    if (!is_file($path)) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $key = random_bytes(32);
        if (file_put_contents($temporary, $key, LOCK_EX) !== 32 || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RetentionLifecycleException(
                'The private lifecycle identity key is unavailable.',
                'LIFECYCLE_IDENTITY_KEY_UNAVAILABLE',
                503
            );
        }
        @chmod($path, 0600);
    }
    $key = file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RetentionLifecycleException(
            'The private lifecycle identity key is invalid.',
            'LIFECYCLE_IDENTITY_KEY_UNAVAILABLE',
            503
        );
    }
    return $key;
}

function retention_lifecycle_opaque_identity(int $userId): string
{
    if ($userId < 1) {
        throw new RetentionLifecycleException('A member is required.', 'LIFECYCLE_USER_REQUIRED', 422);
    }
    return 'member-' . strtolower(substr(
        hash_hmac('sha256', 'corechat-account-lifecycle-v1:' . $userId, retention_lifecycle_private_key()),
        0,
        32
    ));
}

function retention_lifecycle_install_schema(PDO $pdo): void
{
    foreach (retention_lifecycle_schema_statements($pdo) as $statement) $pdo->exec($statement);
    foreach (RETENTION_LIFECYCLE_DOMAINS as $domain) {
        $days = $domain === 'resolved-report-evidence'
            ? RETENTION_LIFECYCLE_EVIDENCE_DEFAULT_DAYS
            : RETENTION_LIFECYCLE_MESSAGE_DEFAULT_DAYS;
        $insert = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO retention_policies (domain_key,retention_days,keep_forever) VALUES (?,?,0)'
            : 'INSERT OR IGNORE INTO retention_policies (domain_key,retention_days,keep_forever) VALUES (?,?,0)';
        $pdo->prepare($insert)->execute([$domain, $days]);
    }
    $users = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($users as $userId) retention_lifecycle_ensure_user($pdo, (int)$userId);
}

function retention_lifecycle_schema_valid(PDO $pdo): bool
{
    foreach ([
        'retention_policies', 'retention_requests', 'retention_holds', 'retention_file_cleanup',
        'account_lifecycle_foundations', 'account_lifecycle_idempotency',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM retention_policies')->fetchColumn()
            === count(RETENTION_LIFECYCLE_DOMAINS)
        && (int)$pdo->query(
            'SELECT COUNT(*) FROM retention_policies
             WHERE keep_forever=0 AND (retention_days<' . RETENTION_LIFECYCLE_MIN_DAYS
                . ' OR retention_days>' . RETENTION_LIFECYCLE_MAX_DAYS . ')'
        )->fetchColumn() === 0;
}

function retention_lifecycle_require_owner(PDO $pdo, int $userId): void
{
    if (!moderation_identity_is_owner($pdo, $userId)) {
        throw new RetentionLifecycleException(
            'Installation Owner authority is required.',
            'RETENTION_OWNER_REQUIRED',
            403
        );
    }
}

function retention_lifecycle_policy(PDO $pdo, string $domain): array
{
    if (!in_array($domain, RETENTION_LIFECYCLE_DOMAINS, true)) {
        throw new RetentionLifecycleException('The retention domain is invalid.', 'RETENTION_DOMAIN_INVALID', 422);
    }
    $statement = $pdo->prepare('SELECT * FROM retention_policies WHERE domain_key=?');
    $statement->execute([$domain]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new RetentionLifecycleException('The retention policy is unavailable.', 'RETENTION_POLICY_UNAVAILABLE', 503);
    }
    return [
        'domain' => (string)$row['domain_key'],
        'days' => $row['retention_days'] === null ? null : (int)$row['retention_days'],
        'keepForever' => (bool)$row['keep_forever'],
        'revision' => (int)$row['revision'],
        'updatedAt' => $row['updated_at'],
    ];
}

function retention_lifecycle_policy_projection(PDO $pdo): array
{
    return [
        'policies' => array_map(
            static fn(string $domain): array => retention_lifecycle_policy($pdo, $domain),
            RETENTION_LIFECYCLE_DOMAINS
        ),
        'bounds' => ['minimumDays' => 1, 'maximumDays' => 3650, 'keepForever' => true],
        'backupDisclosure' => RETENTION_LIFECYCLE_BACKUP_DISCLOSURE,
        'openReportsExpire' => false,
        'safetyHoldsOverrideExpiry' => true,
        'sharedCatalogMediaExpiresWithMessage' => false,
        'accountDeletionAvailable' => false,
        'accountDeletionOwner' => 'Build 000053',
    ];
}

function retention_lifecycle_route(string $domain): array
{
    return match ($domain) {
        'room-community' => [
            'sources' => [
                ['table' => 'messages', 'where' => '1=1', 'params' => []],
                ['table' => 'community_messages', 'where' => "scope='community'", 'params' => []],
            ],
        ],
        'dm' => ['sources' => [
            ['table' => 'community_messages', 'where' => "scope='dm'", 'params' => []],
        ]],
        'relationship' => ['sources' => [
            ['table' => 'community_messages', 'where' => "scope='link'", 'params' => []],
        ]],
        'game' => ['sources' => [
            ['table' => 'game_chat_messages', 'where' => '1=1', 'params' => []],
        ]],
        'resolved-report-evidence' => ['sources' => [
            [
                'table' => 'moderation_evidence',
                'where' => "safety_hold=0 AND report_id IN (
                    SELECT id FROM moderation_reports
                    WHERE status NOT IN ('received','under-review','open')
                )",
                'params' => [],
            ],
        ]],
        default => throw new RetentionLifecycleException(
            'The retention domain is invalid.',
            'RETENTION_DOMAIN_INVALID',
            422
        ),
    };
}

function retention_lifecycle_cutoff(int $days): string
{
    return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
}

function retention_lifecycle_preview(PDO $pdo, string $domain, ?int $days, bool $keepForever): array
{
    if (!$keepForever && ($days === null || $days < 1 || $days > 3650)) {
        throw new RetentionLifecycleException(
            'Retention must be 1-3650 days or Keep forever.',
            'RETENTION_RANGE_INVALID',
            422
        );
    }
    $total = 0;
    if (!$keepForever) {
        $cutoff = retention_lifecycle_cutoff((int)$days);
        foreach (retention_lifecycle_route($domain)['sources'] as $source) {
            $dateColumn = $source['table'] === 'moderation_evidence' ? 'created_at' : 'sent_at';
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM {$source['table']}
                 WHERE {$source['where']}
                   AND NOT EXISTS (
                     SELECT 1 FROM retention_holds rh
                     WHERE rh.active=1 AND rh.subject_type=?
                       AND rh.subject_key=CAST({$source['table']}.id AS CHAR)
                   )
                   AND {$dateColumn}<?"
            );
            $statement->execute([...$source['params'], $source['table'], $cutoff]);
            $total += (int)$statement->fetchColumn();
        }
    }
    return [
        'domain' => $domain,
        'days' => $keepForever ? null : $days,
        'keepForever' => $keepForever,
        'estimatedEligibleItems' => $total,
        'estimateIsBoundedSnapshot' => true,
        'backupDisclosure' => RETENTION_LIFECYCLE_BACKUP_DISCLOSURE,
    ];
}

function retention_lifecycle_request_change(PDO $pdo, int $userId, array $input): array
{
    retention_lifecycle_require_owner($pdo, $userId);
    security_require_recent_authentication();
    $domain = trim((string)($input['domain'] ?? ''));
    $requestId = trim((string)($input['requestId'] ?? ''));
    $keepForever = !empty($input['keepForever']);
    $days = $keepForever ? null : (int)($input['days'] ?? 0);
    if ($requestId === '' || empty($input['confirmed'])) {
        throw new RetentionLifecycleException(
            'Inline review and explicit confirmation are required.',
            'RETENTION_CONFIRMATION_REQUIRED',
            422
        );
    }
    $existing = $pdo->prepare('SELECT * FROM retention_requests WHERE request_id=?');
    $existing->execute([$requestId]);
    $existingRequest = $existing->fetch();
    if (is_array($existingRequest)) {
        $matches = (int)$existingRequest['actor_user_id'] === $userId
            && (string)$existingRequest['domain_key'] === $domain
            && (bool)$existingRequest['to_forever'] === $keepForever
            && (
                $keepForever
                ? $existingRequest['to_days'] === null
                : (int)$existingRequest['to_days'] === $days
            );
        if (!$matches) {
            throw new RetentionLifecycleException(
                'The durable retention request ID belongs to a different operation.',
                'RETENTION_REQUEST_ID_CONFLICT',
                409
            );
        }
        return [
            'requestId' => $requestId,
            'status' => (string)$existingRequest['status'],
            'idempotentReplay' => true,
        ];
    }
    $policy = retention_lifecycle_policy($pdo, $domain);
    if ((int)($input['expectedRevision'] ?? 0) !== $policy['revision']) {
        throw new RetentionLifecycleException('The retention policy changed elsewhere.', 'RETENTION_POLICY_STALE', 409);
    }
    $preview = retention_lifecycle_preview($pdo, $domain, $days, $keepForever);
    $status = $preview['estimatedEligibleItems'] > 0 ? 'preparing' : 'complete';
    $pdo->prepare(
        'INSERT INTO retention_requests
         (request_id,domain_key,actor_user_id,from_days,from_forever,to_days,to_forever,
          expected_policy_revision,preview_total,status,completed_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $requestId, $domain, $userId, $policy['days'], $policy['keepForever'] ? 1 : 0,
        $days, $keepForever ? 1 : 0, $policy['revision'],
        $preview['estimatedEligibleItems'], $status,
        $status === 'complete' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    if ($status === 'complete') {
        retention_lifecycle_set_policy($pdo, $domain, $days, $keepForever, $userId, $policy['revision']);
    }
    log_tool(
        $pdo,
        $userId,
        'retention_policy_change_request',
        null,
        null,
        "domain:{$domain}; estimate:{$preview['estimatedEligibleItems']}; request:{$requestId}"
    );
    return [
        'requestId' => $requestId,
        'status' => $status,
        'preview' => $preview,
        'idempotentReplay' => false,
    ];
}

function retention_lifecycle_set_policy(
    PDO $pdo,
    string $domain,
    ?int $days,
    bool $keepForever,
    int $userId,
    int $expectedRevision
): void {
    $policy = retention_lifecycle_policy($pdo, $domain);
    if ($policy['revision'] !== $expectedRevision) {
        throw new RetentionLifecycleException('The retention policy changed elsewhere.', 'RETENTION_POLICY_STALE', 409);
    }
    $statement = $pdo->prepare(
        'UPDATE retention_policies
         SET retention_days=?,keep_forever=?,revision=revision+1,updated_by_user_id=?,updated_at=CURRENT_TIMESTAMP
         WHERE domain_key=? AND revision=?'
    );
    $statement->execute([$days, $keepForever ? 1 : 0, $userId, $domain, $expectedRevision]);
    if ($statement->rowCount() !== 1) {
        throw new RetentionLifecycleException('The retention policy changed elsewhere.', 'RETENTION_POLICY_STALE', 409);
    }
}

function retention_lifecycle_message_file_referenced(PDO $pdo, string $path): bool
{
    foreach (['messages', 'community_messages', 'game_chat_messages'] as $table) {
        $statement = $pdo->prepare(
            "SELECT 1 FROM {$table} WHERE content=? AND message_type IN ('file','voice_note') LIMIT 1"
        );
        $statement->execute([$path]);
        if ($statement->fetchColumn()) return true;
    }
    return false;
}

function retention_lifecycle_delete_unreferenced_file(PDO $pdo, string $publicPath): bool
{
    if (retention_lifecycle_message_file_referenced($pdo, $publicPath)) return false;
    if (preg_match('#^/assets/uploads/(files|voice)/[A-Za-z0-9._-]+$#', $publicPath) !== 1) return false;
    $root = realpath(dirname(__DIR__) . '/assets/uploads');
    $candidate = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $publicPath);
    $parent = realpath(dirname($candidate));
    if (!is_string($root) || !is_string($parent)
        || !str_starts_with(strtolower($parent) . DIRECTORY_SEPARATOR, strtolower($root) . DIRECTORY_SEPARATOR)
        || !is_file($candidate)) return false;
    return @unlink($candidate);
}

function retention_lifecycle_process_file_cleanup(PDO $pdo, string $requestId, int $limit): int
{
    $statement = $pdo->prepare(
        "SELECT public_path FROM retention_file_cleanup
         WHERE request_id=? AND status='pending' ORDER BY created_at ASC"
    );
    $statement->execute([$requestId]);
    $deleted = 0;
    foreach (array_slice($statement->fetchAll(PDO::FETCH_COLUMN), 0, max(1, $limit)) as $publicPath) {
        $referenced = retention_lifecycle_message_file_referenced($pdo, (string)$publicPath);
        $removed = !$referenced && retention_lifecycle_delete_unreferenced_file($pdo, (string)$publicPath);
        $status = $removed ? 'deleted' : ($referenced ? 'retained-referenced' : 'absent-or-unsafe');
        $pdo->prepare(
            'UPDATE retention_file_cleanup SET status=?,updated_at=CURRENT_TIMESTAMP WHERE public_path=?'
        )->execute([$status, $publicPath]);
        if ($removed) $deleted++;
    }
    return $deleted;
}

function retention_lifecycle_run_batch(
    PDO $pdo,
    int $userId,
    string $requestId,
    int $batchSize = 100
): array {
    retention_lifecycle_require_owner($pdo, $userId);
    $batchSize = max(1, min(500, $batchSize));
    $statement = $pdo->prepare('SELECT * FROM retention_requests WHERE request_id=?');
    $statement->execute([$requestId]);
    $request = $statement->fetch();
    if (!is_array($request)) {
        throw new RetentionLifecycleException('The retention request was not found.', 'RETENTION_REQUEST_NOT_FOUND', 404);
    }
    if ($request['status'] === 'complete') return retention_lifecycle_request_projection($pdo, $requestId);
    $tokenHash = strtoupper(hash('sha256', random_bytes(32)));
    $now = gmdate('Y-m-d H:i:s');
    $lease = $pdo->prepare(
        "UPDATE retention_requests
         SET lease_token_hash=?,lease_expires_at=?,status='running',error_code=NULL,updated_at=CURRENT_TIMESTAMP
         WHERE request_id=? AND (lease_token_hash IS NULL OR lease_expires_at<?)"
    );
    $lease->execute([$tokenHash, gmdate('Y-m-d H:i:s', time() + 30), $requestId, $now]);
    if ($lease->rowCount() !== 1) {
        throw new RetentionLifecycleException('The retention request is already running.', 'RETENTION_REQUEST_LEASED', 409);
    }
    $domain = (string)$request['domain_key'];
    $days = (int)$request['to_days'];
    $cutoff = retention_lifecycle_cutoff($days);
    $messageDeletes = 0;
    $reactionDeletes = 0;
    $fileDeletes = 0;
    $evidenceDeletes = 0;
    $lastId = (int)$request['cursor_id'];
    try {
        $pdo->beginTransaction();
        $sources = retention_lifecycle_route($domain)['sources'];
        foreach ($sources as $source) {
            if (($messageDeletes + $evidenceDeletes) >= $batchSize) break;
            $remainingBatch = $batchSize - $messageDeletes - $evidenceDeletes;
            $dateColumn = $source['table'] === 'moderation_evidence' ? 'created_at' : 'sent_at';
            $select = $pdo->prepare(
                "SELECT * FROM {$source['table']}
                 WHERE {$source['where']}
                   AND NOT EXISTS (
                     SELECT 1 FROM retention_holds rh
                     WHERE rh.active=1 AND rh.subject_type=?
                       AND rh.subject_key=CAST({$source['table']}.id AS CHAR)
                   )
                   AND {$dateColumn}<? AND id>?
                 ORDER BY id ASC LIMIT {$remainingBatch}"
            );
            $select->execute([...$source['params'], $source['table'], $cutoff, 0]);
            $rows = $select->fetchAll();
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if ($source['table'] === 'moderation_evidence') {
                    $deleteEvidence = $pdo->prepare('DELETE FROM moderation_evidence WHERE id=? AND safety_hold=0');
                    $deleteEvidence->execute([$id]);
                    $evidenceDeletes += $deleteEvidence->rowCount();
                } else {
                    $filePath = '';
                    if (in_array((string)($row['message_type'] ?? ''), ['file', 'voice_note'], true)) {
                        $projected = array_key_exists('protection_mode', $row)
                            ? message_protection_project_row($row)
                            : $row;
                        $filePath = (string)($projected['content'] ?? '');
                    }
                    if ($source['table'] === 'messages') {
                        $deleteReactions = $pdo->prepare('DELETE FROM message_reactions WHERE message_id=?');
                        $deleteReactions->execute([$id]);
                        $reactionDeletes += $deleteReactions->rowCount();
                    } elseif ($source['table'] === 'community_messages') {
                        $deleteReactions = $pdo->prepare('DELETE FROM community_message_reactions WHERE message_id=?');
                        $deleteReactions->execute([$id]);
                        $reactionDeletes += $deleteReactions->rowCount();
                    }
                    $deleteMessage = $pdo->prepare("DELETE FROM {$source['table']} WHERE id=?");
                    $deleteMessage->execute([$id]);
                    $messageDeletes += $deleteMessage->rowCount();
                    if ($filePath !== '') {
                        $queue = db_uses_mysql_syntax($pdo)
                            ? 'INSERT IGNORE INTO retention_file_cleanup (public_path,request_id) VALUES (?,?)'
                            : 'INSERT OR IGNORE INTO retention_file_cleanup (public_path,request_id) VALUES (?,?)';
                        $pdo->prepare($queue)->execute([$filePath, $requestId]);
                    }
                }
                $lastId = max($lastId, $id);
            }
        }
        $remaining = retention_lifecycle_preview($pdo, $domain, $days, false)['estimatedEligibleItems'];
        $complete = $remaining === 0;
        $pdo->prepare(
            'UPDATE retention_requests
             SET cursor_id=?,deleted_messages=deleted_messages+?,deleted_reactions=deleted_reactions+?,
                 deleted_files=deleted_files+?,deleted_evidence=deleted_evidence+?,status=?,
                 lease_token_hash=NULL,lease_expires_at=NULL,updated_at=CURRENT_TIMESTAMP,
                 completed_at=? WHERE request_id=? AND lease_token_hash=?'
        )->execute([
            $lastId, $messageDeletes, $reactionDeletes, $fileDeletes, $evidenceDeletes,
            $complete ? 'complete' : 'running',
            $complete ? gmdate('Y-m-d H:i:s') : null,
            $requestId,
            $tokenHash,
        ]);
        if ($complete) {
            retention_lifecycle_set_policy(
                $pdo,
                $domain,
                $days,
                (bool)$request['to_forever'],
                $userId,
                (int)$request['expected_policy_revision']
            );
        }
        $pdo->commit();
        $fileDeletes = retention_lifecycle_process_file_cleanup($pdo, $requestId, $batchSize);
        if ($fileDeletes > 0) {
            $pdo->prepare(
                'UPDATE retention_requests
                 SET deleted_files=deleted_files+?,updated_at=CURRENT_TIMESTAMP WHERE request_id=?'
            )->execute([$fileDeletes, $requestId]);
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $code = $error instanceof RetentionLifecycleException
            ? $error->errorCode
            : 'RETENTION_BATCH_FAILED';
        $pdo->prepare(
            "UPDATE retention_requests
             SET status='interrupted',error_code=?,lease_token_hash=NULL,lease_expires_at=NULL,
                 updated_at=CURRENT_TIMESTAMP WHERE request_id=? AND lease_token_hash=?"
        )->execute([$code, $requestId, $tokenHash]);
        throw $error;
    }
    log_tool(
        $pdo,
        $userId,
        'retention_batch_progress',
        null,
        null,
        "request:{$requestId}; messages:{$messageDeletes}; reactions:{$reactionDeletes}; files:{$fileDeletes}; evidence:{$evidenceDeletes}"
    );
    return retention_lifecycle_request_projection($pdo, $requestId);
}

function retention_lifecycle_request_projection(PDO $pdo, string $requestId): array
{
    $statement = $pdo->prepare('SELECT * FROM retention_requests WHERE request_id=?');
    $statement->execute([$requestId]);
    $row = $statement->fetch();
    if (!is_array($row)) return [];
    return [
        'requestId' => (string)$row['request_id'],
        'domain' => (string)$row['domain_key'],
        'status' => (string)$row['status'],
        'previewTotal' => (int)$row['preview_total'],
        'deletedMessages' => (int)$row['deleted_messages'],
        'deletedReactions' => (int)$row['deleted_reactions'],
        'deletedFiles' => (int)$row['deleted_files'],
        'deletedEvidence' => (int)$row['deleted_evidence'],
        'errorCode' => $row['error_code'],
        'completedAt' => $row['completed_at'],
    ];
}

function retention_lifecycle_set_hold(
    PDO $pdo,
    int $userId,
    string $subjectType,
    string $subjectKey,
    string $reason,
    bool $active
): array {
    moderation_safety_require_staff_capability($pdo, $userId, 'manage-evidence');
    $reason = trim($reason);
    if ($subjectType === '' || $subjectKey === '' || ($active && $reason === '')) {
        throw new RetentionLifecycleException(
            'A hold subject and reason are required.',
            'RETENTION_HOLD_REASON_REQUIRED',
            422
        );
    }
    if ($active) {
        $existing = $pdo->prepare(
            'SELECT hold_id FROM retention_holds
             WHERE subject_type=? AND subject_key=?
             ORDER BY active DESC,created_at DESC LIMIT 1'
        );
        $existing->execute([$subjectType, $subjectKey]);
        $holdId = $existing->fetchColumn();
        if (is_string($holdId)) {
            $pdo->prepare(
                'UPDATE retention_holds
                 SET reason=?,created_by_user_id=?,active=1,revision=revision+1,
                     created_at=CURRENT_TIMESTAMP,released_at=NULL
                 WHERE hold_id=?'
            )->execute([$reason, $userId, $holdId]);
        } else {
            $holdId = 'hold-' . bin2hex(random_bytes(12));
            $pdo->prepare(
                'INSERT INTO retention_holds
                 (hold_id,subject_type,subject_key,reason,created_by_user_id,active)
                 VALUES (?,?,?,?,?,1)'
            )->execute([$holdId, $subjectType, $subjectKey, $reason, $userId]);
        }
        return ['holdId' => $holdId, 'active' => true];
    }
    $pdo->prepare(
        'UPDATE retention_holds
         SET active=0,revision=revision+1,released_at=CURRENT_TIMESTAMP
         WHERE subject_type=? AND subject_key=? AND active=1'
    )->execute([$subjectType, $subjectKey]);
    return ['active' => false];
}

function retention_lifecycle_ensure_user(PDO $pdo, int $userId): array
{
    $insert = db_uses_mysql_syntax($pdo)
        ? 'INSERT IGNORE INTO account_lifecycle_foundations (user_id,opaque_identity) VALUES (?,?)'
        : 'INSERT OR IGNORE INTO account_lifecycle_foundations (user_id,opaque_identity) VALUES (?,?)';
    $pdo->prepare($insert)->execute([$userId, retention_lifecycle_opaque_identity($userId)]);
    $statement = $pdo->prepare('SELECT * FROM account_lifecycle_foundations WHERE user_id=?');
    $statement->execute([$userId]);
    return $statement->fetch() ?: [];
}

function retention_lifecycle_revoke_sessions(
    PDO $pdo,
    int $actorUserId,
    int $targetUserId,
    string $requestId,
    string $reason
): array {
    $reason = trim($reason);
    if ($requestId === '' || $reason === '') {
        throw new RetentionLifecycleException(
            'A durable request and reason are required.',
            'LIFECYCLE_REVOCATION_REASON_REQUIRED',
            422
        );
    }
    $existing = $pdo->prepare(
        "SELECT result_json FROM account_lifecycle_idempotency
         WHERE request_id=? AND operation='revoke-sessions'"
    );
    $existing->execute([$requestId]);
    $resultJson = $existing->fetchColumn();
    if (is_string($resultJson)) {
        $result = json_decode($resultJson, true);
        return is_array($result)
            ? array_replace($result, ['idempotentReplay' => true])
            : [];
    }
    retention_lifecycle_ensure_user($pdo, $targetUserId);
    $pdo->prepare(
        'UPDATE account_lifecycle_foundations
         SET session_generation=session_generation+1,sessions_revoked_at=CURRENT_TIMESTAMP,
             updated_at=CURRENT_TIMESTAMP WHERE user_id=?'
    )->execute([$targetUserId]);
    $row = retention_lifecycle_ensure_user($pdo, $targetUserId);
    $result = [
        'userId' => $targetUserId,
        'opaqueIdentity' => (string)$row['opaque_identity'],
        'sessionGeneration' => (int)$row['session_generation'],
        'revoked' => true,
        'idempotentReplay' => false,
    ];
    $pdo->prepare(
        'INSERT INTO account_lifecycle_idempotency
         (request_id,operation,user_id,result_json) VALUES (?,?,?,?)'
    )->execute([
        $requestId,
        'revoke-sessions',
        $targetUserId,
        json_encode($result, JSON_UNESCAPED_SLASHES),
    ]);
    log_tool(
        $pdo,
        $actorUserId,
        'account_sessions_revoke',
        $targetUserId,
        null,
        'reason-sha256:' . strtoupper(hash('sha256', $reason)) . '; request:' . $requestId
    );
    return $result;
}

function retention_lifecycle_session_authorized(PDO $pdo, int $userId): bool
{
    try {
        if (!database_migration_table_exists($pdo, 'account_lifecycle_foundations')) return true;
    } catch (Throwable) {
        return true;
    }
    $row = retention_lifecycle_ensure_user($pdo, $userId);
    $generation = (int)($row['session_generation'] ?? 1);
    $revokedAt = strtotime((string)($row['sessions_revoked_at'] ?? '')) ?: 0;
    $authenticatedAt = (int)($_SESSION['_authenticated_at'] ?? 0);
    if (!isset($_SESSION['_account_session_generation'])) {
        if ($revokedAt > 0 && $authenticatedAt <= $revokedAt) return false;
        $_SESSION['_account_session_generation'] = $generation;
    }
    return (int)$_SESSION['_account_session_generation'] === $generation
        && !($revokedAt > 0 && $authenticatedAt <= $revokedAt);
}

function retention_lifecycle_ownership_safeguards(PDO $pdo, int $userId): array
{
    $rooms = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE owner_id=?');
    $rooms->execute([$userId]);
    return [
        'opaqueIdentity' => (string)(retention_lifecycle_ensure_user($pdo, $userId)['opaque_identity'] ?? ''),
        'ownedRoomCount' => (int)$rooms->fetchColumn(),
        'isInstallationOwner' => moderation_identity_is_owner($pdo, $userId),
        'transferRequiredBeforeFutureDeletion' => true,
        'futureDeleteAccountOwner' => 'Build 000053',
        'deleteAccountAvailable' => false,
    ];
}

function retention_lifecycle_assert_future_transfer_safe(PDO $pdo, int $userId): void
{
    $projection = retention_lifecycle_ownership_safeguards($pdo, $userId);
    if ($projection['isInstallationOwner'] || $projection['ownedRoomCount'] > 0) {
        throw new RetentionLifecycleException(
            'Ownership must be transferred before any future account-deletion operation.',
            'LIFECYCLE_OWNERSHIP_TRANSFER_REQUIRED',
            409,
            $projection
        );
    }
}
