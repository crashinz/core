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
        'tetris-versus' => [
            'manifest' => dirname(__DIR__) . '/extensions/tetris-versus/extension.json',
            'adapter' => __DIR__ . '/tetris_arcade_extension.php',
            'factory' => 'tetris_arcade_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1, 'publicFallbackName' => 'Tetris Versus',
        ],
        'space-invasion' => [
            'manifest' => dirname(__DIR__) . '/extensions/space-invasion/extension.json',
            'adapter' => __DIR__ . '/space_arcade_extension.php',
            'factory' => 'space_arcade_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1, 'publicFallbackName' => 'Space Invasion',
        ],
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
        'canvas' => [
            'manifest' => dirname(__DIR__) . '/extensions/canvas/extension.json',
            'adapter' => __DIR__ . '/canvas_extension.php',
            'factory' => 'canvas_extension_adapter',
            'activationCapability' => 'presentation.canvas',
            'introducedStorageSchema' => 1,
        ],
        'five-dice' => [
            'manifest' => dirname(__DIR__) . '/extensions/five-dice/extension.json',
            'adapter' => __DIR__ . '/five_dice_extension.php',
            'factory' => 'five_dice_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Five Dice',
        ],
        'checkers' => [
            'manifest' => dirname(__DIR__) . '/extensions/checkers/extension.json',
            'adapter' => __DIR__ . '/checkers_extension.php',
            'factory' => 'checkers_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Checkers',
        ],
        'chess' => [
            'manifest' => dirname(__DIR__) . '/extensions/chess/extension.json',
            'adapter' => __DIR__ . '/chess_extension.php',
            'factory' => 'chess_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Chess',
        ],
        'acey-deucy' => [
            'manifest' => dirname(__DIR__) . '/extensions/acey-deucy/extension.json',
            'adapter' => __DIR__ . '/acey_deucy_extension.php',
            'factory' => 'acey_deucy_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Acey Deucy',
        ],
        'battleship' => [
            'manifest' => dirname(__DIR__) . '/extensions/battleship/extension.json',
            'adapter' => __DIR__ . '/battleship_extension.php',
            'factory' => 'battleship_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Battleship',
        ],
        'spades' => [
            'manifest' => dirname(__DIR__) . '/extensions/spades/extension.json',
            'adapter' => __DIR__ . '/spades_extension.php',
            'factory' => 'spades_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Spades',
        ],
        'blackjack' => [
            'manifest' => dirname(__DIR__) . '/extensions/blackjack/extension.json',
            'adapter' => __DIR__ . '/blackjack_extension.php',
            'factory' => 'blackjack_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Blackjack',
        ],
        'hearts' => [
            'manifest' => dirname(__DIR__) . '/extensions/hearts/extension.json',
            'adapter' => __DIR__ . '/hearts_extension.php',
            'factory' => 'hearts_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Hearts',
        ],
        'uno' => [
            'manifest' => dirname(__DIR__) . '/extensions/uno/extension.json',
            'adapter' => __DIR__ . '/uno_extension.php',
            'factory' => 'uno_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'UNO',
        ],
        'chinese-checkers' => [
            'manifest' => dirname(__DIR__) . '/extensions/chinese-checkers/extension.json',
            'adapter' => __DIR__ . '/chinese_checkers_extension.php',
            'factory' => 'chinese_checkers_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Chinese Checkers',
        ],
        'eight-ball' => ['manifest'=>dirname(__DIR__).'/extensions/eight-ball/extension.json','adapter'=>__DIR__.'/eight_ball_extension.php','factory'=>'eight_ball_extension_adapter','activationCapability'=>'game.rules.authoritative','introducedStorageSchema'=>1,'publicFallbackName'=>'Pool'],
        'dominos' => ['manifest'=>dirname(__DIR__).'/extensions/dominos/extension.json','adapter'=>__DIR__.'/dominos_extension.php','factory'=>'dominos_extension_adapter','activationCapability'=>'game.rules.authoritative','introducedStorageSchema'=>1,'publicFallbackName'=>'Dominos'],
        'nested-four' => [
            'manifest' => dirname(__DIR__) . '/extensions/nested-four/extension.json',
            'adapter' => __DIR__ . '/nested_four_extension.php',
            'factory' => 'nested_four_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Nested Four',
        ],
        'puppy-panic' => [
            'manifest' => dirname(__DIR__) . '/extensions/puppy-panic/extension.json',
            'adapter' => __DIR__ . '/puppy_panic_extension.php',
            'factory' => 'puppy_panic_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Puppy Panic!',
        ],
        'backgammon-first-party' => [
            'manifest' => dirname(__DIR__) . '/extensions/backgammon-first-party/extension.json',
            'adapter' => __DIR__ . '/backgammon_extension.php',
            'factory' => 'backgammon_extension_adapter',
            'activationCapability' => 'game.rules.authoritative',
            'introducedStorageSchema' => 1,
            'publicFallbackName' => 'Backgammon',
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
        'canvas.document.read',
        'canvas.document.write',
        'canvas.comment',
        'canvas.manage',
        'canvas.publish',
        'presentation.canvas',
        'game.registry.describe',
        'game.session.use',
        'game.rules.authoritative',
        'game.presentation.private-media',
        'game.presentation.pack',
        'game.rules.help',
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
        'presentation.canvas',
        'presentation.games',
    ];
}

