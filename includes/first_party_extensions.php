<?php
declare(strict_types=1);

/**
 * Capability-safe registry for repository-owned first-party extensions.
 *
 * Manifests are descriptive only. Executable adapters are loaded exclusively
 * from this core allowlist; a manifest can never select an arbitrary PHP or JS
 * file.
 */

const FIRST_PARTY_EXTENSION_MANIFEST_SCHEMA = 'chatspace.first-party-extension';
const FIRST_PARTY_EXTENSION_MANIFEST_VERSION = 1;
const FIRST_PARTY_EXTENSION_STORAGE_PREFIX = 'first_party_extension.storage.';
const FIRST_PARTY_EXTENSION_STORAGE_QUOTA_BYTES = 65536;

function first_party_extension_sources(): array {
    return [
        'private-site-branding' => [
            'manifest' => dirname(__DIR__) . '/extensions/private-site-branding/extension.json',
            'adapter' => __DIR__ . '/private_site_branding.php',
            'factory' => 'private_site_branding_extension_adapter',
            'activationCapability' => 'presentation.branding',
            'introducedStorageSchema' => 1,
        ],
        'gesture-maker' => [
            'manifest' => dirname(__DIR__) . '/extensions/gesture-maker/extension.json',
            'adapter' => __DIR__ . '/gesture_maker_extension.php',
            'factory' => 'gesture_maker_extension_adapter',
            'activationCapability' => 'presentation.gesture-maker',
            'introducedStorageSchema' => 1,
        ],
    ];
}

function first_party_extension_capability_catalog(): array {
    return [
        'branding.settings.read',
        'branding.settings.write',
        'branding.asset.reference',
        'presentation.branding',
        'presentation.public-utility-link',
        'legal.license.read',
        'legal.modifications.read',
        'gesture.catalog.read',
        'gesture.editor.command',
        'gesture.package.import',
        'gesture.package.export',
        'gesture.preview',
        'gesture.protected-media.reference',
        'presentation.gesture-maker',
    ];
}

function first_party_extension_subscription_catalog(): array {
    return [
        'settings.registry',
        'presentation.branding',
        'presentation.public-utility-links',
        'presentation.room-version-attribution',
        'presentation.gesture-management',
        'presentation.gesture-editor',
        'presentation.gesture-preview',
        'presentation.gesture-package',
    ];
}

function first_party_extension_setting_defaults(): array {
    return [
        'first_party_extension.private-site-branding.enabled' => '1',
        'first_party_extension.private-site-branding.lifecycle_revision' => '1',
        'first_party_extension.private-site-branding.storage_schema' => '1',
        'first_party_extension.private-site-branding.last_failure' => '',
        'first_party_extension.gesture-maker.enabled' => '1',
        'first_party_extension.gesture-maker.lifecycle_revision' => '1',
        'first_party_extension.gesture-maker.storage_schema' => '1',
        'first_party_extension.gesture-maker.last_failure' => '',
    ];
}

function first_party_extension_service_catalog(): array {
    return [
        'gesture.catalog.projection' => 'gesture.catalog.read',
        'gesture.editor.commands' => 'gesture.editor.command',
        'gesture.package.import' => 'gesture.package.import',
        'gesture.package.export' => 'gesture.package.export',
        'gesture.preview.references' => 'gesture.preview',
        'gesture.protected-media.references' => 'gesture.protected-media.reference',
    ];
}

