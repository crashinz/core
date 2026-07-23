<?php
declare(strict_types=1);

const GESTURE_CAPABILITY_REVISION_SETTING = 'gesture_capability_revision';

function gesture_capability_registry(): array
{
    return [
        [
            'id' => 'allow_gestures',
            'publicKey' => 'allowGestures',
            'label' => 'Allow gestures',
            'description' => 'Master member capability for gesture catalogs, sending, mutation, and protected delivery.',
            'errorCode' => 'GESTURES_DISABLED',
            'order' => 10,
            'parent' => null,
        ],
        [
            'id' => 'allow_server_gestures',
            'publicKey' => 'allowServerGestures',
            'label' => 'Allow Server Gestures',
            'description' => 'Allow members to browse, send, and receive gestures published by another account.',
            'errorCode' => 'SERVER_GESTURES_DISABLED',
            'order' => 20,
            'parent' => 'allow_gestures',
        ],
        [
            'id' => 'allow_personal_gestures',
            'publicKey' => 'allowPersonalGestures',
            'label' => 'Allow Personal Gestures',
            'description' => 'Allow members to browse, send, and receive gestures owned by their account.',
            'errorCode' => 'PERSONAL_GESTURES_DISABLED',
            'order' => 30,
            'parent' => 'allow_gestures',
        ],
        [
            'id' => 'allow_user_gesture_mutation',
            'publicKey' => 'allowUserGestureMutation',
            'label' => 'Allow user gesture creation and editing',
            'description' => 'Allow ordinary members to create, upload, import, edit, publish, unpublish, and delete gestures.',
            'errorCode' => 'GESTURE_USER_MUTATION_DISABLED',
            'order' => 40,
            'parent' => 'allow_gestures',
        ],
        [
            'id' => 'allow_gesture_audio_delivery',
            'publicKey' => 'allowGestureAudioDelivery',
            'label' => 'Allow gesture audio delivery',
            'description' => 'Allow protected gesture audio delivery when the package policy and viewer preference also permit it.',
            'errorCode' => 'GESTURE_AUDIO_DISABLED',
            'order' => 50,
            'parent' => 'allow_gestures',
        ],
    ];
}

function gesture_capability_setting_defaults(): array
{
    $defaults = [GESTURE_CAPABILITY_REVISION_SETTING => '1'];
    foreach (gesture_capability_registry() as $definition) {
        $defaults[(string)$definition['id']] = '1';
    }
    return $defaults;
}

function gesture_capability_policy(PDO $pdo): array
{
    $stored = [];
    $effective = [];
    foreach (gesture_capability_registry() as $definition) {
        $id = (string)$definition['id'];
        $stored[$id] = app_setting($pdo, $id, '1') === '1';
    }
    $master = !empty($stored['allow_gestures']);
    foreach (gesture_capability_registry() as $definition) {
        $id = (string)$definition['id'];
        $effective[$id] = $id === 'allow_gestures'
            ? $master
            : $master && !empty($stored[$id]);
    }

    $projection = [
        'revision' => max(1, (int)app_setting($pdo, GESTURE_CAPABILITY_REVISION_SETTING, '1')),
        'settingsRegistryRevision' => function_exists('settings_registry_revision')
            ? settings_registry_revision($pdo)
            : max(1, (int)app_setting($pdo, 'settings_registry_revision', '1')),
        'stored' => $stored,
        'effective' => $effective,
    ];
    foreach (gesture_capability_registry() as $definition) {
        $projection[(string)$definition['publicKey']] = !empty($effective[(string)$definition['id']]);
    }
    return $projection;
}

function gesture_capability_lock(PDO $pdo): array
{
    $sql = 'SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['settings_registry_revision']);
    $stmt->fetchColumn();
    return gesture_capability_policy($pdo);
}

function gesture_capability_boolean(mixed $value): ?bool
{
    return in_array($value, [true, false, 0, 1, '0', '1'], true)
        ? (bool)$value
        : null;
}

function gesture_capability_update_locked(PDO $pdo, array $values): array
{
    $before = gesture_capability_policy($pdo);
    $stored = $before['stored'];
    foreach (gesture_capability_registry() as $definition) {
        $id = (string)$definition['id'];
        if (!array_key_exists($id, $values)) continue;
        $normalized = gesture_capability_boolean($values[$id]);
        if ($normalized === null) {
            throw new InvalidArgumentException("{$id} must be enabled or disabled.");
        }
        $stored[$id] = $normalized;
    }

    $changed = [];
    foreach ($stored as $id => $value) {
        if ($value !== !empty($before['stored'][$id])) $changed[] = $id;
    }
    if (!$changed) {
        return ['ok' => true, 'idempotent' => true, 'changedIds' => [], 'capability' => $before];
    }
    foreach ($changed as $id) set_app_setting($pdo, $id, $stored[$id] ? '1' : '0');
    set_app_setting(
        $pdo,
        GESTURE_CAPABILITY_REVISION_SETTING,
        (string)((int)$before['revision'] + 1)
    );
    gesture_capability_reset_viewer_state_cache();
    return [
        'ok' => true,
        'idempotent' => false,
        'changedIds' => $changed,
        'capability' => gesture_capability_policy($pdo),
    ];
}