function first_party_extension_setting_defaults(): array {
    return [
        'first_party_extension.strict_integrity' => '0',
        'first_party_extension.private-site-branding.enabled' => '1',
        'first_party_extension.private-site-branding.lifecycle_revision' => '1',
        'first_party_extension.private-site-branding.storage_schema' => '1',
        'first_party_extension.private-site-branding.last_failure' => '',
        'first_party_extension.gesture-maker.enabled' => '1',
        'first_party_extension.gesture-maker.lifecycle_revision' => '1',
        'first_party_extension.gesture-maker.storage_schema' => '1',
        'first_party_extension.gesture-maker.last_failure' => '',
        'first_party_extension.canvas.enabled' => '1',
        'first_party_extension.canvas.lifecycle_revision' => '1',
        'first_party_extension.canvas.storage_schema' => '1',
        'first_party_extension.canvas.last_failure' => '',
        'first_party_extension.five-dice.enabled' => '1',
        'first_party_extension.five-dice.lifecycle_revision' => '1',
        'first_party_extension.five-dice.storage_schema' => '1',
        'first_party_extension.five-dice.last_failure' => '',
        'first_party_extension.checkers.enabled' => '1',
        'first_party_extension.checkers.lifecycle_revision' => '1',
        'first_party_extension.checkers.storage_schema' => '1',
        'first_party_extension.checkers.last_failure' => '',
        'first_party_extension.chess.enabled' => '1',
        'first_party_extension.chess.lifecycle_revision' => '1',
        'first_party_extension.chess.storage_schema' => '1',
        'first_party_extension.chess.last_failure' => '',
        'first_party_extension.acey-deucy.enabled' => '1',
        'first_party_extension.acey-deucy.lifecycle_revision' => '1',
        'first_party_extension.acey-deucy.storage_schema' => '1',
        'first_party_extension.acey-deucy.last_failure' => '',
        'first_party_extension.battleship.enabled' => '1',
        'first_party_extension.battleship.lifecycle_revision' => '1',
        'first_party_extension.battleship.storage_schema' => '1',
        'first_party_extension.battleship.last_failure' => '',
        'first_party_extension.spades.enabled' => '1',
        'first_party_extension.spades.lifecycle_revision' => '1',
        'first_party_extension.spades.storage_schema' => '1',
        'first_party_extension.spades.last_failure' => '',
        'first_party_extension.blackjack.enabled' => '1',
        'first_party_extension.blackjack.lifecycle_revision' => '1',
        'first_party_extension.blackjack.storage_schema' => '1',
        'first_party_extension.blackjack.last_failure' => '',
        'first_party_extension.hearts.enabled' => '1',
        'first_party_extension.hearts.lifecycle_revision' => '1',
        'first_party_extension.hearts.storage_schema' => '1',
        'first_party_extension.hearts.last_failure' => '',
        'first_party_extension.uno.enabled' => '1',
        'first_party_extension.uno.lifecycle_revision' => '1',
        'first_party_extension.uno.storage_schema' => '1',
        'first_party_extension.uno.last_failure' => '',
        'first_party_extension.chinese-checkers.enabled' => '1',
        'first_party_extension.chinese-checkers.lifecycle_revision' => '1',
        'first_party_extension.chinese-checkers.storage_schema' => '1',
        'first_party_extension.chinese-checkers.last_failure' => '',
        'first_party_extension.eight-ball.enabled'=>'1', 'first_party_extension.eight-ball.lifecycle_revision'=>'1', 'first_party_extension.eight-ball.storage_schema'=>'1', 'first_party_extension.eight-ball.last_failure'=>'',
        'first_party_extension.dominos.enabled'=>'1', 'first_party_extension.dominos.lifecycle_revision'=>'1', 'first_party_extension.dominos.storage_schema'=>'1', 'first_party_extension.dominos.last_failure'=>'',
        'first_party_extension.nested-four.enabled' => '1',
        'first_party_extension.nested-four.lifecycle_revision' => '1',
        'first_party_extension.nested-four.storage_schema' => '1',
        'first_party_extension.nested-four.last_failure' => '',
        'first_party_extension.puppy-panic.enabled' => '1',
        'first_party_extension.puppy-panic.lifecycle_revision' => '1',
        'first_party_extension.puppy-panic.storage_schema' => '1',
        'first_party_extension.puppy-panic.last_failure' => '',
        'first_party_extension.backgammon-first-party.enabled' => '1',
        'first_party_extension.backgammon-first-party.lifecycle_revision' => '1',
        'first_party_extension.backgammon-first-party.storage_schema' => '1',
        'first_party_extension.backgammon-first-party.last_failure' => '',
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
        'canvas.document.projection' => 'canvas.document.read',
        'canvas.document.commands' => 'canvas.document.write',
        'canvas.comment.commands' => 'canvas.comment',
        'canvas.permissions' => 'canvas.manage',
        'canvas.publish' => 'canvas.publish',
        'game.registry.projection' => 'game.registry.describe',
        'game.framework.sessions' => 'game.session.use',
        'game.rules.authoritative' => 'game.rules.authoritative',
        'game.presentation.private-media' => 'game.presentation.private-media',
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
    $requiredFiles = $decoded['requiredFiles'] ?? null;
    if (!is_array($requiredFiles) || !array_is_list($requiredFiles) || !$requiredFiles
        || count($requiredFiles) > 1024 || count(array_unique($requiredFiles, SORT_REGULAR)) !== count($requiredFiles)) {
        throw new RuntimeException('First-party extension required-file declaration is invalid.');
    }
    foreach ($requiredFiles as $relative) first_party_extension_required_path($relative);
    if (array_key_exists('game', $decoded)) {
        $game = $decoded['game'];
        if (!is_array($game)
            || !in_array('game.registry.describe', $capabilities, true)
            || !in_array('game.session.use', $capabilities, true)
            || !in_array('game.registry.projection', $services, true)
            || !in_array('game.framework.sessions', $services, true)
            || !in_array('presentation.games', $subscriptions, true)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string)($game['key'] ?? ''))
            || trim((string)($game['name'] ?? '')) === ''
            || !in_array((string)($game['profile'] ?? ''), ['one-player','versus'], true)
            || (int)($game['minPlayers'] ?? 0) < 1
            || (int)($game['maxPlayers'] ?? 0) < (int)($game['minPlayers'] ?? 0)
            || (int)($game['maxPlayers'] ?? 0) > 10
            || (int)($game['practiceMinPlayers'] ?? $game['minPlayers']) < 1
            || (int)($game['practiceMinPlayers'] ?? $game['minPlayers']) > (int)$game['maxPlayers']
            || (int)($game['recordedMinPlayers'] ?? $game['minPlayers']) < 1
            || (int)($game['recordedMinPlayers'] ?? $game['minPlayers']) > (int)$game['maxPlayers']
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string)($game['path'] ?? ''))
            || !preg_match('/^[A-Za-z0-9._-]{1,96}$/', (string)($game['entry'] ?? ''))
            || !preg_match('/^[A-Za-z0-9._-]{1,96}$/', (string)($game['icon'] ?? ''))
            || !preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/i', (string)($game['adaptationVersion'] ?? ''))
            || !is_array($game['seats'] ?? null)
            || count($game['seats']) !== (int)$game['maxPlayers']
            || !is_array($game['presentationPacks'] ?? null)
            || ($game['presentationPacks'] ?? []) === []) {
            throw new RuntimeException('First-party game extension descriptor is invalid.');
        }
        if (array_key_exists('displayNameSettingKey', $game)
            && !preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', (string)$game['displayNameSettingKey'])) {
            throw new RuntimeException('First-party game display-name setting identity is invalid.');
        }
        $selectionOwner = (string)($game['presentationSelectionOwner'] ?? 'viewer');
        if (!in_array($selectionOwner, ['viewer', 'installation-owner'], true)) {
            throw new RuntimeException('First-party game presentation selection owner is invalid.');
        }
        if ($selectionOwner === 'installation-owner'
            && !preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', (string)($game['presentationPackSettingKey'] ?? ''))) {
            throw new RuntimeException('First-party game presentation setting identity is invalid.');
        }
        $packIds = array_column((array)$game['presentationPacks'], 'id');
        if (array_key_exists('defaultPresentationPack', $game)
            && !in_array((string)$game['defaultPresentationPack'], $packIds, true)) {
            throw new RuntimeException('First-party game default presentation pack is invalid.');
        }
        if (array_key_exists('scoreRecordsSurface', $game)) {
            $surface = $game['scoreRecordsSurface'];
            if (!is_array($surface)
                || trim((string)($surface['label'] ?? '')) === ''
                || empty($surface['separate'])
                || empty($surface['presentationOnly'])) {
                throw new RuntimeException('First-party game Score & Records descriptor is invalid.');
            }
        }
        if (array_key_exists('soundDefaults', $game)) {
            $sound = $game['soundDefaults'];
            if (!is_array($sound)
                || !is_int($sound['masterVolume'] ?? null)
                || (int)$sound['masterVolume'] < 0
                || (int)$sound['masterVolume'] > 100
                || !is_bool($sound['musicEnabled'] ?? null)
                || !is_bool($sound['voiceEnabled'] ?? null)
                || !is_bool($sound['effectsEnabled'] ?? null)) {
                throw new RuntimeException('First-party game sound defaults are invalid.');
            }
        }
        foreach ($game['seats'] as $seat) {
            if (trim((string)$seat) === '' || strlen((string)$seat) > 64) {
                throw new RuntimeException('First-party game extension seat label is invalid.');
            }
        }
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