function first_party_extension_validate_manifest(string $extensionId, array $decoded): array {
    if (($decoded['schema'] ?? '') !== FIRST_PARTY_EXTENSION_MANIFEST_SCHEMA
        || (int)($decoded['schemaVersion'] ?? 0) !== FIRST_PARTY_EXTENSION_MANIFEST_VERSION
        || ($decoded['id'] ?? '') !== $extensionId
        || empty($decoded['repositoryOwned'])
        || empty($decoded['trustedFirstParty'])
        || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $extensionId)
        || !preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/i', (string)($decoded['version'] ?? ''))) {
        throw new RuntimeException('First-party extension manifest identity or version is invalid.');
    }
    $capabilities = array_values(array_unique(array_map('strval', (array)($decoded['capabilities'] ?? []))));
    if (array_diff($capabilities, first_party_extension_capability_catalog())) {
        throw new RuntimeException('First-party extension declares an unknown capability.');
    }
    $subscriptions = array_values(array_unique(array_map('strval', (array)($decoded['subscriptions'] ?? []))));
    if (array_diff($subscriptions, first_party_extension_subscription_catalog())) {
        throw new RuntimeException('First-party extension declares an unknown subscription.');
    }
    $services = array_values(array_unique(array_map('strval', (array)($decoded['services'] ?? []))));
    if (array_diff($services, array_keys(first_party_extension_service_catalog()))) {
        throw new RuntimeException('First-party extension declares an unknown service.');
    }
    foreach ($services as $service) {
        $requiredCapability = first_party_extension_service_catalog()[$service];
        if (!in_array($requiredCapability, $capabilities, true)) {
            throw new RuntimeException('First-party extension service is missing its required capability.');
        }
    }
    $dependencies = array_values(array_unique(array_map('strval', (array)($decoded['dependencies'] ?? []))));
    $conflicts = array_values(array_unique(array_map('strval', (array)($decoded['conflicts'] ?? []))));
    foreach (array_merge($dependencies, $conflicts) as $relatedId) {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $relatedId)) {
            throw new RuntimeException('First-party extension dependency or conflict identity is invalid.');
        }
    }
    if (in_array($extensionId, $dependencies, true) || in_array($extensionId, $conflicts, true)) {
        throw new RuntimeException('First-party extension cannot depend on or conflict with itself.');
    }
    $compatibility = (array)($decoded['compatibility'] ?? []);
    $prefix = (string)($compatibility['applicationVersionPrefix'] ?? '');
    if ($prefix === '' || !str_starts_with(chatspace_application_version(), $prefix)) {
        throw new RuntimeException('First-party extension is not compatible with this application version.');
    }
    $storage = (array)($decoded['storage'] ?? []);
    $storageSchema = (int)($storage['schemaVersion'] ?? 0);
    $storageQuota = (int)($storage['quotaBytes'] ?? 0);
    if ($storageSchema < 1
        || $storageQuota < 1
        || $storageQuota > FIRST_PARTY_EXTENSION_STORAGE_QUOTA_BYTES
        || !in_array((string)($storage['disablePolicy'] ?? ''), ['preserve', 'retire'], true)
        || (string)($storage['uninstallPolicy'] ?? '') !== 'explicit-cleanup-only') {
        throw new RuntimeException('First-party extension storage policy is invalid.');
    }
    $integrity = (array)($decoded['integrity'] ?? []);
    $integrityFiles = (array)($integrity['files'] ?? []);
    if (($integrity['algorithm'] ?? '') !== 'sha256' || !$integrityFiles) {
        throw new RuntimeException('First-party extension integrity declaration is invalid.');
    }
    $decoded['capabilities'] = $capabilities;
    $decoded['subscriptions'] = $subscriptions;
    $decoded['services'] = $services;
    $decoded['dependencies'] = $dependencies;
    $decoded['conflicts'] = $conflicts;
    $decoded['storage']['schemaVersion'] = $storageSchema;
    $decoded['storage']['quotaBytes'] = $storageQuota;
    return $decoded;
}

