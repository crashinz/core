<?php
declare(strict_types=1);

const PRIVATE_VOICE_ENABLED_SETTING = 'private_voice_chats_enabled';
const PRIVATE_VOICE_PARTICIPANT_LIMIT_SETTING = 'private_voice_participant_limit';
const VOICE_TRANSMISSION_MODES_ENABLED_SETTING = 'voice_transmission_modes_enabled';
const SELECTIVE_WEBCAM_AUDIENCE_ENABLED_SETTING = 'selective_webcam_audience_enabled';
const PRIVATE_VOICE_RECOMMENDED_PARTICIPANTS = 4;
const PRIVATE_VOICE_SUPPORTED_CEILING = 4;
const PRIVATE_VOICE_INVITATION_EXPIRY_SECONDS = 180;
const PRIVATE_VOICE_REQUEST_EXPIRY_SECONDS = 180;
const VOICE_TRANSMISSION_MODES = ['voice-activation', 'push-to-talk', 'push-to-mute'];
const WEBCAM_AUDIENCE_MODES = ['everyone', 'private-voice', 'selected', 'nobody'];

final class PrivateVoiceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'PRIVATE_VOICE_OPERATION_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function optional_core_voice_webcam_setting_defaults(): array
{
    return [
        PRIVATE_VOICE_ENABLED_SETTING => '0',
        PRIVATE_VOICE_PARTICIPANT_LIMIT_SETTING => (string)PRIVATE_VOICE_RECOMMENDED_PARTICIPANTS,
        VOICE_TRANSMISSION_MODES_ENABLED_SETTING => '0',
        SELECTIVE_WEBCAM_AUDIENCE_ENABLED_SETTING => '0',
    ];
}

