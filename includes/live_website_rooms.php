<?php
declare(strict_types=1);

const LIVE_WEBSITE_ROOMS_SETTING = 'allow_live_website_rooms';
const LIVE_WEBSITE_ROOM_CAPABILITY = 'create-temporary-live-website-room';
const LIVE_WEBSITE_ROOM_STAY_SECONDS = 600;
const LIVE_WEBSITE_ROOM_EMPTY_SECONDS = 300;
const LIVE_WEBSITE_ROOM_CREATE_COOLDOWN_SECONDS = 30;
const LIVE_WEBSITE_ROOM_EMERGENCY_DAILY_CEILING = 20;

final class LiveWebsiteRoomException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        public readonly array $projection = []
    ) {
        parent::__construct($message);
    }
}

function live_website_rooms_setting_defaults(): array
{
    return [LIVE_WEBSITE_ROOMS_SETTING => '1'];
}

function live_website_rooms_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS live_website_rooms (
                room_id INT PRIMARY KEY,
                creator_user_id INT NOT NULL,
                target_url VARCHAR(2048) NOT NULL,
                target_host VARCHAR(255) NOT NULL,
                frame_policy VARCHAR(64) NOT NULL DEFAULT 'browser-enforced',
                navigation_version INT NOT NULL DEFAULT 0,
                empty_since DATETIME DEFAULT NULL,
                successor_room_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_live_website_room_empty (empty_since),
                CONSTRAINT fk_live_website_room_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_room_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_room_successor FOREIGN KEY (successor_room_id) REFERENCES rooms(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS live_website_navigation_offers (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                request_id VARCHAR(128) NOT NULL UNIQUE,
                source_room_id INT NOT NULL,
                destination_room_id INT NOT NULL,
                creator_user_id INT NOT NULL,
                source_navigation_version INT NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_live_website_offer_source (source_room_id,expires_at),
                CONSTRAINT fk_live_website_offer_source FOREIGN KEY (source_room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_offer_destination FOREIGN KEY (destination_room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_offer_creator FOREIGN KEY (creator_user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS live_website_navigation_choices (
                offer_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                choice VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (offer_id,user_id),
                CONSTRAINT fk_live_website_choice_offer FOREIGN KEY (offer_id) REFERENCES live_website_navigation_offers(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_choice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS live_website_room_accounting (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(32) NOT NULL,
                room_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_live_website_accounting_user (user_id,created_at),
                CONSTRAINT fk_live_website_accounting_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_live_website_accounting_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS live_website_rooms (
            room_id INTEGER PRIMARY KEY,
            creator_user_id INTEGER NOT NULL,
            target_url TEXT NOT NULL,
            target_host TEXT NOT NULL,
            frame_policy TEXT NOT NULL DEFAULT 'browser-enforced',
            navigation_version INTEGER NOT NULL DEFAULT 0,
            empty_since TEXT DEFAULT NULL,
            successor_room_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(creator_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(successor_room_id) REFERENCES rooms(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_live_website_room_empty ON live_website_rooms(empty_since)',
        "CREATE TABLE IF NOT EXISTS live_website_navigation_offers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT NOT NULL UNIQUE,
            source_room_id INTEGER NOT NULL,
            destination_room_id INTEGER NOT NULL,
            creator_user_id INTEGER NOT NULL,
            source_navigation_version INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(source_room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(destination_room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(creator_user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_live_website_offer_source ON live_website_navigation_offers(source_room_id,expires_at)',
        "CREATE TABLE IF NOT EXISTS live_website_navigation_choices (
            offer_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            choice TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(offer_id,user_id),
            FOREIGN KEY(offer_id) REFERENCES live_website_navigation_offers(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS live_website_room_accounting (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            room_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_live_website_accounting_user ON live_website_room_accounting(user_id,created_at)',
    ];
}

function live_website_rooms_install_schema(PDO $pdo): void
{
    foreach (live_website_rooms_schema_statements($pdo) as $statement) $pdo->exec($statement);
}

function live_website_rooms_schema_valid(PDO $pdo): bool
{
    $required = [
        'live_website_rooms' => ['room_id', 'creator_user_id', 'target_url', 'target_host', 'frame_policy', 'navigation_version', 'empty_since', 'successor_room_id'],
        'live_website_navigation_offers' => ['id', 'request_id', 'source_room_id', 'destination_room_id', 'creator_user_id', 'source_navigation_version', 'expires_at'],
        'live_website_navigation_choices' => ['offer_id', 'user_id', 'choice'],
        'live_website_room_accounting' => ['id', 'user_id', 'action', 'room_id', 'created_at'],
    ];
    foreach ($required as $table => $columns) {
        if (!database_migration_table_exists($pdo, $table) || !database_migration_has_columns($pdo, $table, $columns)) return false;
    }
    return true;
}

function live_website_rooms_install_settings(PDO $pdo): int
{
    $inserted = 0;
    foreach (live_website_rooms_setting_defaults() as $key => $value) {
        $statement = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
        $statement->execute([$key]);
        if ($statement->fetchColumn() !== false) continue;
        set_app_setting($pdo, $key, $value);
        $inserted++;
    }
    return $inserted;
}

function live_website_rooms_settings_valid(PDO $pdo): bool
{
    $statement = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
    $statement->execute([LIVE_WEBSITE_ROOMS_SETTING]);
    return in_array((string)$statement->fetchColumn(), ['0', '1'], true);
}

function database_migration_apply_build_000063_live_website_rooms(PDO $pdo, array $context = []): array
{
    live_website_rooms_install_schema($pdo);
    return ['inserted_settings' => live_website_rooms_install_settings($pdo)];
}

function database_migration_validate_build_000063_live_website_rooms(PDO $pdo, array $context = []): bool
{
    return live_website_rooms_schema_valid($pdo) && live_website_rooms_settings_valid($pdo);
}

function live_website_rooms_install_capability_catalog(PDO $pdo): array
{
    moderation_identity_upsert_catalog($pdo);
    $owner = moderation_identity_owner($pdo);
    if ($owner === null) return ['owner_grant_created' => false, 'owner_present' => false];

    $trust = $pdo->prepare('SELECT trust_state FROM user_trust WHERE user_id=? LIMIT 1');
    $trust->execute([(int)$owner['userId']]);
    if ((string)$trust->fetchColumn() !== 'trusted') {
        return ['owner_grant_created' => false, 'owner_present' => true];
    }

    $existing = $pdo->prepare('SELECT enabled FROM user_capability_grants WHERE user_id=? AND capability_id=? LIMIT 1');
    $existing->execute([(int)$owner['userId'], LIVE_WEBSITE_ROOM_CAPABILITY]);
    if ($existing->fetchColumn() !== false) {
        return ['owner_grant_created' => false, 'owner_present' => true];
    }

    $pdo->prepare(
        'INSERT INTO user_capability_grants
            (user_id,capability_id,enabled,revision,granted_by_user_id)
         VALUES (?,?,1,1,?)'
    )->execute([(int)$owner['userId'], LIVE_WEBSITE_ROOM_CAPABILITY, (int)$owner['userId']]);
    return ['owner_grant_created' => true, 'owner_present' => true];
}

function live_website_rooms_capability_catalog_valid(PDO $pdo): bool
{
    $catalog = $pdo->prepare(
        'SELECT available,implementation_owner FROM moderation_capability_catalog WHERE capability_id=? LIMIT 1'
    );
    $catalog->execute([LIVE_WEBSITE_ROOM_CAPABILITY]);
    $row = $catalog->fetch();
    if (!is_array($row)
        || empty($row['available'])
        || (string)$row['implementation_owner'] !== 'live-website-rooms') {
        return false;
    }

    $owner = moderation_identity_owner($pdo);
    if ($owner === null) return true;
    $trust = $pdo->prepare('SELECT trust_state FROM user_trust WHERE user_id=? LIMIT 1');
    $trust->execute([(int)$owner['userId']]);
    if ((string)$trust->fetchColumn() !== 'trusted') return true;
    $grant = $pdo->prepare('SELECT 1 FROM user_capability_grants WHERE user_id=? AND capability_id=? LIMIT 1');
    $grant->execute([(int)$owner['userId'], LIVE_WEBSITE_ROOM_CAPABILITY]);
    return (bool)$grant->fetchColumn();
}

function database_migration_apply_build_000063_live_website_capability_catalog(PDO $pdo, array $context = []): array
{
    return live_website_rooms_install_capability_catalog($pdo);
}

function database_migration_validate_build_000063_live_website_capability_catalog(PDO $pdo, array $context = []): bool
{
    return live_website_rooms_capability_catalog_valid($pdo);
}

function live_website_rooms_enabled(PDO $pdo): bool
{
    return app_setting($pdo, LIVE_WEBSITE_ROOMS_SETTING, '1') === '1';
}

function live_website_rooms_is_admin(array $user): bool
{
    return in_array((string)($user['role'] ?? 'user'), ['admin', 'developer'], true);
}

function live_website_rooms_active_count(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM live_website_rooms WHERE is_official=0')->fetchColumn();
}

function live_website_rooms_header_values(array $headers, string $name): array
{
    $values = [];
    foreach ($headers as $header) {
        if (!is_string($header) || stripos($header, $name . ':') !== 0) continue;
        $values[] = trim(substr($header, strlen($name) + 1));
    }
    return $values;
}

function live_website_rooms_frame_policy(array $headers): string
{
    foreach (live_website_rooms_header_values($headers, 'X-Frame-Options') as $value) {
        $normalized = strtolower(trim($value));
        if ($normalized !== '' && $normalized !== 'allowall') {
            throw new LiveWebsiteRoomException('This website does not allow safe embedding. Choose another website.', 'LIVE_WEBSITE_FRAME_POLICY_BLOCKED', 422);
        }
    }
    $framePolicy = 'browser-enforced';
    foreach (live_website_rooms_header_values($headers, 'Content-Security-Policy') as $value) {
        if (!preg_match('/(?:^|;)\s*frame-ancestors\s+([^;]+)/i', $value, $match)) continue;
        $tokens = preg_split('/\s+/', strtolower(trim((string)$match[1]))) ?: [];
        if (!in_array('*', $tokens, true)) {
            throw new LiveWebsiteRoomException('This website does not allow safe embedding. Choose another website.', 'LIVE_WEBSITE_FRAME_POLICY_BLOCKED', 422);
        }
        $framePolicy = 'csp-frame-ancestors-wildcard';
    }
    return $framePolicy;
}

function live_website_rooms_validate_target(string $url): array
{
    $target = security_remote_target($url);
    if ($target['scheme'] !== 'https') {
        throw new LiveWebsiteRoomException('Live Website Rooms require an HTTPS address.', 'LIVE_WEBSITE_HTTPS_REQUIRED', 422);
    }
    try {
        $fetched = security_fetch_remote_url($target['url'], 512 * 1024, 'text/html,application/xhtml+xml,*/*;q=0.2', [
            'redirects' => 3,
            'timeout' => 12,
            'user_agent' => 'ChatSpaceCE-LiveWebsitePreflight/1.0',
        ]);
    } catch (SecurityPolicyViolation $error) {
        throw new LiveWebsiteRoomException($error->getMessage(), 'LIVE_WEBSITE_TARGET_UNSAFE', $error->getCode() >= 400 ? $error->getCode() : 400);
    }
    $final = security_remote_target((string)$fetched['url']);
    if ($final['scheme'] !== 'https') {
        throw new LiveWebsiteRoomException('The website redirected away from HTTPS.', 'LIVE_WEBSITE_REDIRECT_UNSAFE', 422);
    }
    $headers = (array)($fetched['headers'] ?? []);
    $framePolicy = live_website_rooms_frame_policy($headers);
    $contentType = strtolower((string)($fetched['content_type'] ?? ''));
    if ($contentType !== '' && !str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
        throw new LiveWebsiteRoomException('Choose an HTTPS web page rather than a file or download.', 'LIVE_WEBSITE_HTML_REQUIRED', 422);
    }
    $title = '';
    if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', (string)$fetched['body'], $match)) {
        $title = trim(html_entity_decode(strip_tags((string)$match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($title === '') $title = preg_replace('/^www\./i', '', (string)$final['host']) ?: 'Live Website';
    $title = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title) ?? 'Live Website';
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 90, 'UTF-8') : substr($title, 0, 90);
    return [
        'url' => (string)$fetched['url'],
        'host' => (string)$final['host'],
        'title' => $title,
        'framePolicy' => $framePolicy,
        'previewHtml' => (string)$fetched['body'],
    ];
}

function live_website_rooms_require_creation(PDO $pdo, array $user): void
{
    if (!live_website_rooms_enabled($pdo)) {
        throw new LiveWebsiteRoomException('Live Website Rooms are disabled for this installation.', 'LIVE_WEBSITE_ROOMS_DISABLED', 403);
    }
    try {
        moderation_identity_require_capability($pdo, (int)$user['id'], LIVE_WEBSITE_ROOM_CAPABILITY);
    } catch (ModerationIdentityPolicyException $error) {
        throw new LiveWebsiteRoomException($error->getMessage(), $error->errorCode, $error->httpStatus, $error->projection);
    }
    $recent = $pdo->prepare('SELECT created_at FROM live_website_room_accounting WHERE user_id=? AND action=? ORDER BY id DESC LIMIT 1');
    $recent->execute([(int)$user['id'], 'create']);
    $last = $recent->fetchColumn();
    if ($last !== false && strtotime((string)$last) > time() - LIVE_WEBSITE_ROOM_CREATE_COOLDOWN_SECONDS) {
        throw new LiveWebsiteRoomException('Wait briefly before creating another Live Website Room.', 'LIVE_WEBSITE_ROOM_CREATE_COOLDOWN', 429);
    }
    $daily = $pdo->prepare('SELECT COUNT(*) FROM live_website_room_accounting WHERE user_id=? AND action=? AND created_at>=?');
    $daily->execute([(int)$user['id'], 'create', gmdate('Y-m-d H:i:s', time() - 86400)]);
    if ((int)$daily->fetchColumn() >= LIVE_WEBSITE_ROOM_EMERGENCY_DAILY_CEILING) {
        throw new LiveWebsiteRoomException('The Live Website Room emergency creation ceiling has been reached.', 'LIVE_WEBSITE_ROOM_EMERGENCY_CEILING', 429);
    }
    if (!live_website_rooms_is_admin($user)) {
        $active = $pdo->prepare('SELECT COUNT(*) FROM live_website_rooms l JOIN rooms r ON r.id=l.room_id WHERE r.owner_id=? AND l.is_official=0');
        $active->execute([(int)$user['id']]);
        if ((int)$active->fetchColumn() >= 1) {
            throw new LiveWebsiteRoomException('You may own one active Live Website Room at a time.', 'LIVE_WEBSITE_ROOM_OWNER_LIMIT', 409);
        }
    }
}

function live_website_rooms_require_import_capacity(PDO $pdo, array $user): void
{
    if (live_website_rooms_is_admin($user)) return;
    $statement = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE owner_id=? AND import_url IS NOT NULL AND import_url<>''");
    $statement->execute([(int)$user['id']]);
    if ((int)$statement->fetchColumn() >= 1) {
        throw new LiveWebsiteRoomException('You may own one active imported website room at a time.', 'IMPORTED_ROOM_OWNER_LIMIT', 409);
    }
}

function live_website_rooms_create(PDO $pdo, array $user, string $url, string $requestedName = ''): array
{
    security_authorize_outside_content_or_json($pdo, $user, 'live_website_room_create', ['source' => 'live_website_rooms']);
    $target = live_website_rooms_validate_target($url);
    $owns = db_begin_write_transaction($pdo);
    try {
        live_website_rooms_require_creation($pdo, $user);
        $name = trim($requestedName) !== '' ? trim($requestedName) : (string)$target['title'];
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 90, 'UTF-8') : substr($name, 0, 90);
        $publicId = uuid_v4();
        $pdo->prepare('INSERT INTO rooms (public_id,owner_id,name) VALUES (?,?,?)')->execute([$publicId, (int)$user['id'], $name]);
        $roomId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO live_website_rooms (room_id,creator_user_id,target_url,target_host,frame_policy) VALUES (?,?,?,?,?)')->execute([
            $roomId, (int)$user['id'], $target['url'], $target['host'], $target['framePolicy'],
        ]);
        $pdo->prepare('INSERT INTO live_website_room_accounting (user_id,action,room_id) VALUES (?,?,?)')->execute([(int)$user['id'], 'create', $roomId]);
        active_session_for_room($pdo, $roomId);
        db_commit_write_transaction($pdo, $owns);
        live_website_rooms_capture_preview($pdo, $roomId, $target);
        return ['roomPublicId' => $publicId, 'roomId' => $roomId, 'name' => $name, 'targetHost' => $target['host'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode($publicId))];
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
}

function live_website_rooms_capture_preview(PDO $pdo, int $roomId, array $target): bool
{
    require_once __DIR__ . '/live_website_room_previews.php';
    $target['originalUrl'] = (string)$target['url'];
    return live_website_preview_capture($pdo, $roomId, $target);
}

function live_website_room_row(PDO $pdo, string $publicId, bool $forUpdate = false): array
{
    $sql = 'SELECT r.public_id,r.owner_id,r.name,l.* FROM rooms r JOIN live_website_rooms l ON l.room_id=r.id WHERE r.public_id=? LIMIT 1';
    if ($forUpdate && db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql);
    $statement->execute([$publicId]);
    $row = $statement->fetch();
    if (!is_array($row)) throw new LiveWebsiteRoomException('Live Website Room not found.', 'LIVE_WEBSITE_ROOM_NOT_FOUND', 404);
    return $row;
}

function live_website_room_projection(PDO $pdo, int $roomId, int $userId): ?array
{
    $statement = $pdo->prepare('SELECT r.public_id,r.owner_id,r.name,l.* FROM rooms r JOIN live_website_rooms l ON l.room_id=r.id WHERE r.id=? LIMIT 1');
    $statement->execute([$roomId]);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    $offer = $pdo->prepare('SELECT o.id,o.request_id,o.destination_room_id,o.expires_at,d.public_id AS destination_public_id,d.name AS destination_name FROM live_website_navigation_offers o JOIN rooms d ON d.id=o.destination_room_id LEFT JOIN live_website_navigation_choices c ON c.offer_id=o.id AND c.user_id=? WHERE o.source_room_id=? AND o.expires_at>CURRENT_TIMESTAMP AND c.offer_id IS NULL ORDER BY o.id DESC LIMIT 1');
    $offer->execute([$userId, $roomId]);
    $pending = $offer->fetch() ?: null;
    $isOfficial = (int)($row['is_official'] ?? 0) === 1;
    $successorPublicId = null;
    if (!empty($row['successor_room_id'])) {
        $successor = $pdo->prepare('SELECT public_id FROM rooms WHERE id=? LIMIT 1');
        $successor->execute([(int)$row['successor_room_id']]);
        $successorPublicId = $successor->fetchColumn() ?: null;
    }
    return [
        'roomPublicId' => (string)$row['public_id'],
        'name' => (string)$row['name'],
        'targetUrl' => (string)$row['target_url'],
        'targetHost' => (string)$row['target_host'],
        'framePolicy' => (string)$row['frame_policy'],
        'navigationVersion' => (int)$row['navigation_version'],
        'isOwner' => (int)$row['owner_id'] === $userId,
        'isOfficial' => $isOfficial,
        'canMakeOfficial' => (int)$row['owner_id'] === $userId && !$isOfficial && $successorPublicId === null,
        'successorRoomPublicId' => $successorPublicId,
        'pendingNavigation' => $pending ? [
            'offerId' => (int)$pending['id'],
            'destinationName' => (string)$pending['destination_name'],
            'expiresAt' => (string)$pending['expires_at'],
        ] : null,
    ];
}

function live_website_rooms_navigate(PDO $pdo, array $user, string $sourcePublicId, string $url, int $expectedVersion, string $requestId): array
{
    if (!preg_match('/^[A-Za-z0-9._:-]{12,128}$/', $requestId)) throw new LiveWebsiteRoomException('A stable navigation request identity is required.', 'LIVE_WEBSITE_NAVIGATION_REQUEST_REQUIRED', 400);
    $target = live_website_rooms_validate_target($url);
    $owns = db_begin_write_transaction($pdo);
    try {
        $existing = $pdo->prepare('SELECT d.public_id,d.name FROM live_website_navigation_offers o JOIN rooms d ON d.id=o.destination_room_id WHERE o.request_id=? AND o.creator_user_id=? LIMIT 1');
        $existing->execute([$requestId, (int)$user['id']]);
        if ($prior = $existing->fetch()) {
            db_commit_write_transaction($pdo, $owns);
            return ['idempotent' => true, 'roomPublicId' => (string)$prior['public_id'], 'name' => (string)$prior['name'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode((string)$prior['public_id']))];
        }
        $source = live_website_room_row($pdo, $sourcePublicId, true);
        if ((int)$source['owner_id'] !== (int)$user['id']) throw new LiveWebsiteRoomException('Only the current room creator may navigate the shared room.', 'LIVE_WEBSITE_OWNER_REQUIRED', 403);
        if ((int)$source['navigation_version'] !== $expectedVersion) throw new LiveWebsiteRoomException('The live website navigation changed. Refresh and try again.', 'LIVE_WEBSITE_NAVIGATION_STALE', 409, ['actualVersion' => (int)$source['navigation_version']]);
        $destinationPublicId = uuid_v4();
        $pdo->prepare('INSERT INTO rooms (public_id,owner_id,name) VALUES (?,?,?)')->execute([$destinationPublicId, (int)$user['id'], (string)$target['title']]);
        $destinationRoomId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO live_website_rooms (room_id,creator_user_id,target_url,target_host,frame_policy) VALUES (?,?,?,?,?)')->execute([$destinationRoomId, (int)$user['id'], $target['url'], $target['host'], $target['framePolicy']]);
        active_session_for_room($pdo, $destinationRoomId);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + LIVE_WEBSITE_ROOM_STAY_SECONDS);
        $pdo->prepare('INSERT INTO live_website_navigation_offers (request_id,source_room_id,destination_room_id,creator_user_id,source_navigation_version,expires_at) VALUES (?,?,?,?,?,?)')->execute([$requestId, (int)$source['room_id'], $destinationRoomId, (int)$user['id'], $expectedVersion, $expiresAt]);
        $offerId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE live_website_rooms SET navigation_version=navigation_version+1,updated_at=CURRENT_TIMESTAMP WHERE room_id=?')->execute([(int)$source['room_id']]);
        $session = active_session_for_room($pdo, (int)$source['room_id']);
        emit_event($pdo, (int)$session['id'], 'live_website_navigation_offer', ['offerId' => $offerId, 'destinationName' => (string)$target['title'], 'expiresAt' => $expiresAt]);
        db_commit_write_transaction($pdo, $owns);
        live_website_rooms_capture_preview($pdo, $destinationRoomId, $target);
        return ['idempotent' => false, 'offerId' => $offerId, 'roomPublicId' => $destinationPublicId, 'name' => (string)$target['title'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode($destinationPublicId))];
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
}

function live_website_rooms_choose_navigation(PDO $pdo, array $user, string $sourcePublicId, int $offerId, string $choice): array
{
    if (!in_array($choice, ['follow', 'stay'], true)) throw new LiveWebsiteRoomException('Choose Follow or Stay.', 'LIVE_WEBSITE_NAVIGATION_CHOICE_INVALID', 422);
    $owns = db_begin_write_transaction($pdo);
    try {
        $source = live_website_room_row($pdo, $sourcePublicId, true);
        $session = active_session_for_room($pdo, (int)$source['room_id']);
        $participant = $pdo->prepare('SELECT 1 FROM participants WHERE session_id=? AND user_id=? LIMIT 1');
        $participant->execute([(int)$session['id'], (int)$user['id']]);
        if (!$participant->fetchColumn()) throw new LiveWebsiteRoomException('Join the source room before choosing Follow or Stay.', 'LIVE_WEBSITE_PARTICIPANT_REQUIRED', 403);
        $offerSql = 'SELECT o.*,d.public_id AS destination_public_id,d.name AS destination_name FROM live_website_navigation_offers o JOIN rooms d ON d.id=o.destination_room_id WHERE o.id=? AND o.source_room_id=? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $offerSql .= ' FOR UPDATE';
        $offer = $pdo->prepare($offerSql);
        $offer->execute([$offerId, (int)$source['room_id']]);
        $row = $offer->fetch();
        if (!is_array($row) || strtotime((string)$row['expires_at']) <= time()) throw new LiveWebsiteRoomException('That Follow or Stay choice has expired.', 'LIVE_WEBSITE_NAVIGATION_CHOICE_EXPIRED', 409);
        $insert = db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO live_website_navigation_choices (offer_id,user_id,choice) VALUES (?,?,?)'
            : 'INSERT OR IGNORE INTO live_website_navigation_choices (offer_id,user_id,choice) VALUES (?,?,?)';
        $pdo->prepare($insert)->execute([$offerId, (int)$user['id'], $choice]);
        if ($choice === 'stay' && (int)$source['owner_id'] === (int)$row['creator_user_id']) {
            $pdo->prepare('UPDATE rooms SET owner_id=? WHERE id=? AND owner_id=?')->execute([(int)$user['id'], (int)$source['room_id'], (int)$row['creator_user_id']]);
            $pdo->prepare('UPDATE live_website_rooms SET creator_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE room_id=?')->execute([(int)$user['id'], (int)$source['room_id']]);
            emit_event($pdo, (int)$session['id'], 'live_website_owner_changed', ['ownerUserId' => (int)$user['id']]);
        }
        db_commit_write_transaction($pdo, $owns);
        return $choice === 'follow'
            ? ['choice' => 'follow', 'roomPublicId' => (string)$row['destination_public_id'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode((string)$row['destination_public_id']))]
            : ['choice' => 'stay', 'roomPublicId' => $sourcePublicId];
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
}

function live_website_rooms_promote_successor(PDO $pdo, array $source, int $successorId): void
{
    if ($successorId <= 0) throw new LiveWebsiteRoomException('The official room successor is invalid.', 'LIVE_WEBSITE_SUCCESSOR_INVALID', 500);
    $existing = $pdo->prepare('SELECT room_id FROM live_website_rooms WHERE room_id=? LIMIT 1');
    $existing->execute([$successorId]);
    if ($existing->fetchColumn() !== false) {
        $pdo->prepare('UPDATE live_website_rooms SET is_official=1,empty_since=NULL,updated_at=CURRENT_TIMESTAMP WHERE room_id=?')->execute([$successorId]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO live_website_rooms (room_id,creator_user_id,target_url,target_host,frame_policy,navigation_version,empty_since,successor_room_id,is_official) '
        . 'VALUES (?,?,?,?,?,0,NULL,NULL,1)'
    )->execute([
        $successorId,
        (int)$source['creator_user_id'],
        (string)$source['target_url'],
        (string)$source['target_host'],
        (string)$source['frame_policy'],
    ]);
}

function live_website_rooms_copy_successor_preview(PDO $pdo, array $source, int $successorId): void
{
    $preview = $pdo->prepare('SELECT background_thumb_path FROM rooms WHERE id=?');
    $preview->execute([(int)$source['room_id']]);
    $previewPath = (string)($preview->fetchColumn() ?: '');
    if ($previewPath !== '') {
        $pdo->prepare("UPDATE rooms SET background_thumb_path=? WHERE id=? AND (background_thumb_path IS NULL OR background_thumb_path='')")->execute([$previewPath, $successorId]);
    }
}

function live_website_rooms_make_official(PDO $pdo, array $user, string $publicId, int $expectedVersion): array
{
    $owns = db_begin_write_transaction($pdo);
    try {
        $source = live_website_room_row($pdo, $publicId, true);
        if ((int)$source['owner_id'] !== (int)$user['id']) throw new LiveWebsiteRoomException('Only the current creator may make this room official.', 'LIVE_WEBSITE_OWNER_REQUIRED', 403);
        if ((int)$source['navigation_version'] !== $expectedVersion) throw new LiveWebsiteRoomException('The room changed. Refresh and try again.', 'LIVE_WEBSITE_NAVIGATION_STALE', 409);
        if (!empty($source['successor_room_id'])) {
            $existing = $pdo->prepare('SELECT public_id,name FROM rooms WHERE id=? LIMIT 1');
            $existing->execute([(int)$source['successor_room_id']]);
            $room = $existing->fetch();
            if (!is_array($room)) throw new LiveWebsiteRoomException('The official room successor is unavailable.', 'LIVE_WEBSITE_SUCCESSOR_NOT_FOUND', 409);
            live_website_rooms_promote_successor($pdo, $source, (int)$source['successor_room_id']);
        live_website_rooms_copy_successor_preview($pdo, $source, (int)$source['successor_room_id']);
            db_commit_write_transaction($pdo, $owns);
            return ['idempotent' => true, 'roomPublicId' => (string)$room['public_id'], 'name' => (string)$room['name'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode((string)$room['public_id']))];
        }
        try {
            moderation_identity_require_capability($pdo, (int)$user['id'], 'create-regular-room');
        } catch (ModerationIdentityPolicyException $error) {
            throw new LiveWebsiteRoomException($error->getMessage(), $error->errorCode, $error->httpStatus, $error->projection);
        }
        $successorPublicId = uuid_v4();
        $pdo->prepare('INSERT INTO rooms (public_id,owner_id,name) VALUES (?,?,?)')->execute([$successorPublicId, (int)$user['id'], (string)$source['name']]);
        $successorId = (int)$pdo->lastInsertId();
        live_website_rooms_promote_successor($pdo, $source, $successorId);
        live_website_rooms_copy_successor_preview($pdo, $source, $successorId);
        active_session_for_room($pdo, $successorId);
        $pdo->prepare('UPDATE live_website_rooms SET successor_room_id=?,updated_at=CURRENT_TIMESTAMP WHERE room_id=?')->execute([$successorId, (int)$source['room_id']]);
        db_commit_write_transaction($pdo, $owns);
        return ['idempotent' => false, 'roomPublicId' => $successorPublicId, 'name' => (string)$source['name'], 'enterUrl' => app_url('/chatroom.php?id=' . rawurlencode($successorPublicId))];
    } catch (Throwable $error) {
        db_rollback_write_transaction($pdo, $owns);
        throw $error;
    }
}

function live_website_rooms_cleanup(PDO $pdo): int
{
    $cutoff = stale_cutoff($pdo);
    $rows = $pdo->prepare(
        'SELECT l.room_id,l.empty_since,l.created_at,
                MAX(CASE WHEN p.last_seen_at IS NOT NULL THEN p.last_seen_at ELSE p.joined_at END) AS latest_activity_at,
                SUM(CASE WHEN p.last_seen_at IS NOT NULL AND p.last_seen_at>=? THEN 1 ELSE 0 END) AS active_count
           FROM live_website_rooms l
      LEFT JOIN room_sessions s ON s.room_id=l.room_id
      LEFT JOIN participants p ON p.session_id=s.id
          WHERE l.is_official=0
        GROUP BY l.room_id,l.empty_since,l.created_at
       ORDER BY l.room_id'
    );
    $rows->execute([$cutoff]);
    $deleted = 0;
    foreach ($rows->fetchAll() as $row) {
        if ((int)($row['active_count'] ?? 0) > 0) {
            if ($row['empty_since'] !== null) $pdo->prepare('UPDATE live_website_rooms SET empty_since=NULL WHERE room_id=?')->execute([(int)$row['room_id']]);
            continue;
        }

        $emptySince = trim((string)($row['empty_since'] ?? ''));
        if ($emptySince === '') {
            $emptySinceTimestamp = strtotime(trim((string)($row['latest_activity_at'] ?? '')) . ' UTC');
            if ($emptySinceTimestamp === false || $emptySinceTimestamp > time()) {
                $emptySinceTimestamp = strtotime(trim((string)($row['created_at'] ?? '')) . ' UTC');
            }
            if ($emptySinceTimestamp === false || $emptySinceTimestamp > time()) $emptySinceTimestamp = time();
            $emptySince = gmdate('Y-m-d H:i:s', $emptySinceTimestamp);
            $pdo->prepare('UPDATE live_website_rooms SET empty_since=? WHERE room_id=? AND empty_since IS NULL')
                ->execute([$emptySince, (int)$row['room_id']]);
        }

        $emptySinceTimestamp = strtotime($emptySince . ' UTC');
        if ($emptySinceTimestamp === false || $emptySinceTimestamp > time() - LIVE_WEBSITE_ROOM_EMPTY_SECONDS) continue;

        $active = $pdo->prepare('SELECT COUNT(*) FROM participants p JOIN room_sessions s ON s.id=p.session_id WHERE s.room_id=? AND p.last_seen_at>=?');
        $active->execute([(int)$row['room_id'], $cutoff]);
        if ((int)$active->fetchColumn() > 0) {
            $pdo->prepare('UPDATE live_website_rooms SET empty_since=NULL WHERE room_id=?')->execute([(int)$row['room_id']]);
            continue;
        }
        $pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([(int)$row['room_id']]);
        $deleted++;
    }
    return $deleted;
}

function live_website_rooms_mark_empty_if_unoccupied(PDO $pdo, int $roomId, ?string $observedAt = null): bool
{
    if ($roomId <= 0) return false;
    $official = $pdo->prepare('SELECT is_official FROM live_website_rooms WHERE room_id=? LIMIT 1');
    $official->execute([$roomId]);
    if ((int)$official->fetchColumn() === 1) {
        $pdo->prepare('UPDATE live_website_rooms SET empty_since=NULL WHERE room_id=?')->execute([$roomId]);
        return false;
    }
    $active = $pdo->prepare(
        'SELECT COUNT(*) FROM participants p JOIN room_sessions s ON s.id=p.session_id '
        . 'WHERE s.room_id=? AND p.last_seen_at>=?'
    );
    $active->execute([$roomId, stale_cutoff($pdo)]);
    if ((int)$active->fetchColumn() > 0) {
        $pdo->prepare('UPDATE live_website_rooms SET empty_since=NULL WHERE room_id=?')->execute([$roomId]);
        return false;
    }

    $timestamp = strtotime(trim((string)$observedAt) . ' UTC');
    if ($timestamp === false || $timestamp > time()) $timestamp = time();
    $emptySince = gmdate('Y-m-d H:i:s', $timestamp);
    $update = $pdo->prepare('UPDATE live_website_rooms SET empty_since=COALESCE(empty_since,?) WHERE room_id=?');
    $update->execute([$emptySince, $roomId]);
    if ($update->rowCount() > 0) return true;
    $exists = $pdo->prepare('SELECT COUNT(*) FROM live_website_rooms WHERE room_id=? AND empty_since IS NOT NULL');
    $exists->execute([$roomId]);
    return (int)$exists->fetchColumn() === 1;
}

function live_website_rooms_apply_setting_locked(PDO $pdo, bool $enabled, bool $confirmed): array
{
    $active = live_website_rooms_active_count($pdo);
    if (!$enabled && $active > 0 && !$confirmed) throw new LiveWebsiteRoomException('Confirm closing active Live Website Rooms.', 'LIVE_WEBSITE_ROOMS_IMPACT_CONFIRMATION_REQUIRED', 409, ['activeRoomCount' => $active]);
    $stopped = 0;
    if (!$enabled && $active > 0) {
        $stopped = (int)$pdo->exec('DELETE FROM rooms WHERE id IN (SELECT room_id FROM live_website_rooms WHERE is_official=0)');
    }
    set_app_setting($pdo, LIVE_WEBSITE_ROOMS_SETTING, $enabled ? '1' : '0');
    return ['enabled' => $enabled, 'stoppedRoomCount' => $stopped];
}