function first_party_extension_manifest(string $extensionId): array {
    static $cache = [];
    if (isset($cache[$extensionId])) return $cache[$extensionId];
    $source = first_party_extension_sources()[$extensionId] ?? null;
    if (!$source) throw new RuntimeException('Unknown first-party extension.');
    $path = (string)$source['manifest'];
    if (!is_file($path) || filesize($path) === false || filesize($path) > 65536) {
        throw new RuntimeException('First-party extension manifest is unavailable or oversized.');
    }
    $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('First-party extension manifest is invalid.');
    $decoded = first_party_extension_validate_manifest($extensionId, $decoded);
    $integrity = (array)($decoded['integrity'] ?? []);
    foreach ((array)($integrity['files'] ?? []) as $relative => $expectedHash) {
        $relative = str_replace('\\', '/', (string)$relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new RuntimeException('First-party extension integrity path is invalid.');
        }
        $absolute = dirname(__DIR__) . '/' . $relative;
        if (!is_file($absolute)
            || !preg_match('/^[a-f0-9]{64}$/i', (string)$expectedHash)
            || !hash_equals(strtolower((string)$expectedHash), hash_file('sha256', $absolute))) {
            throw new RuntimeException('First-party extension integrity verification failed.');
        }
    }
    return $cache[$extensionId] = $decoded;
}

function first_party_extension_resolve_order(array $manifests): array {
    $ordered = [];
    $visiting = [];
    $visited = [];
    $visit = static function (string $extensionId) use (&$visit, &$ordered, &$visiting, &$visited, $manifests): void {
        if (isset($visited[$extensionId])) return;
        if (!isset($manifests[$extensionId])) {
            throw new RuntimeException('First-party extension dependency is unavailable.');
        }
        if (isset($visiting[$extensionId])) {
            throw new RuntimeException('First-party extension dependency cycle detected.');
        }
        $visiting[$extensionId] = true;
        foreach ($manifests[$extensionId]['dependencies'] as $dependency) $visit($dependency);
        unset($visiting[$extensionId]);
        $visited[$extensionId] = true;
        $ordered[] = $extensionId;
    };
    foreach (array_keys($manifests) as $extensionId) $visit($extensionId);
    foreach ($ordered as $extensionId) {
        foreach ($manifests[$extensionId]['conflicts'] as $conflict) {
            if (isset($manifests[$conflict])) {
                throw new RuntimeException('First-party extension conflict detected.');
            }
        }
    }
    return $ordered;
}

function first_party_extension_registry(): array {
    static $registry = null;
    if ($registry !== null) return $registry;
    $manifests = [];
    foreach (array_keys(first_party_extension_sources()) as $extensionId) {
        $manifests[$extensionId] = first_party_extension_manifest($extensionId);
    }
    $ordered = first_party_extension_resolve_order($manifests);
    return $registry = ['loadOrder' => $ordered, 'manifests' => $manifests];
}

function first_party_extension_enabled(PDO $pdo, string $extensionId, bool $safeMode = false): bool {
    if ($safeMode) return false;
    if (!isset(first_party_extension_sources()[$extensionId])) return false;
    return app_setting($pdo, "first_party_extension.{$extensionId}.enabled", '1') === '1';
}

function first_party_extension_status(PDO $pdo, string $extensionId, bool $safeMode = false): array {
    try {
        $manifest = first_party_extension_manifest($extensionId);
        $enabled = first_party_extension_enabled($pdo, $extensionId, $safeMode);
        $expectedStorageSchema = (int)$manifest['storage']['schemaVersion'];
        $introducedStorageSchema = max(
            0,
            (int)(first_party_extension_sources()[$extensionId]['introducedStorageSchema'] ?? 0)
        );
        $actualStorageSchema = max(0, (int)app_setting(
            $pdo,
            "first_party_extension.{$extensionId}.storage_schema",
            (string)$introducedStorageSchema
        ));
        $storageReady = $actualStorageSchema === $expectedStorageSchema;
        $state = !$enabled
            ? ($safeMode ? 'safe-mode-suppressed' : 'disabled')
            : ($storageReady ? 'enabled' : 'update-required');
        return [
            'id' => $extensionId,
            'name' => (string)$manifest['name'],
            'version' => (string)$manifest['version'],
            'enabled' => $enabled,
            'safeModeSuppressed' => $safeMode,
            'state' => $state,
            'capabilities' => $manifest['capabilities'],
            'dependencies' => $manifest['dependencies'],
            'subscriptions' => array_values(array_map('strval', (array)($manifest['subscriptions'] ?? []))),
            'services' => array_values(array_map('strval', (array)($manifest['services'] ?? []))),
            'storagePolicy' => (string)($manifest['storage']['disablePolicy'] ?? 'preserve'),
            'storageSchema' => $actualStorageSchema,
            'expectedStorageSchema' => $expectedStorageSchema,
            'failure' => '',
        ];
    } catch (Throwable $error) {
        return [
            'id' => $extensionId,
            'name' => $extensionId,
            'version' => '',
            'enabled' => false,
            'safeModeSuppressed' => $safeMode,
            'state' => 'failed',
            'capabilities' => [],
            'dependencies' => [],
            'subscriptions' => [],
            'services' => [],
            'storagePolicy' => 'preserve',
            'storageSchema' => null,
            'expectedStorageSchema' => null,
            'failure' => 'Manifest, compatibility, dependency, or integrity validation failed.',
        ];
    }
}

