<?php
declare(strict_types=1);

final class AccountDeletionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ACCOUNT_DELETION_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}

const ACCOUNT_DELETION_CONFIRMATION = 'DELETE';
const ACCOUNT_DELETION_OPERATION = 'delete-account';

function account_deletion_disposition_registry(): array
{
    return [
        'account_deletions' => 'retain-opaque-history',
        'account_lifecycle_foundations' => 'retain-opaque-history',
        'account_lifecycle_idempotency' => 'retain-machine-control',
        'avatar_hidden_preferences' => 'delete-active-edge',
        'avatar_relationship_members' => 'terminate-active-state',
        'avatar_relationship_membership_history' => 'retain-opaque-history',
        'avatar_relationship_requests' => 'terminate-active-state',
        'avatar_relationships' => 'terminate-active-state',
        'build_000051_upgrade_previews' => 'delete-active-edge',
        'community_ejections' => 'retain-opaque-history',
        'community_message_reactions' => 'retain-opaque-history',
        'community_messages' => 'anonymize-tombstone',
        'core_migration_attempts' => 'retain-machine-control',
        'core_migration_ledger' => 'retain-machine-control',
        'game_chat_messages' => 'anonymize-tombstone',
        'game_lobbies' => 'terminate-active-state',
        'game_moves' => 'retain-opaque-history',
        'game_sessions' => 'terminate-active-state',
        'gesture_custom_order' => 'delete-active-edge',
        'gesture_downloads' => 'delete-active-edge',
        'gesture_hidden' => 'delete-active-edge',
        'gesture_operation_requests' => 'delete-active-edge',
        'gesture_package_generations' => 'dispose-personal-content',
        'gesture_preferences' => 'delete-active-edge',
        'gesture_sender_media_hidden' => 'delete-active-edge',
        'gestures' => 'dispose-personal-content',
        'installation_identity' => 'retain-machine-control',
        'installation_owner_history' => 'retain-opaque-history',
        'media_signal_clients' => 'delete-active-edge',
        'media_signals' => 'delete-active-edge',
        'member_deleted_username_uses' => 'retain-opaque-history',
        'member_display_name_history' => 'delete-active-edge',
        'member_identity_names' => 'anonymize-tombstone',
        'member_profile_requests' => 'delete-active-edge',
        'member_profiles' => 'anonymize-tombstone',
        'message_protection_device_approvals' => 'delete-active-edge',
        'message_protection_devices' => 'delete-active-edge',
        'message_protection_policies' => 'retain-opaque-history',
        'message_protection_recovery' => 'delete-active-edge',
        'message_protection_transitions' => 'retain-opaque-history',
        'message_reactions' => 'retain-opaque-history',
        'messages' => 'anonymize-tombstone',
        'moderation_actions' => 'retain-opaque-history',
        'moderation_case_actions' => 'retain-opaque-history',
        'moderation_case_assignments' => 'delete-active-edge',
        'moderation_cases' => 'retain-opaque-history',
        'moderation_notices' => 'terminate-active-state',
        'moderation_reports' => 'retain-opaque-history',
        'moderation_trust_master_transitions' => 'retain-opaque-history',
        'network_ban_previews' => 'delete-active-edge',
        'network_manual_bans' => 'retain-opaque-history',
        'network_observation_contexts' => 'retain-opaque-history',
        'network_privacy_events' => 'retain-opaque-history',
        'operational_capacity_requests' => 'retain-opaque-history',
        'outside_content_confirmations' => 'delete-active-edge',
        'participants' => 'anonymize-tombstone',
        'personal_mutes' => 'delete-active-edge',
        'policy_acceptances' => 'retain-opaque-history',
        'private_message_clears' => 'delete-active-edge',
        'private_voice_chats' => 'terminate-active-state',
        'private_voice_invitations' => 'terminate-active-state',
        'private_voice_join_requests' => 'terminate-active-state',
        'private_voice_members' => 'terminate-active-state',
        'registration_invitations' => 'terminate-active-state',
        'retention_holds' => 'retain-opaque-history',
        'retention_policies' => 'retain-opaque-history',
        'retention_requests' => 'retain-opaque-history',
        'room_deletion_notices' => 'retain-opaque-history',
        'room_effects' => 'terminate-active-state',
        'room_ejections' => 'retain-opaque-history',
        'rooms' => 'transfer-ownership',
        'runtime_diagnostic_policy_requests' => 'retain-opaque-history',
        'runtime_issue_deletion_requests' => 'retain-opaque-history',
        'runtime_issue_export_audits' => 'retain-opaque-history',
        'runtime_issue_handoffs' => 'retain-opaque-history',
        'runtime_issue_occurrences' => 'retain-opaque-history',
        'runtime_issue_screenshots' => 'retain-opaque-history',
        'runtime_issue_status_history' => 'retain-opaque-history',
        'runtime_issues' => 'retain-opaque-history',
        'tool_logs' => 'retain-opaque-history',
        'user_blocks' => 'delete-active-edge',
        'user_capability_grants' => 'delete-active-edge',
        'user_staff_capability_grants' => 'delete-active-edge',
        'user_trust' => 'delete-active-edge',
        'users' => 'anonymize-tombstone',
        'voice_sessions' => 'delete-active-edge',
        'voice_webcam_preferences' => 'delete-active-edge',
        'webcam_audience_recipients' => 'delete-active-edge',
        'webcam_audience_sessions' => 'delete-active-edge',
    ];
}

