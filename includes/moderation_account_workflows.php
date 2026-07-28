<?php
declare(strict_types=1);

/**
 * Build 000051 Account request, appeal, notice, and outside-content
 * confirmation owner.
 */

const MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING = 'moderation_trust_outside_content_confirmation_mode';
const MODERATION_ACCOUNT_OUTSIDE_MODES = [
    'every-upload-import',
    'public-only',
    'reminder',
    'disabled',
];
const MODERATION_ACCOUNT_CASE_STATUSES = [
    'received',
    'under-review',
    'approved',
    'denied',
    'modified',
    'closed',
];
const MODERATION_ACCOUNT_ACTIVE_CASE_STATUSES = ['received', 'under-review'];
const MODERATION_ACCOUNT_NOTE_MAX = 2000;
const MODERATION_ACCOUNT_PUBLIC_REASON_MAX = 500;
const MODERATION_ACCOUNT_COOLDOWN_SECONDS = 604800;

final class ModerationAccountWorkflowException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MODERATION_ACCOUNT_WORKFLOW_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $projection = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function moderation_account_unicode_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function moderation_account_bounded_text(
    mixed $value,
    int $maximum,
    string $label,
    bool $required = false
): string {
    $text = trim((string)$value);
    if ($required && $text === '') {
        throw new ModerationAccountWorkflowException(
            "{$label} is required.",
            'MODERATION_CASE_TEXT_REQUIRED',
            400
        );
    }
    if (moderation_account_unicode_length($text) > $maximum) {
        throw new ModerationAccountWorkflowException(
            "{$label} must be {$maximum} characters or fewer.",
            'MODERATION_CASE_TEXT_TOO_LONG',
            400,
            ['maximum' => $maximum]
        );
    }
    return $text;
}