function first_party_extension_statuses(PDO $pdo, bool $safeMode = false): array {
    $statuses = [];
    try {
        $registry = first_party_extension_registry();
        foreach ($registry['loadOrder'] as $extensionId) {
            $statuses[] = first_party_extension_status($pdo, $extensionId, $safeMode);
        }
    } catch (Throwable) {
        foreach (array_keys(first_party_extension_sources()) as $extensionId) {
            $statuses[] = first_party_extension_status($pdo, $extensionId, $safeMode);
        }
    }
    return $statuses;
}

function first_party_extension_assert_capability(
    PDO $pdo,
    string $extensionId,
    string $capability
): array {
    $status = first_party_extension_status($pdo, $extensionId);
    if ($status['state'] !== 'enabled' || !in_array($capability, $status['capabilities'], true)) {
        throw new RuntimeException('First-party extension capability denied.');
    }
    return $status;
}

function first_party_extension_adapter(
    PDO $pdo,
    string $extensionId,
    ?string $requiredCapability = null
): array {
    $source = first_party_extension_sources()[$extensionId] ?? null;
    if (!$source) throw new RuntimeException('Unknown first-party extension adapter.');
    $requiredCapability = $requiredCapability ?? (string)($source['activationCapability'] ?? '');
    if ($requiredCapability === '') throw new RuntimeException('First-party extension activation capability is unavailable.');
    first_party_extension_assert_capability($pdo, $extensionId, $requiredCapability);
    require_once (string)$source['adapter'];
    $factory = (string)$source['factory'];
    if (!function_exists($factory)) throw new RuntimeException('First-party extension adapter is unavailable.');
    $adapter = $factory();
    if (!is_array($adapter) || ($adapter['id'] ?? '') !== $extensionId) {
        throw new RuntimeException('First-party extension adapter identity mismatch.');
    }
    return $adapter;
}

function first_party_extension_service_facade(
    PDO $pdo,
    string $extensionId,
    string $service
): array {
    $catalog = first_party_extension_service_catalog();
    $requiredCapability = $catalog[$service] ?? null;
    if (!is_string($requiredCapability)) {
        throw new RuntimeException('Unknown first-party extension service.');
    }
    $status = first_party_extension_assert_capability($pdo, $extensionId, $requiredCapability);
    if (!in_array($service, (array)($status['services'] ?? []), true)) {
        throw new RuntimeException('First-party extension service denied.');
    }
    return match ($service) {
        'gesture.catalog.projection' => [
            'service' => $service,
            'endpoint' => '/api/gestures.php',
            'methods' => ['GET'],
            'projection' => 'viewer-filtered-catalog',
        ],
        'gesture.editor.commands' => [
            'service' => $service,
            'endpoint' => '/api/gesture_packages.php',
            'methods' => ['GET', 'POST'],
            'commands' => ['preferences', 'detail', 'create', 'edit', 'admin_edit'],
        ],
        'gesture.package.import' => [
            'service' => $service,
            'endpoint' => '/api/gesture_packages.php',
            'methods' => ['POST'],
            'commands' => ['create', 'edit', 'admin_edit'],
        ],
        'gesture.package.export' => [
            'service' => $service,
            'endpoint' => '/api/gesture_packages.php',
            'methods' => ['GET'],
            'commands' => ['download'],
        ],
        'gesture.preview.references' => [
            'service' => $service,
            'source' => 'browser-object-url-or-core-protected-reference',
            'directFilesystemAccess' => false,
        ],
        'gesture.protected-media.references' => [
            'service' => $service,
            'endpoint' => '/api/gesture_media.php',
            'methods' => ['GET'],
            'referenceOnly' => true,
        ],
    };
}