function account_deletion_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS account_deletions (
                user_id INT PRIMARY KEY,
                opaque_identity VARCHAR(191) NOT NULL UNIQUE,
                request_id VARCHAR(96) NOT NULL UNIQUE,
                request_sha256 VARCHAR(64) NOT NULL,
                room_successor_user_id INT DEFAULT NULL,
                disposition_json LONGTEXT NOT NULL,
                completed_at VARCHAR(32) NOT NULL,
                CONSTRAINT fk_account_deletions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_account_deletions_successor FOREIGN KEY (room_successor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX idx_account_deletions_completed ON account_deletions(completed_at)',
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS account_deletions (
            user_id INTEGER PRIMARY KEY,
            opaque_identity TEXT NOT NULL UNIQUE,
            request_id TEXT NOT NULL UNIQUE,
            request_sha256 TEXT NOT NULL,
            room_successor_user_id INTEGER DEFAULT NULL,
            disposition_json TEXT NOT NULL,
            completed_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY(room_successor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_account_deletions_completed ON account_deletions(completed_at)',
    ];
}

function account_deletion_install_schema(PDO $pdo): void
{
    foreach (account_deletion_schema_statements($pdo) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $error) {
            if (db_uses_mysql_syntax($pdo) && str_contains(strtolower($error->getMessage()), 'duplicate key name')) {
                continue;
            }
            throw $error;
        }
    }
}

function account_deletion_schema_valid(PDO $pdo): bool
{
    if (!database_migration_table_exists($pdo, 'account_deletions')) return false;
    $columns = [];
    if (db_uses_mysql_syntax($pdo)) {
        foreach ($pdo->query('SHOW COLUMNS FROM account_deletions')->fetchAll() as $column) {
            $columns[] = (string)$column['Field'];
        }
    } else {
        foreach ($pdo->query('PRAGMA table_info(account_deletions)')->fetchAll() as $column) {
            $columns[] = (string)$column['name'];
        }
    }
    foreach ([
        'user_id', 'opaque_identity', 'request_id', 'request_sha256',
        'room_successor_user_id', 'disposition_json', 'completed_at',
    ] as $column) {
        if (!in_array($column, $columns, true)) return false;
    }
    return true;
}

function account_deletion_apply_bundled_seed_compatibility(PDO $pdo): void
{
    if (!account_deletion_table_exists($pdo, 'users')
        || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0
        || !account_deletion_table_exists($pdo, 'core_migration_attempts')) {
        return;
    }
    $attempt = $pdo->query(
        "SELECT source_variant FROM core_migration_attempts
         WHERE status IN ('active','recovering') ORDER BY started_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (($attempt['source_variant'] ?? '') !== 'bundled-seed') return;
    moderation_identity_apply_open_community_fresh_default($pdo, true);
}

function account_deletion_table_exists(PDO $pdo, string $table): bool
{
    return database_migration_table_exists($pdo, $table);
}

function account_deletion_is_deleted(PDO $pdo, int $userId): bool
{
    if ($userId < 1 || !account_deletion_table_exists($pdo, 'account_deletions')) return false;
    $statement = $pdo->prepare('SELECT 1 FROM account_deletions WHERE user_id=? LIMIT 1');
    $statement->execute([$userId]);
    return (bool)$statement->fetchColumn();
}

function account_deletion_visible_name(PDO $pdo, int $userId, ?string $fallback = null): string
{
    return account_deletion_is_deleted($pdo, $userId) ? '[Deleted User]' : (string)($fallback ?? '');
}

function account_deletion_internal_identity(string $opaqueIdentity): array
{
    $suffix = strtolower(substr(hash('sha256', 'account-deletion:' . $opaqueIdentity), 0, 24));
    $variation = '';
    foreach (str_split(substr($suffix, 0, 16)) as $hex) {
        $variation .= mb_chr(0xFE00 + hexdec($hex), 'UTF-8');
    }
    return [
        'username' => 'deleted-' . $suffix,
        'email' => 'deleted+' . $suffix . '@invalid.local',
        'display_name' => '[Deleted User]' . $variation,
    ];
}

function account_deletion_request_sha256(
    int $userId,
    string $requestId,
    string $confirmation,
    ?int $successorUserId
): string
{
    return strtoupper(hash('sha256', json_encode([
        'operation' => ACCOUNT_DELETION_OPERATION,
        'user_id' => $userId,
        'request_id' => $requestId,
        'confirmation' => $confirmation,
        'room_successor_user_id' => $successorUserId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
}

function account_deletion_validate_request_id(mixed $requestId): string
{
    $requestId = trim((string)$requestId);
    if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)) {
        throw new AccountDeletionException(
            'The deletion request identity is invalid. Refresh and try again.',
            'ACCOUNT_DELETION_REQUEST_INVALID',
            400
        );
    }
    return $requestId;
}

function account_deletion_user_row(PDO $pdo, int $userId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM users WHERE id=? LIMIT 1';
    if ($forUpdate && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);
    return $statement->fetch() ?: null;
}

function account_deletion_successor_eligible(PDO $pdo, int $userId, int $deletingUserId, bool $forUpdate = false): bool
{
    if ($userId < 1 || $userId === $deletingUserId || account_deletion_is_deleted($pdo, $userId)) return false;
    $sql = "SELECT u.id FROM users u
            LEFT JOIN user_trust t ON t.user_id=u.id
            WHERE u.id=? AND COALESCE(t.trust_state,'trusted') <> 'suspended' LIMIT 1";
    if ($forUpdate && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);
    return (bool)$statement->fetchColumn();
}

function account_deletion_preview(PDO $pdo, int $userId): array
{
    if ($userId < 1 || !account_deletion_user_row($pdo, $userId) || account_deletion_is_deleted($pdo, $userId)) {
        throw new AccountDeletionException('This account is unavailable.', 'ACCOUNT_DELETION_ACCOUNT_UNAVAILABLE', 404);
    }
    $rooms = $pdo->prepare('SELECT id, public_id, name FROM rooms WHERE owner_id=? ORDER BY name,id');
    $rooms->execute([$userId]);
    $ownedRooms = array_map(static fn(array $room): array => [
        'id' => (int)$room['id'],
        'publicId' => $room['public_id'] === null ? null : (string)$room['public_id'],
        'name' => (string)$room['name'],
    ], $rooms->fetchAll());
    $successors = $pdo->prepare(
        "SELECT u.id,u.display_name,u.role,COALESCE(t.trust_state,'trusted') AS trust_state
         FROM users u
         LEFT JOIN user_trust t ON t.user_id=u.id
         LEFT JOIN account_deletions d ON d.user_id=u.id
         WHERE u.id<>? AND d.user_id IS NULL AND COALESCE(t.trust_state,'trusted')<>'suspended'
         ORDER BY LOWER(u.display_name),u.id"
    );
    $successors->execute([$userId]);
    $eligibleSuccessors = array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'displayName' => (string)$row['display_name'],
        'role' => (string)$row['role'],
    ], $successors->fetchAll());
    $isOwner = moderation_identity_is_owner($pdo, $userId);
    return [
        'confirmationText' => ACCOUNT_DELETION_CONFIRMATION,
        'isInstallationOwner' => $isOwner,
        'ownedRoomCount' => count($ownedRooms),
        'ownedRooms' => $ownedRooms,
        'eligibleSuccessors' => $eligibleSuccessors,
        'roomTransferRequired' => $ownedRooms !== [],
        'canDelete' => !$isOwner && ($ownedRooms === [] || $eligibleSuccessors !== []),
        'retainedHistoryNotice' => 'Required chat, moderation, safety, audit, and shared-history records remain under a Deleted User identity.',
    ];
}

function account_deletion_delete(PDO $pdo, string $table, string $where, array $params): int
{
    if (!account_deletion_table_exists($pdo, $table)) return 0;
    $statement = $pdo->prepare("DELETE FROM {$table} WHERE {$where}");
    $statement->execute($params);
    return $statement->rowCount();
}

function account_deletion_update(PDO $pdo, string $table, string $set, string $where, array $params): int
{
    if (!account_deletion_table_exists($pdo, $table)) return 0;
    $statement = $pdo->prepare("UPDATE {$table} SET {$set} WHERE {$where}");
    $statement->execute($params);
    return $statement->rowCount();
}

function account_deletion_file_staging_directory(): string
{
    return security_private_storage_directory('account-deletion-staging');
}

function account_deletion_resolve_public_upload(string $stored, string $prefix): ?string
{
    if (!str_starts_with($stored, $prefix) || basename($stored) !== substr($stored, strrpos($stored, '/') + 1)) return null;
    $root = realpath(dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, dirname($stored)));
    $path = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $stored);
    if ($root === false || !is_file($path) || !hash_equals(strtolower($root), strtolower((string)realpath(dirname($path))))) return null;
    return $path;
}