function optional_core_voice_webcam_schema_statements(PDO $pdo): array
{
    $auto = db_uses_mysql_syntax($pdo) ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = db_uses_mysql_syntax($pdo) ? 'VARCHAR(191)' : 'TEXT';
    $short = db_uses_mysql_syntax($pdo) ? 'VARCHAR(32)' : 'TEXT';
    $stamp = db_uses_mysql_syntax($pdo) ? 'DATETIME' : 'TEXT';
    $engine = db_uses_mysql_syntax($pdo) ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS private_voice_chats (
            id {$auto}, public_id {$text} NOT NULL UNIQUE, session_id BIGINT NOT NULL,
            created_by_user_id BIGINT NOT NULL, status {$short} NOT NULL DEFAULT 'active',
            participant_limit INTEGER NOT NULL DEFAULT 4, version INTEGER NOT NULL DEFAULT 1,
            created_at {$stamp} NOT NULL, updated_at {$stamp} NOT NULL, ended_at {$stamp} DEFAULT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS private_voice_members (
            id {$auto}, chat_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
            membership_status {$short} NOT NULL DEFAULT 'active', joined_at {$stamp} NOT NULL,
            ended_at {$stamp} DEFAULT NULL, UNIQUE(chat_id, user_id)
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS private_voice_invitations (
            id {$auto}, public_id {$text} NOT NULL UNIQUE, chat_id BIGINT NOT NULL,
            inviter_user_id BIGINT NOT NULL, recipient_user_id BIGINT NOT NULL,
            status {$short} NOT NULL DEFAULT 'pending', request_id {$text} NOT NULL,
            created_at {$stamp} NOT NULL, expires_at {$stamp} NOT NULL,
            resolved_at {$stamp} DEFAULT NULL, resolution_user_id BIGINT DEFAULT NULL,
            UNIQUE(inviter_user_id, request_id)
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS private_voice_join_requests (
            id {$auto}, public_id {$text} NOT NULL UNIQUE, chat_id BIGINT NOT NULL,
            requester_user_id BIGINT NOT NULL, member_set_hash VARCHAR(64) NOT NULL,
            status {$short} NOT NULL DEFAULT 'pending', request_id {$text} NOT NULL,
            created_at {$stamp} NOT NULL, expires_at {$stamp} NOT NULL,
            resolved_at {$stamp} DEFAULT NULL, resolution_user_id BIGINT DEFAULT NULL,
            UNIQUE(requester_user_id, request_id)
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS voice_webcam_preferences (
            user_id BIGINT PRIMARY KEY, transmission_mode {$short} NOT NULL DEFAULT 'voice-activation',
            always_muted_on_join INTEGER NOT NULL DEFAULT 0,
            webcam_audience_mode {$short} NOT NULL DEFAULT 'everyone',
            version INTEGER NOT NULL DEFAULT 1, updated_at {$stamp} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS webcam_audience_sessions (
            sender_participant_id BIGINT PRIMARY KEY, session_id BIGINT NOT NULL,
            sender_user_id BIGINT NOT NULL, client_epoch VARCHAR(96) NOT NULL,
            context_type {$short} NOT NULL DEFAULT 'room', context_public_id {$text} DEFAULT NULL,
            audience_mode {$short} NOT NULL DEFAULT 'everyone', revision INTEGER NOT NULL DEFAULT 1,
            confirmed_at {$stamp} DEFAULT NULL, updated_at {$stamp} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS webcam_audience_recipients (
            sender_participant_id BIGINT NOT NULL, recipient_user_id BIGINT NOT NULL,
            audience_revision INTEGER NOT NULL, created_at {$stamp} NOT NULL,
            PRIMARY KEY(sender_participant_id, recipient_user_id)
        ){$engine}",
    ];
}

function optional_core_voice_webcam_install_schema(PDO $pdo): void
{
    foreach (optional_core_voice_webcam_schema_statements($pdo) as $statement) $pdo->exec($statement);
    $scopeColumns = [
        'context_type' => db_uses_mysql_syntax($pdo)
            ? "VARCHAR(32) NOT NULL DEFAULT 'room'"
            : "TEXT NOT NULL DEFAULT 'room'",
        'context_public_id' => db_uses_mysql_syntax($pdo) ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT DEFAULT NULL',
    ];
    foreach (['voice_sessions', 'media_signals'] as $table) {
        $columns = [];
        if (db_uses_mysql_syntax($pdo)) {
            foreach ($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $column) {
                $columns[(string)$column['Field']] = true;
            }
        } else {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $column) {
                $columns[(string)$column['name']] = true;
            }
        }
        foreach ($scopeColumns as $name => $definition) {
            if (!isset($columns[$name])) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
        }
    }
    foreach ([
        ['private_voice_chats', 'idx_private_voice_chats_session_status', false, 'session_id, status, id'],
        ['private_voice_members', 'idx_private_voice_members_user_status', false, 'user_id, membership_status, chat_id'],
        ['private_voice_invitations', 'idx_private_voice_invitation_retry', false, 'chat_id, inviter_user_id, recipient_user_id, created_at'],
        ['private_voice_invitations', 'idx_private_voice_invitation_recipient', false, 'recipient_user_id, status, expires_at'],
        ['private_voice_join_requests', 'idx_private_voice_request_retry', false, 'chat_id, requester_user_id, member_set_hash, created_at'],
        ['private_voice_join_requests', 'idx_private_voice_request_status', false, 'chat_id, status, expires_at'],
        ['webcam_audience_sessions', 'idx_webcam_audience_context', false, 'session_id, sender_user_id, client_epoch'],
        ['voice_sessions', 'idx_voice_sessions_context', false, 'session_id, context_type, context_public_id, joined_at'],
        ['media_signals', 'idx_media_signals_context_delivery', false, 'session_id, context_type, context_public_id, to_participant_id, id'],
    ] as [$table, $name, $unique, $columns]) {
        try {
            if (db_uses_mysql_syntax($pdo)) {
                $check = $pdo->prepare("SHOW INDEX FROM {$table} WHERE Key_name = ?");
                $check->execute([$name]);
                if ($check->fetch()) continue;
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$name} ON {$table}({$columns})");
            } else {
                $pdo->exec('CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$name} ON {$table}({$columns})");
            }
        } catch (Throwable $error) {
            if (!database_migration_index_exists($pdo, $table, $name)) throw $error;
        }
    }
}

function optional_core_voice_webcam_schema_valid(PDO $pdo): bool
{
    foreach ([
        'private_voice_chats', 'private_voice_members', 'private_voice_invitations',
        'private_voice_join_requests', 'voice_webcam_preferences',
        'webcam_audience_sessions', 'webcam_audience_recipients',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    foreach (['voice_sessions', 'media_signals'] as $table) {
        $columns = [];
        if (db_uses_mysql_syntax($pdo)) {
            foreach ($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $column) $columns[] = (string)$column['Field'];
        } else {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $column) $columns[] = (string)$column['name'];
        }
        if (!in_array('context_type', $columns, true) || !in_array('context_public_id', $columns, true)) return false;
    }
    return true;
}

function optional_core_voice_webcam_policy(PDO $pdo): array
{
    $selectedLimit = (int)app_setting(
        $pdo,
        PRIVATE_VOICE_PARTICIPANT_LIMIT_SETTING,
        (string)PRIVATE_VOICE_RECOMMENDED_PARTICIPANTS
    );
    $selectedLimit = max(2, min(PRIVATE_VOICE_SUPPORTED_CEILING, $selectedLimit));
    return [
        'privateVoice' => [
            'enabled' => app_setting($pdo, PRIVATE_VOICE_ENABLED_SETTING, '0') === '1',
            'participantLimit' => $selectedLimit,
            'recommendedParticipants' => PRIVATE_VOICE_RECOMMENDED_PARTICIPANTS,
            'supportedCeiling' => PRIVATE_VOICE_SUPPORTED_CEILING,
            'expirySeconds' => PRIVATE_VOICE_INVITATION_EXPIRY_SECONDS,
        ],
        'transmissionModes' => [
            'enabled' => app_setting($pdo, VOICE_TRANSMISSION_MODES_ENABLED_SETTING, '0') === '1',
            'availableModes' => VOICE_TRANSMISSION_MODES,
            'defaultMode' => 'voice-activation',
            'bindingDefault' => 'unassigned',
        ],
        'selectiveWebcamAudience' => [
            'enabled' => app_setting($pdo, SELECTIVE_WEBCAM_AUDIENCE_ENABLED_SETTING, '0') === '1',
            'availableModes' => WEBCAM_AUDIENCE_MODES,
            'defaultMode' => 'everyone',
        ],
    ];
}

function optional_core_voice_webcam_reconcile_policy_change_locked(PDO $pdo, array $changedIds, array $target): int
{
    $stopped = 0;
    if (in_array(PRIVATE_VOICE_ENABLED_SETTING, $changedIds, true)
        && empty($target[PRIVATE_VOICE_ENABLED_SETTING])) {
        $now = private_voice_now();
        $stopped = (int)$pdo->query("SELECT COUNT(*) FROM private_voice_chats WHERE status = 'active'")->fetchColumn();
        $pdo->prepare("UPDATE private_voice_invitations SET status = 'revoked', resolved_at = ? WHERE status = 'pending'")
            ->execute([$now]);
        $pdo->prepare("UPDATE private_voice_join_requests SET status = 'dismissed', resolved_at = ? WHERE status = 'pending'")
            ->execute([$now]);
        $pdo->prepare("UPDATE private_voice_members SET membership_status = 'ended', ended_at = ? WHERE membership_status = 'active'")
            ->execute([$now]);
        $pdo->prepare("UPDATE private_voice_chats SET status = 'ended', ended_at = ?, updated_at = ?, version = version + 1 WHERE status = 'active'")
            ->execute([$now, $now]);
        $pdo->exec("DELETE FROM voice_sessions WHERE context_type = 'private-voice'");
    }
    if (in_array(SELECTIVE_WEBCAM_AUDIENCE_ENABLED_SETTING, $changedIds, true)
        && empty($target[SELECTIVE_WEBCAM_AUDIENCE_ENABLED_SETTING])) {
        $stopped += (int)$pdo->query('SELECT COUNT(*) FROM webcam_audience_sessions')->fetchColumn();
        $pdo->exec('DELETE FROM webcam_audience_recipients');
        $pdo->exec('DELETE FROM webcam_audience_sessions');
    }
    return $stopped;
}

function private_voice_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function private_voice_expiry(int $seconds = PRIVATE_VOICE_INVITATION_EXPIRY_SECONDS): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

function private_voice_request_id(mixed $value): string
{
    $requestId = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/', $requestId)) {
        throw new PrivateVoiceException('A valid request identifier is required.', 'PRIVATE_VOICE_REQUEST_ID_INVALID', 400);
    }
    return $requestId;
}

function private_voice_transaction(PDO $pdo, callable $operation): mixed
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        $result = $operation();
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function private_voice_participant(PDO $pdo, int $sessionId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id, p.session_id, p.user_id, p.display_name, p.avatar_path, p.last_seen_at,
                rs.room_id, u.role
           FROM participants p
           JOIN room_sessions rs ON rs.id = p.session_id
           JOIN users u ON u.id = p.user_id
          WHERE p.session_id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId, $userId]);
    $participant = $stmt->fetch() ?: null;
    if (!$participant || empty($participant['last_seen_at'])) return null;
    if (active_room_ejection($pdo, (int)$participant['room_id'], $userId)
        || active_community_ejection($pdo, $userId)) return null;
    return $participant;
}

function private_voice_users_blocked(PDO $pdo, int $leftUserId, int $rightUserId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks
          WHERE (blocker_user_id = ? AND blocked_user_id = ?)
             OR (blocker_user_id = ? AND blocked_user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([$leftUserId, $rightUserId, $rightUserId, $leftUserId]);
    return (bool)$stmt->fetchColumn();
}

function private_voice_require_enabled(PDO $pdo): array
{
    $policy = optional_core_voice_webcam_policy($pdo)['privateVoice'];
    if (!$policy['enabled']) {
        throw new PrivateVoiceException(
            'Private Voice Chats are disabled for this installation.',
            'PRIVATE_VOICE_DISABLED',
            403,
            ['policy' => $policy]
        );
    }
    return $policy;
}

function private_voice_expire_pending(PDO $pdo): void
{
    $now = private_voice_now();
    $pdo->prepare("UPDATE private_voice_invitations SET status = 'expired', resolved_at = ? WHERE status = 'pending' AND expires_at <= ?")
        ->execute([$now, $now]);
    $pdo->prepare("UPDATE private_voice_join_requests SET status = 'expired', resolved_at = ? WHERE status = 'pending' AND expires_at <= ?")
        ->execute([$now, $now]);
}

function private_voice_active_chat_for_user(PDO $pdo, int $sessionId, int $userId, bool $lock = false): ?array
{
    $sql = "SELECT pvc.*
              FROM private_voice_chats pvc
              JOIN private_voice_members pvm ON pvm.chat_id = pvc.id
             WHERE pvc.session_id = ? AND pvc.status = 'active'
               AND pvm.user_id = ? AND pvm.membership_status = 'active'
             ORDER BY pvm.joined_at DESC, pvc.id DESC LIMIT 1";
    if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $userId]);
    return $stmt->fetch() ?: null;
}

function private_voice_chat_by_public_id(PDO $pdo, int $sessionId, string $publicId, bool $lock = false): ?array
{
    $sql = "SELECT * FROM private_voice_chats WHERE session_id = ? AND public_id = ? AND status = 'active' LIMIT 1";
    if ($lock && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $publicId]);
    return $stmt->fetch() ?: null;
}