function first_party_extension_set_enabled_locked(
    PDO $pdo,
    string $extensionId,
    bool $enabled
): array {
    if (!isset(first_party_extension_sources()[$extensionId])) {
        throw new RuntimeException('Unknown first-party extension.');
    }
    if ($enabled) {
        first_party_extension_manifest($extensionId);
        first_party_extension_migrate_storage_locked($pdo, $extensionId);
    }
    $current = first_party_extension_enabled($pdo, $extensionId);
    $revisionKey = "first_party_extension.{$extensionId}.lifecycle_revision";
    $revision = max(1, (int)app_setting($pdo, $revisionKey, '1'));
    if ($current === $enabled) {
        return ['changed' => false, 'revision' => $revision, 'state' => $enabled ? 'enabled' : 'disabled'];
    }
    set_app_setting($pdo, "first_party_extension.{$extensionId}.enabled", $enabled ? '1' : '0');
    set_app_setting($pdo, $revisionKey, (string)($revision + 1));
    set_app_setting($pdo, "first_party_extension.{$extensionId}.last_failure", '');
    return [
        'changed' => true,
        'revision' => $revision + 1,
        'state' => $enabled ? 'enabled' : 'disabled',
        'subscriptionsRemoved' => $enabled ? 0 : count((array)first_party_extension_manifest($extensionId)['subscriptions']),
        'storageDisposition' => 'preserved',
    ];
}

function first_party_extension_migrate_storage_locked(PDO $pdo, string $extensionId): array {
    $manifest = first_party_extension_manifest($extensionId);
    $target = (int)$manifest['storage']['schemaVersion'];
    $key = "first_party_extension.{$extensionId}.storage_schema";
    $introducedStorageSchema = max(
        0,
        (int)(first_party_extension_sources()[$extensionId]['introducedStorageSchema'] ?? 0)
    );
    $current = max(0, (int)app_setting($pdo, $key, (string)$introducedStorageSchema));
    if ($current > $target) {
        throw new RuntimeException('First-party extension storage downgrade is not supported.');
    }
    if ($current === $target) {
        return ['changed' => false, 'from' => $current, 'to' => $target];
    }
    for ($revision = $current + 1; $revision <= $target; $revision++) {
        set_app_setting($pdo, $key, (string)$revision);
    }
    return ['changed' => true, 'from' => $current, 'to' => $target];
}

function first_party_extension_update_locked(PDO $pdo, string $extensionId): array {
    $migration = first_party_extension_migrate_storage_locked($pdo, $extensionId);
    $revisionKey = "first_party_extension.{$extensionId}.lifecycle_revision";
    $revision = max(1, (int)app_setting($pdo, $revisionKey, '1'));
    if (!empty($migration['changed'])) {
        set_app_setting($pdo, $revisionKey, (string)($revision + 1));
        $revision++;
    }
    return [
        'changed' => (bool)$migration['changed'],
        'revision' => $revision,
        'state' => first_party_extension_status($pdo, $extensionId)['state'],
        'storageMigration' => $migration,
    ];
}