function account_deletion_collect_file_paths(PDO $pdo, int $userId, array $personalGestureIds): array
{
    $paths = [];
    $user = account_deletion_user_row($pdo, $userId);
    $avatar = (string)($user['avatar_path'] ?? '');
    $avatarPath = account_deletion_resolve_public_upload($avatar, '/assets/uploads/avatars/');
    if ($avatarPath !== null) {
        $checks = [
            ['SELECT COUNT(*) FROM users WHERE id<>? AND avatar_path=?', [$userId, $avatar]],
            ['SELECT COUNT(*) FROM participants WHERE user_id<>? AND avatar_path=?', [$userId, $avatar]],
            ['SELECT COUNT(*) FROM messages WHERE COALESCE(user_id,0)<>? AND (avatar_path=? OR avatar_url=?)', [$userId, $avatar, $avatar]],
            ['SELECT COUNT(*) FROM community_messages WHERE COALESCE(user_id,0)<>? AND (avatar_path=? OR avatar_url=?)', [$userId, $avatar, $avatar]],
        ];
        $referenced = false;
        foreach ($checks as [$sql, $params]) {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            if ((int)$statement->fetchColumn() > 0) $referenced = true;
        }
        if (!$referenced) $paths[$avatarPath] = $avatarPath;
    }

    if ($personalGestureIds !== []) {
        $placeholders = implode(',', array_fill(0, count($personalGestureIds), '?'));
        $gestures = $pdo->prepare("SELECT gif_path,audio_path FROM gestures WHERE id IN ({$placeholders})");
        $gestures->execute($personalGestureIds);
        foreach ($gestures->fetchAll() as $gesture) {
            foreach (['gif_path', 'audio_path'] as $field) {
                $stored = (string)($gesture[$field] ?? '');
                $path = account_deletion_resolve_public_upload($stored, '/assets/uploads/gestures/');
                if ($path === null) continue;
                $reference = $pdo->prepare(
                    "SELECT COUNT(*) FROM gestures WHERE id NOT IN ({$placeholders}) AND (gif_path=? OR audio_path=?) AND deleted_at IS NULL"
                );
                $reference->execute(array_merge($personalGestureIds, [$stored, $stored]));
                if ((int)$reference->fetchColumn() === 0) $paths[$path] = $path;
            }
        }
        if (function_exists('gesture_package_storage_root')) {
            $generation = $pdo->prepare(
                "SELECT package_storage_name,animation_storage_name,poster_storage_name,audio_storage_name
                 FROM gesture_package_generations WHERE gesture_id IN ({$placeholders})"
            );
            $generation->execute($personalGestureIds);
            $root = realpath(gesture_package_storage_root());
            foreach ($generation->fetchAll() as $row) {
                foreach (['package_storage_name', 'animation_storage_name', 'poster_storage_name', 'audio_storage_name'] as $field) {
                    $name = (string)($row[$field] ?? '');
                    if ($name === '' || str_starts_with($name, 'legacy:') || basename($name) !== $name) continue;
                    $path = gesture_package_storage_root() . DIRECTORY_SEPARATOR . $name;
                    if ($root !== false && is_file($path) && hash_equals(strtolower($root), strtolower((string)realpath(dirname($path))))) {
                        $paths[$path] = $path;
                    }
                }
            }
        }
    }
    if (account_deletion_table_exists($pdo, 'server_media_assets')) {
        $serverMedia = $pdo->prepare(
            "SELECT storage_path,preview_path FROM server_media_assets "
            . "WHERE uploader_user_id=? AND category IN ('chat-attachment','voice-note') AND status='active'"
        );
        $serverMedia->execute([$userId]);
        foreach ($serverMedia->fetchAll() as $asset) {
            foreach (['storage_path','preview_path'] as $column) {
                $path = (string)($asset[$column] ?? '');
                if ($path !== '' && is_file($path)) $paths[$path] = $path;
            }
        }
    }
    return array_values($paths);
}