function private_voice_members(PDO $pdo, int $chatId): array
{
    $stmt = $pdo->prepare(
        "SELECT pvm.user_id, pvm.joined_at, u.username, u.display_name, u.avatar_path,
                p.id AS participant_id, p.last_seen_at
           FROM private_voice_members pvm
           JOIN users u ON u.id = pvm.user_id
           LEFT JOIN private_voice_chats pvc ON pvc.id = pvm.chat_id
           LEFT JOIN participants p ON p.session_id = pvc.session_id AND p.user_id = pvm.user_id
          WHERE pvm.chat_id = ? AND pvm.membership_status = 'active'
          ORDER BY pvm.joined_at ASC, pvm.id ASC"
    );
    $stmt->execute([$chatId]);
    return array_map(static fn(array $row): array => [
        'userId' => (int)$row['user_id'],
        'participantId' => $row['participant_id'] !== null ? (int)$row['participant_id'] : null,
        'displayName' => (string)($row['display_name'] ?: $row['username']),
        'avatarUrl' => resolve_avatar($row['avatar_path'] ?? null),
        'joinedAt' => (string)$row['joined_at'],
        'available' => !empty($row['last_seen_at']),
    ], $stmt->fetchAll());
}

function private_voice_member_set_hash(array $members): string
{
    $ids = array_values(array_unique(array_map(static fn(array $member): int => (int)$member['userId'], $members)));
    sort($ids, SORT_NUMERIC);
    return strtoupper(hash('sha256', implode(':', $ids)));
}

function private_voice_is_member(PDO $pdo, int $chatId, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM private_voice_members WHERE chat_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1");
    $stmt->execute([$chatId, $userId]);
    return (bool)$stmt->fetchColumn();
}

function private_voice_media_context(PDO $pdo, int $sessionId, int $userId, string $publicId): array
{
    private_voice_require_enabled($pdo);
    $chat = private_voice_chat_by_public_id($pdo, $sessionId, $publicId);
    if (!$chat || !private_voice_is_member($pdo, (int)$chat['id'], $userId)) {
        throw new PrivateVoiceException(
            'Private voice membership is required.',
            'PRIVATE_VOICE_MEMBERSHIP_REQUIRED',
            403
        );
    }
    return ['type' => 'private-voice', 'publicId' => (string)$chat['public_id']];
}

function private_voice_end_user_memberships(PDO $pdo, int $sessionId, int $userId, string $reason): array
{
    $active = private_voice_active_chat_for_user($pdo, $sessionId, $userId, true);
    if (!$active) return [];
    $now = private_voice_now();
    $pdo->prepare("UPDATE private_voice_members SET membership_status = 'ended', ended_at = ? WHERE chat_id = ? AND user_id = ? AND membership_status = 'active'")
        ->execute([$now, (int)$active['id'], $userId]);
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM private_voice_members WHERE chat_id = ? AND membership_status = 'active'");
    $remaining->execute([(int)$active['id']]);
    if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare("UPDATE private_voice_chats SET status = 'ended', ended_at = ?, updated_at = ?, version = version + 1 WHERE id = ?")
            ->execute([$now, $now, (int)$active['id']]);
    } else {
        $pdo->prepare('UPDATE private_voice_chats SET updated_at = ?, version = version + 1 WHERE id = ?')
            ->execute([$now, (int)$active['id']]);
    }
    $pdo->prepare("UPDATE private_voice_invitations SET status = 'revoked', resolved_at = ? WHERE chat_id = ? AND status = 'pending' AND (inviter_user_id = ? OR recipient_user_id = ?)")
        ->execute([$now, (int)$active['id'], $userId, $userId]);
    $pdo->prepare(
        "DELETE FROM voice_sessions
          WHERE session_id = ? AND context_type = 'private-voice'
            AND participant_id IN (SELECT id FROM participants WHERE session_id = ? AND user_id = ?)"
    )->execute([$sessionId, $sessionId, $userId]);
    return ['chatId' => (string)$active['public_id'], 'reason' => $reason];
}

function private_voice_add_member(PDO $pdo, array $chat, int $userId): void
{
    $members = private_voice_members($pdo, (int)$chat['id']);
    if (count($members) >= (int)$chat['participant_limit']) {
        throw new PrivateVoiceException('This private voice chat is full.', 'PRIVATE_VOICE_CHAT_FULL', 409);
    }
    foreach ($members as $member) {
        if (private_voice_users_blocked($pdo, $userId, (int)$member['userId'])) {
            throw new PrivateVoiceException('Private voice membership is not available.', 'PRIVATE_VOICE_ELIGIBILITY_DENIED', 403);
        }
    }
    $now = private_voice_now();
    if (db_uses_mysql_syntax($pdo)) {
        $pdo->prepare(
            "INSERT INTO private_voice_members (chat_id,user_id,membership_status,joined_at,ended_at)
             VALUES (?,?,'active',?,NULL)
             ON DUPLICATE KEY UPDATE membership_status='active', joined_at=VALUES(joined_at), ended_at=NULL"
        )->execute([(int)$chat['id'], $userId, $now]);
    } else {
        $pdo->prepare(
            "INSERT INTO private_voice_members (chat_id,user_id,membership_status,joined_at,ended_at)
             VALUES (?,?,'active',?,NULL)
             ON CONFLICT(chat_id,user_id) DO UPDATE SET membership_status='active', joined_at=excluded.joined_at, ended_at=NULL"
        )->execute([(int)$chat['id'], $userId, $now]);
    }
    $pdo->prepare('UPDATE private_voice_chats SET version = version + 1, updated_at = ? WHERE id = ?')
        ->execute([$now, (int)$chat['id']]);
}