/** Required paths stay structural; only released-byte equality is optional. */
function first_party_extension_required_path(mixed $relative): string {
    if (!is_string($relative) || $relative === '' || strlen($relative) > 240
        || str_starts_with($relative, '/') || str_contains($relative, '\\')
        || str_contains($relative, ':') || preg_match('/[\x00-\x1f\x7f]/', $relative)
        || preg_match('#(^|/)(\.\.?|)(/|$)#', $relative)) {
        throw new RuntimeException('First-party extension required-file path is invalid.');
    }
    return $relative;
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
    $root = realpath(dirname(__DIR__));
    foreach ($decoded['requiredFiles'] as $relative) {
        $absolute = dirname(__DIR__) . '/' . $relative;
        $resolved = realpath($absolute);
        if (!is_file($absolute) || !is_readable($absolute) || is_link($absolute)
            || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('First-party extension required file is missing or unsafe: ' . $relative);
        }
    }
    return $cache[$extensionId] = $decoded;
}

/** Shared inventory, read once per request. Never rewrites or accepts changed bytes. */
function first_party_extension_release_inventory(): ?array {
    static $loaded = false, $inventory = null;
    if ($loaded) return $inventory;
    $loaded = true;
    $path = dirname(__DIR__) . '/release-manifest.json';
    if (!is_file($path) || !is_readable($path) || filesize($path) > 4194304) return null;
    try {
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || ($decoded['schema'] ?? '') !== 'corechat-deployed-release-v1'
            || !is_array($decoded['files'] ?? null)) return null;
        return $inventory = $decoded['files'];
    } catch (Throwable) { return null; }
}

