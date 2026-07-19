<?php
declare(strict_types=1);

const AVATAR_VISIBILITY_SCOPE_EXACT = 'avatar';
const AVATAR_VISIBILITY_SCOPE_USER = 'user';
const AVATAR_VISIBILITY_PLACEHOLDER_LABEL = 'Avatar hidden by you';

function avatar_visibility_cache_clear(?int $viewerUserId = null, ?int $targetUserId = null): void {
    $cache = &$GLOBALS['chatspace_avatar_visibility_cache'];
    if (!is_array($cache)) $cache = [];
    if ($viewerUserId === null && $targetUserId === null) {
        $cache = [];
        return;
    }
    foreach (array_keys($cache) as $key) {
        [, $viewer, $target] = array_map('intval', explode(':', (string)$key));
        if (($viewerUserId === null || $viewer === $viewerUserId)
            && ($targetUserId === null || $target === $targetUserId)) {
            unset($cache[$key]);
        }
    }
}

function avatar_identity_is_valid(mixed $identity): bool {
    return is_string($identity) && preg_match('/^[a-f0-9]{64}$/', $identity) === 1;
}

function avatar_identity_for_source(string $path, ?string $absoluteFile = null): string {
    if ($absoluteFile && is_file($absoluteFile)) {
        $identity = hash_file('sha256', $absoluteFile);
        if (is_string($identity)) return $identity;
    }
    if (str_starts_with($path, 'preset:')) {
        return hash('sha256', "chatspace-avatar-preset\0" . $path);
    }
    return hash('sha256', "chatspace-avatar-legacy\0" . random_bytes(32));
}

function avatar_source_file(string $path): ?string {
    if ($path === '' || str_starts_with($path, 'preset:') || str_starts_with($path, 'data:')) return null;
    $urlPath = (string)(parse_url($path, PHP_URL_PATH) ?: '');
    if ($urlPath === '' || !str_starts_with($urlPath, '/assets/')) return null;
    $root = dirname(__DIR__);
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($urlPath, '/'));
    return is_file($absolute) ? $absolute : null;
}

function avatar_source_dimensions(string $path, ?string $absoluteFile = null): array {
    if (str_starts_with($path, 'preset:')) return ['width' => 120, 'height' => 120];
    $file = $absoluteFile ?: avatar_source_file($path);
    $dimensions = $file ? @getimagesize($file) : false;
    return [
        'width' => max(1, (int)($dimensions[0] ?? 150)),
        'height' => max(1, (int)($dimensions[1] ?? 150)),
    ];
}

function avatar_identity_backfill(PDO $pdo): void {
    $users = $pdo->query(
        'SELECT id, avatar_path, avatar_identity, avatar_source_width_px, avatar_source_height_px FROM users ORDER BY id ASC'
    )->fetchAll();
    $updateUser = $pdo->prepare(
        'UPDATE users SET avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ? WHERE id = ?'
    );
    $updateParticipants = $pdo->prepare(
        'UPDATE participants SET avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ? WHERE user_id = ?'
    );
    foreach ($users as $user) {
        $path = (string)($user['avatar_path'] ?? 'preset:Default');
        $file = avatar_source_file($path);
        $identity = avatar_identity_is_valid($user['avatar_identity'] ?? null)
            ? (string)$user['avatar_identity']
            : avatar_identity_for_source($path, $file);
        $dimensions = avatar_source_dimensions($path, $file);
        $width = (int)($user['avatar_source_width_px'] ?? 0) > 0
            ? (int)$user['avatar_source_width_px'] : $dimensions['width'];
        $height = (int)($user['avatar_source_height_px'] ?? 0) > 0
            ? (int)$user['avatar_source_height_px'] : $dimensions['height'];
        $updateUser->execute([$identity, $width, $height, (int)$user['id']]);
        $updateParticipants->execute([$identity, $width, $height, (int)$user['id']]);
    }
}