function private_voice_chat_payload(PDO $pdo, array $chat, int $viewerUserId): array
{
    $members = private_voice_members($pdo, (int)$chat['id']);
    $authorized = private_voice_is_member($pdo, (int)$chat['id'], $viewerUserId);
    if (!$authorized) {
        foreach ($members as $member) {
            if (private_voice_users_blocked($pdo, $viewerUserId, (int)$member['userId'])) {
                throw new PrivateVoiceException('Private voice membership is not available.', 'PRIVATE_VOICE_ELIGIBILITY_DENIED', 403);
            }
        }
    }
    return [
        'id' => (string)$chat['public_id'],
        'version' => (int)$chat['version'],
        'status' => (string)$chat['status'],
        'participantLimit' => (int)$chat['participant_limit'],
        'memberCount' => count($members),
        'members' => $members,
        'viewerIsMember' => $authorized,
    ];
}

function private_voice_create_chat(PDO $pdo, int $sessionId, int $userId, string $requestId): array
{
    $policy = private_voice_require_enabled($pdo);
    if (!private_voice_participant($pdo, $sessionId, $userId)) {
        throw new PrivateVoiceException('Private voice is not available in this room.', 'PRIVATE_VOICE_ELIGIBILITY_DENIED', 403);
    }
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $userId, $requestId, $policy): array {
        $existingIdempotent = $pdo->prepare('SELECT pvc.* FROM private_voice_chats pvc WHERE pvc.session_id = ? AND pvc.created_by_user_id = ? AND pvc.public_id = ? LIMIT 1');
        $idempotentPublicId = 'pv-' . substr(hash('sha256', $sessionId . ':' . $userId . ':' . $requestId), 0, 32);
        $existingIdempotent->execute([$sessionId, $userId, $idempotentPublicId]);
        if ($row = $existingIdempotent->fetch()) return private_voice_chat_payload($pdo, $row, $userId);
        private_voice_end_user_memberships($pdo, $sessionId, $userId, 'switch-create');
        $now = private_voice_now();
        $pdo->prepare(
            "INSERT INTO private_voice_chats
                (public_id,session_id,created_by_user_id,status,participant_limit,version,created_at,updated_at)
             VALUES (?,?,?,'active',?,1,?,?)"
        )->execute([$idempotentPublicId, $sessionId, $userId, (int)$policy['participantLimit'], $now, $now]);
        $chatId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO private_voice_members (chat_id,user_id,membership_status,joined_at) VALUES (?,?,'active',?)")
            ->execute([$chatId, $userId, $now]);
        $chat = private_voice_chat_by_public_id($pdo, $sessionId, $idempotentPublicId, true);
        if (!$chat) throw new PrivateVoiceException('Private voice chat could not be created.', 'PRIVATE_VOICE_CREATE_FAILED', 500);
        return private_voice_chat_payload($pdo, $chat, $userId);
    });
}

function private_voice_create_invitation(PDO $pdo, int $sessionId, int $inviterUserId, int $recipientUserId, string $chatPublicId, string $requestId): array
{
    private_voice_require_enabled($pdo);
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $inviterUserId, $recipientUserId, $chatPublicId, $requestId): array {
        private_voice_expire_pending($pdo);
        $chat = private_voice_chat_by_public_id($pdo, $sessionId, $chatPublicId, true);
        if (!$chat || !private_voice_is_member($pdo, (int)$chat['id'], $inviterUserId)) {
            throw new PrivateVoiceException('Only a current member may invite someone.', 'PRIVATE_VOICE_MEMBER_REQUIRED', 403);
        }
        if ($recipientUserId === $inviterUserId || !private_voice_participant($pdo, $sessionId, $recipientUserId)) {
            throw new PrivateVoiceException('The selected recipient is not eligible.', 'PRIVATE_VOICE_RECIPIENT_INELIGIBLE', 409);
        }
        if (private_voice_is_member($pdo, (int)$chat['id'], $recipientUserId)) {
            throw new PrivateVoiceException('That person is already in the private voice chat.', 'PRIVATE_VOICE_ALREADY_MEMBER', 409);
        }
        foreach (private_voice_members($pdo, (int)$chat['id']) as $member) {
            if (private_voice_users_blocked($pdo, $recipientUserId, (int)$member['userId'])) {
                throw new PrivateVoiceException('The selected recipient is not eligible.', 'PRIVATE_VOICE_RECIPIENT_INELIGIBLE', 403);
            }
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - PRIVATE_VOICE_INVITATION_EXPIRY_SECONDS);
        $retry = $pdo->prepare(
            'SELECT public_id,status,created_at,expires_at FROM private_voice_invitations
              WHERE chat_id = ? AND inviter_user_id = ? AND recipient_user_id = ? AND created_at > ?
              ORDER BY created_at DESC LIMIT 1'
        );
        $retry->execute([(int)$chat['id'], $inviterUserId, $recipientUserId, $cutoff]);
        if ($current = $retry->fetch()) {
            throw new PrivateVoiceException(
                'Wait until the original 180-second invitation window ends before inviting this person again.',
                'PRIVATE_VOICE_INVITATION_RETRY_BLOCKED',
                429,
                ['invitationId' => $current['public_id'], 'status' => $current['status'], 'retryAt' => $current['expires_at']]
            );
        }
        $now = private_voice_now();
        $expires = private_voice_expiry();
        $publicId = 'pvi-' . substr(hash('sha256', $requestId . ':' . random_bytes(16)), 0, 32);
        $pdo->prepare(
            "INSERT INTO private_voice_invitations
                (public_id,chat_id,inviter_user_id,recipient_user_id,status,request_id,created_at,expires_at)
             VALUES (?,?,?,?,'pending',?,?,?)"
        )->execute([$publicId, (int)$chat['id'], $inviterUserId, $recipientUserId, $requestId, $now, $expires]);
        return ['id' => $publicId, 'status' => 'pending', 'createdAt' => $now, 'expiresAt' => $expires, 'chatId' => $chatPublicId];
    });
}