function first_party_extension_integrity(string $extensionId): array {
    static $cache = [], $hashes = [];
    if (isset($cache[$extensionId])) return $cache[$extensionId];
    $manifest = first_party_extension_manifest($extensionId);
    $inventory = first_party_extension_release_inventory();
    $modified = []; $unverified = [];
    foreach (array_unique(array_merge($manifest['requiredFiles'], ['extensions/' . $extensionId . '/extension.json'])) as $relative) {
        $expected = $inventory[$relative]['sha256'] ?? null;
        if (!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/i', $expected)) {
            $unverified[] = $relative; continue;
        }
        if (!array_key_exists($relative, $hashes)) $hashes[$relative] = hash_file('sha256', dirname(__DIR__) . '/' . $relative);
        if (!is_string($hashes[$relative]) || !hash_equals(strtolower($expected), $hashes[$relative])) $modified[] = $relative;
    }
    return $cache[$extensionId] = [
        'state' => $unverified ? 'unverified' : ($modified ? 'modified' : 'verified'),
        'modifiedFiles' => $modified, 'unverifiedFiles' => $unverified,
        'inventoryAvailable' => $inventory !== null,
    ];
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
    $legacyKey = ['tetris-versus' => 'tetris', 'space-invasion' => 'spaceinvasion'][$extensionId] ?? null;
    $default = $legacyKey === null ? '1' : app_setting($pdo, 'multiplayer_game_enabled_' . $legacyKey, '1');
    return app_setting($pdo, "first_party_extension.{$extensionId}.enabled", $default) === '1';
}