function account_deletion_stage_files(PDO $pdo, int $userId, string $requestId, array $personalGestureIds): ?string
{
    $paths = account_deletion_collect_file_paths($pdo, $userId, $personalGestureIds);
    if ($paths === []) return null;
    $directory = account_deletion_file_staging_directory();
    $key = strtolower(hash('sha256', $requestId));
    $journalPath = $directory . DIRECTORY_SEPARATOR . $key . '.json';
    $entries = [];
    foreach (array_values($paths) as $index => $source) {
        $entries[] = [
            'source' => $source,
            'staged' => $directory . DIRECTORY_SEPARATOR . $key . '-' . $index . '.bin',
        ];
    }
    $journal = [
        'request_id' => $requestId,
        'user_id' => $userId,
        'entries' => $entries,
    ];
    $temporary = $journalPath . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false
        || !@rename($temporary, $journalPath)) {
        @unlink($temporary);
        throw new AccountDeletionException('Personal media could not be staged safely.', 'ACCOUNT_DELETION_FILE_STAGE_FAILED', 500);
    }
    $moved = [];
    try {
        foreach ($entries as $entry) {
            if (!@rename((string)$entry['source'], (string)$entry['staged'])) {
                throw new AccountDeletionException('Personal media could not be staged safely.', 'ACCOUNT_DELETION_FILE_STAGE_FAILED', 500);
            }
            $moved[] = $entry;
        }
        return $journalPath;
    } catch (Throwable $error) {
        foreach (array_reverse($moved) as $entry) @rename((string)$entry['staged'], (string)$entry['source']);
        @unlink($journalPath);
        throw $error;
    }
}

function account_deletion_finish_file_journal(?string $journalPath, bool $committed): void
{
    if ($journalPath === null || !is_file($journalPath)) return;
    $journal = json_decode((string)file_get_contents($journalPath), true);
    if (!is_array($journal)) return;
    foreach ((array)($journal['entries'] ?? []) as $entry) {
        $source = (string)($entry['source'] ?? '');
        $staged = (string)($entry['staged'] ?? '');
        if ($committed) {
            if (is_file($staged)) @unlink($staged);
        } elseif (is_file($staged) && !is_file($source)) {
            @rename($staged, $source);
        }
    }
    @unlink($journalPath);
}

function account_deletion_reconcile_file_journals(PDO $pdo, int $limit = 5): int
{
    if (!account_deletion_table_exists($pdo, 'account_deletions')) return 0;
    $directory = account_deletion_file_staging_directory();
    $handled = 0;
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $journalPath) {
        if ($handled >= max(1, $limit)) break;
        $journal = json_decode((string)file_get_contents($journalPath), true);
        if (!is_array($journal) || empty($journal['request_id'])) continue;
        $statement = $pdo->prepare('SELECT 1 FROM account_deletions WHERE request_id=? LIMIT 1');
        $statement->execute([(string)$journal['request_id']]);
        account_deletion_finish_file_journal($journalPath, (bool)$statement->fetchColumn());
        $handled++;
    }
    return $handled;
}