function private_voice_respond_invitation(PDO $pdo, int $sessionId, int $recipientUserId, string $invitationPublicId, string $decision): array
{
    private_voice_require_enabled($pdo);
    if (!in_array($decision, ['accepted', 'rejected'], true)) {
        throw new PrivateVoiceException('Invitation decision is invalid.', 'PRIVATE_VOICE_DECISION_INVALID', 400);
    }
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $recipientUserId, $invitationPublicId, $decision): array {
        private_voice_expire_pending($pdo);
        $stmt = $pdo->prepare(
            'SELECT pvi.*, pvc.public_id AS chat_public_id, pvc.session_id, pvc.status AS chat_status,
                    pvc.participant_limit, pvc.version, pvc.created_by_user_id, pvc.created_at AS chat_created_at,
                    pvc.updated_at AS chat_updated_at, pvc.id AS chat_id
               FROM private_voice_invitations pvi JOIN private_voice_chats pvc ON pvc.id=pvi.chat_id
              WHERE pvi.public_id=? AND pvi.recipient_user_id=? LIMIT 1'
        );
        $stmt->execute([$invitationPublicId, $recipientUserId]);
        $invitation = $stmt->fetch() ?: null;
        if (!$invitation || (int)$invitation['session_id'] !== $sessionId) {
            throw new PrivateVoiceException('Invitation is not available.', 'PRIVATE_VOICE_INVITATION_NOT_FOUND', 404);
        }
        if ((string)$invitation['status'] !== 'pending') {
            return ['id' => $invitationPublicId, 'status' => (string)$invitation['status'], 'idempotent' => true];
        }
        if (!private_voice_participant($pdo, $sessionId, $recipientUserId)) {
            throw new PrivateVoiceException('Invitation eligibility was lost.', 'PRIVATE_VOICE_RECIPIENT_INELIGIBLE', 403);
        }
        $now = private_voice_now();
        $update = $pdo->prepare(
            "UPDATE private_voice_invitations SET status=?,resolved_at=?,resolution_user_id=?
              WHERE id=? AND status='pending' AND expires_at>?"
        );
        $update->execute([$decision, $now, $recipientUserId, (int)$invitation['id'], $now]);
        if ($update->rowCount() !== 1) return ['id' => $invitationPublicId, 'status' => 'expired', 'idempotent' => true];
        if ($decision === 'accepted') {
            private_voice_end_user_memberships($pdo, $sessionId, $recipientUserId, 'switch-invitation');
            $chat = private_voice_chat_by_public_id($pdo, $sessionId, (string)$invitation['chat_public_id'], true);
            if (!$chat) throw new PrivateVoiceException('Invitation chat is no longer active.', 'PRIVATE_VOICE_CHAT_ENDED', 409);
            private_voice_add_member($pdo, $chat, $recipientUserId);
        }
        return ['id' => $invitationPublicId, 'status' => $decision, 'idempotent' => false];
    });
}

function private_voice_revoke_invitation(PDO $pdo, int $sessionId, int $actorUserId, string $invitationPublicId): array
{
    private_voice_require_enabled($pdo);
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $actorUserId, $invitationPublicId): array {
        private_voice_expire_pending($pdo);
        $stmt = $pdo->prepare(
            'SELECT pvi.*,pvc.session_id FROM private_voice_invitations pvi
             JOIN private_voice_chats pvc ON pvc.id=pvi.chat_id
             WHERE pvi.public_id=? LIMIT 1'
        );
        $stmt->execute([$invitationPublicId]);
        $invitation = $stmt->fetch() ?: null;
        if (!$invitation || (int)$invitation['session_id'] !== $sessionId) {
            throw new PrivateVoiceException('Invitation is not available.', 'PRIVATE_VOICE_INVITATION_NOT_FOUND', 404);
        }
        if ((int)$invitation['inviter_user_id'] !== $actorUserId
            || !private_voice_is_member($pdo, (int)$invitation['chat_id'], $actorUserId)) {
            throw new PrivateVoiceException('Only the current inviter may revoke this invitation.', 'PRIVATE_VOICE_INVITATION_REVOKE_DENIED', 403);
        }
        if ((string)$invitation['status'] !== 'pending') {
            return ['id' => $invitationPublicId, 'status' => (string)$invitation['status'], 'idempotent' => true];
        }
        $now = private_voice_now();
        $pdo->prepare("UPDATE private_voice_invitations SET status='revoked',resolved_at=?,resolution_user_id=? WHERE id=? AND status='pending'")
            ->execute([$now, $actorUserId, (int)$invitation['id']]);
        return ['id' => $invitationPublicId, 'status' => 'revoked', 'idempotent' => false];
    });
}

function private_voice_reconcile_session(PDO $pdo, int $sessionId): void
{
    $statement = $pdo->prepare("SELECT * FROM private_voice_chats WHERE session_id=? AND status='active' ORDER BY id ASC");
    $statement->execute([$sessionId]);
    foreach ($statement->fetchAll() as $chat) {
        $members = private_voice_members($pdo, (int)$chat['id']);
        $removed = [];
        foreach ($members as $member) {
            $userId = (int)$member['userId'];
            if (!private_voice_participant($pdo, $sessionId, $userId)) $removed[$userId] = 'room-lifecycle';
        }
        foreach ($members as $left) {
            foreach ($members as $right) {
                if ((int)$left['userId'] >= (int)$right['userId']) continue;
                if (private_voice_users_blocked($pdo, (int)$left['userId'], (int)$right['userId'])) {
                    $removed[(int)$right['userId']] = 'block-reconciliation';
                }
            }
        }
        if (!$removed) continue;
        private_voice_transaction($pdo, function () use ($pdo, $chat, $removed): void {
            $now = private_voice_now();
            foreach ($removed as $userId => $reason) {
                $pdo->prepare("UPDATE private_voice_members SET membership_status='ended',ended_at=? WHERE chat_id=? AND user_id=? AND membership_status='active'")
                    ->execute([$now, (int)$chat['id'], (int)$userId]);
                $pdo->prepare("UPDATE private_voice_invitations SET status='revoked',resolved_at=? WHERE chat_id=? AND status='pending' AND (inviter_user_id=? OR recipient_user_id=?)")
                    ->execute([$now, (int)$chat['id'], (int)$userId, (int)$userId]);
                $pdo->prepare(
                    "DELETE FROM voice_sessions
                      WHERE session_id=? AND context_type='private-voice'
                        AND participant_id IN (SELECT id FROM participants WHERE session_id=? AND user_id=?)"
                )->execute([$sessionId, $sessionId, (int)$userId]);
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM private_voice_members WHERE chat_id=? AND membership_status='active'");
            $count->execute([(int)$chat['id']]);
            if ((int)$count->fetchColumn() === 0) {
                $pdo->prepare("UPDATE private_voice_chats SET status='ended',ended_at=?,updated_at=?,version=version+1 WHERE id=?")
                    ->execute([$now, $now, (int)$chat['id']]);
            } else {
                $pdo->prepare('UPDATE private_voice_chats SET updated_at=?,version=version+1 WHERE id=?')
                    ->execute([$now, (int)$chat['id']]);
            }
        });
    }
}

function private_voice_create_join_request(PDO $pdo, int $sessionId, int $requesterUserId, string $chatPublicId, string $requestId): array
{
    private_voice_require_enabled($pdo);
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $requesterUserId, $chatPublicId, $requestId): array {
        private_voice_expire_pending($pdo);
        if (!private_voice_participant($pdo, $sessionId, $requesterUserId)) {
            throw new PrivateVoiceException('Private voice is not available in this room.', 'PRIVATE_VOICE_ELIGIBILITY_DENIED', 403);
        }
        $chat = private_voice_chat_by_public_id($pdo, $sessionId, $chatPublicId, true);
        if (!$chat) throw new PrivateVoiceException('Private voice chat is not available.', 'PRIVATE_VOICE_CHAT_NOT_FOUND', 404);
        if (private_voice_is_member($pdo, (int)$chat['id'], $requesterUserId)) {
            throw new PrivateVoiceException('You are already a member.', 'PRIVATE_VOICE_ALREADY_MEMBER', 409);
        }
        $members = private_voice_members($pdo, (int)$chat['id']);
        foreach ($members as $member) {
            if (private_voice_users_blocked($pdo, $requesterUserId, (int)$member['userId'])) {
                throw new PrivateVoiceException('Private voice membership is not available.', 'PRIVATE_VOICE_ELIGIBILITY_DENIED', 403);
            }
        }
        $memberSetHash = private_voice_member_set_hash($members);
        $cutoff = gmdate('Y-m-d H:i:s', time() - PRIVATE_VOICE_REQUEST_EXPIRY_SECONDS);
        $retry = $pdo->prepare(
            'SELECT public_id,status,expires_at FROM private_voice_join_requests
              WHERE chat_id=? AND requester_user_id=? AND member_set_hash=? AND created_at>?
              ORDER BY created_at DESC LIMIT 1'
        );
        $retry->execute([(int)$chat['id'], $requesterUserId, $memberSetHash, $cutoff]);
        if ($current = $retry->fetch()) {
            throw new PrivateVoiceException(
                'Wait until the original 180-second request window ends before asking this call again.',
                'PRIVATE_VOICE_REQUEST_RETRY_BLOCKED',
                429,
                ['requestId' => $current['public_id'], 'status' => $current['status'], 'retryAt' => $current['expires_at']]
            );
        }
        $now = private_voice_now();
        $expires = private_voice_expiry(PRIVATE_VOICE_REQUEST_EXPIRY_SECONDS);
        $publicId = 'pvr-' . substr(hash('sha256', $requestId . ':' . random_bytes(16)), 0, 32);
        $pdo->prepare(
            "INSERT INTO private_voice_join_requests
                (public_id,chat_id,requester_user_id,member_set_hash,status,request_id,created_at,expires_at)
             VALUES (?,?,?,?,'pending',?,?,?)"
        )->execute([$publicId, (int)$chat['id'], $requesterUserId, $memberSetHash, $requestId, $now, $expires]);
        return ['id' => $publicId, 'chatId' => $chatPublicId, 'status' => 'pending', 'createdAt' => $now, 'expiresAt' => $expires];
    });
}

