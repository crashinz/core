<?php
declare(strict_types=1);

const CANVAS_EXTENSION_ID = 'canvas';
const CANVAS_MAX_SECTIONS = 40;
const CANVAS_MAX_DOCUMENT_BYTES = 131072;
const CANVAS_MAX_REVISIONS = 50;

final class CanvasException extends RuntimeException
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

function canvas_extension_adapter(): array
{
    return [
        'id' => CANVAS_EXTENSION_ID,
        'projectionEndpoint' => '/api/canvas.php',
        'documentOwner' => 'canvas.documents',
        'commentOwner' => 'canvas.comments',
        'permissionOwner' => 'canvas.permissions',
        'publishOwner' => 'canvas.publish',
    ];
}

function canvas_schema_statements(PDO $pdo): array
{
    if (db_driver($pdo) === 'mysql') {
        return [
            "CREATE TABLE IF NOT EXISTS canvas_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                scope_key VARCHAR(96) NOT NULL UNIQUE,
                scope_type VARCHAR(16) NOT NULL,
                room_id INT DEFAULT NULL,
                title VARCHAR(160) NOT NULL DEFAULT 'Canvas',
                draft_json LONGTEXT NOT NULL,
                published_json LONGTEXT NOT NULL,
                draft_version INT NOT NULL DEFAULT 0,
                published_version INT NOT NULL DEFAULT 0,
                created_by_user_id INT DEFAULT NULL,
                updated_by_user_id INT DEFAULT NULL,
                published_by_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at DATETIME DEFAULT NULL,
                INDEX idx_canvas_documents_room (room_id),
                CONSTRAINT fk_canvas_document_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_document_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_canvas_document_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_canvas_document_publisher FOREIGN KEY (published_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS canvas_revisions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                document_id INT NOT NULL,
                revision_number INT NOT NULL,
                revision_kind VARCHAR(16) NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_sha256 VARCHAR(64) NOT NULL,
                actor_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_canvas_revisions_document (document_id, id),
                CONSTRAINT fk_canvas_revision_document FOREIGN KEY (document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_revision_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS canvas_comments (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(64) NOT NULL UNIQUE,
                document_id INT NOT NULL,
                section_public_id VARCHAR(64) NOT NULL,
                author_user_id INT DEFAULT NULL,
                body_text TEXT NOT NULL,
                version INT NOT NULL DEFAULT 1,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                removed_by_user_id INT DEFAULT NULL,
                removal_reason VARCHAR(240) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                removed_at DATETIME DEFAULT NULL,
                INDEX idx_canvas_comments_section (document_id, section_public_id, id),
                CONSTRAINT fk_canvas_comment_document FOREIGN KEY (document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_comment_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_canvas_comment_remover FOREIGN KEY (removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS canvas_permissions (
                document_id INT NOT NULL,
                user_id INT NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                can_comment TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_manage TINYINT(1) NOT NULL DEFAULT 0,
                can_publish TINYINT(1) NOT NULL DEFAULT 0,
                updated_by_user_id INT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (document_id, user_id),
                CONSTRAINT fk_canvas_permission_document FOREIGN KEY (document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_permission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_permission_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS canvas_command_receipts (
                request_id VARCHAR(96) PRIMARY KEY,
                actor_user_id INT NOT NULL,
                action_name VARCHAR(48) NOT NULL,
                document_id INT DEFAULT NULL,
                response_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_canvas_receipts_actor (actor_user_id, created_at),
                CONSTRAINT fk_canvas_receipt_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_canvas_receipt_document FOREIGN KEY (document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    return [
        "CREATE TABLE IF NOT EXISTS canvas_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            scope_key TEXT NOT NULL UNIQUE,
            scope_type TEXT NOT NULL,
            room_id INTEGER DEFAULT NULL,
            title TEXT NOT NULL DEFAULT 'Canvas',
            draft_json TEXT NOT NULL,
            published_json TEXT NOT NULL,
            draft_version INTEGER NOT NULL DEFAULT 0,
            published_version INTEGER NOT NULL DEFAULT 0,
            created_by_user_id INTEGER DEFAULT NULL,
            updated_by_user_id INTEGER DEFAULT NULL,
            published_by_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            published_at TEXT DEFAULT NULL,
            FOREIGN KEY(room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(published_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_canvas_documents_room ON canvas_documents(room_id)',
        "CREATE TABLE IF NOT EXISTS canvas_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            document_id INTEGER NOT NULL,
            revision_number INTEGER NOT NULL,
            revision_kind TEXT NOT NULL,
            snapshot_json TEXT NOT NULL,
            snapshot_sha256 TEXT NOT NULL,
            actor_user_id INTEGER DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_canvas_revisions_document ON canvas_revisions(document_id, id)',
        "CREATE TABLE IF NOT EXISTS canvas_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            document_id INTEGER NOT NULL,
            section_public_id TEXT NOT NULL,
            author_user_id INTEGER DEFAULT NULL,
            body_text TEXT NOT NULL,
            version INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active',
            removed_by_user_id INTEGER DEFAULT NULL,
            removal_reason TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            removed_at TEXT DEFAULT NULL,
            FOREIGN KEY(document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
            FOREIGN KEY(author_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY(removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_canvas_comments_section ON canvas_comments(document_id, section_public_id, id)',
        "CREATE TABLE IF NOT EXISTS canvas_permissions (
            document_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 1,
            can_comment INTEGER NOT NULL DEFAULT 0,
            can_edit INTEGER NOT NULL DEFAULT 0,
            can_manage INTEGER NOT NULL DEFAULT 0,
            can_publish INTEGER NOT NULL DEFAULT 0,
            updated_by_user_id INTEGER DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(document_id, user_id),
            FOREIGN KEY(document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        "CREATE TABLE IF NOT EXISTS canvas_command_receipts (
            request_id TEXT PRIMARY KEY,
            actor_user_id INTEGER NOT NULL,
            action_name TEXT NOT NULL,
            document_id INTEGER DEFAULT NULL,
            response_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(document_id) REFERENCES canvas_documents(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_canvas_receipts_actor ON canvas_command_receipts(actor_user_id, created_at)',
    ];
}

function canvas_install_schema(PDO $pdo): void
{
    foreach (canvas_schema_statements($pdo) as $statement) {
        $pdo->exec($statement);
    }
}

function canvas_schema_valid(PDO $pdo): bool
{
    $required = [
        'canvas_documents' => ['public_id', 'scope_key', 'draft_json', 'published_json', 'draft_version', 'published_version'],
        'canvas_revisions' => ['public_id', 'document_id', 'revision_number', 'snapshot_sha256'],
        'canvas_comments' => ['public_id', 'document_id', 'section_public_id', 'status'],
        'canvas_permissions' => ['document_id', 'user_id', 'can_view', 'can_comment', 'can_edit', 'can_manage', 'can_publish'],
        'canvas_command_receipts' => ['request_id', 'actor_user_id', 'action_name', 'response_json'],
    ];
    foreach ($required as $table => $columns) {
        if (!database_migration_table_exists($pdo, $table)) return false;
        if (array_diff($columns, database_migration_columns($pdo, $table))) return false;
    }
    return true;
}

function canvas_context(PDO $pdo, array $user, string $scope, string $roomPublicId = ''): array
{
    if ($scope === 'community') {
        return [
            'scope' => 'community',
            'scopeKey' => 'community',
            'room' => null,
            'roomId' => null,
            'manager' => moderation_identity_is_owner($pdo, (int)$user['id'])
                || in_array((string)($user['role'] ?? 'user'), ['admin', 'developer'], true),
        ];
    }
    if ($scope !== 'room' || $roomPublicId === '') {
        throw new CanvasException('Canvas context is invalid.', 'CANVAS_CONTEXT_INVALID', 400);
    }
    $stmt = $pdo->prepare(
        'SELECT r.*, rs.id AS session_id
           FROM rooms r
           JOIN room_sessions rs ON rs.room_id=r.id
          WHERE r.public_id=?
          LIMIT 1'
    );
    $stmt->execute([$roomPublicId]);
    $room = $stmt->fetch();
    if (!$room) throw new CanvasException('This room Canvas is unavailable.', 'CANVAS_ROOM_NOT_FOUND', 404);
    $member = $pdo->prepare('SELECT 1 FROM participants WHERE session_id=? AND user_id=? LIMIT 1');
    $member->execute([(int)$room['session_id'], (int)$user['id']]);
    $manager = (int)$room['owner_id'] === (int)$user['id']
        || in_array((string)($user['role'] ?? 'user'), ['admin', 'developer'], true);
    if (!$member->fetchColumn() && !$manager) {
        throw new CanvasException('Enter this room before opening its Canvas.', 'CANVAS_ROOM_MEMBERSHIP_REQUIRED', 403);
    }
    return [
        'scope' => 'room',
        'scopeKey' => 'room:' . (int)$room['id'],
        'room' => $room,
        'roomId' => (int)$room['id'],
        'manager' => $manager,
    ];
}

function canvas_document_row(PDO $pdo, string $scopeKey): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM canvas_documents WHERE scope_key=? LIMIT 1');
    $stmt->execute([$scopeKey]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function canvas_permission_projection(PDO $pdo, array $user, array $context, ?array $document): array
{
    $implicitManager = !empty($context['manager']);
    $grant = null;
    if ($document) {
        $stmt = $pdo->prepare('SELECT * FROM canvas_permissions WHERE document_id=? AND user_id=? LIMIT 1');
        $stmt->execute([(int)$document['id'], (int)$user['id']]);
        $grant = $stmt->fetch() ?: null;
    }
    $canManage = $implicitManager || !empty($grant['can_manage']);
    return [
        'view' => $implicitManager || $grant === null || !empty($grant['can_view']),
        'comment' => $implicitManager || !empty($grant['can_comment']),
        'edit' => $implicitManager || !empty($grant['can_edit']),
        'manage' => $canManage,
        'publish' => $implicitManager || !empty($grant['can_publish']),
    ];
}

function canvas_require_permission(array $permissions, string $permission): void
{
    if (empty($permissions[$permission])) {
        throw new CanvasException('This account is not authorized for that Canvas action.', 'CANVAS_PERMISSION_DENIED', 403);
    }
}

function canvas_decode_snapshot(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) && is_array($decoded['sections'] ?? null)
        ? $decoded
        : ['sections' => []];
}

function canvas_valid_reference(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (strlen($value) > 2048 || preg_match('/[ - ]/', $value)) {
        throw new CanvasException('A Canvas media or link reference is invalid.', 'CANVAS_REFERENCE_INVALID', 422);
    }
    if (str_starts_with($value, '/assets/uploads/')) return $value;
    $parts = parse_url($value);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new CanvasException('Canvas references must use safe HTTPS or an existing protected upload.', 'CANVAS_REFERENCE_INVALID', 422);
    }
    return $value;
}

function canvas_normalize_document(array $input): array
{
    $title = trim((string)($input['title'] ?? 'Canvas'));
    if ($title === '' || strlen($title) > 160 || preg_match('/[ -]/', $title)) {
        throw new CanvasException('Canvas title must be 1 to 160 plain-text characters.', 'CANVAS_TITLE_INVALID', 422);
    }
    $sections = (array)($input['sections'] ?? []);
    if (count($sections) > CANVAS_MAX_SECTIONS) {
        throw new CanvasException('Canvas supports at most 40 sections.', 'CANVAS_SECTION_LIMIT', 422);
    }
    $normalized = [];
    $seen = [];
    $total = 0;
    foreach ($sections as $index => $section) {
        if (!is_array($section)) {
            throw new CanvasException('A Canvas section is invalid.', 'CANVAS_SECTION_INVALID', 422);
        }
        $publicId = trim((string)($section['id'] ?? ''));
        if ($publicId === '') $publicId = uuid_v4();
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{15,63}$/', $publicId) || isset($seen[$publicId])) {
            throw new CanvasException('Canvas section identity is invalid or duplicated.', 'CANVAS_SECTION_ID_INVALID', 422);
        }
        $seen[$publicId] = true;
        $kind = (string)($section['kind'] ?? 'text');
        if (!in_array($kind, ['text', 'rules', 'checklist', 'links', 'media'], true)) {
            throw new CanvasException('Canvas section type is invalid.', 'CANVAS_SECTION_KIND_INVALID', 422);
        }
        $heading = trim((string)($section['heading'] ?? ''));
        $body = str_replace(["
", ""], "
", (string)($section['body'] ?? ''));
        if ($heading === '' || strlen($heading) > 160 || strlen($body) > 12000) {
            throw new CanvasException('Canvas section heading or content is too long.', 'CANVAS_SECTION_CONTENT_INVALID', 422);
        }
        $mediaUrl = canvas_valid_reference((string)($section['mediaUrl'] ?? ''));
        $total += strlen($heading) + strlen($body) + strlen($mediaUrl);
        $normalized[] = [
            'id' => $publicId,
            'kind' => $kind,
            'heading' => $heading,
            'body' => $body,
            'mediaUrl' => $mediaUrl,
            'position' => $index,
        ];
    }
    if ($total > CANVAS_MAX_DOCUMENT_BYTES) {
        throw new CanvasException('Canvas content exceeds the bounded document limit.', 'CANVAS_DOCUMENT_LIMIT', 422);
    }
    return ['title' => $title, 'snapshot' => ['sections' => $normalized]];
}

function canvas_comments_projection(PDO $pdo, int $documentId, array $published): array
{
    $sectionIds = array_fill_keys(array_map(
        static fn(array $section): string => (string)$section['id'],
        (array)($published['sections'] ?? [])
    ), true);
    if (!$sectionIds) return [];
    $stmt = $pdo->prepare(
        "SELECT c.public_id,c.section_public_id,c.author_user_id,c.body_text,c.version,
                c.created_at,c.updated_at,u.display_name AS author_name
           FROM canvas_comments c
           LEFT JOIN users u ON u.id=c.author_user_id
          WHERE c.document_id=? AND c.status='active'
          ORDER BY c.id ASC"
    );
    $stmt->execute([$documentId]);
    $comments = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!isset($sectionIds[(string)$row['section_public_id']])) continue;
        $comments[] = [
            'id' => (string)$row['public_id'],
            'sectionId' => (string)$row['section_public_id'],
            'authorId' => $row['author_user_id'] !== null ? (int)$row['author_user_id'] : null,
            'author' => (string)($row['author_name'] ?: 'Deleted member'),
            'body' => (string)$row['body_text'],
            'version' => (int)$row['version'],
            'createdAt' => (string)$row['created_at'],
            'updatedAt' => (string)$row['updated_at'],
        ];
    }
    return $comments;
}

function canvas_grants_projection(PDO $pdo, int $documentId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.user_id,p.can_view,p.can_comment,p.can_edit,p.can_manage,p.can_publish,
                u.username,u.display_name
           FROM canvas_permissions p
           JOIN users u ON u.id=p.user_id
          WHERE p.document_id=?
          ORDER BY LOWER(u.display_name),p.user_id'
    );
    $stmt->execute([$documentId]);
    return array_map(static fn(array $row): array => [
        'userId' => (int)$row['user_id'],
        'username' => (string)$row['username'],
        'displayName' => (string)$row['display_name'],
        'view' => !empty($row['can_view']),
        'comment' => !empty($row['can_comment']),
        'edit' => !empty($row['can_edit']),
        'manage' => !empty($row['can_manage']),
        'publish' => !empty($row['can_publish']),
    ], $stmt->fetchAll());
}

function canvas_projection(PDO $pdo, array $user, string $scope, string $roomPublicId = ''): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.document.projection');
    $context = canvas_context($pdo, $user, $scope, $roomPublicId);
    $document = canvas_document_row($pdo, (string)$context['scopeKey']);
    $permissions = canvas_permission_projection($pdo, $user, $context, $document);
    canvas_require_permission($permissions, 'view');

    $published = $document ? canvas_decode_snapshot((string)$document['published_json']) : ['sections' => []];
    $projection = [
        'scope' => $scope,
        'roomPublicId' => $scope === 'room' ? $roomPublicId : '',
        'documentId' => $document ? (string)$document['public_id'] : '',
        'title' => $document ? (string)$document['title'] : ($scope === 'community' ? 'Community Canvas' : 'Room Canvas'),
        'published' => $published,
        'publishedVersion' => $document ? (int)$document['published_version'] : 0,
        'publishedAt' => $document ? (string)($document['published_at'] ?? '') : '',
        'permissions' => $permissions,
        'comments' => $document ? canvas_comments_projection($pdo, (int)$document['id'], $published) : [],
    ];
    if ($document && !empty($permissions['edit'])) {
        $projection['draft'] = canvas_decode_snapshot((string)$document['draft_json']);
        $projection['draftVersion'] = (int)$document['draft_version'];
    } else {
        $projection['draft'] = null;
        $projection['draftVersion'] = $document ? (int)$document['draft_version'] : 0;
    }
    if ($document && !empty($permissions['manage'])) {
        $projection['grants'] = canvas_grants_projection($pdo, (int)$document['id']);
    }
    return $projection;
}

function canvas_request_id(string $requestId): string
{
    $requestId = trim($requestId);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $requestId)) {
        throw new CanvasException('Canvas request identity is invalid.', 'CANVAS_REQUEST_ID_INVALID', 422);
    }
    return $requestId;
}

function canvas_receipt(PDO $pdo, string $requestId, int $actorId, string $action): ?array
{
    $stmt = $pdo->prepare('SELECT actor_user_id,action_name,response_json FROM canvas_command_receipts WHERE request_id=? LIMIT 1');
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((int)$row['actor_user_id'] !== $actorId || (string)$row['action_name'] !== $action) {
        throw new CanvasException('Canvas request identity was already used for another command.', 'CANVAS_REQUEST_CONFLICT', 409);
    }
    $response = json_decode((string)$row['response_json'], true);
    return is_array($response) ? $response : null;
}

function canvas_store_receipt(PDO $pdo, string $requestId, int $actorId, string $action, ?int $documentId, array $response): void
{
    $stmt = $pdo->prepare('INSERT INTO canvas_command_receipts (request_id,actor_user_id,action_name,document_id,response_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$requestId, $actorId, $action, $documentId, json_encode($response, JSON_UNESCAPED_SLASHES)]);
}

function canvas_revision(PDO $pdo, int $documentId, int $version, string $kind, string $snapshot, int $actorId): void
{
    $stmt = $pdo->prepare('INSERT INTO canvas_revisions (public_id,document_id,revision_number,revision_kind,snapshot_json,snapshot_sha256,actor_user_id) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([uuid_v4(), $documentId, $version, $kind, $snapshot, strtoupper(hash('sha256', $snapshot)), $actorId]);
    $ids = $pdo->prepare('SELECT id FROM canvas_revisions WHERE document_id=? ORDER BY id DESC');
    $ids->execute([$documentId]);
    $all = array_map('intval', array_column($ids->fetchAll(), 'id'));
    $remove = array_slice($all, CANVAS_MAX_REVISIONS);
    if ($remove) {
        $pdo->prepare('DELETE FROM canvas_revisions WHERE id IN (' . implode(',', array_fill(0, count($remove), '?')) . ')')->execute($remove);
    }
}

function canvas_save_draft(PDO $pdo, array $user, array $input): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.document.commands');
    $context = canvas_context($pdo, $user, (string)($input['scope'] ?? ''), (string)($input['room_public_id'] ?? ''));
    $requestId = canvas_request_id((string)($input['request_id'] ?? ''));
    $actorId = (int)$user['id'];
    if ($existing = canvas_receipt($pdo, $requestId, $actorId, 'save_draft')) return $existing;
    $normalized = canvas_normalize_document((array)($input['document'] ?? []));
    $expected = max(0, (int)($input['expected_version'] ?? 0));
    $snapshot = json_encode($normalized['snapshot'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $pdo->beginTransaction();
    try {
        $document = canvas_document_row($pdo, (string)$context['scopeKey']);
        $permissions = canvas_permission_projection($pdo, $user, $context, $document);
        canvas_require_permission($permissions, 'edit');
        if (!$document) {
            if ($expected !== 0) throw new CanvasException('Canvas draft changed before this save.', 'CANVAS_STALE_WRITE', 409);
            $stmt = $pdo->prepare('INSERT INTO canvas_documents (public_id,scope_key,scope_type,room_id,title,draft_json,published_json,draft_version,published_version,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([uuid_v4(), $context['scopeKey'], $context['scope'], $context['roomId'], $normalized['title'], $snapshot, '{"sections":[]}', 1, 0, $actorId, $actorId]);
            $document = canvas_document_row($pdo, (string)$context['scopeKey']);
            $version = 1;
        } else {
            if ((int)$document['draft_version'] !== $expected) {
                throw new CanvasException('Canvas draft changed before this save. Reload and merge your work.', 'CANVAS_STALE_WRITE', 409, ['currentVersion' => (int)$document['draft_version']]);
            }
            $version = $expected + 1;
            $stmt = $pdo->prepare('UPDATE canvas_documents SET title=?,draft_json=?,draft_version=?,updated_by_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND draft_version=?');
            $stmt->execute([$normalized['title'], $snapshot, $version, $actorId, (int)$document['id'], $expected]);
            if ($stmt->rowCount() !== 1) throw new CanvasException('Canvas draft changed before this save.', 'CANVAS_STALE_WRITE', 409);
            $document['title'] = $normalized['title'];
            $document['draft_json'] = $snapshot;
            $document['draft_version'] = $version;
        }
        canvas_revision($pdo, (int)$document['id'], $version, 'draft', $snapshot, $actorId);
        $response = ['ok' => true, 'documentId' => (string)$document['public_id'], 'draftVersion' => $version];
        canvas_store_receipt($pdo, $requestId, $actorId, 'save_draft', (int)$document['id'], $response);
        log_tool($pdo, $actorId, 'canvas_save_draft', null, $context['roomId'], json_encode(['scope' => $context['scope'], 'version' => $version]));
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function canvas_publish(PDO $pdo, array $user, array $input): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.publish');
    $context = canvas_context($pdo, $user, (string)($input['scope'] ?? ''), (string)($input['room_public_id'] ?? ''));
    $requestId = canvas_request_id((string)($input['request_id'] ?? ''));
    $actorId = (int)$user['id'];
    if ($existing = canvas_receipt($pdo, $requestId, $actorId, 'publish')) return $existing;
    $expected = max(1, (int)($input['expected_version'] ?? 0));

    $pdo->beginTransaction();
    try {
        $document = canvas_document_row($pdo, (string)$context['scopeKey']);
        if (!$document) throw new CanvasException('Save a Canvas draft before publishing.', 'CANVAS_DRAFT_REQUIRED', 409);
        $permissions = canvas_permission_projection($pdo, $user, $context, $document);
        canvas_require_permission($permissions, 'publish');
        if ((int)$document['draft_version'] !== $expected) {
            throw new CanvasException('Canvas draft changed before publication.', 'CANVAS_STALE_WRITE', 409, ['currentVersion' => (int)$document['draft_version']]);
        }
        $stmt = $pdo->prepare('UPDATE canvas_documents SET published_json=draft_json,published_version=draft_version,published_by_user_id=?,published_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND draft_version=?');
        $stmt->execute([$actorId, (int)$document['id'], $expected]);
        if ($stmt->rowCount() !== 1) throw new CanvasException('Canvas draft changed before publication.', 'CANVAS_STALE_WRITE', 409);
        canvas_revision($pdo, (int)$document['id'], $expected, 'published', (string)$document['draft_json'], $actorId);
        $response = ['ok' => true, 'documentId' => (string)$document['public_id'], 'publishedVersion' => $expected];
        canvas_store_receipt($pdo, $requestId, $actorId, 'publish', (int)$document['id'], $response);
        log_tool($pdo, $actorId, 'canvas_publish', null, $context['roomId'], json_encode(['scope' => $context['scope'], 'version' => $expected]));
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function canvas_add_comment(PDO $pdo, array $user, array $input): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.comment.commands');
    $context = canvas_context($pdo, $user, (string)($input['scope'] ?? ''), (string)($input['room_public_id'] ?? ''));
    $requestId = canvas_request_id((string)($input['request_id'] ?? ''));
    $actorId = (int)$user['id'];
    if ($existing = canvas_receipt($pdo, $requestId, $actorId, 'add_comment')) return $existing;
    $body = trim(str_replace(["
", ""], "
", (string)($input['body'] ?? '')));
    $sectionId = trim((string)($input['section_id'] ?? ''));
    if ($body === '' || strlen($body) > 4000 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{15,63}$/', $sectionId)) {
        throw new CanvasException('Comment content or section identity is invalid.', 'CANVAS_COMMENT_INVALID', 422);
    }
    $pdo->beginTransaction();
    try {
        $document = canvas_document_row($pdo, (string)$context['scopeKey']);
        if (!$document || (int)$document['published_version'] < 1) throw new CanvasException('This Canvas section is not published.', 'CANVAS_SECTION_NOT_PUBLISHED', 409);
        $permissions = canvas_permission_projection($pdo, $user, $context, $document);
        canvas_require_permission($permissions, 'comment');
        $published = canvas_decode_snapshot((string)$document['published_json']);
        $sectionIds = array_column((array)$published['sections'], 'id');
        if (!in_array($sectionId, $sectionIds, true)) throw new CanvasException('This Canvas section is not published.', 'CANVAS_SECTION_NOT_PUBLISHED', 409);
        $publicId = uuid_v4();
        $stmt = $pdo->prepare('INSERT INTO canvas_comments (public_id,document_id,section_public_id,author_user_id,body_text) VALUES (?,?,?,?,?)');
        $stmt->execute([$publicId, (int)$document['id'], $sectionId, $actorId, $body]);
        $response = ['ok' => true, 'commentId' => $publicId];
        canvas_store_receipt($pdo, $requestId, $actorId, 'add_comment', (int)$document['id'], $response);
        log_tool($pdo, $actorId, 'canvas_comment_add', null, $context['roomId'], json_encode(['scope' => $context['scope'], 'sectionId' => $sectionId]));
        $pdo->commit();
        return $response;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function canvas_remove_comment(PDO $pdo, array $user, array $input): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.comment.commands');
    $context = canvas_context($pdo, $user, (string)($input['scope'] ?? ''), (string)($input['room_public_id'] ?? ''));
    $document = canvas_document_row($pdo, (string)$context['scopeKey']);
    if (!$document) throw new CanvasException('Canvas comment is unavailable.', 'CANVAS_COMMENT_NOT_FOUND', 404);
    $permissions = canvas_permission_projection($pdo, $user, $context, $document);
    $stmt = $pdo->prepare('SELECT * FROM canvas_comments WHERE public_id=? AND document_id=? LIMIT 1');
    $stmt->execute([(string)($input['comment_id'] ?? ''), (int)$document['id']]);
    $comment = $stmt->fetch();
    if (!$comment) throw new CanvasException('Canvas comment is unavailable.', 'CANVAS_COMMENT_NOT_FOUND', 404);
    if ((int)$comment['author_user_id'] !== (int)$user['id']
        && empty($permissions['manage'])
        && !in_array((string)($user['role'] ?? 'user'), ['admin', 'developer', 'moderator'], true)) {
        throw new CanvasException('This account cannot remove that Canvas comment.', 'CANVAS_COMMENT_REMOVE_DENIED', 403);
    }
    $reason = trim((string)($input['reason'] ?? 'Removed by an authorized member.'));
    if ($reason === '' || strlen($reason) > 240) throw new CanvasException('Removal reason is invalid.', 'CANVAS_REMOVAL_REASON_INVALID', 422);
    $update = $pdo->prepare("UPDATE canvas_comments SET status='removed',removed_by_user_id=?,removal_reason=?,removed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=? AND status='active'");
    $update->execute([(int)$user['id'], $reason, (int)$comment['id']]);
    log_tool($pdo, (int)$user['id'], 'canvas_comment_remove', (int)($comment['author_user_id'] ?? 0) ?: null, $context['roomId'], json_encode(['scope' => $context['scope']]));
    return ['ok' => true, 'changed' => $update->rowCount() === 1];
}

function canvas_set_permission(PDO $pdo, array $user, array $input): array
{
    first_party_extension_service_facade($pdo, CANVAS_EXTENSION_ID, 'canvas.permissions');
    $context = canvas_context($pdo, $user, (string)($input['scope'] ?? ''), (string)($input['room_public_id'] ?? ''));
    $document = canvas_document_row($pdo, (string)$context['scopeKey']);
    if (!$document) throw new CanvasException('Save the Canvas before managing permissions.', 'CANVAS_DOCUMENT_REQUIRED', 409);
    $permissions = canvas_permission_projection($pdo, $user, $context, $document);
    canvas_require_permission($permissions, 'manage');
    $username = trim((string)($input['username'] ?? ''));
    if ($username === '' || strlen($username) > 64) throw new CanvasException('Member username is invalid.', 'CANVAS_PERMISSION_TARGET_INVALID', 422);
    $targetStmt = $pdo->prepare('SELECT id,username,display_name FROM users WHERE LOWER(username)=LOWER(?) LIMIT 1');
    $targetStmt->execute([$username]);
    $target = $targetStmt->fetch();
    if (!$target) throw new CanvasException('That member could not be found.', 'CANVAS_PERMISSION_TARGET_NOT_FOUND', 404);
    $flags = [];
    foreach (['view', 'comment', 'edit', 'manage', 'publish'] as $flag) $flags[$flag] = !empty($input[$flag]) ? 1 : 0;
    if (db_driver($pdo) === 'mysql') {
        $sql = 'INSERT INTO canvas_permissions (document_id,user_id,can_view,can_comment,can_edit,can_manage,can_publish,updated_by_user_id)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_comment=VALUES(can_comment),can_edit=VALUES(can_edit),can_manage=VALUES(can_manage),can_publish=VALUES(can_publish),updated_by_user_id=VALUES(updated_by_user_id),updated_at=CURRENT_TIMESTAMP';
    } else {
        $sql = 'INSERT INTO canvas_permissions (document_id,user_id,can_view,can_comment,can_edit,can_manage,can_publish,updated_by_user_id)
                VALUES (?,?,?,?,?,?,?,?)
                ON CONFLICT(document_id,user_id) DO UPDATE SET can_view=excluded.can_view,can_comment=excluded.can_comment,can_edit=excluded.can_edit,can_manage=excluded.can_manage,can_publish=excluded.can_publish,updated_by_user_id=excluded.updated_by_user_id,updated_at=CURRENT_TIMESTAMP';
    }
    $pdo->prepare($sql)->execute([(int)$document['id'], (int)$target['id'], $flags['view'], $flags['comment'], $flags['edit'], $flags['manage'], $flags['publish'], (int)$user['id']]);
    log_tool($pdo, (int)$user['id'], 'canvas_permission_update', (int)$target['id'], $context['roomId'], json_encode(['scope' => $context['scope'], 'flags' => $flags]));
    return ['ok' => true, 'target' => ['username' => (string)$target['username'], 'displayName' => (string)$target['display_name']]];
}