function gesture_capability_emit(PDO $pdo, ?array $capability = null): void
{
    $capability ??= gesture_capability_policy($pdo);
    $sessionIds = array_map(
        'intval',
        $pdo->query('SELECT id FROM room_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
    );
    foreach ($sessionIds as $sessionId) {
        emit_event($pdo, $sessionId, 'gesture_capability', $capability);
    }
}

function gesture_capability_definition(string $id): ?array
{
    foreach (gesture_capability_registry() as $definition) {
        if ((string)$definition['id'] === $id) return $definition;
    }
    return null;
}

function gesture_capability_require(array $policy, string $id): void
{
    $definition = gesture_capability_definition($id);
    if (!$definition) throw new InvalidArgumentException('Unknown gesture capability.');
    if (!empty($policy['effective'][$id])) return;
    $masterDisabled = $id !== 'allow_gestures' && empty($policy['effective']['allow_gestures']);
    $effectiveDefinition = $masterDisabled
        ? gesture_capability_definition('allow_gestures')
        : $definition;
    throw new GestureCatalogException(
        (string)$effectiveDefinition['label'] . ' is disabled through shared Settings.',
        403,
        (string)$effectiveDefinition['errorCode']
    );
}

function gesture_capability_scope_for_gesture(array $gesture, int $actorUserId): string
{
    return (int)($gesture['owner_user_id'] ?? 0) === $actorUserId
        ? 'personal'
        : 'server';
}

function gesture_capability_require_scope(array $policy, string $scope): void
{
    gesture_capability_require(
        $policy,
        $scope === 'personal' ? 'allow_personal_gestures' : 'allow_server_gestures'
    );
}

function gesture_capability_project_catalog_payload(
    PDO $pdo,
    array $payload,
    bool $admin = false,
    ?array $policy = null,
    ?array $part4 = null
): array {
    if ($admin) return $payload;
    $policy ??= gesture_capability_policy($pdo);
    $part4 ??= function_exists('gesture_part4_feature_flags')
        ? gesture_part4_feature_flags($pdo)
        : [];
    if (array_key_exists('animation_media', $part4) && empty($part4['animation_media'])) {
        $payload['gif_path'] = null;
        $payload['gif_url'] = null;
        $payload['poster_path'] = null;
        $payload['poster_url'] = null;
    }
    if (
        empty($policy['effective']['allow_gesture_audio_delivery'])
        || (array_key_exists('audio_media', $part4) && empty($part4['audio_media']))
    ) {
        $payload['audio_path'] = null;
        $payload['audio_url'] = null;
    }
    return $payload;
}

function gesture_capability_reset_viewer_state_cache(): void
{
    $GLOBALS['chatspace_gesture_capability_cache_generation'] =
        (int)($GLOBALS['chatspace_gesture_capability_cache_generation'] ?? 0) + 1;
}

function gesture_capability_viewer_state(PDO $pdo, int $viewerUserId): array
{
    static $cache = [];
    $key = (int)($GLOBALS['chatspace_gesture_capability_cache_generation'] ?? 0)
        . ':' . spl_object_id($pdo) . ':' . $viewerUserId;
    if (isset($cache[$key])) return $cache[$key];
    $preferences = function_exists('gesture_catalog_preferences_payload')
        ? gesture_catalog_preferences_payload($pdo, $viewerUserId)
        : [
            'show_animations' => true,
            'play_sounds' => true,
            'hidden_sender_user_ids' => [],
        ];
    return $cache[$key] = [
        'capability' => gesture_capability_policy($pdo),
        'part4' => function_exists('gesture_part4_feature_flags')
            ? gesture_part4_feature_flags($pdo)
            : [],
        'preferences' => $preferences,
        'hiddenSenderUserIds' => array_fill_keys(
            array_map('intval', (array)($preferences['hidden_sender_user_ids'] ?? [])),
            true
        ),
    ];
}

function gesture_capability_hydrate_snapshot(PDO $pdo, array $gesture): array
{
    $hydrated = $gesture;
    foreach (['gif_path', 'gif_url', 'poster_path', 'poster_url', 'audio_path', 'audio_url'] as $key) {
        $hydrated[$key] = null;
    }
    $publicId = trim((string)($gesture['public_id'] ?? ''));
    if ($publicId === '' || strlen($publicId) > 64) return $hydrated;

    try {
        $stmt = $pdo->prepare('SELECT * FROM gestures WHERE public_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$publicId]);
        $current = $stmt->fetch();
        if (!$current) return $hydrated;
        $snapshotOwner = (int)($gesture['owner_user_id'] ?? 0);
        if ($snapshotOwner > 0 && (int)$current['owner_user_id'] !== $snapshotOwner) return $hydrated;

        $source = $current;
        $generation = max(1, (int)($gesture['package_generation'] ?? 1));
        if (
            (string)($current['package_status'] ?? 'legacy-unverified') !== 'legacy-unverified'
            && function_exists('gesture_package_media_record')
        ) {
            $source = gesture_package_media_record($pdo, $publicId, $generation);
            $source['package_generation'] = $generation;
        }
        $snapshotHash = trim((string)($gesture['content_sha256'] ?? ''));
        $sourceHash = trim((string)($source['content_sha256'] ?? ''));
        if ($snapshotHash !== '' && ($sourceHash === '' || !hash_equals($snapshotHash, $sourceHash))) {
            return $hydrated;
        }

        if (function_exists('gesture_package_media_url')) {
            $hydrated['gif_path'] = gesture_package_media_url($source, 'animation', 'message');
            $hydrated['gif_url'] = $hydrated['gif_path'];
            $hydrated['poster_path'] = gesture_package_media_url($source, 'poster', 'message');
            $hydrated['poster_url'] = $hydrated['poster_path'];
            $hydrated['audio_path'] = gesture_package_media_url($source, 'audio', 'message');
            $hydrated['audio_url'] = $hydrated['audio_path'];
        }
    } catch (Throwable) {
        return $hydrated;
    }
    return $hydrated;
}

function gesture_capability_project_snapshot(
    PDO $pdo,
    int $viewerUserId,
    int $senderUserId,
    array $gesture
): array {
    $state = gesture_capability_viewer_state($pdo, $viewerUserId);
    $capability = $state['capability'];
    $scope = in_array(($gesture['scope'] ?? null), ['server', 'personal'], true)
        ? (string)$gesture['scope']
        : gesture_capability_scope_for_gesture($gesture, $senderUserId);
    $scopeId = $scope === 'personal' ? 'allow_personal_gestures' : 'allow_server_gestures';
    $privatePersonalForOther = $scope === 'personal'
        && $viewerUserId !== (int)($gesture['owner_user_id'] ?? 0)
        && empty($gesture['is_public']);
    $mediaAllowed = !empty($capability['effective']['allow_gestures'])
        && !empty($capability['effective'][$scopeId])
        && !$privatePersonalForOther
        && empty($state['hiddenSenderUserIds'][$senderUserId]);
    $animationAllowed = $mediaAllowed
        && !empty($state['preferences']['show_animations'])
        && ($state['part4']['animation_media'] ?? true) !== false;
    $audioAllowed = $mediaAllowed
        && !empty($capability['effective']['allow_gesture_audio_delivery'])
        && !empty($state['preferences']['play_sounds'])
        && ($state['part4']['audio_media'] ?? true) !== false;

    $projected = $animationAllowed || $audioAllowed
        ? gesture_capability_hydrate_snapshot($pdo, $gesture)
        : $gesture;
    if (!$animationAllowed) {
        $projected['gif_path'] = null;
        $projected['gif_url'] = null;
        $projected['poster_path'] = null;
        $projected['poster_url'] = null;
    }
    if (!$audioAllowed) {
        $projected['audio_path'] = null;
        $projected['audio_url'] = null;
    }
    $projected['media_projection'] = [
        'scope' => $scope,
        'animationAllowed' => $animationAllowed,
        'audioAllowed' => $audioAllowed,
        'senderMediaHidden' => !empty($state['hiddenSenderUserIds'][$senderUserId]),
        'capabilityRevision' => (int)$capability['revision'],
    ];
    return $projected;
}

function gesture_capability_project_message_payload(
    PDO $pdo,
    int $viewerUserId,
    array $message
): array {
    if (($message['message_type'] ?? 'text') !== 'gesture') return $message;
    $gesture = is_array($message['gesture'] ?? null)
        ? $message['gesture']
        : (function_exists('message_gesture') ? message_gesture((string)($message['content'] ?? '')) : null);
    if (!is_array($gesture)) {
        $message['gesture'] = ['text' => ''];
        $message['content'] = json_encode(['text' => ''], JSON_UNESCAPED_SLASHES);
        return $message;
    }
    $safeSnapshot = $gesture;
    foreach (['gif_path', 'gif_url', 'poster_path', 'poster_url', 'audio_path', 'audio_url', 'media_projection'] as $key) {
        if ($key === 'media_projection') unset($safeSnapshot[$key]);
        else $safeSnapshot[$key] = null;
    }
    $projected = gesture_capability_project_snapshot(
        $pdo,
        $viewerUserId,
        (int)($message['user_id'] ?? 0),
        $gesture
    );
    $message['gesture'] = $projected;
    $message['content'] = json_encode(
        $safeSnapshot,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    return $message;
}