function moderation_account_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS moderation_cases (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                subject_user_id INT NOT NULL,
                case_type VARCHAR(48) NOT NULL,
                status VARCHAR(32) NOT NULL,
                public_reason VARCHAR(500) DEFAULT NULL,
                private_note TEXT DEFAULT NULL,
                enforcement_reference VARCHAR(128) DEFAULT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME DEFAULT NULL,
                INDEX idx_moderation_case_subject (subject_user_id, case_type, status),
                CONSTRAINT fk_moderation_case_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_case_items (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                case_id BIGINT NOT NULL,
                item_type VARCHAR(32) NOT NULL,
                item_key VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL,
                public_reason VARCHAR(500) DEFAULT NULL,
                revision BIGINT NOT NULL DEFAULT 1,
                UNIQUE KEY idx_moderation_case_item (case_id, item_type, item_key),
                CONSTRAINT fk_moderation_case_item_case FOREIGN KEY (case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_case_actions (
                request_id VARCHAR(128) PRIMARY KEY,
                case_id BIGINT NOT NULL,
                actor_user_id INT NOT NULL,
                action VARCHAR(64) NOT NULL,
                from_revision BIGINT NOT NULL,
                to_revision BIGINT NOT NULL,
                public_reason VARCHAR(500) DEFAULT NULL,
                internal_note TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_moderation_case_action_case (case_id, created_at),
                CONSTRAINT fk_moderation_case_action_case FOREIGN KEY (case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS moderation_notices (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                notice_type VARCHAR(64) NOT NULL,
                public_reason VARCHAR(500) DEFAULT NULL,
                changed_capabilities_json TEXT DEFAULT NULL,
                effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME DEFAULT NULL,
                read_at DATETIME DEFAULT NULL,
                INDEX idx_moderation_notice_user (user_id, effective_at),
                CONSTRAINT fk_moderation_notice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS outside_content_confirmations (
                confirmation_id VARCHAR(128) NOT NULL,
                actor_user_id INT NOT NULL,
                operation VARCHAR(96) NOT NULL,
                mode VARCHAR(32) NOT NULL,
                token_sha256 VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (confirmation_id, actor_user_id, operation),
                INDEX idx_outside_confirmation_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS moderation_cases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            subject_user_id INTEGER NOT NULL,
            case_type TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('received','under-review','approved','denied','modified','closed')),
            public_reason TEXT DEFAULT NULL,
            private_note TEXT DEFAULT NULL,
            enforcement_reference TEXT DEFAULT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT DEFAULT NULL,
            FOREIGN KEY(subject_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_moderation_case_subject ON moderation_cases(subject_user_id, case_type, status)',
        "CREATE TABLE IF NOT EXISTS moderation_case_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            case_id INTEGER NOT NULL,
            item_type TEXT NOT NULL,
            item_key TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('received','under-review','approved','denied','modified','closed')),
            public_reason TEXT DEFAULT NULL,
            revision INTEGER NOT NULL DEFAULT 1,
            UNIQUE(case_id, item_type, item_key),
            FOREIGN KEY(case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS moderation_case_actions (
            request_id TEXT PRIMARY KEY,
            case_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            from_revision INTEGER NOT NULL,
            to_revision INTEGER NOT NULL,
            public_reason TEXT DEFAULT NULL,
            internal_note TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(case_id) REFERENCES moderation_cases(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_moderation_case_action_case ON moderation_case_actions(case_id, created_at)',
        "CREATE TABLE IF NOT EXISTS moderation_notices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            notice_type TEXT NOT NULL,
            public_reason TEXT DEFAULT NULL,
            changed_capabilities_json TEXT DEFAULT NULL,
            effective_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT DEFAULT NULL,
            read_at TEXT DEFAULT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_moderation_notice_user ON moderation_notices(user_id, effective_at)',
        "CREATE TABLE IF NOT EXISTS outside_content_confirmations (
            confirmation_id TEXT NOT NULL,
            actor_user_id INTEGER NOT NULL,
            operation TEXT NOT NULL,
            mode TEXT NOT NULL,
            token_sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (confirmation_id, actor_user_id, operation)
        )",
        'CREATE INDEX IF NOT EXISTS idx_outside_confirmation_created ON outside_content_confirmations(created_at)',
    ];
}

function moderation_account_outside_mode_default(PDO $pdo): string
{
    $preset = app_setting($pdo, MODERATION_IDENTITY_SETUP_PRESET_SETTING, 'small-trusted');
    $definition = moderation_identity_setup_presets()[$preset] ?? moderation_identity_setup_presets()['small-trusted'];
    return (string)($definition['outsideConfirmationMode'] ?? 'public-only');
}

function moderation_account_install_schema(PDO $pdo): void
{
    foreach (moderation_account_schema_statements($pdo) as $statement) $pdo->exec($statement);
    $stored = app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, "\0");
    if ($stored === "\0") {
        set_app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, moderation_account_outside_mode_default($pdo));
    }
    $rows = $pdo->query("SELECT user_id, revision FROM user_trust WHERE trust_state='pending-approval' ORDER BY user_id")->fetchAll();
    foreach ($rows as $row) {
        moderation_account_ensure_pending_review($pdo, (int)$row['user_id'], (int)$row['revision']);
    }
}

function moderation_account_schema_valid(PDO $pdo): bool
{
    foreach ([
        'moderation_cases', 'moderation_case_items', 'moderation_case_actions',
        'moderation_notices', 'outside_content_confirmations',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return in_array(
        app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, ''),
        MODERATION_ACCOUNT_OUTSIDE_MODES,
        true
    );
}

function moderation_account_case_public_id(): string
{
    return 'case-' . strtolower(str_replace('-', '', uuid_v4()));
}

function moderation_account_notice_public_id(): string
{
    return 'notice-' . strtolower(str_replace('-', '', uuid_v4()));
}

function moderation_account_ensure_pending_review(PDO $pdo, int $userId, int $trustRevision = 1): array
{
    $active = $pdo->prepare(
        "SELECT * FROM moderation_cases
         WHERE subject_user_id=? AND case_type='trusted-review'
           AND status IN ('received','under-review')
         ORDER BY id DESC LIMIT 1"
    );
    $active->execute([$userId]);
    $row = $active->fetch();
    if (is_array($row)) return $row;
    $publicId = moderation_account_case_public_id();
    $pdo->prepare(
        "INSERT INTO moderation_cases
         (public_id,subject_user_id,case_type,status,public_reason,private_note,enforcement_reference,revision)
         VALUES (?,?,'trusted-review','received',NULL,NULL,?,1)"
    )->execute([$publicId, $userId, 'pending-trust-revision-' . max(1, $trustRevision)]);
    $id = (int)$pdo->lastInsertId();
    $result = $pdo->prepare('SELECT * FROM moderation_cases WHERE id=?');
    $result->execute([$id]);
    return $result->fetch() ?: [];
}

function moderation_account_case_projection(PDO $pdo, int $userId): array
{
    $cases = $pdo->prepare(
        'SELECT id,public_id,case_type,status,public_reason,revision,created_at,updated_at,closed_at
         FROM moderation_cases WHERE subject_user_id=? ORDER BY id DESC'
    );
    $cases->execute([$userId]);
    $result = [];
    foreach ($cases->fetchAll() as $case) {
        $items = $pdo->prepare(
            'SELECT item_type,item_key,status,public_reason,revision
             FROM moderation_case_items WHERE case_id=? ORDER BY item_type,item_key'
        );
        $items->execute([(int)$case['id']]);
        unset($case['id']);
        $case['revision'] = (int)$case['revision'];
        $case['items'] = $items->fetchAll();
        $result[] = $case;
    }
    return $result;
}

function moderation_account_notice_projection(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT public_id,notice_type,public_reason,changed_capabilities_json,
                effective_at,expires_at,read_at
         FROM moderation_notices WHERE user_id=? ORDER BY id DESC LIMIT 100'
    );
    $statement->execute([$userId]);
    return array_map(static function (array $notice): array {
        $changed = json_decode((string)($notice['changed_capabilities_json'] ?? '[]'), true);
        $notice['changedCapabilities'] = is_array($changed) ? array_values($changed) : [];
        unset($notice['changed_capabilities_json']);
        return $notice;
    }, $statement->fetchAll());
}

function moderation_account_full_projection(PDO $pdo, int $userId): array
{
    $authorization = moderation_identity_account_authorization($pdo, $userId);
    if ($authorization['trustState'] === 'pending-approval') {
        moderation_account_ensure_pending_review($pdo, $userId, $authorization['trustRevision']);
    }
    return [
        'authorization' => $authorization,
        'cases' => moderation_account_case_projection($pdo, $userId),
        'notices' => moderation_account_notice_projection($pdo, $userId),
        'requestPolicy' => [
            'trustedReviewNoteMaximum' => MODERATION_ACCOUNT_NOTE_MAX,
            'appealNoteMaximum' => MODERATION_ACCOUNT_NOTE_MAX,
            'publicReasonMaximum' => MODERATION_ACCOUNT_PUBLIC_REASON_MAX,
            'closedRequestCooldownDays' => 7,
            'caseStatuses' => MODERATION_ACCOUNT_CASE_STATUSES,
        ],
    ];
}

function moderation_account_validate_request_id(mixed $value): string
{
    $requestId = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $requestId)) {
        throw new ModerationAccountWorkflowException(
            'A valid request ID is required.',
            'MODERATION_REQUEST_ID_REQUIRED',
            400
        );
    }
    return $requestId;
}

function moderation_account_assert_case_submission_allowed(PDO $pdo, int $userId, string $caseType): void
{
    $active = $pdo->prepare(
        "SELECT 1 FROM moderation_cases
         WHERE subject_user_id=? AND case_type=?
           AND status IN ('received','under-review') LIMIT 1"
    );
    $active->execute([$userId, $caseType]);
    if ($active->fetchColumn()) {
        throw new ModerationAccountWorkflowException(
            'An active request of this type already exists.',
            'MODERATION_REQUEST_ALREADY_ACTIVE',
            409
        );
    }
    $recent = $pdo->prepare(
        "SELECT updated_at FROM moderation_cases
         WHERE subject_user_id=? AND case_type=? AND status IN ('denied','closed')
         ORDER BY id DESC LIMIT 1"
    );
    $recent->execute([$userId, $caseType]);
    $updatedAt = $recent->fetchColumn();
    if (is_string($updatedAt) && strtotime($updatedAt) > time() - MODERATION_ACCOUNT_COOLDOWN_SECONDS) {
        throw new ModerationAccountWorkflowException(
            'This request is in its seven-day cooldown.',
            'MODERATION_REQUEST_COOLDOWN_ACTIVE',
            429
        );
    }
}

function moderation_account_submit_case(
    PDO $pdo,
    int $userId,
    string $caseType,
    array $input
): array {
    $requestId = moderation_account_validate_request_id($input['request_id'] ?? null);
    $replay = $pdo->prepare('SELECT case_id FROM moderation_case_actions WHERE request_id=? LIMIT 1');
    $replay->execute([$requestId]);
    $replayCaseId = $replay->fetchColumn();
    if ($replayCaseId !== false) {
        $case = $pdo->prepare('SELECT public_id FROM moderation_cases WHERE id=?');
        $case->execute([(int)$replayCaseId]);
        return ['publicId' => (string)$case->fetchColumn(), 'idempotentReplay' => true];
    }
    if (!in_array($caseType, ['trusted-review', 'capability-request', 'appeal'], true)) {
        throw new ModerationAccountWorkflowException('The request type is invalid.', 'MODERATION_REQUEST_TYPE_INVALID', 400);
    }
    $authorization = moderation_identity_account_authorization($pdo, $userId);
    if ($caseType !== 'appeal') moderation_trust_require_optional_enabled($pdo, $caseType);
    if ($caseType === 'trusted-review' && $authorization['trustState'] !== 'pending-approval') {
        throw new ModerationAccountWorkflowException('Trusted Review is available only while approval is pending.', 'TRUSTED_REVIEW_NOT_AVAILABLE', 409);
    }
    if ($caseType === 'appeal' && !in_array($authorization['trustState'], ['restricted', 'suspended'], true)) {
        throw new ModerationAccountWorkflowException('There is no active restriction or suspension to appeal.', 'APPEAL_NOT_AVAILABLE', 409);
    }
    $note = moderation_account_bounded_text($input['note'] ?? '', MODERATION_ACCOUNT_NOTE_MAX, 'Private note', false);
    $capabilityIds = [];
    if ($caseType === 'trusted-review') {
        $active = $pdo->prepare(
            "SELECT * FROM moderation_cases
             WHERE subject_user_id=? AND case_type='trusted-review'
               AND status IN ('received','under-review')
             ORDER BY id DESC LIMIT 1"
        );
        $active->execute([$userId]);
        $existingReview = $active->fetch();
        if (is_array($existingReview)) {
            if ($note === '') {
                throw new ModerationAccountWorkflowException(
                    'Add a private note to the active Trusted Review.',
                    'TRUSTED_REVIEW_NOTE_REQUIRED',
                    400
                );
            }
            if (trim((string)($existingReview['private_note'] ?? '')) !== '') {
                throw new ModerationAccountWorkflowException(
                    'The active Trusted Review already has its one private note.',
                    'TRUSTED_REVIEW_NOTE_ALREADY_ADDED',
                    409
                );
            }
            $newRevision = (int)$existingReview['revision'] + 1;
            $pdo->prepare(
                'UPDATE moderation_cases
                 SET private_note=?,revision=?,updated_at=CURRENT_TIMESTAMP
                 WHERE id=? AND revision=?'
            )->execute([$note, $newRevision, (int)$existingReview['id'], (int)$existingReview['revision']]);
            $pdo->prepare(
                "INSERT INTO moderation_case_actions
                 (request_id,case_id,actor_user_id,action,from_revision,to_revision)
                 VALUES (?,?,?,'trusted-review-note-added',?,?)"
            )->execute([
                $requestId, (int)$existingReview['id'], $userId,
                (int)$existingReview['revision'], $newRevision,
            ]);
            log_tool(
                $pdo,
                $userId,
                'moderation_case_note_added',
                $userId,
                null,
                'Type: trusted-review; case: ' . (string)$existingReview['public_id']
            );
            return ['publicId' => (string)$existingReview['public_id'], 'idempotentReplay' => false];
        }
    }
    if ($caseType === 'capability-request') {
        if ($authorization['trustState'] !== 'trusted') {
            throw new ModerationAccountWorkflowException('Trusted account required.', 'ACCOUNT_TRUST_REQUIRED', 403);
        }
        $requested = array_values(array_unique(array_map('strval', (array)($input['capability_ids'] ?? []))));
        foreach ($authorization['capabilities'] as $capability) {
            if (!in_array($capability['id'], $requested, true)) continue;
            if (!$capability['available'] || $capability['effectiveEnabled']) continue;
            $duplicate = $pdo->prepare(
                "SELECT 1 FROM moderation_case_items i
                 JOIN moderation_cases c ON c.id=i.case_id
                 WHERE c.subject_user_id=? AND i.item_type='capability'
                   AND i.item_key=? AND i.status IN ('received','under-review') LIMIT 1"
            );
            $duplicate->execute([$userId, $capability['id']]);
            if ($duplicate->fetchColumn()) {
                throw new ModerationAccountWorkflowException(
                    'One or more selected capabilities already has an active request.',
                    'CAPABILITY_REQUEST_ALREADY_ACTIVE',
                    409,
                    ['capabilityId' => $capability['id']]
                );
            }
            $capabilityIds[] = $capability['id'];
        }
        if (!$capabilityIds) {
            throw new ModerationAccountWorkflowException(
                'Select at least one available capability that is not already enabled.',
                'CAPABILITY_REQUEST_SELECTION_REQUIRED',
                400
            );
        }
    } else {
        moderation_account_assert_case_submission_allowed($pdo, $userId, $caseType);
    }

    $publicId = moderation_account_case_public_id();
    $pdo->prepare(
        'INSERT INTO moderation_cases
         (public_id,subject_user_id,case_type,status,private_note,enforcement_reference,revision)
         VALUES (?,?,?,\'received\',?,?,1)'
    )->execute([
        $publicId, $userId, $caseType, $note !== '' ? $note : null,
        $caseType === 'appeal' ? (string)($input['enforcement_reference'] ?? '') : null,
    ]);
    $caseId = (int)$pdo->lastInsertId();
    foreach ($capabilityIds as $capabilityId) {
        $pdo->prepare(
            "INSERT INTO moderation_case_items (case_id,item_type,item_key,status,revision)
             VALUES (?,'capability',?,'received',1)"
        )->execute([$caseId, $capabilityId]);
    }
    $pdo->prepare(
        "INSERT INTO moderation_case_actions
         (request_id,case_id,actor_user_id,action,from_revision,to_revision)
         VALUES (?,?,?,'submitted',0,1)"
    )->execute([$requestId, $caseId, $userId]);
    log_tool($pdo, $userId, 'moderation_case_submitted', $userId, null, "Type: {$caseType}; case: {$publicId}");
    return ['publicId' => $publicId, 'idempotentReplay' => false];
}

function moderation_account_create_notice(
    PDO $pdo,
    int $userId,
    string $type,
    string $publicReason,
    array $changedCapabilities = [],
    ?string $expiresAt = null
): string {
    $publicReason = moderation_account_bounded_text(
        $publicReason,
        MODERATION_ACCOUNT_PUBLIC_REASON_MAX,
        'Public reason',
        false
    );
    $publicId = moderation_account_notice_public_id();
    $pdo->prepare(
        'INSERT INTO moderation_notices
         (public_id,user_id,notice_type,public_reason,changed_capabilities_json,expires_at)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $publicId, $userId, $type, $publicReason !== '' ? $publicReason : null,
        json_encode(array_values($changedCapabilities), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        $expiresAt,
    ]);
    return $publicId;
}

function moderation_account_staff_cases(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT c.id,c.public_id,c.subject_user_id,u.username,u.display_name,c.case_type,
                c.status,c.public_reason,c.private_note,c.revision,c.created_at,c.updated_at
         FROM moderation_cases c JOIN users u ON u.id=c.subject_user_id
         WHERE c.status IN ('received','under-review')
         ORDER BY c.created_at,c.id"
    )->fetchAll();
    foreach ($rows as &$row) {
        $items = $pdo->prepare(
            'SELECT item_type,item_key,status,public_reason,revision
             FROM moderation_case_items WHERE case_id=? ORDER BY item_type,item_key'
        );
        $items->execute([(int)$row['id']]);
        $row['items'] = $items->fetchAll();
        $row['revision'] = (int)$row['revision'];
        unset($row['id']);
    }
    unset($row);
    return $rows;
}

function moderation_account_decide_case(PDO $pdo, int $actorUserId, array $input): array
{
    $requestId = moderation_account_validate_request_id($input['request_id'] ?? null);
    $replay = $pdo->prepare('SELECT case_id,to_revision FROM moderation_case_actions WHERE request_id=?');
    $replay->execute([$requestId]);
    $existing = $replay->fetch();
    if (is_array($existing)) return ['revision' => (int)$existing['to_revision'], 'idempotentReplay' => true];
    $publicId = trim((string)($input['case_public_id'] ?? ''));
    $expectedRevision = filter_var($input['expected_revision'] ?? null, FILTER_VALIDATE_INT);
    $status = strtolower(trim((string)($input['status'] ?? '')));
    if ($expectedRevision === false || $expectedRevision < 1) {
        throw new ModerationAccountWorkflowException('The current case revision is required.', 'MODERATION_CASE_REVISION_REQUIRED', 400);
    }
    if (!in_array($status, ['under-review', 'approved', 'denied', 'modified', 'closed'], true)) {
        throw new ModerationAccountWorkflowException('The case decision status is invalid.', 'MODERATION_CASE_STATUS_INVALID', 400);
    }
    $publicReason = moderation_account_bounded_text($input['public_reason'] ?? '', MODERATION_ACCOUNT_PUBLIC_REASON_MAX, 'Public reason', false);
    $internalNote = moderation_account_bounded_text($input['internal_note'] ?? '', MODERATION_ACCOUNT_NOTE_MAX, 'Internal note', false);
    $caseStatement = $pdo->prepare('SELECT * FROM moderation_cases WHERE public_id=? LIMIT 1');
    $caseStatement->execute([$publicId]);
    $case = $caseStatement->fetch();
    if (!is_array($case)) throw new ModerationAccountWorkflowException('The case was not found.', 'MODERATION_CASE_NOT_FOUND', 404);
    if ((int)$case['revision'] !== (int)$expectedRevision) {
        throw new ModerationAccountWorkflowException(
            'The case changed. Refresh before deciding.',
            'MODERATION_CASE_STALE',
            409,
            ['currentRevision' => (int)$case['revision']]
        );
    }
    if (in_array((string)$case['case_type'], ['trusted-review', 'capability-request'], true)) {
        moderation_trust_require_optional_enabled($pdo, (string)$case['case_type']);
    }
    $newRevision = (int)$case['revision'] + 1;
    $closedAtSql = in_array($status, ['approved', 'denied', 'closed'], true) ? 'CURRENT_TIMESTAMP' : 'NULL';
    $update = $pdo->prepare(
        "UPDATE moderation_cases
         SET status=?,public_reason=?,revision=?,updated_at=CURRENT_TIMESTAMP,closed_at={$closedAtSql}
         WHERE id=? AND revision=?"
    );
    $update->execute([$status, $publicReason !== '' ? $publicReason : null, $newRevision, (int)$case['id'], (int)$case['revision']]);
    if ($update->rowCount() !== 1) throw new ModerationAccountWorkflowException('The case changed. Refresh before deciding.', 'MODERATION_CASE_STALE', 409);

    $changedCapabilities = [];
    if ((string)$case['case_type'] === 'trusted-review' && $status === 'approved') {
        moderation_identity_insert_trust($pdo, (int)$case['subject_user_id'], 'trusted');
    }
    if ((string)$case['case_type'] === 'capability-request') {
        $decisions = (array)($input['item_decisions'] ?? []);
        $items = $pdo->prepare('SELECT * FROM moderation_case_items WHERE case_id=? ORDER BY id');
        $items->execute([(int)$case['id']]);
        foreach ($items->fetchAll() as $item) {
            $itemStatus = strtolower((string)($decisions[(string)$item['item_key']] ?? ''));
            if (!in_array($itemStatus, ['approved', 'denied', 'modified', 'closed'], true)) continue;
            $pdo->prepare(
                'UPDATE moderation_case_items
                 SET status=?,public_reason=?,revision=revision+1 WHERE id=?'
            )->execute([$itemStatus, $publicReason !== '' ? $publicReason : null, (int)$item['id']]);
            if ($itemStatus === 'approved') {
                moderation_trust_require_capability_available($pdo, (string)$item['item_key']);
                $sql = db_uses_mysql_syntax($pdo)
                    ? 'INSERT INTO user_capability_grants (user_id,capability_id,enabled,revision,granted_by_user_id) VALUES (?,?,1,1,?) ON DUPLICATE KEY UPDATE enabled=1,revision=revision+1,granted_by_user_id=VALUES(granted_by_user_id)'
                    : 'INSERT INTO user_capability_grants (user_id,capability_id,enabled,revision,granted_by_user_id) VALUES (?,?,1,1,?) ON CONFLICT(user_id,capability_id) DO UPDATE SET enabled=1,revision=revision+1,granted_by_user_id=excluded.granted_by_user_id';
                $pdo->prepare($sql)->execute([(int)$case['subject_user_id'], (string)$item['item_key'], $actorUserId]);
                $changedCapabilities[] = (string)$item['item_key'];
            }
        }
    }
    $pdo->prepare(
        'INSERT INTO moderation_case_actions
         (request_id,case_id,actor_user_id,action,from_revision,to_revision,public_reason,internal_note)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $requestId, (int)$case['id'], $actorUserId, 'decision-' . $status,
        (int)$case['revision'], $newRevision,
        $publicReason !== '' ? $publicReason : null,
        $internalNote !== '' ? $internalNote : null,
    ]);
    moderation_account_create_notice(
        $pdo,
        (int)$case['subject_user_id'],
        (string)$case['case_type'] . '-' . $status,
        $publicReason,
        $changedCapabilities,
        null
    );
    log_tool(
        $pdo,
        $actorUserId,
        'moderation_case_decided',
        (int)$case['subject_user_id'],
        null,
        "Case: {$publicId}; status: {$status}; revision: {$newRevision}"
    );
    return ['revision' => $newRevision, 'idempotentReplay' => false, 'changedCapabilities' => $changedCapabilities];
}

function moderation_account_outside_confirmation_text(string $operation): array
{
    $website = str_contains($operation, 'room_import');
    return $website ? [
        'title' => 'Content permission and safety confirmation',
        'body' => "By continuing, you confirm that you own this website content or have permission from the rights holder to copy and store it on this Chatspace server. You also confirm that the website and its assets do not contain unlawful, sexually explicit, exploitative, abusive, or otherwise prohibited material, and that importing them will not violate copyright, privacy, or other people's rights.",
        'checkbox' => 'I confirm that I am authorized to import and store this content and that it complies with the community rules.',
    ] : [
        'title' => 'Upload confirmation',
        'body' => "By uploading this file, you confirm that you own it or have permission to use and store it on this server. You also confirm that it does not contain unlawful, sexually explicit, exploitative, abusive, or otherwise prohibited content and does not violate copyright, privacy, or other people's rights.",
        'checkbox' => 'I confirm that I am permitted to upload this content and that it complies with the community rules.',
    ];
}

function moderation_account_channel_notice_text(string $channel): string
{
    return match ($channel) {
        'private-message' => 'Private messages remain subject to the Terms and Community Rules. Do not send unlawful, abusive, exploitative, or unauthorized content.',
        'game-chat' => 'Game messages are visible to the participating players and remain subject to the Terms and Community Rules.',
        default => '',
    };
}

function moderation_account_outside_policy(PDO $pdo, string $operation, array $context = []): array
{
    $stored = app_setting($pdo, MODERATION_ACCOUNT_OUTSIDE_MODE_SETTING, moderation_account_outside_mode_default($pdo));
    if (!in_array($stored, MODERATION_ACCOUNT_OUTSIDE_MODES, true)) $stored = 'every-upload-import';
    $master = moderation_trust_policy($pdo);
    $effective = !empty($master['effectiveEnabled']) ? $stored : 'disabled';
    $publicOperations = [
        'avatar_upload', 'room_background_upload', 'room_import_preview',
        'room_import_create', 'admin_link_icon_upload', 'admin_branding',
    ];
    $becomesPublic = !empty($context['becomes_public']) || in_array($operation, $publicOperations, true);
    $required = $effective === 'every-upload-import' || ($effective === 'public-only' && $becomesPublic);
    return [
        'storedMode' => $stored,
        'effectiveMode' => $effective,
        'required' => $required,
        'reminder' => $effective === 'reminder',
        'becomesPublic' => $becomesPublic,
        'termsAndRulesRemainMandatory' => true,
        'safetyAndModerationRemainMandatory' => true,
        'text' => moderation_account_outside_confirmation_text($operation),
        'termsUrl' => app_url('/policy.php?document=terms'),
        'rulesUrl' => app_url('/policy.php?document=community-rules'),
    ];
}

function moderation_account_authorize_outside_content(
    PDO $pdo,
    int $actorUserId,
    string $operation,
    array $context = []
): array {
    $policy = moderation_account_outside_policy($pdo, $operation, $context);
    if (!$policy['required']) return $policy;
    $confirmed = !empty($context['outside_content_confirmed'])
        || (string)($_SERVER['HTTP_X_OUTSIDE_CONTENT_CONFIRMED'] ?? '') === '1'
        || (string)($_POST['outside_content_confirmed'] ?? '') === '1';
    $confirmationId = trim((string)(
        $context['outside_content_confirmation_id']
        ?? $_SERVER['HTTP_X_OUTSIDE_CONTENT_CONFIRMATION_ID']
        ?? $_POST['outside_content_confirmation_id']
        ?? ''
    ));
    if (!$confirmed || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $confirmationId)) {
        throw new ModerationAccountWorkflowException(
            'Review and confirm the outside-content permission and safety statement.',
            'OUTSIDE_CONTENT_CONFIRMATION_REQUIRED',
            428,
            ['confirmation' => $policy]
        );
    }
    $token = strtoupper(hash('sha256', implode('|', [
        $confirmationId, (string)$actorUserId, $operation, (string)$policy['effectiveMode'],
    ])));
    $existing = $pdo->prepare(
        'SELECT token_sha256,created_at FROM outside_content_confirmations
         WHERE confirmation_id=? AND actor_user_id=? AND operation=?'
    );
    $existing->execute([$confirmationId, $actorUserId, $operation]);
    $row = $existing->fetch();
    if (is_array($row)) {
        if (!hash_equals((string)$row['token_sha256'], $token)
            || strtotime((string)$row['created_at']) < time() - 900) {
            throw new ModerationAccountWorkflowException(
                'The outside-content confirmation is stale or does not match this transaction.',
                'OUTSIDE_CONTENT_CONFIRMATION_STALE',
                409
            );
        }
        return $policy + ['confirmationId' => $confirmationId, 'idempotentReplay' => true];
    }
    $pdo->prepare(
        'INSERT INTO outside_content_confirmations
         (confirmation_id,actor_user_id,operation,mode,token_sha256)
         VALUES (?,?,?,?,?)'
    )->execute([$confirmationId, $actorUserId, $operation, $policy['effectiveMode'], $token]);
    return $policy + ['confirmationId' => $confirmationId, 'idempotentReplay' => false];
}

function moderation_account_session_authorization(PDO $pdo, int $userId): array
{
    $authorization = moderation_identity_account_authorization($pdo, $userId);
    $suspended = $authorization['trustState'] === 'suspended';
    return $authorization + [
        'ordinaryAccessAllowed' => !$suspended,
        'accountAppealAccessAllowed' => true,
        'roleBypassAllowed' => false,
    ];
}