function first_party_extension_status(PDO $pdo, string $extensionId, bool $safeMode = false, bool $inspectIntegrity = false): array {
    try {
        $manifest = first_party_extension_manifest($extensionId);
        $strict = app_setting($pdo, 'first_party_extension.strict_integrity', '0') === '1';
        $integrity = ($strict || $inspectIntegrity) ? first_party_extension_integrity($extensionId) : null;
        $integrityBlocked = $strict && ($integrity['state'] ?? '') !== 'verified';
        $displayName = (string)$manifest['name'];
        $displayNameSetting = trim((string)($manifest['game']['displayNameSettingKey'] ?? ''));
        if ($displayNameSetting !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{2,127}$/', $displayNameSetting)) {
            $configuredName = trim((string)app_setting($pdo, $displayNameSetting, $displayName));
            if ($configuredName !== '' && strlen($configuredName) <= 64
                && !preg_match('/[\x00-\x1F\x7F]/', $configuredName)) {
                $displayName = $configuredName;
            }
        }
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
        if ($integrityBlocked) $state = 'integrity-blocked';
        return [
            'integrity' => $integrity,
            'strictIntegrity' => $strict,
            'id' => $extensionId,
            'name' => $displayName,
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
            'failure' => $integrityBlocked ? 'Strict checksum verification blocked modified or unverified files.' : '',
        ];
    } catch (Throwable $error) {
        $fallbackName = trim((string)(first_party_extension_sources()[$extensionId]['publicFallbackName'] ?? 'Installed feature'));
        return [
            'id' => $extensionId,
            'name' => $fallbackName !== '' ? $fallbackName : 'Installed feature',
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
            'failure' => 'Manifest, compatibility, dependency, or required-file validation failed.',
        ];
    }
}

function first_party_extension_statuses(PDO $pdo, bool $safeMode = false): array {
    $statuses = [];
    try {
        $registry = first_party_extension_registry();
        foreach ($registry['loadOrder'] as $extensionId) {
            $statuses[] = first_party_extension_status($pdo, $extensionId, $safeMode, true);
        }
    } catch (Throwable) {
        foreach (array_keys(first_party_extension_sources()) as $extensionId) {
            $statuses[] = first_party_extension_status($pdo, $extensionId, $safeMode, true);
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
        'canvas.document.projection' => [
            'service' => $service,
            'endpoint' => '/api/canvas.php',
            'methods' => ['GET'],
            'projection' => 'permission-filtered-canvas',
        ],
        'canvas.document.commands' => [
            'service' => $service,
            'endpoint' => '/api/canvas.php',
            'methods' => ['POST'],
            'commands' => ['save_draft'],
        ],
        'canvas.comment.commands' => [
            'service' => $service,
            'endpoint' => '/api/canvas.php',
            'methods' => ['POST'],
            'commands' => ['add_comment', 'remove_comment'],
        ],
        'canvas.permissions' => [
            'service' => $service,
            'endpoint' => '/api/canvas.php',
            'methods' => ['POST'],
            'commands' => ['set_permission'],
        ],
        'canvas.publish' => [
            'service' => $service,
            'endpoint' => '/api/canvas.php',
            'methods' => ['POST'],
            'commands' => ['publish'],
        ],
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
        'game.registry.projection' => [
            'service' => $service,
            'endpoint' => '/api/game_framework.php?action=catalog',
            'methods' => ['GET'],
            'projection' => 'registry-derived-installed-games',
        ],
        'game.framework.sessions' => [
            'service' => $service,
            'endpoint' => '/api/game_framework.php',
            'methods' => ['GET', 'POST'],
            'projection' => 'authenticated-authoritative-game-sessions',
        ],
        'game.rules.authoritative' => [
            'service' => $service,
            'endpoint' => '/api/game_framework.php',
            'methods' => ['POST'],
            'commands' => ['extension-action'],
            'clientStateAcceptedAsTruth' => false,
        ],
        'game.presentation.private-media' => [
            'service' => $service,
            'endpoint' => $extensionId === 'five-dice'
                ? '/api/five_dice_media.php'
                : '/api/game_media.php?game=' . rawurlencode($extensionId),
            'methods' => ['GET'],
            'privateStorageOnly' => true,
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
        'THIRD_PARTY_NOTICES.md' => 'legal.third-party-notices.read',
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