function first_party_extension_storage_key(string $extensionId, string $key): string {
    if (!isset(first_party_extension_sources()[$extensionId])
        || !preg_match('/^[a-z0-9][a-z0-9._-]{0,95}$/', $key)) {
        throw new InvalidArgumentException('Invalid first-party extension storage key.');
    }
    return FIRST_PARTY_EXTENSION_STORAGE_PREFIX . $extensionId . '.' . $key;
}

function first_party_extension_storage_get(
    PDO $pdo,
    string $extensionId,
    string $key,
    mixed $default = null
): mixed {
    first_party_extension_assert_capability($pdo, $extensionId, 'branding.settings.read');
    $raw = app_setting($pdo, first_party_extension_storage_key($extensionId, $key), '');
    if ($raw === '') return $default;
    try {
        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $default;
    }
}

function first_party_extension_storage_set(
    PDO $pdo,
    string $extensionId,
    string $key,
    mixed $value
): void {
    first_party_extension_assert_capability($pdo, $extensionId, 'branding.settings.write');
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $settingKey = first_party_extension_storage_key($extensionId, $key);
    $prefix = FIRST_PARTY_EXTENSION_STORAGE_PREFIX . $extensionId . '.';
    $stmt = $pdo->prepare('SELECT setting_key, value FROM app_settings WHERE setting_key LIKE ?');
    $stmt->execute([$prefix . '%']);
    $size = 0;
    foreach ($stmt->fetchAll() as $row) {
        if ((string)$row['setting_key'] === $settingKey) continue;
        $size += strlen((string)$row['setting_key']) + strlen((string)$row['value']);
    }
    $size += strlen($settingKey) + strlen($encoded);
    $manifest = first_party_extension_manifest($extensionId);
    if ($size > (int)$manifest['storage']['quotaBytes']) {
        throw new RuntimeException('First-party extension storage quota exceeded.');
    }
    set_app_setting($pdo, $settingKey, $encoded);
}

function first_party_extension_storage_cleanup(PDO $pdo, string $extensionId): int {
    first_party_extension_assert_capability($pdo, $extensionId, 'branding.settings.write');
    return first_party_extension_storage_cleanup_locked($pdo, $extensionId);
}

function first_party_extension_storage_cleanup_locked(PDO $pdo, string $extensionId): int {
    if (!isset(first_party_extension_sources()[$extensionId])) {
        throw new RuntimeException('Unknown first-party extension.');
    }
    $prefix = FIRST_PARTY_EXTENSION_STORAGE_PREFIX . $extensionId . '.';
    $stmt = $pdo->prepare('DELETE FROM app_settings WHERE setting_key LIKE ?');
    $stmt->execute([$prefix . '%']);
    return $stmt->rowCount();
}

function first_party_extension_teardown_locked(
    PDO $pdo,
    string $extensionId,
    bool $explicitStorageCleanup = false
): array {
    $lifecycle = first_party_extension_set_enabled_locked($pdo, $extensionId, false);
    $removed = $explicitStorageCleanup
        ? first_party_extension_storage_cleanup_locked($pdo, $extensionId)
        : 0;
    return [
        'lifecycle' => $lifecycle,
        'subscriptionsRemoved' => (int)($lifecycle['subscriptionsRemoved'] ?? 0),
        'storageDisposition' => $explicitStorageCleanup ? 'cleaned' : 'preserved',
        'storageRecordsRemoved' => $removed,
    ];
}

function first_party_extension_read_document(
    PDO $pdo,
    string $extensionId,
    string $capability,
    string $document
): string {
    first_party_extension_assert_capability($pdo, $extensionId, $capability);
    $allowed = [
        'LICENSE.md' => 'legal.license.read',
        'MODIFICATIONS.md' => 'legal.modifications.read',
    ];
    if (($allowed[$document] ?? null) !== $capability) {
        throw new RuntimeException('Repository document capability denied.');
    }
    $path = dirname(__DIR__) . '/' . $document;
    if (!is_file($path) || filesize($path) === false || filesize($path) > 262144) {
        throw new RuntimeException('Repository document is unavailable or oversized.');
    }
    return (string)file_get_contents($path);
}