function private_voice_decide_join_request(PDO $pdo, int $sessionId, int $responderUserId, string $requestPublicId, string $decision): array
{
    private_voice_require_enabled($pdo);
    if (!in_array($decision, ['approved', 'rejected', 'dismissed'], true)) {
        throw new PrivateVoiceException('Join request decision is invalid.', 'PRIVATE_VOICE_DECISION_INVALID', 400);
    }
    $storedDecision = $decision === 'dismissed' ? 'rejected' : $decision;
    return private_voice_transaction($pdo, function () use ($pdo, $sessionId, $responderUserId, $requestPublicId, $storedDecision): array {
        private_voice_expire_pending($pdo);
        $stmt = $pdo->prepare(
            'SELECT pvr.*, pvc.public_id AS chat_public_id, pvc.session_id
               FROM private_voice_join_requests pvr JOIN private_voice_chats pvc ON pvc.id=pvr.chat_id
              WHERE pvr.public_id=? LIMIT 1'
        );
        $stmt->execute([$requestPublicId]);
        $request = $stmt->fetch() ?: null;
        if (!$request || (int)$request['session_id'] !== $sessionId) {
            throw new PrivateVoiceException('Join request is not available.', 'PRIVATE_VOICE_REQUEST_NOT_FOUND', 404);
        }
        if ((string)$request['status'] !== 'pending') {
            return ['id' => $requestPublicId, 'status' => (string)$request['status'], 'idempotent' => true];
        }
        if (!private_voice_is_member($pdo, (int)$request['chat_id'], $responderUserId)
            || !private_voice_participant($pdo, $sessionId, $responderUserId)) {
            throw new PrivateVoiceException('Only a current member may decide this request.', 'PRIVATE_VOICE_MEMBER_REQUIRED', 403);
        }
        $now = private_voice_now();
        $update = $pdo->prepare(
            "UPDATE private_voice_join_requests SET status=?,resolved_at=?,resolution_user_id=?
              WHERE id=? AND status='pending' AND expires_at>?"
        );
        $update->execute([$storedDecision, $now, $responderUserId, (int)$request['id'], $now]);
        if ($update->rowCount() !== 1) return ['id' => $requestPublicId, 'status' => 'expired', 'idempotent' => true];
        if ($storedDecision === 'approved') {
            $requesterUserId = (int)$request['requester_user_id'];
            if (!private_voice_participant($pdo, $sessionId, $requesterUserId)) {
                throw new PrivateVoiceException('The requester is no longer eligible.', 'PRIVATE_VOICE_REQUESTER_INELIGIBLE', 409);
            }
            private_voice_end_user_memberships($pdo, $sessionId, $requesterUserId, 'switch-request');
            $chat = private_voice_chat_by_public_id($pdo, $sessionId, (string)$request['chat_public_id'], true);
            if (!$chat) throw new PrivateVoiceException('Private voice chat ended.', 'PRIVATE_VOICE_CHAT_ENDED', 409);
            private_voice_add_member($pdo, $chat, $requesterUserId);
        }
        return ['id' => $requestPublicId, 'status' => $storedDecision, 'idempotent' => false];
    });
}

function private_voice_leave(PDO $pdo, int $sessionId, int $userId): array
{
    return private_voice_transaction($pdo, static fn(): array => private_voice_end_user_memberships($pdo, $sessionId, $userId, 'leave'));
}

function private_voice_snapshot(PDO $pdo, int $sessionId, int $viewerUserId): array
{
    $policy = optional_core_voice_webcam_policy($pdo);
    private_voice_expire_pending($pdo);
    private_voice_reconcile_session($pdo, $sessionId);
    $participant = private_voice_participant($pdo, $sessionId, $viewerUserId);
    $active = $participant ? private_voice_active_chat_for_user($pdo, $sessionId, $viewerUserId) : null;
    $activePayload = $active ? private_voice_chat_payload($pdo, $active, $viewerUserId) : null;

    $invitations = [];
    $stmt = $pdo->prepare(
        "SELECT pvi.public_id,pvi.status,pvi.created_at,pvi.expires_at,pvc.public_id AS chat_public_id,
                u.display_name,u.username
           FROM private_voice_invitations pvi
           JOIN private_voice_chats pvc ON pvc.id=pvi.chat_id
           JOIN users u ON u.id=pvi.inviter_user_id
          WHERE pvi.recipient_user_id=? AND pvc.session_id=? AND pvi.status='pending' AND pvi.expires_at>?
          ORDER BY pvi.created_at DESC"
    );
    $stmt->execute([$viewerUserId, $sessionId, private_voice_now()]);
    foreach ($stmt->fetchAll() as $row) {
        $invitations[] = [
            'id' => (string)$row['public_id'], 'chatId' => (string)$row['chat_public_id'],
            'from' => (string)($row['display_name'] ?: $row['username']),
            'status' => (string)$row['status'], 'createdAt' => (string)$row['created_at'],
            'expiresAt' => (string)$row['expires_at'],
        ];
    }

    $requests = [];
    if ($active) {
        $stmt = $pdo->prepare(
            "SELECT pvr.public_id,pvr.status,pvr.created_at,pvr.expires_at,u.display_name,u.username,u.id AS user_id
               FROM private_voice_join_requests pvr JOIN users u ON u.id=pvr.requester_user_id
              WHERE pvr.chat_id=? AND pvr.status='pending' AND pvr.expires_at>?
              ORDER BY pvr.created_at ASC"
        );
        $stmt->execute([(int)$active['id'], private_voice_now()]);
        foreach ($stmt->fetchAll() as $row) {
            $requests[] = [
                'id' => (string)$row['public_id'], 'requesterUserId' => (int)$row['user_id'],
                'requesterName' => (string)($row['display_name'] ?: $row['username']),
                'status' => (string)$row['status'], 'createdAt' => (string)$row['created_at'],
                'expiresAt' => (string)$row['expires_at'],
            ];
        }
    }

    $availableCalls = [];
    if ($participant && $policy['privateVoice']['enabled']) {
        $stmt = $pdo->prepare("SELECT * FROM private_voice_chats WHERE session_id=? AND status='active' ORDER BY created_at ASC");
        $stmt->execute([$sessionId]);
        foreach ($stmt->fetchAll() as $chat) {
            try {
                $availableCalls[] = private_voice_chat_payload($pdo, $chat, $viewerUserId);
            } catch (PrivateVoiceException) {
                // A blocked or otherwise ineligible viewer receives no call or member disclosure.
            }
        }
    }
    return [
        'policy' => $policy,
        'eligible' => $participant !== null,
        'activeChat' => $activePayload,
        'availableChats' => $availableCalls,
        'invitations' => $invitations,
        'joinRequests' => $requests,
    ];
}