function avatar_identity_ensure_user(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, avatar_path, avatar_identity, avatar_source_width_px, avatar_source_height_px, avatar_visibility_version
           FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) throw new RuntimeException('Avatar owner not found.');
    $path = (string)($user['avatar_path'] ?? 'preset:Default');
    $file = avatar_source_file($path);
    $identity = avatar_identity_is_valid($user['avatar_identity'] ?? null)
        ? (string)$user['avatar_identity']
        : avatar_identity_for_source($path, $file);
    $dimensions = avatar_source_dimensions($path, $file);
    $width = (int)($user['avatar_source_width_px'] ?? 0) > 0
        ? (int)$user['avatar_source_width_px'] : $dimensions['width'];
    $height = (int)($user['avatar_source_height_px'] ?? 0) > 0
        ? (int)$user['avatar_source_height_px'] : $dimensions['height'];
    if ($identity !== ($user['avatar_identity'] ?? null)
        || $width !== (int)($user['avatar_source_width_px'] ?? 0)
        || $height !== (int)($user['avatar_source_height_px'] ?? 0)) {
        $pdo->prepare(
            'UPDATE users SET avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ? WHERE id = ?'
        )->execute([$identity, $width, $height, $userId]);
        $pdo->prepare(
            'UPDATE participants SET avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ? WHERE user_id = ?'
        )->execute([$identity, $width, $height, $userId]);
    }
    return [
        'identity' => $identity,
        'width' => $width,
        'height' => $height,
        'version' => max(1, (int)($user['avatar_visibility_version'] ?? 1)),
    ];
}

function avatar_identity_apply(
    PDO $pdo,
    int $userId,
    string $path,
    string $identity,
    int $width,
    int $height
): void {
    if (!avatar_identity_is_valid($identity)) throw new InvalidArgumentException('Invalid avatar identity.');
    $width = max(1, $width);
    $height = max(1, $height);
    $pdo->prepare(
        'UPDATE users SET avatar_path = ?, avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ? WHERE id = ?'
    )->execute([$path, $identity, $width, $height, $userId]);
    $pdo->prepare(
        'UPDATE participants SET avatar_path = ?, avatar_identity = ?, avatar_source_width_px = ?, avatar_source_height_px = ?, webcam_path = NULL, webcam_enabled = 0 WHERE user_id = ?'
    )->execute([$path, $identity, $width, $height, $userId]);
    $staleStmt = $pdo->prepare(
        'SELECT DISTINCT viewer_user_id FROM avatar_hidden_preferences
          WHERE target_user_id = ? AND scope = ? AND (avatar_identity IS NULL OR avatar_identity <> ?)'
    );
    $staleStmt->execute([$userId, AVATAR_VISIBILITY_SCOPE_EXACT, $identity]);
    $staleViewers = array_map('intval', $staleStmt->fetchAll(PDO::FETCH_COLUMN));
    $pdo->prepare(
        'DELETE FROM avatar_hidden_preferences
          WHERE target_user_id = ? AND scope = ? AND (avatar_identity IS NULL OR avatar_identity <> ?)'
    )->execute([$userId, AVATAR_VISIBILITY_SCOPE_EXACT, $identity]);
    $increment = $pdo->prepare(
        'UPDATE users SET avatar_visibility_version = avatar_visibility_version + 1 WHERE id = ?'
    );
    foreach ($staleViewers as $viewerUserId) $increment->execute([$viewerUserId]);
    avatar_visibility_cache_clear(null, $userId);
}