function account_deletion_execute(
    PDO $pdo,
    int $userId,
    string $confirmation,
    string $requestId,
    ?int $roomSuccessorUserId,
    string $currentPassword
): array {
    $requestId = account_deletion_validate_request_id($requestId);
    if ($currentPassword === '') {
        throw new AccountDeletionException('Current password is not correct.', 'ACCOUNT_DELETION_PASSWORD_INVALID', 403);
    }
    security_require_recent_authentication();
    if (!hash_equals(ACCOUNT_DELETION_CONFIRMATION, $confirmation)) {
        throw new AccountDeletionException('Type DELETE exactly to confirm.', 'ACCOUNT_DELETION_CONFIRMATION_INVALID', 422);
    }
    $requestSha = account_deletion_request_sha256(
        $userId,
        $requestId,
        $confirmation,
        $roomSuccessorUserId
    );
    $existing = $pdo->prepare('SELECT request_sha256,disposition_json,completed_at FROM account_deletions WHERE request_id=? OR user_id=? LIMIT 1');
    $existing->execute([$requestId, $userId]);
    if ($row = $existing->fetch()) {
        if (!hash_equals((string)$row['request_sha256'], $requestSha)) {
            throw new AccountDeletionException('The deletion request conflicts with an existing result.', 'ACCOUNT_DELETION_REQUEST_CONFLICT', 409);
        }
        $result = json_decode((string)$row['disposition_json'], true);
        return is_array($result) ? array_replace($result, ['idempotentReplay' => true]) : [
            'deleted' => true,
            'completedAt' => (string)$row['completed_at'],
            'idempotentReplay' => true,
        ];
    }

    $transaction = database_transaction_begin($pdo, true);
    $journalPath = null;
    $committed = false;
    try {
        $user = account_deletion_user_row($pdo, $userId, true);
        if (!$user) {
            throw new AccountDeletionException('This account is unavailable.', 'ACCOUNT_DELETION_ACCOUNT_UNAVAILABLE', 404);
        }
        $serializedExisting = $pdo->prepare(
            'SELECT request_sha256,disposition_json,completed_at FROM account_deletions '
            . 'WHERE request_id=? OR user_id=? LIMIT 1'
            . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : '')
        );
        $serializedExisting->execute([$requestId, $userId]);
        if ($row = $serializedExisting->fetch()) {
            if (!hash_equals((string)$row['request_sha256'], $requestSha)) {
                throw new AccountDeletionException(
                    'The deletion request conflicts with an existing result.',
                    'ACCOUNT_DELETION_REQUEST_CONFLICT',
                    409
                );
            }
            $result = json_decode((string)$row['disposition_json'], true);
            database_transaction_commit($pdo, $transaction);
            $committed = true;
            return is_array($result) ? array_replace($result, ['idempotentReplay' => true]) : [
                'deleted' => true,
                'completedAt' => (string)$row['completed_at'],
                'idempotentReplay' => true,
            ];
        }
        if (account_deletion_is_deleted($pdo, $userId)) {
            throw new AccountDeletionException('This account is unavailable.', 'ACCOUNT_DELETION_ACCOUNT_UNAVAILABLE', 404);
        }
        security_require_recent_authentication();
        if (!password_verify($currentPassword, (string)$user['password_hash'])) {
            throw new AccountDeletionException(
                'Current password is not correct.',
                'ACCOUNT_DELETION_PASSWORD_INVALID',
                403
            );
        }
        if (moderation_identity_is_owner($pdo, $userId)) {
            throw new AccountDeletionException(
                'Transfer Installation Owner responsibility before deleting this account.',
                'ACCOUNT_DELETION_OWNER_TRANSFER_REQUIRED',
                409
            );
        }
        $rooms = $pdo->prepare('SELECT id FROM rooms WHERE owner_id=? ORDER BY id' . (db_uses_mysql_syntax($pdo) ? ' FOR UPDATE' : ''));
        $rooms->execute([$userId]);
        $roomIds = array_map('intval', $rooms->fetchAll(PDO::FETCH_COLUMN));
        if ($roomIds !== []) {
            if ($roomSuccessorUserId === null || !account_deletion_successor_eligible($pdo, $roomSuccessorUserId, $userId, true)) {
                throw new AccountDeletionException(
                    'Choose an eligible account to receive the rooms you own.',
                    'ACCOUNT_DELETION_ROOM_SUCCESSOR_REQUIRED',
                    409
                );
            }
        } elseif ($roomSuccessorUserId !== null && !account_deletion_successor_eligible($pdo, $roomSuccessorUserId, $userId, true)) {
            throw new AccountDeletionException('The selected successor is unavailable.', 'ACCOUNT_DELETION_ROOM_SUCCESSOR_INVALID', 409);
        }

        $lifecycle = retention_lifecycle_ensure_user($pdo, $userId);
        $opaqueIdentity = (string)($lifecycle['opaque_identity'] ?? '');
        if ($opaqueIdentity === '') {
            throw new AccountDeletionException('The account identity is unavailable.', 'ACCOUNT_DELETION_IDENTITY_UNAVAILABLE', 500);
        }
        member_profiles_record_deleted_username_use($pdo, $userId);
        $personalGestures = [];
        if (account_deletion_table_exists($pdo, 'gestures')) {
            $gestureStatement = $pdo->prepare('SELECT id,public_id FROM gestures WHERE owner_user_id=? AND is_public=0 AND deleted_at IS NULL ORDER BY id');
            $gestureStatement->execute([$userId]);
            $personalGestures = $gestureStatement->fetchAll();
        }
        $personalGestureIds = array_map(static fn(array $row): int => (int)$row['id'], $personalGestures);
        $journalPath = account_deletion_stage_files($pdo, $userId, $requestId, $personalGestureIds);

        $counts = [
            'roomsTransferred' => 0,
            'personalGesturesRemoved' => count($personalGestureIds),
            'activeEdgesRemoved' => 0,
            'retainedIdentitySnapshotsAnonymized' => 0,
        ];
        if ($roomIds !== []) {
            $transfer = $pdo->prepare('UPDATE rooms SET owner_id=? WHERE owner_id=?');
            $transfer->execute([$roomSuccessorUserId, $userId]);
            $counts['roomsTransferred'] = $transfer->rowCount();
        }

        if ($personalGestureIds !== []) {
            $placeholders = implode(',', array_fill(0, count($personalGestureIds), '?'));
            $pdo->prepare("UPDATE gestures SET deleted_at=CURRENT_TIMESTAMP,is_public=0,active_catalog_key=NULL,creator_credit='[Deleted User]',updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id IN ({$placeholders})")
                ->execute($personalGestureIds);
            foreach ($personalGestures as $gesture) {
                if (function_exists('gesture_package_cleanup_deleted')) {
                    gesture_package_cleanup_deleted($pdo, (string)$gesture['public_id']);
                }
            }
        }
        $published = $pdo->prepare("UPDATE gestures SET creator_credit='[Deleted User]',updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE owner_user_id=? AND is_public=1 AND deleted_at IS NULL");
        $published->execute([$userId]);

        foreach ([
            ['avatar_hidden_preferences', '(viewer_user_id=? OR target_user_id=?)', [$userId, $userId]],
            ['gesture_custom_order', 'user_id=?', [$userId]],
            ['gesture_hidden', 'user_id=?', [$userId]],
            ['gesture_operation_requests', 'user_id=?', [$userId]],
            ['gesture_preferences', 'user_id=?', [$userId]],
            ['gesture_sender_media_hidden', '(viewer_user_id=? OR target_user_id=?)', [$userId, $userId]],
            ['gesture_downloads', 'user_id=?', [$userId]],
            ['user_capability_grants', 'user_id=?', [$userId]],
            ['user_staff_capability_grants', 'user_id=?', [$userId]],
            ['personal_mutes', '(muter_user_id=? OR muted_user_id=?)', [$userId, $userId]],
            ['user_blocks', '(blocker_user_id=? OR blocked_user_id=?)', [$userId, $userId]],
            ['private_message_clears', 'user_id=?', [$userId]],
            ['voice_webcam_preferences', 'user_id=?', [$userId]],
            ['webcam_audience_recipients', 'recipient_user_id=?', [$userId]],
            ['network_ban_previews', 'owner_user_id=?', [$userId]],
            ['outside_content_confirmations', 'actor_user_id=?', [$userId]],
            ['build_000051_upgrade_previews', 'owner_user_id=?', [$userId]],
            ['moderation_case_assignments', 'assignee_user_id=?', [$userId]],
            ['room_effects', 'started_by_user_id=?', [$userId]],
        ] as [$table, $where, $params]) {
            $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, $table, $where, $params);
        }

        account_deletion_update($pdo, 'registration_invitations', "status='cancelled',revision=revision+1", "created_by_user_id=? AND status='active'", [$userId]);
        account_deletion_update($pdo, 'moderation_cases', "status='closed',private_note=NULL,updated_at=CURRENT_TIMESTAMP,closed_at=CURRENT_TIMESTAMP,revision=revision+1", "subject_user_id=? AND status IN ('received','under-review') AND case_type IN ('trusted-review','capability-request','appeal')", [$userId]);
        account_deletion_update($pdo, 'moderation_notices', 'read_at=COALESCE(read_at,CURRENT_TIMESTAMP)', 'user_id=?', [$userId]);
        account_deletion_update(
            $pdo,
            'p2p_transfer_offers',
            "status='failed',status_reason='Account unavailable',updated_at=CURRENT_TIMESTAMP",
            "(sender_user_id=? OR recipient_user_id=?) AND status IN ('offered','accepted','connecting','transferring','paused')",
            [$userId, $userId]
        );
        if (function_exists('server_media_revoke_user_uploads')) {
            $counts['activeEdgesRemoved'] += server_media_revoke_user_uploads($pdo, $userId, 'Account deleted');
        }

        if (account_deletion_table_exists($pdo, 'participants')) {
            $participantIdsStatement = $pdo->prepare('SELECT id FROM participants WHERE user_id=?');
            $participantIdsStatement->execute([$userId]);
            $participantIds = array_map('intval', $participantIdsStatement->fetchAll(PDO::FETCH_COLUMN));
            if ($participantIds !== []) {
                $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
                account_deletion_update($pdo, 'avatar_relationship_requests', "status='rejected',active_request_key=NULL,resolution_reason='account-unavailable',resolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP", "status='pending' AND (requester_participant_id IN ({$placeholders}) OR target_participant_id IN ({$placeholders}))", array_merge($participantIds, $participantIds));
                account_deletion_update($pdo, 'avatar_relationship_members', "membership_status='ended',active_participant_id=NULL,lap_host_participant_id=NULL,updated_at=CURRENT_TIMESTAMP", "participant_id IN ({$placeholders}) AND membership_status='active'", $participantIds);
                account_deletion_update($pdo, 'avatar_relationships', "status='dissolved',dissolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1", "status='active' AND id IN (SELECT relationship_id FROM avatar_relationship_members WHERE participant_id IN ({$placeholders}))", $participantIds);
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'voice_sessions', "participant_id IN ({$placeholders})", $participantIds);
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'media_signal_clients', "participant_id IN ({$placeholders})", $participantIds);
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'media_signals', "from_participant_id IN ({$placeholders}) OR to_participant_id IN ({$placeholders})", array_merge($participantIds, $participantIds));
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'webcam_audience_sessions', "sender_participant_id IN ({$placeholders}) OR sender_user_id=?", array_merge($participantIds, [$userId]));
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'webcam_audience_recipients', "sender_participant_id IN ({$placeholders})", $participantIds);
                $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'room_effects', "started_by_participant_id IN ({$placeholders})", $participantIds);
                account_deletion_update($pdo, 'game_sessions', 'ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP)', "started_by_participant_id IN ({$placeholders}) AND ended_at IS NULL", $participantIds);
            }
            $participantUpdate = $pdo->prepare(
                "UPDATE participants SET display_name='[Deleted User]',profile_location='',profile_about='',profile_visibility='private',
                 email_changed_at=NULL,password_changed_at=NULL,avatar_path=NULL,avatar_identity=NULL,
                 avatar_source_width_px=NULL,avatar_source_height_px=NULL,avatar_orientation='original',avatar_display_size_px=NULL,
                 webcam_display_width_px=NULL,webcam_display_height_px=NULL,aura_effect=NULL,join_token=?,webcam_path=NULL,
                 webcam_enabled=0,linked_to_participant_id=NULL,link_mode='normal',last_seen_at='1970-01-01 00:00:00'
                 WHERE id=? AND user_id=?"
            );
            foreach ($participantIds as $participantId) {
                $participantUpdate->execute([
                    'deleted-' . bin2hex(random_bytes(24)),
                    $participantId,
                    $userId,
                ]);
                $counts['retainedIdentitySnapshotsAnonymized'] += $participantUpdate->rowCount();
            }
        }

        foreach ([
            ['messages', "display_name='[Deleted User]',avatar_path=NULL,avatar_url=NULL", 'user_id=?'],
            ['community_messages', "display_name='[Deleted User]',avatar_path=NULL,avatar_url=NULL", 'user_id=?'],
            ['game_chat_messages', "display_name='[Deleted User]'", 'user_id=?'],
        ] as [$table, $set, $where]) {
            $counts['retainedIdentitySnapshotsAnonymized'] += account_deletion_update($pdo, $table, $set, $where, [$userId]);
        }

        account_deletion_update($pdo, 'private_voice_chats', "status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP,version=version+1", "created_by_user_id=? AND status='active'", [$userId]);
        account_deletion_update($pdo, 'private_voice_members', "membership_status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP)", "user_id=? AND membership_status='active'", [$userId]);
        account_deletion_update($pdo, 'private_voice_invitations', "status='revoked',resolved_at=CURRENT_TIMESTAMP,resolution_user_id=NULL", "status='pending' AND (inviter_user_id=? OR recipient_user_id=?)", [$userId, $userId]);
        account_deletion_update($pdo, 'private_voice_join_requests', "status='rejected',resolved_at=CURRENT_TIMESTAMP,resolution_user_id=NULL", "status='pending' AND requester_user_id=?", [$userId]);

        $deviceIds = [];
        if (account_deletion_table_exists($pdo, 'message_protection_devices')) {
            $deviceStatement = $pdo->prepare('SELECT device_id FROM message_protection_devices WHERE user_id=?');
            $deviceStatement->execute([$userId]);
            $deviceIds = array_map('strval', $deviceStatement->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($deviceIds !== []) {
            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'message_protection_key_envelopes', "recipient_device_id IN ({$placeholders}) OR sender_device_id IN ({$placeholders})", array_merge($deviceIds, $deviceIds));
            $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'message_protection_device_approvals', "user_id=? OR approver_device_id IN ({$placeholders}) OR target_device_id IN ({$placeholders})", array_merge([$userId], $deviceIds, $deviceIds));
        }
        $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'message_protection_devices', 'user_id=?', [$userId]);
        $counts['activeEdgesRemoved'] += account_deletion_delete($pdo, 'message_protection_recovery', 'user_id=?', [$userId]);

        account_deletion_update($pdo, 'game_lobbies', "user1_id=CASE WHEN user1_id=? THEN NULL ELSE user1_id END,user2_id=CASE WHEN user2_id=? THEN NULL ELSE user2_id END,status=CASE WHEN status IN ('waiting','active') THEN 'ended' ELSE status END,updated_at=CURRENT_TIMESTAMP", '(user1_id=? OR user2_id=?)', [$userId, $userId, $userId, $userId]);

        $identity = account_deletion_internal_identity($opaqueIdentity);
        $toolLogRedaction = $pdo->prepare(
            "UPDATE tool_logs
             SET detail=REPLACE(REPLACE(REPLACE(COALESCE(detail,''),?,'[Deleted User]'),?,'[Deleted User]'),?,'[Deleted User]')
             WHERE actor_user_id=? OR target_user_id=?"
        );
        $toolLogRedaction->execute([
            (string)$user['email'],
            (string)$user['username'],
            (string)$user['display_name'],
            $userId,
            $userId,
        ]);
        account_deletion_delete($pdo, 'member_display_name_history', 'user_id=?', [$userId]);
        account_deletion_delete($pdo, 'member_profile_requests', 'user_id=?', [$userId]);
        account_deletion_update($pdo, 'member_profiles', "profile_name=NULL,location=NULL,about_me=NULL,public_contact_email=NULL,website=NULL,interests=NULL,discord_username=NULL,discord_visible=0,profile_version=profile_version+1,updated_at=CURRENT_TIMESTAMP", 'user_id=?', [$userId]);
        if (account_deletion_table_exists($pdo, 'member_identity_names')) {
            $pdo->prepare('DELETE FROM member_identity_names WHERE user_id=?')->execute([$userId]);
            $identityName = $pdo->prepare(
                'INSERT INTO member_identity_names (canonical_name,user_id,name_kind) VALUES (?,?,?)'
            );
            $identityName->execute([strtolower($identity['username']), $userId, 'username']);
            $identityName->execute([strtolower($identity['display_name']), $userId, 'display_name']);
        }

        account_deletion_delete($pdo, 'user_trust', 'user_id=?', [$userId]);
        $newPassword = password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT);
        $userUpdate = $pdo->prepare(
            "UPDATE users SET email=?,username=?,password_hash=?,recovery_code_hash=NULL,recovery_code_suffix=NULL,
             display_name=?,role='user',avatar_path=NULL,avatar_identity=NULL,avatar_source_width_px=NULL,
             avatar_source_height_px=NULL,avatar_orientation='original',avatar_display_size_px=NULL,
             webcam_display_width_px=NULL,webcam_display_height_px=NULL,webcam_show_preference=0,
             webcam_receive_preference=0,aura_effect=NULL,current_room_id=NULL,last_seen_at=NULL,
             profile_location='',profile_about='',profile_visibility='private',email_changed_at=NULL,password_changed_at=NULL
             WHERE id=?"
        );
        $userUpdate->execute([$identity['email'], $identity['username'], $newPassword, $identity['display_name'], $userId]);

        $foundation = retention_lifecycle_ensure_user($pdo, $userId);
        $pdo->prepare(
            'UPDATE account_lifecycle_foundations SET session_generation=session_generation+1,sessions_revoked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE user_id=?'
        )->execute([$userId]);
        $completedAt = gmdate('Y-m-d H:i:s');
        $result = [
            'deleted' => true,
            'completedAt' => $completedAt,
            'roomsTransferred' => $counts['roomsTransferred'],
            'personalGesturesRemoved' => $counts['personalGesturesRemoved'],
            'activeEdgesRemoved' => $counts['activeEdgesRemoved'],
            'retainedIdentitySnapshotsAnonymized' => $counts['retainedIdentitySnapshotsAnonymized'],
            'idempotentReplay' => false,
        ];
        $resultJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO account_deletions
             (user_id,opaque_identity,request_id,request_sha256,room_successor_user_id,disposition_json,completed_at)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$userId, $opaqueIdentity, $requestId, $requestSha, $roomSuccessorUserId, $resultJson, $completedAt]);
        $pdo->prepare(
            'INSERT INTO account_lifecycle_idempotency (request_id,operation,user_id,result_json) VALUES (?,?,?,?)'
        )->execute([$requestId, ACCOUNT_DELETION_OPERATION, $userId, $resultJson]);
        log_tool($pdo, $userId, 'account_delete', $userId, null, json_encode([
            'request_sha256' => strtoupper(hash('sha256', $requestId)),
            'rooms_transferred' => $counts['roomsTransferred'],
            'personal_gestures_removed' => $counts['personalGesturesRemoved'],
            'active_edges_removed' => $counts['activeEdgesRemoved'],
            'snapshots_anonymized' => $counts['retainedIdentitySnapshotsAnonymized'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        database_transaction_commit($pdo, $transaction);
        $committed = true;
        account_deletion_finish_file_journal($journalPath, true);
        return $result;
    } catch (SecurityPolicyViolation $error) {
        database_transaction_rollback($pdo, $transaction);
        account_deletion_finish_file_journal($journalPath, false);
        throw new AccountDeletionException($error->getMessage(), 'ACCOUNT_DELETION_RECENT_AUTH_REQUIRED', $error->httpStatus);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        account_deletion_finish_file_journal($journalPath, false);
        if ($error instanceof AccountDeletionException) throw $error;
        throw new AccountDeletionException('The account could not be deleted safely.', 'ACCOUNT_DELETION_FAILED', 500);
    } finally {
        if (!$committed) account_deletion_finish_file_journal($journalPath, false);
    }
}