function voice_webcam_preferences(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM voice_webcam_preferences WHERE user_id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $mode = in_array((string)($row['transmission_mode'] ?? ''), VOICE_TRANSMISSION_MODES, true)
        ? (string)$row['transmission_mode'] : 'voice-activation';
    $audience = in_array((string)($row['webcam_audience_mode'] ?? ''), WEBCAM_AUDIENCE_MODES, true)
        ? (string)$row['webcam_audience_mode'] : 'everyone';
    return [
        'transmissionMode' => $mode,
        'alwaysMutedOnJoin' => !empty($row['always_muted_on_join']),
        'webcamAudienceMode' => $audience,
        'version' => max(1, (int)($row['version'] ?? 1)),
        'policy' => optional_core_voice_webcam_policy($pdo),
    ];
}

function voice_webcam_update_preferences(PDO $pdo, int $userId, array $input): array
{
    $current = voice_webcam_preferences($pdo, $userId);
    $voiceEnabled = !empty($current['policy']['transmissionModes']['enabled']);
    $audienceEnabled = !empty($current['policy']['selectiveWebcamAudience']['enabled']);
    if (!$voiceEnabled && !$audienceEnabled) {
        throw new PrivateVoiceException(
            'No personal voice or webcam options are currently available.',
            'VOICE_WEBCAM_OPTIONS_UNAVAILABLE',
            403
        );
    }
    $mode = $voiceEnabled
        ? (string)($input['transmission_mode'] ?? $current['transmissionMode'])
        : (string)$current['transmissionMode'];
    $audience = $audienceEnabled
        ? (string)($input['webcam_audience_mode'] ?? $current['webcamAudienceMode'])
        : (string)$current['webcamAudienceMode'];
    if (!in_array($mode, VOICE_TRANSMISSION_MODES, true)) {
        throw new PrivateVoiceException('Voice transmission mode is invalid.', 'VOICE_MODE_INVALID', 400);
    }
    if (!in_array($audience, WEBCAM_AUDIENCE_MODES, true)) {
        throw new PrivateVoiceException('Webcam audience mode is invalid.', 'WEBCAM_AUDIENCE_MODE_INVALID', 400);
    }
    $expected = max(1, (int)($input['expected_version'] ?? 0));
    if ($expected !== (int)$current['version']) {
        throw new PrivateVoiceException('Voice and webcam settings changed in another session.', 'VOICE_WEBCAM_PREFERENCES_STALE', 409, ['current' => $current]);
    }
    $nextVersion = $expected + 1;
    $now = private_voice_now();
    $muted = $voiceEnabled
        ? (!empty($input['always_muted_on_join']) ? 1 : 0)
        : (!empty($current['alwaysMutedOnJoin']) ? 1 : 0);
    if (db_uses_mysql_syntax($pdo)) {
        $pdo->prepare(
            'INSERT INTO voice_webcam_preferences (user_id,transmission_mode,always_muted_on_join,webcam_audience_mode,version,updated_at)
             VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE transmission_mode=VALUES(transmission_mode),always_muted_on_join=VALUES(always_muted_on_join),webcam_audience_mode=VALUES(webcam_audience_mode),version=VALUES(version),updated_at=VALUES(updated_at)'
        )->execute([$userId, $mode, $muted, $audience, $nextVersion, $now]);
    } else {
        $pdo->prepare(
            'INSERT INTO voice_webcam_preferences (user_id,transmission_mode,always_muted_on_join,webcam_audience_mode,version,updated_at)
             VALUES (?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET transmission_mode=excluded.transmission_mode,always_muted_on_join=excluded.always_muted_on_join,webcam_audience_mode=excluded.webcam_audience_mode,version=excluded.version,updated_at=excluded.updated_at'
        )->execute([$userId, $mode, $muted, $audience, $nextVersion, $now]);
    }
    return voice_webcam_preferences($pdo, $userId);
}

function webcam_audience_clear(PDO $pdo, int $senderParticipantId): int
{
    $stmt = $pdo->prepare('DELETE FROM webcam_audience_recipients WHERE sender_participant_id=?');
    $stmt->execute([$senderParticipantId]);
    $pdo->prepare('DELETE FROM webcam_audience_sessions WHERE sender_participant_id=?')
        ->execute([$senderParticipantId]);
    return $stmt->rowCount();
}

function webcam_audience_confirm(
    PDO $pdo,
    int $sessionId,
    array $sender,
    string $clientEpoch,
    string $mode,
    array $selectedUserIds = []
): array {
    $policy = optional_core_voice_webcam_policy($pdo)['selectiveWebcamAudience'];
    if (!$policy['enabled']) {
        throw new PrivateVoiceException('Selective Webcam Audience is disabled.', 'WEBCAM_AUDIENCE_DISABLED', 403);
    }
    if (!in_array($mode, WEBCAM_AUDIENCE_MODES, true)) {
        throw new PrivateVoiceException('Webcam audience mode is invalid.', 'WEBCAM_AUDIENCE_MODE_INVALID', 400);
    }
    $senderParticipantId = (int)$sender['id'];
    $senderUserId = (int)$sender['user_id'];
    $contextType = 'room';
    $contextPublicId = null;
    $recipientIds = [];

    if ($mode === 'private-voice') {
        $chat = private_voice_active_chat_for_user($pdo, $sessionId, $senderUserId);
        if (!$chat) {
            throw new PrivateVoiceException('Join a private voice chat before choosing its members.', 'WEBCAM_PRIVATE_VOICE_REQUIRED', 409);
        }
        $contextType = 'private-voice';
        $contextPublicId = (string)$chat['public_id'];
        foreach (private_voice_members($pdo, (int)$chat['id']) as $member) {
            if ((int)$member['userId'] !== $senderUserId && !empty($member['available'])) {
                $recipientIds[] = (int)$member['userId'];
            }
        }
    } elseif ($mode === 'selected') {
        $selectedUserIds = array_values(array_unique(array_filter(
            array_map('intval', $selectedUserIds),
            static fn(int $id): bool => $id > 0 && $id !== $senderUserId
        )));
        if (!$selectedUserIds) {
            throw new PrivateVoiceException('Select at least one current room member.', 'WEBCAM_AUDIENCE_RECIPIENT_REQUIRED', 409);
        }
        foreach ($selectedUserIds as $recipientUserId) {
            if (!private_voice_participant($pdo, $sessionId, $recipientUserId)
                || private_voice_users_blocked($pdo, $senderUserId, $recipientUserId)) {
                throw new PrivateVoiceException('A selected webcam recipient is no longer eligible.', 'WEBCAM_AUDIENCE_RECIPIENT_INELIGIBLE', 409);
            }
            $recipientIds[] = $recipientUserId;
        }
    }

    return private_voice_transaction($pdo, function () use (
        $pdo, $sessionId, $senderParticipantId, $senderUserId, $clientEpoch,
        $contextType, $contextPublicId, $mode, $recipientIds
    ): array {
        $current = $pdo->prepare('SELECT revision FROM webcam_audience_sessions WHERE sender_participant_id=? LIMIT 1');
        $current->execute([$senderParticipantId]);
        $revision = max(1, (int)($current->fetchColumn() ?: 0) + 1);
        $now = private_voice_now();
        if (db_uses_mysql_syntax($pdo)) {
            $pdo->prepare(
                'INSERT INTO webcam_audience_sessions
                    (sender_participant_id,session_id,sender_user_id,client_epoch,context_type,context_public_id,audience_mode,revision,confirmed_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE session_id=VALUES(session_id),sender_user_id=VALUES(sender_user_id),client_epoch=VALUES(client_epoch),context_type=VALUES(context_type),context_public_id=VALUES(context_public_id),audience_mode=VALUES(audience_mode),revision=VALUES(revision),confirmed_at=VALUES(confirmed_at),updated_at=VALUES(updated_at)'
            )->execute([$senderParticipantId,$sessionId,$senderUserId,$clientEpoch,$contextType,$contextPublicId,$mode,$revision,$now,$now]);
        } else {
            $pdo->prepare(
                'INSERT INTO webcam_audience_sessions
                    (sender_participant_id,session_id,sender_user_id,client_epoch,context_type,context_public_id,audience_mode,revision,confirmed_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON CONFLICT(sender_participant_id) DO UPDATE SET session_id=excluded.session_id,sender_user_id=excluded.sender_user_id,client_epoch=excluded.client_epoch,context_type=excluded.context_type,context_public_id=excluded.context_public_id,audience_mode=excluded.audience_mode,revision=excluded.revision,confirmed_at=excluded.confirmed_at,updated_at=excluded.updated_at'
            )->execute([$senderParticipantId,$sessionId,$senderUserId,$clientEpoch,$contextType,$contextPublicId,$mode,$revision,$now,$now]);
        }
        $pdo->prepare('DELETE FROM webcam_audience_recipients WHERE sender_participant_id=?')->execute([$senderParticipantId]);
        $insert = $pdo->prepare('INSERT INTO webcam_audience_recipients(sender_participant_id,recipient_user_id,audience_revision,created_at) VALUES(?,?,?,?)');
        foreach ($recipientIds as $recipientUserId) $insert->execute([$senderParticipantId,$recipientUserId,$revision,$now]);
        return [
            'mode' => $mode,
            'revision' => $revision,
            'contextType' => $contextType,
            'contextPublicId' => $contextPublicId,
            'recipientCount' => count($recipientIds),
            'confirmed' => true,
        ];
    });
}

function webcam_audience_projection(PDO $pdo, int $senderParticipantId, string $clientEpoch): array
{
    $stmt = $pdo->prepare('SELECT * FROM webcam_audience_sessions WHERE sender_participant_id=? LIMIT 1');
    $stmt->execute([$senderParticipantId]);
    $row = $stmt->fetch() ?: null;
    if (!$row || !hash_equals((string)$row['client_epoch'], $clientEpoch)) {
        return ['mode' => 'unconfirmed', 'revision' => 0, 'recipientUserIds' => [], 'confirmed' => false];
    }
    $recipients = $pdo->prepare('SELECT recipient_user_id FROM webcam_audience_recipients WHERE sender_participant_id=? AND audience_revision=? ORDER BY recipient_user_id');
    $recipients->execute([$senderParticipantId, (int)$row['revision']]);
    return [
        'mode' => (string)$row['audience_mode'],
        'revision' => (int)$row['revision'],
        'contextType' => (string)$row['context_type'],
        'contextPublicId' => $row['context_public_id'],
        'recipientUserIds' => array_map('intval', $recipients->fetchAll(PDO::FETCH_COLUMN)),
        'confirmed' => $row['confirmed_at'] !== null,
    ];
}

function webcam_audience_recipient_allowed(
    PDO $pdo,
    int $sessionId,
    int $senderParticipantId,
    int $recipientParticipantId,
    string $clientEpoch
): bool {
    if ($senderParticipantId === $recipientParticipantId) return true;
    $policy = optional_core_voice_webcam_policy($pdo)['selectiveWebcamAudience'];
    if (!$policy['enabled']) return true;
    $projection = webcam_audience_projection($pdo, $senderParticipantId, $clientEpoch);
    if (!$projection['confirmed']) return false;
    if ($projection['mode'] === 'everyone') return true;
    if ($projection['mode'] === 'nobody') return false;
    $recipient = $pdo->prepare('SELECT user_id FROM participants WHERE id=? AND session_id=? AND last_seen_at IS NOT NULL LIMIT 1');
    $recipient->execute([$recipientParticipantId, $sessionId]);
    $recipientUserId = (int)($recipient->fetchColumn() ?: 0);
    return $recipientUserId > 0 && in_array($recipientUserId, $projection['recipientUserIds'], true);
}

function webcam_audience_project_participant(
    PDO $pdo,
    int $sessionId,
    int $viewerParticipantId,
    array $participant
): array {
    if (empty($participant['webcam_enabled'])
        || !optional_core_voice_webcam_policy($pdo)['selectiveWebcamAudience']['enabled']) {
        return $participant;
    }
    $senderParticipantId = (int)($participant['id'] ?? 0);
    $epoch = $pdo->prepare('SELECT client_epoch FROM media_signal_clients WHERE participant_id=? AND session_id=? LIMIT 1');
    $epoch->execute([$senderParticipantId, $sessionId]);
    if (!webcam_audience_recipient_allowed(
        $pdo,
        $sessionId,
        $senderParticipantId,
        $viewerParticipantId,
        (string)($epoch->fetchColumn() ?: '')
    )) {
        $participant['webcam_enabled'] = false;
        $participant['webcam_path'] = null;
    }
    return $participant;
}