function avatar_visibility_version(PDO $pdo, int $viewerUserId): int {
    $stmt = $pdo->prepare('SELECT avatar_visibility_version FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$viewerUserId]);
    return max(1, (int)($stmt->fetchColumn() ?: 1));
}

function avatar_visibility_preferences(PDO $pdo, int $viewerUserId): array {
    $stmt = $pdo->prepare(
        'SELECT hp.id, hp.target_user_id, hp.scope, u.display_name
           FROM avatar_hidden_preferences hp
           JOIN users u ON u.id = hp.target_user_id
          WHERE hp.viewer_user_id = ?
          ORDER BY LOWER(u.display_name) ASC, hp.scope ASC, hp.id ASC'
    );
    $stmt->execute([$viewerUserId]);
    $entries = array_map(static function(array $row): array {
        $scope = (string)$row['scope'];
        return [
            'id' => (int)$row['id'],
            'targetUserId' => (int)$row['target_user_id'],
            'displayName' => (string)$row['display_name'],
            'scope' => $scope,
            'notice' => $scope === AVATAR_VISIBILITY_SCOPE_USER
                ? 'Avatar hidden — You chose to hide avatars from this user.'
                : 'Avatar hidden — You chose to hide this avatar until it changes.',
        ];
    }, $stmt->fetchAll());
    return [
        'version' => avatar_visibility_version($pdo, $viewerUserId),
        'entries' => $entries,
    ];
}

function avatar_visibility_effective(PDO $pdo, int $viewerUserId, int $targetUserId): array {
    $cache = &$GLOBALS['chatspace_avatar_visibility_cache'];
    if (!is_array($cache)) $cache = [];
    if ($viewerUserId <= 0 || $targetUserId <= 0 || $viewerUserId === $targetUserId) {
        return ['hidden' => false, 'scope' => null, 'notice' => null];
    }
    $cacheKey = spl_object_id($pdo) . ':' . $viewerUserId . ':' . $targetUserId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $identity = avatar_identity_ensure_user($pdo, $targetUserId)['identity'];
    $stmt = $pdo->prepare(
        'SELECT scope FROM avatar_hidden_preferences
          WHERE viewer_user_id = ? AND target_user_id = ?
            AND (scope = ? OR (scope = ? AND avatar_identity = ?))
          ORDER BY CASE WHEN scope = ? THEN 0 ELSE 1 END
          LIMIT 1'
    );
    $stmt->execute([
        $viewerUserId,
        $targetUserId,
        AVATAR_VISIBILITY_SCOPE_USER,
        AVATAR_VISIBILITY_SCOPE_EXACT,
        $identity,
        AVATAR_VISIBILITY_SCOPE_USER,
    ]);
    $scope = $stmt->fetchColumn();
    if ($scope === false) {
        return $cache[$cacheKey] = ['hidden' => false, 'scope' => null, 'notice' => null];
    }
    return $cache[$cacheKey] = [
        'hidden' => true,
        'scope' => (string)$scope,
        'notice' => $scope === AVATAR_VISIBILITY_SCOPE_USER
            ? 'Avatar hidden — You chose to hide avatars from this user.'
            : 'Avatar hidden — You chose to hide this avatar until it changes.',
    ];
}

function avatar_visibility_participant_user_id(PDO $pdo, int $participantId): int {
    static $cache = [];
    if ($participantId <= 0) return 0;
    if (array_key_exists($participantId, $cache)) return $cache[$participantId];
    $stmt = $pdo->prepare('SELECT user_id FROM participants WHERE id = ? LIMIT 1');
    $stmt->execute([$participantId]);
    return $cache[$participantId] = (int)($stmt->fetchColumn() ?: 0);
}

function avatar_visibility_project_payload(PDO $pdo, int $viewerUserId, array $payload): array {
    $targetUserId = (int)($payload['user_id'] ?? $payload['avatar_owner_user_id'] ?? 0);
    if ($targetUserId <= 0 && isset($payload['participant_id'])) {
        $targetUserId = avatar_visibility_participant_user_id($pdo, (int)$payload['participant_id']);
    }
    if ($targetUserId > 0 && (array_key_exists('avatar_url', $payload) || array_key_exists('avatar_path', $payload))) {
        $policy = avatar_visibility_effective($pdo, $viewerUserId, $targetUserId);
        $payload['avatar_hidden'] = $policy['hidden'];
        $payload['avatar_hidden_scope'] = $policy['scope'];
        $payload['avatar_hidden_notice'] = $policy['notice'];
        if ($policy['hidden']) {
            if (array_key_exists('avatar_url', $payload)) $payload['avatar_url'] = null;
            if (array_key_exists('avatar_path', $payload)) $payload['avatar_path'] = null;
        }
    }
    foreach ($payload as $key => $value) {
        if (!is_array($value) || in_array($key, ['url_preview', 'reply_to', 'gesture'], true)) continue;
        $payload[$key] = array_is_list($value)
            ? array_map(fn($item) => is_array($item)
                ? avatar_visibility_project_payload($pdo, $viewerUserId, $item)
                : $item, $value)
            : avatar_visibility_project_payload($pdo, $viewerUserId, $value);
    }
    return $payload;
}

function avatar_visibility_revealed_avatars(PDO $pdo, int $viewerUserId, array $targetUserIds): array {
    $targetUserIds = array_values(array_unique(array_filter(array_map('intval', $targetUserIds))));
    if (!$targetUserIds) return [];
    $placeholders = implode(',', array_fill(0, count($targetUserIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, display_name, avatar_path, avatar_source_width_px, avatar_source_height_px
           FROM users WHERE id IN ($placeholders)"
    );
    $stmt->execute($targetUserIds);
    $revealed = [];
    foreach ($stmt->fetchAll() as $row) {
        $userId = (int)$row['id'];
        if (avatar_visibility_effective($pdo, $viewerUserId, $userId)['hidden']) continue;
        $revealed[] = [
            'user_id' => $userId,
            'display_name' => (string)$row['display_name'],
            'avatar_path' => (string)($row['avatar_path'] ?? 'preset:Default'),
            'avatar_url' => resolve_avatar((string)($row['avatar_path'] ?? 'preset:Default')),
            'avatar_source_width_px' => max(1, (int)($row['avatar_source_width_px'] ?? 150)),
            'avatar_source_height_px' => max(1, (int)($row['avatar_source_height_px'] ?? 150)),
            'avatar_hidden' => false,
            'avatar_hidden_scope' => null,
            'avatar_hidden_notice' => null,
        ];
    }
    return $revealed;
}

function avatar_visibility_mutate(PDO $pdo, int $viewerUserId, array $input): array {
    $action = (string)($input['action'] ?? '');
    $allowed = ['hide_avatar', 'show_avatar', 'hide_user', 'show_user', 'show_all'];
    if (!in_array($action, $allowed, true)) {
        return ['ok' => false, 'code' => 'AVATAR_VISIBILITY_ACTION_INVALID', 'error' => 'Unknown avatar visibility action.', 'http_status' => 400];
    }
    $expected = filter_var($input['expected_version'] ?? null, FILTER_VALIDATE_INT);
    if ($expected === false || (int)$expected < 1) {
        return ['ok' => false, 'code' => 'AVATAR_VISIBILITY_VERSION_REQUIRED', 'error' => 'Hidden avatar preferences changed. Refresh and try again.', 'http_status' => 409];
    }
    $targetUserId = (int)($input['target_user_id'] ?? 0);
    if ($action !== 'show_all' && ($targetUserId <= 0 || $targetUserId === $viewerUserId)) {
        return ['ok' => false, 'code' => 'AVATAR_VISIBILITY_TARGET_INVALID', 'error' => 'Choose another user.', 'http_status' => 400];
    }

    $ownsTransaction = !$pdo->inTransaction();
    $revealTargets = [];
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) $pdo->beginTransaction();
            else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        }
        $sql = 'SELECT avatar_visibility_version FROM users WHERE id = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$viewerUserId]);
        $rawVersion = $stmt->fetchColumn();
        if ($rawVersion === false) throw new RuntimeException('Avatar visibility owner not found.');
        $currentVersion = max(1, (int)$rawVersion);

        $identity = null;
        $scope = null;
        if ($action !== 'show_all') {
            $identity = avatar_identity_ensure_user($pdo, $targetUserId)['identity'];
            $scope = str_contains($action, 'avatar') ? AVATAR_VISIBILITY_SCOPE_EXACT : AVATAR_VISIBILITY_SCOPE_USER;
        }
        $existingStmt = $pdo->prepare(
            'SELECT id FROM avatar_hidden_preferences
              WHERE viewer_user_id = ? AND target_user_id = ? AND scope = ?
                AND ((avatar_identity IS NULL AND ? IS NULL) OR avatar_identity = ?)
              LIMIT 1'
        );
        $existing = false;
        if ($action !== 'show_all') {
            $scopeIdentity = $scope === AVATAR_VISIBILITY_SCOPE_EXACT ? $identity : null;
            $existingStmt->execute([$viewerUserId, $targetUserId, $scope, $scopeIdentity, $scopeIdentity]);
            $existing = $existingStmt->fetchColumn() !== false;
        }
        $desiredPresent = str_starts_with($action, 'hide_');
        $alreadyDesired = $action === 'show_all'
            ? !(bool)$pdo->query('SELECT 1 FROM avatar_hidden_preferences WHERE viewer_user_id = ' . $viewerUserId . ' LIMIT 1')->fetchColumn()
            : $existing === $desiredPresent;
        if ($action === 'show_all') {
            $targetStmt = $pdo->prepare('SELECT DISTINCT target_user_id FROM avatar_hidden_preferences WHERE viewer_user_id = ?');
            $targetStmt->execute([$viewerUserId]);
            $revealTargets = array_map('intval', $targetStmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif (str_starts_with($action, 'show_')) {
            $revealTargets = [$targetUserId];
        }
        if ($currentVersion !== (int)$expected && !$alreadyDesired) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return [
                'ok' => false,
                'code' => 'AVATAR_VISIBILITY_STALE',
                'error' => 'Hidden avatar preferences changed. Refresh and try again.',
                'preferences' => avatar_visibility_preferences($pdo, $viewerUserId),
                'http_status' => 409,
            ];
        }

        $changed = false;
        if (!$alreadyDesired) {
            if ($action === 'show_all') {
                $delete = $pdo->prepare('DELETE FROM avatar_hidden_preferences WHERE viewer_user_id = ?');
                $delete->execute([$viewerUserId]);
                $changed = $delete->rowCount() > 0;
            } elseif ($desiredPresent) {
                $pdo->prepare(
                    'INSERT INTO avatar_hidden_preferences (viewer_user_id, target_user_id, scope, avatar_identity, preference_key) VALUES (?,?,?,?,?)'
                )->execute([
                    $viewerUserId,
                    $targetUserId,
                    $scope,
                    $scope === AVATAR_VISIBILITY_SCOPE_EXACT ? $identity : null,
                    $scope === AVATAR_VISIBILITY_SCOPE_EXACT ? 'avatar:' . $identity : 'user',
                ]);
                $changed = true;
            } else {
                $scopeIdentity = $scope === AVATAR_VISIBILITY_SCOPE_EXACT ? $identity : null;
                $delete = $pdo->prepare(
                    'DELETE FROM avatar_hidden_preferences
                      WHERE viewer_user_id = ? AND target_user_id = ? AND scope = ?
                        AND ((avatar_identity IS NULL AND ? IS NULL) OR avatar_identity = ?)'
                );
                $delete->execute([$viewerUserId, $targetUserId, $scope, $scopeIdentity, $scopeIdentity]);
                $changed = $delete->rowCount() > 0;
            }
        }
        $nextVersion = $currentVersion + ($changed ? 1 : 0);
        if ($changed) {
            $pdo->prepare('UPDATE users SET avatar_visibility_version = ? WHERE id = ?')
                ->execute([$nextVersion, $viewerUserId]);
            avatar_visibility_cache_clear($viewerUserId, $action === 'show_all' ? null : $targetUserId);
        }
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotent' => !$changed,
            'preferences' => avatar_visibility_preferences($pdo, $viewerUserId),
            'revealedAvatars' => avatar_visibility_revealed_avatars($pdo, $viewerUserId, $revealTargets),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
