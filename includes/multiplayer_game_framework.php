<?php
declare(strict_types=1);
require_once __DIR__ . '/game_recording.php';
require_once __DIR__ . '/multiplayer_game_seating.php';

/**
 * Build 000056 mandatory multiplayer-game framework.
 *
 * Game extensions own rules and presentation. This file owns authenticated
 * sessions, membership, acceptance, authoritative versioning, lifecycle,
 * randomness receipts, immutable results, and viewer-local presentation-pack
 * preferences. The existing games remain compatibility adapters until their
 * later first-party-extension checkpoints.
 */

const MULTIPLAYER_GAME_REGISTRY_REVISION = 3;
const MULTIPLAYER_GAME_FRAMEWORK_SCHEMA_VERSION = 1;
const MULTIPLAYER_GAME_RESULT_RETENTION_DAYS = 31;
const MULTIPLAYER_GAME_SESSION_TTL_DAYS = 31;
const MULTIPLAYER_GAME_MAX_PLAYERS = 10;
const MULTIPLAYER_GAME_MAX_SPECTATORS = 64;
const MULTIPLAYER_GAME_RANDOMNESS_TTL_SECONDS = 300;

function multiplayer_game_default_presentation_sizing(): array
{
    return [
        'preferredAspectRatio' => 1.333333,
        'minimumReadableWidth' => 640,
        'minimumReadableHeight' => 480,
    ];
}

final class MultiplayerGameException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'MULTIPLAYER_GAME_FAILED',
        public readonly int $httpStatus = 409,
        public readonly array $facts = []
    ) {
        parent::__construct($message);
    }
}

/**
 * Transitional launch metadata projected through one registry boundary.
 * Later checkpoints replace compatibility=true entries with extension-owned
 * descriptors without changing launcher/session ownership.
 */
function multiplayer_game_registry(bool $includeFirstParty = true): array
{
    $registry = [
        'spaceinvasion' => [
            'key' => 'spaceinvasion', 'name' => 'Space Invasion', 'gameId' => 6,
            'path' => 'spaceinvasion', 'entry' => 'spaceinvasion.html', 'icon' => 'spaceinvasion-icon.png',
            'profile' => 'one-player', 'minPlayers' => 1, 'maxPlayers' => 1,
            'seats' => ['Player'],
            'compatibility' => true, 'extensionId' => null,
            'adaptationVersion' => 'compatibility-1',
            'presentationPacks' => [['id' => 'current', 'label' => 'Current artwork']],
            'presentationSizing' => multiplayer_game_default_presentation_sizing(),
        ],
        'tetris' => [
            'key' => 'tetris', 'name' => 'Tetris Versus', 'gameId' => 7,
            'path' => 'tetris-versus', 'entry' => 'tetris-versus.html', 'icon' => 'tetris-icon.png',
            'profile' => 'versus', 'minPlayers' => 2, 'maxPlayers' => 2,
            'seats' => ['Player 1', 'Player 2'],
            'compatibility' => true, 'extensionId' => null,
            'adaptationVersion' => 'compatibility-1',
            'presentationPacks' => [['id' => 'current', 'label' => 'Current artwork']],
            'presentationSizing' => multiplayer_game_default_presentation_sizing(),
        ],
    ];
    if (!$includeFirstParty) return $registry;
    foreach ((array)(first_party_extension_registry()['manifests'] ?? []) as $extensionId => $manifest) {
        $descriptor = $manifest['game'] ?? null;
        if (!is_array($descriptor)) continue;
        $key = (string)$descriptor['key'];
        if (isset($registry[$key])) {
            throw new MultiplayerGameException('A game registry identity is duplicated.', 'MULTIPLAYER_GAME_REGISTRY_DUPLICATE', 500);
        }
        $registry[$key] = $descriptor + [
            'gameId' => (int)($descriptor['gameId'] ?? 0),
            'compatibility' => false,
            'extensionId' => (string)$extensionId,
            'presentationSizing' => $descriptor['presentationSizing'] ?? multiplayer_game_default_presentation_sizing(),
        ];
    }
    // Catalog cutover only. registry(false) and definition(oldKey) still serve
    // already-open compatibility sessions without rewriting their saved state.
    if (isset($registry['g_b64p3_space'])) unset($registry['spaceinvasion']);
    if (isset($registry['g_b64p3_tetris'])) unset($registry['tetris']);
    return $registry;
}

function multiplayer_game_setting_key(string $gameKey): string
{
    return 'multiplayer_game_enabled_' . preg_replace('/[^a-z0-9_]/', '', strtolower($gameKey));
}

function multiplayer_game_effective_display_name(PDO $pdo, array $definition): string
{
    $fallback = trim((string)($definition['name'] ?? 'Installed game'));
    $settingKey = trim((string)($definition['displayNameSettingKey'] ?? ''));
    if ($settingKey === '') return $fallback;
    $value = trim((string)app_setting($pdo, $settingKey, $fallback));
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($value === '' || $length > 64 || preg_match('/[\x00-\x1F\x7F]/u', $value)) return $fallback;
    return $value;
}

function multiplayer_game_presentation_projection(PDO $pdo, array $definition, int $viewerUserId = 0, ?string $reviewPack = null): array
{
    $packs = array_column((array)($definition['presentationPacks'] ?? []), null, 'id');
    $packIds = array_keys($packs);
    if ($packIds === []) {
        throw new MultiplayerGameException('A game presentation descriptor is invalid.', 'MULTIPLAYER_GAME_REGISTRY_INVALID', 500);
    }
    $selectionOwner = (string)($definition['presentationSelectionOwner'] ?? 'viewer');
    $requested = (string)($definition['defaultPresentationPack'] ?? $packIds[0]);
    if (!isset($packs[$requested])) $requested = $packIds[0];
    if ($selectionOwner === 'installation-owner') {
        $settingKey = (string)($definition['presentationPackSettingKey'] ?? '');
        $requested = app_setting($pdo, $settingKey, $requested);
    } elseif ($viewerUserId > 0 && database_migration_table_exists($pdo, 'multiplayer_game_presentation_preferences')) {
        $stmt = $pdo->prepare('SELECT pack_id FROM multiplayer_game_presentation_preferences WHERE user_id=? AND game_key=? LIMIT 1');
        $stmt->execute([$viewerUserId, (string)$definition['key']]);
        $saved = $stmt->fetchColumn();
        if ($saved !== false) $requested = (string)$saved;
    }
    if ($reviewPack !== null) $requested = $reviewPack;
    if (!isset($packs[$requested])) $requested = $packIds[0];
    $projection = [
        'selectionOwner' => $selectionOwner,
        'requestedPack' => $requested,
        'effectivePack' => $requested,
        'classicAvailable' => $requested === 'classic',
        'fallbackApplied' => false,
        'scoreRecordsSurface' => $definition['scoreRecordsSurface'] ?? null,
        'presentationOnly' => true,
    ];
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $callback = is_array($adapter) ? trim((string)($adapter['presentationStatus'] ?? '')) : '';
    if ($callback !== '') {
        if (!function_exists($callback)) {
            throw new MultiplayerGameException('The installed game presentation is unavailable.', 'MULTIPLAYER_GAME_PRESENTATION_UNAVAILABLE', 500);
        }
        $extensionProjection = $callback($pdo, $requested, $viewerUserId, $definition);
        if (!is_array($extensionProjection)) {
            throw new MultiplayerGameException('The installed game presentation is invalid.', 'MULTIPLAYER_GAME_PRESENTATION_INVALID', 500);
        }
        $projection = array_replace($projection, $extensionProjection);
    }
    if (!isset($packs[(string)$projection['effectivePack']])
        || !isset($packs[(string)$projection['requestedPack']])
        || empty($projection['presentationOnly'])) {
        throw new MultiplayerGameException('The installed game presentation is invalid.', 'MULTIPLAYER_GAME_PRESENTATION_INVALID', 500);
    }
    return $projection + ['packs' => array_values($packs)];
}

function multiplayer_game_extension_adapter(PDO $pdo, array $definition): ?array
{
    $extensionId = trim((string)($definition['extensionId'] ?? ''));
    if ($extensionId === '') return null;
    return first_party_extension_adapter($pdo, $extensionId, 'game.rules.authoritative');
}

function multiplayer_game_validate_initial_state_result(mixed $state): array
{
    if (!is_array($state)) {
        throw new MultiplayerGameException(
            'The installed game returned invalid initial state.',
            'MULTIPLAYER_GAME_RULE_RESULT_INVALID',
            500
        );
    }
    return $state;
}

function multiplayer_game_inactivity_defaults(array $definition): array
{
    return match ((string)($definition['extensionId'] ?? '')) {
        'battleship' => ['label' => 'Battleship', 'seconds' => 30, 'placementSeconds' => 180],
        'spades' => ['label' => 'Spades', 'seconds' => 45],
        'blackjack' => ['label' => 'Blackjack', 'seconds' => 60],
        'five-dice' => ['label' => 'Five Dice', 'seconds' => 90],
        'checkers' => ['label' => 'Checkers', 'seconds' => 120],
        'chess' => ['label' => 'Chess', 'seconds' => 180],
        'backgammon-first-party' => ['label' => 'Backgammon', 'seconds' => 120],
        'acey-deucy' => ['label' => 'Acey Deucy', 'seconds' => 180],
        default => ['label' => 'Game', 'seconds' => 120],
    };
}

function multiplayer_game_validate_shared_settings(array $settings, string $mode, array $definition): array
{
    $profile = strtolower(trim((string)($settings['inactivityProfile'] ?? ($mode === 'practice' ? 'unlimited' : 'default'))));
    $allowedProfiles = $mode === 'practice'
        ? ['quick', 'default', 'relaxed', 'custom', 'unlimited']
        : ['quick', 'default', 'relaxed', 'custom'];
    if (!in_array($profile, $allowedProfiles, true)) {
        throw new MultiplayerGameException('Choose a supported inactivity profile.', 'MULTIPLAYER_GAME_INACTIVITY_PROFILE_INVALID', 422);
    }
    $defaults = multiplayer_game_inactivity_defaults($definition);
    $custom = (int)($settings['customInactivitySeconds'] ?? $defaults['seconds']);
    if ($custom < 15 || $custom > 3600) {
        throw new MultiplayerGameException('Custom inactivity time must be from 15 through 3600 seconds.', 'MULTIPLAYER_GAME_INACTIVITY_CUSTOM_INVALID', 422);
    }
    $validated = [
        'inactivityProfile' => $profile,
        'customInactivitySeconds' => $custom,
    ];
    if (isset($defaults['placementSeconds'])) {
        $placement = (int)($settings['customPlacementInactivitySeconds'] ?? $defaults['placementSeconds']);
        if ($placement < 60 || $placement > 3600) {
            throw new MultiplayerGameException('Custom placement inactivity time must be from 60 through 3600 seconds.', 'MULTIPLAYER_GAME_INACTIVITY_CUSTOM_INVALID', 422);
        }
        $validated['customPlacementInactivitySeconds'] = $placement;
    }
    return $validated;
}

function multiplayer_game_extension_only_settings(array $settings): array
{
    return array_diff_key($settings, array_fill_keys(
        ['inactivityProfile', 'customInactivitySeconds', 'customPlacementInactivitySeconds'],
        true
    ));
}

function multiplayer_game_shared_settings_controls(array $settings, string $mode, array $definition): array
{
    $settings = multiplayer_game_validate_shared_settings($settings, $mode, $definition);
    $defaults = multiplayer_game_inactivity_defaults($definition);
    $options = [
        ['value' => 'quick', 'label' => 'Quick'],
        ['value' => 'default', 'label' => 'Default'],
        ['value' => 'relaxed', 'label' => 'Relaxed'],
        ['value' => 'custom', 'label' => 'Custom'],
    ];
    if ($mode === 'practice') $options[] = ['value' => 'unlimited', 'label' => 'Unlimited — Practice'];
    $controls = [[
        'key' => 'inactivityProfile',
        'type' => 'select',
        'value' => $settings['inactivityProfile'],
        'defaultValue' => $mode === 'practice' ? 'unlimited' : 'default',
        'label' => 'Inactivity protection',
        'description' => 'Used in Ranked or Recorded play only when no competitive game clock is active. Quick is half the game-specific Default; Relaxed is twice Default. Only accepted gameplay progress resets the server deadline.',
        'options' => $options,
    ], [
        'key' => 'customInactivitySeconds',
        'type' => 'stepper',
        'value' => $settings['customInactivitySeconds'],
        'defaultValue' => (int)$defaults['seconds'],
        'minimum' => 15,
        'maximum' => 3600,
        'step' => 15,
        'label' => 'Custom inactivity seconds',
        'description' => 'Used only by Custom. The bounded server deadline is 15 through 3600 seconds.',
    ]];
    if (isset($defaults['placementSeconds'])) {
        $controls[] = [
            'key' => 'customPlacementInactivitySeconds',
            'type' => 'stepper',
            'value' => $settings['customPlacementInactivitySeconds'],
            'defaultValue' => (int)$defaults['placementSeconds'],
            'minimum' => 60,
            'maximum' => 3600,
            'step' => 30,
            'label' => 'Custom placement seconds',
            'description' => 'Battleship placement is one absolute server deadline; repositioning ships never refreshes it.',
        ];
    }
    return $controls;
}

function multiplayer_game_validate_extension_settings(
    PDO $pdo,
    array $definition,
    array $settings,
    string $mode
): array {
    multiplayer_game_assert_bot_mode($mode, [], $settings);
    $sharedKeys = ['inactivityProfile', 'customInactivitySeconds', 'customPlacementInactivitySeconds'];
    $sharedInput = array_intersect_key($settings, array_fill_keys($sharedKeys, true));
    $extensionInput = array_diff_key($settings, array_fill_keys($sharedKeys, true));
    $shared = multiplayer_game_validate_shared_settings($sharedInput, $mode, $definition);
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    if (!is_array($adapter)) return $extensionInput + $shared;
    $callback = trim((string)($adapter['validateSettings'] ?? ''));
    if ($callback === '') return $extensionInput + $shared;
    if (!function_exists($callback)) {
        throw new MultiplayerGameException('The installed game settings validator is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
    }
    $validated = $callback($extensionInput, $mode, $definition);
    if (!is_array($validated)) {
        throw new MultiplayerGameException('The installed game settings are invalid.', 'MULTIPLAYER_GAME_SETTINGS_INVALID', 500);
    }
    return $validated + $shared;
}

/** Shared invariant for every adapter: virtual players can never enter rankings. */
function multiplayer_game_assert_bot_mode(string $mode, array $state = [], array $settings = []): void
{
    if ($mode === 'practice') return;
    $hasBots = !empty($state['bots']);
    foreach ((array)($state['turnOrder'] ?? []) as $id) $hasBots = $hasBots || (int)$id < 0;
    foreach ($settings as $key => $value) {
        if (preg_match('/^botSeat[1-9][0-9]*Difficulty$/D', (string)$key) && $value !== 'none') $hasBots = true;
    }
    foreach ((array)($state['_framework']['players'] ?? []) as $player) {
        if (is_array($player) && (!empty($player['bot']) || (int)($player['userId'] ?? 0) < 0)) $hasBots = true;
    }
    if ($hasBots) throw new MultiplayerGameException('Games with bots are Practice only and cannot update rankings or Recorded records.', 'MULTIPLAYER_GAME_BOTS_PRACTICE_ONLY', 422);
}

function multiplayer_game_settings_control_projection(
    PDO $pdo,
    array $definition,
    array $settings,
    string $mode
): array {
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $callback = is_array($adapter) ? trim((string)($adapter['settingsProjection'] ?? '')) : '';
    $projection = [
        'label' => 'Game Options',
        'description' => 'Every player accepts the shared server-authoritative options before play begins.',
        'classification' => 'standard',
        'classificationLabel' => 'Standard Rules',
        'controls' => [],
    ];
    if ($callback !== '') {
        if (!function_exists($callback)) {
            throw new MultiplayerGameException('The installed game settings surface is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
        }
        $projection = $callback(multiplayer_game_extension_only_settings($settings), $mode, $definition);
        if (!is_array($projection) || trim((string)($projection['label'] ?? '')) === '' || !is_array($projection['controls'] ?? null)) {
            throw new MultiplayerGameException('The installed game settings surface is invalid.', 'MULTIPLAYER_GAME_SETTINGS_INVALID', 500);
        }
    }
    $projection['controls'] = array_merge(
        array_values($projection['controls']),
        multiplayer_game_shared_settings_controls($settings, $mode, $definition)
    );
    $seen = [];
    foreach ($projection['controls'] as $control) {
        $key = trim((string)($control['key'] ?? ''));
        $type = trim((string)($control['type'] ?? ''));
        $valid = is_array($control)
            && preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $key)
            && !isset($seen[$key])
            && trim((string)($control['label'] ?? '')) !== ''
            && trim((string)($control['description'] ?? '')) !== '';
        if ($valid && $type === 'checkbox') {
            $valid = is_bool($control['value'] ?? null)
                && (!array_key_exists('defaultValue', $control) || is_bool($control['defaultValue']));
        } elseif ($valid && in_array($type, ['select', 'choice-grid', 'button-choice'], true)) {
            $options = is_array($control['options'] ?? null) ? array_values($control['options']) : [];
            $serialized = [];
            foreach ($options as $option) {
                $value = is_array($option) ? ($option['value'] ?? null) : null;
                $label = is_array($option) ? trim((string)($option['label'] ?? '')) : '';
                $encoded = is_int($value) || is_string($value) || is_bool($value)
                    ? multiplayer_game_canonical_json($value)
                    : '';
                if ($label === '' || $encoded === '' || isset($serialized[$encoded])) {
                    $valid = false;
                    break;
                }
                $serialized[$encoded] = true;
            }
            $current = $control['value'] ?? null;
            $default = $control['defaultValue'] ?? null;
            $valid = $valid && $options !== []
                && isset($serialized[multiplayer_game_canonical_json($current)])
                && isset($serialized[multiplayer_game_canonical_json($default)]);
        } elseif ($valid && $type === 'stepper') {
            $minimum = $control['minimum'] ?? null;
            $maximum = $control['maximum'] ?? null;
            $step = $control['step'] ?? null;
            $current = $control['value'] ?? null;
            $default = $control['defaultValue'] ?? null;
            $shortcuts = is_array($control['shortcuts'] ?? null) ? array_values($control['shortcuts']) : [];
            $valid = is_int($minimum) && is_int($maximum) && is_int($step)
                && is_int($current) && is_int($default)
                && $minimum < $maximum && $step > 0
                && $current >= $minimum && $current <= $maximum
                && $default >= $minimum && $default <= $maximum
                && (($current - $minimum) % $step) === 0
                && (($default - $minimum) % $step) === 0;
            foreach ($shortcuts as $shortcut) {
                $value = is_array($shortcut) ? ($shortcut['value'] ?? null) : null;
                $label = is_array($shortcut) ? trim((string)($shortcut['label'] ?? '')) : '';
                if (!is_int($value) || $label === '' || $value < $minimum || $value > $maximum
                    || (($value - $minimum) % $step) !== 0) {
                    $valid = false;
                    break;
                }
            }
        } else {
            $valid = false;
        }
        if (!$valid) {
            throw new MultiplayerGameException('The installed game settings control is invalid.', 'MULTIPLAYER_GAME_SETTINGS_INVALID', 500);
        }
        $seen[$key] = true;
    }
    return $projection + ['controls' => [], 'acceptanceBound' => true, 'lockedAfterStart' => true];
}

function multiplayer_game_rules_projection(
    PDO $pdo,
    array $definition,
    array $settings,
    string $mode
): array {
    $fallback = is_array($definition['rulesHelp'] ?? null) ? $definition['rulesHelp'] : [];
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $callback = is_array($adapter) ? trim((string)($adapter['rulesProjection'] ?? '')) : '';
    $projection = $fallback;
    if ($projection === [] && $adapter === null && !empty($definition['compatibility'])) {
        $name = trim((string)($definition['name'] ?? 'Game'));
        $projection = [
            'label' => $name . ' rules',
            'description' => 'This compatibility game continues to use its existing rules and controls until its assigned first-party extension migration. Review the game surface before accepting play.',
        ];
    }
    if ($callback !== '') {
        if (!function_exists($callback)) {
            throw new MultiplayerGameException('The installed game Rules information is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
        }
        $projection = $callback(multiplayer_game_extension_only_settings($settings), $mode, $definition);
    }
    if (!is_array($projection)
        || trim((string)($projection['label'] ?? '')) === ''
        || trim((string)($projection['description'] ?? '')) === '') {
        throw new MultiplayerGameException('The installed game Rules information is invalid.', 'MULTIPLAYER_GAME_RULES_INVALID', 500);
    }
    return $projection + [
        'controlPattern' => 'compact-information-control',
        'interactions' => ['click', 'tap', 'keyboard'],
        'spectatorReadable' => true,
        'spectatorMutable' => false,
        'mode' => $mode,
    ];
}

function multiplayer_game_project_extension_state(
    PDO $pdo,
    array $definition,
    array $state,
    int $viewerUserId,
    array $context = []
): array {
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $callback = is_array($adapter) ? trim((string)($adapter['projectState'] ?? '')) : '';
    if ($callback === '') return $state;
    if (!function_exists($callback)) {
        throw new MultiplayerGameException('The installed game state projection is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
    }
    $projected = $callback($state, $viewerUserId, $context);
    if (!is_array($projected)) {
        throw new MultiplayerGameException('The installed game state projection is invalid.', 'MULTIPLAYER_GAME_RULE_RESULT_INVALID', 500);
    }
    return $projected;
}

function multiplayer_game_setting_defaults(): array
{
    $defaults = ['multiplayer_game_registry_revision' => (string)MULTIPLAYER_GAME_REGISTRY_REVISION];
    foreach (multiplayer_game_registry() as $key => $_game) {
        // Existing launch availability is preserved on upgrades and clean installs.
        $defaults[multiplayer_game_setting_key($key)] = '1';
    }
    return $defaults;
}

function multiplayer_game_schema_statements(PDO $pdo): array
{
    if (db_uses_mysql_syntax($pdo)) {
        return [
            "CREATE TABLE IF NOT EXISTS multiplayer_game_sessions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id CHAR(36) NOT NULL UNIQUE,
                game_key VARCHAR(64) NOT NULL,
                extension_id VARCHAR(128) DEFAULT NULL,
                source_room_session_id INT NOT NULL,
                master_user_id INT NOT NULL,
                mode VARCHAR(24) NOT NULL,
                profile VARCHAR(24) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'lobby',
                settings_json LONGTEXT NOT NULL,
                settings_sha256 CHAR(64) NOT NULL,
                state_json LONGTEXT NOT NULL,
                state_version BIGINT NOT NULL DEFAULT 0,
                turn_user_id INT DEFAULT NULL,
                result_public_id CHAR(36) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                INDEX idx_multiplayer_game_room (source_room_session_id,status,updated_at),
                INDEX idx_multiplayer_game_lifecycle (status,expires_at),
                CONSTRAINT fk_multiplayer_game_master FOREIGN KEY (master_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_members (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                game_session_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                participant_id INT DEFAULT NULL,
                role VARCHAR(24) NOT NULL,
                seat_number INT DEFAULT NULL,
                membership_status VARCHAR(24) NOT NULL DEFAULT 'active',
                client_epoch VARCHAR(128) NOT NULL,
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reconnect_deadline_at DATETIME DEFAULT NULL,
                departed_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_multiplayer_game_member (game_session_id,user_id),
                UNIQUE KEY uq_multiplayer_game_seat (game_session_id,seat_number),
                INDEX idx_multiplayer_game_member_user (user_id,membership_status),
                CONSTRAINT fk_multiplayer_game_member_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_multiplayer_game_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_acceptances (
                game_session_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                settings_sha256 CHAR(64) NOT NULL,
                player_set_sha256 CHAR(64) NOT NULL,
                accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (game_session_id,user_id),
                CONSTRAINT fk_multiplayer_game_accept_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_multiplayer_game_accept_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_actions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                game_session_id BIGINT NOT NULL,
                request_id VARCHAR(96) NOT NULL,
                actor_user_id INT NOT NULL,
                expected_version BIGINT NOT NULL,
                resulting_version BIGINT NOT NULL,
                action_type VARCHAR(64) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                receipt_sha256 CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_multiplayer_game_action_request (game_session_id,request_id),
                UNIQUE KEY uq_multiplayer_game_action_version (game_session_id,resulting_version),
                CONSTRAINT fk_multiplayer_game_action_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_multiplayer_game_action_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_randomness (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                game_session_id BIGINT NOT NULL,
                request_id VARCHAR(96) NOT NULL,
                actor_user_id INT NOT NULL,
                mode VARCHAR(24) NOT NULL,
                purpose VARCHAR(64) NOT NULL,
                state_version BIGINT NOT NULL,
                commitment_sha256 CHAR(64) NOT NULL,
                reveal_json LONGTEXT DEFAULT NULL,
                receipt_sha256 CHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                revealed_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_multiplayer_game_random_request (game_session_id,request_id),
                CONSTRAINT fk_multiplayer_game_random_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_votes (
                game_session_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                vote_type VARCHAR(32) NOT NULL,
                vote_value VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (game_session_id,user_id,vote_type),
                CONSTRAINT fk_multiplayer_game_vote_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_results (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                public_id CHAR(36) NOT NULL UNIQUE,
                game_session_public_id CHAR(36) NOT NULL UNIQUE,
                game_key VARCHAR(64) NOT NULL,
                adaptation_version VARCHAR(64) NOT NULL,
                mode VARCHAR(24) NOT NULL,
                exact_player_set_sha256 CHAR(64) NOT NULL,
                result_json LONGTEXT NOT NULL,
                result_sha256 CHAR(64) NOT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                correction_of_public_id CHAR(36) DEFAULT NULL,
                correction_reason VARCHAR(500) DEFAULT NULL,
                INDEX idx_multiplayer_game_results_lookup (game_key,recorded_at),
                INDEX idx_multiplayer_game_results_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_result_members (
                result_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                outcome VARCHAR(24) NOT NULL,
                score_value DECIMAL(20,6) DEFAULT NULL,
                PRIMARY KEY (result_id,user_id),
                CONSTRAINT fk_multiplayer_game_result_member FOREIGN KEY (result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_result_pairs (
                result_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                opponent_user_id INT NOT NULL,
                outcome VARCHAR(24) NOT NULL,
                PRIMARY KEY (result_id,user_id,opponent_user_id),
                INDEX idx_multiplayer_game_result_pairs_user (user_id,opponent_user_id),
                CONSTRAINT fk_multiplayer_game_result_pair FOREIGN KEY (result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_result_aggregates (
                game_key VARCHAR(64) NOT NULL,
                outcome VARCHAR(24) NOT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (game_key,outcome)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_seat_requests (
                game_session_id BIGINT NOT NULL,
                user_id INT NOT NULL,
                request_status VARCHAR(24) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME DEFAULT NULL,
                resolved_by_user_id INT DEFAULT NULL,
                PRIMARY KEY (game_session_id,user_id),
                CONSTRAINT fk_multiplayer_game_seat_request_session FOREIGN KEY (game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_multiplayer_game_seat_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_presentation_preferences (
                user_id INT NOT NULL,
                game_key VARCHAR(64) NOT NULL,
                pack_id VARCHAR(64) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id,game_key),
                CONSTRAINT fk_multiplayer_game_preference_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_saves (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                game_key VARCHAR(64) NOT NULL,
                exact_player_set_sha256 CHAR(64) NOT NULL,
                game_session_public_id CHAR(36) NOT NULL,
                saved_by_user_id INT NOT NULL,
                framework_schema_version INT NOT NULL,
                adaptation_version VARCHAR(64) NOT NULL,
                settings_sha256 CHAR(64) NOT NULL,
                state_sha256 CHAR(64) NOT NULL,
                mode VARCHAR(24) NOT NULL,
                session_status VARCHAR(24) NOT NULL,
                state_json LONGTEXT NOT NULL,
                state_version BIGINT NOT NULL,
                saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                UNIQUE KEY uq_multiplayer_game_save (game_key,exact_player_set_sha256),
                CONSTRAINT fk_multiplayer_game_save_user FOREIGN KEY (saved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_options (
                user_id INT NOT NULL,
                game_key VARCHAR(64) NOT NULL,
                master_volume INT NOT NULL DEFAULT 100,
                music_enabled TINYINT(1) NOT NULL DEFAULT 1,
                voice_enabled TINYINT(1) NOT NULL DEFAULT 1,
                effects_enabled TINYINT(1) NOT NULL DEFAULT 1,
                category_json LONGTEXT NOT NULL,
                individual_json LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id,game_key),
                CONSTRAINT fk_multiplayer_game_options_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_result_corrections (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                result_id BIGINT NOT NULL,
                actor_user_id INT NOT NULL,
                reason VARCHAR(500) NOT NULL,
                replacement_json LONGTEXT NOT NULL,
                replacement_sha256 CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_multiplayer_game_corrections (result_id,created_at),
                CONSTRAINT fk_multiplayer_game_correction_result FOREIGN KEY (result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE,
                CONSTRAINT fk_multiplayer_game_correction_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS multiplayer_game_accounting (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                game_session_id BIGINT DEFAULT NULL,
                user_id INT NOT NULL,
                action_class VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_multiplayer_game_accounting (user_id,action_class,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    return [
        "CREATE TABLE IF NOT EXISTS multiplayer_game_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            game_key TEXT NOT NULL,
            extension_id TEXT DEFAULT NULL,
            source_room_session_id INTEGER NOT NULL,
            master_user_id INTEGER NOT NULL,
            mode TEXT NOT NULL CHECK (mode IN ('practice','recorded')),
            profile TEXT NOT NULL CHECK (profile IN ('one-player','versus')),
            status TEXT NOT NULL DEFAULT 'lobby' CHECK (status IN ('lobby','active','paused','completed','forfeited','abandoned','ended')),
            settings_json TEXT NOT NULL,
            settings_sha256 TEXT NOT NULL,
            state_json TEXT NOT NULL DEFAULT '{}',
            state_version INTEGER NOT NULL DEFAULT 0,
            turn_user_id INTEGER DEFAULT NULL,
            result_public_id TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TEXT DEFAULT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            ended_at TEXT DEFAULT NULL,
            FOREIGN KEY(master_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_room ON multiplayer_game_sessions(source_room_session_id,status,updated_at)',
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_lifecycle ON multiplayer_game_sessions(status,expires_at)',
        "CREATE TABLE IF NOT EXISTS multiplayer_game_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_session_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            participant_id INTEGER DEFAULT NULL,
            role TEXT NOT NULL CHECK (role IN ('master','player','spectator')),
            seat_number INTEGER DEFAULT NULL,
            membership_status TEXT NOT NULL DEFAULT 'active' CHECK (membership_status IN ('active','departed','removed')),
            client_epoch TEXT NOT NULL,
            joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reconnect_deadline_at TEXT DEFAULT NULL,
            departed_at TEXT DEFAULT NULL,
            UNIQUE(game_session_id,user_id),
            UNIQUE(game_session_id,seat_number),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_member_user ON multiplayer_game_members(user_id,membership_status)',
        "CREATE TABLE IF NOT EXISTS multiplayer_game_acceptances (
            game_session_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            settings_sha256 TEXT NOT NULL,
            player_set_sha256 TEXT NOT NULL,
            accepted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (game_session_id,user_id),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_session_id INTEGER NOT NULL,
            request_id TEXT NOT NULL,
            actor_user_id INTEGER NOT NULL,
            expected_version INTEGER NOT NULL,
            resulting_version INTEGER NOT NULL,
            action_type TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            receipt_sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(game_session_id,request_id),
            UNIQUE(game_session_id,resulting_version),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_randomness (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_session_id INTEGER NOT NULL,
            request_id TEXT NOT NULL,
            actor_user_id INTEGER NOT NULL,
            mode TEXT NOT NULL,
            purpose TEXT NOT NULL,
            state_version INTEGER NOT NULL,
            commitment_sha256 TEXT NOT NULL,
            reveal_json TEXT DEFAULT NULL,
            receipt_sha256 TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            revealed_at TEXT DEFAULT NULL,
            UNIQUE(game_session_id,request_id),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_votes (
            game_session_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            vote_type TEXT NOT NULL,
            vote_value TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (game_session_id,user_id,vote_type),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            game_session_public_id TEXT NOT NULL UNIQUE,
            game_key TEXT NOT NULL,
            adaptation_version TEXT NOT NULL,
            mode TEXT NOT NULL,
            exact_player_set_sha256 TEXT NOT NULL,
            result_json TEXT NOT NULL,
            result_sha256 TEXT NOT NULL,
            recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            correction_of_public_id TEXT DEFAULT NULL,
            correction_reason TEXT DEFAULT NULL
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_results_lookup ON multiplayer_game_results(game_key,recorded_at)',
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_results_expiry ON multiplayer_game_results(expires_at)',
        "CREATE TABLE IF NOT EXISTS multiplayer_game_result_members (
            result_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            outcome TEXT NOT NULL,
            score_value REAL DEFAULT NULL,
            PRIMARY KEY (result_id,user_id),
            FOREIGN KEY(result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_result_pairs (
            result_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            opponent_user_id INTEGER NOT NULL,
            outcome TEXT NOT NULL CHECK (outcome IN ('win','loss','draw')),
            PRIMARY KEY (result_id,user_id,opponent_user_id),
            FOREIGN KEY(result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_result_pairs_user ON multiplayer_game_result_pairs(user_id,opponent_user_id)',
        "CREATE TABLE IF NOT EXISTS multiplayer_game_result_aggregates (
            game_key TEXT NOT NULL,
            outcome TEXT NOT NULL,
            total_count INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (game_key,outcome)
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_seat_requests (
            game_session_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            request_status TEXT NOT NULL DEFAULT 'pending' CHECK (request_status IN ('pending','approved','declined')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TEXT DEFAULT NULL,
            resolved_by_user_id INTEGER DEFAULT NULL,
            PRIMARY KEY (game_session_id,user_id),
            FOREIGN KEY(game_session_id) REFERENCES multiplayer_game_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_presentation_preferences (
            user_id INTEGER NOT NULL,
            game_key TEXT NOT NULL,
            pack_id TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id,game_key),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_saves (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_key TEXT NOT NULL,
            exact_player_set_sha256 TEXT NOT NULL,
            game_session_public_id TEXT NOT NULL,
            saved_by_user_id INTEGER NOT NULL,
            framework_schema_version INTEGER NOT NULL,
            adaptation_version TEXT NOT NULL,
            settings_sha256 TEXT NOT NULL,
            state_sha256 TEXT NOT NULL,
            mode TEXT NOT NULL,
            session_status TEXT NOT NULL,
            state_json TEXT NOT NULL,
            state_version INTEGER NOT NULL,
            saved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            UNIQUE(game_key,exact_player_set_sha256),
            FOREIGN KEY(saved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_options (
            user_id INTEGER NOT NULL,
            game_key TEXT NOT NULL,
            master_volume INTEGER NOT NULL DEFAULT 100 CHECK (master_volume BETWEEN 0 AND 100),
            music_enabled INTEGER NOT NULL DEFAULT 1 CHECK (music_enabled IN (0,1)),
            voice_enabled INTEGER NOT NULL DEFAULT 1 CHECK (voice_enabled IN (0,1)),
            effects_enabled INTEGER NOT NULL DEFAULT 1 CHECK (effects_enabled IN (0,1)),
            category_json TEXT NOT NULL DEFAULT '{}',
            individual_json TEXT NOT NULL DEFAULT '{}',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id,game_key),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS multiplayer_game_result_corrections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            result_id INTEGER NOT NULL,
            actor_user_id INTEGER NOT NULL,
            reason TEXT NOT NULL,
            replacement_json TEXT NOT NULL,
            replacement_sha256 TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(result_id) REFERENCES multiplayer_game_results(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_corrections ON multiplayer_game_result_corrections(result_id,created_at)',
        "CREATE TABLE IF NOT EXISTS multiplayer_game_accounting (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            game_session_id INTEGER DEFAULT NULL,
            user_id INTEGER NOT NULL,
            action_class TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_multiplayer_game_accounting ON multiplayer_game_accounting(user_id,action_class,created_at)',
    ];
}

function multiplayer_game_install_schema(PDO $pdo): void
{
    foreach (multiplayer_game_schema_statements($pdo) as $sql) $pdo->exec($sql);
    message_protection_add_message_columns($pdo, 'game_chat_messages');
    multiplayer_game_install_game_chat_package_columns($pdo);
    $indexSql = db_uses_mysql_syntax($pdo)
        ? 'CREATE UNIQUE INDEX idx_game_chat_client_protection ON game_chat_messages(user_id,client_message_id)'
        : 'CREATE UNIQUE INDEX IF NOT EXISTS idx_game_chat_client_protection ON game_chat_messages(user_id,client_message_id)';
    try {
        $pdo->exec($indexSql);
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        if (!str_contains($message, 'duplicate') && !str_contains($message, 'exists')) throw $error;
    }
    foreach (multiplayer_game_setting_defaults() as $key => $value) {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() === false) set_app_setting($pdo, $key, $value);
    }
}

function multiplayer_game_build_000056_schema_valid(PDO $pdo): bool
{
    foreach ([
        'multiplayer_game_sessions', 'multiplayer_game_members', 'multiplayer_game_acceptances',
        'multiplayer_game_actions', 'multiplayer_game_randomness', 'multiplayer_game_votes',
        'multiplayer_game_results', 'multiplayer_game_result_members', 'multiplayer_game_result_pairs',
        'multiplayer_game_result_aggregates', 'multiplayer_game_seat_requests',
        'multiplayer_game_presentation_preferences', 'multiplayer_game_accounting',
        'multiplayer_game_saves', 'multiplayer_game_options',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)) return false;
    }
    return database_migration_has_columns($pdo, 'multiplayer_game_sessions', [
        'public_id', 'game_key', 'master_user_id', 'mode', 'profile', 'status',
        'settings_sha256', 'state_json', 'state_version', 'expires_at',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_members', [
        'game_session_id', 'user_id', 'role', 'seat_number', 'client_epoch', 'membership_status',
        'reconnect_deadline_at',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_acceptances', [
        'game_session_id', 'user_id', 'settings_sha256', 'player_set_sha256',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_randomness', [
        'game_session_id', 'request_id', 'purpose', 'state_version', 'commitment_sha256', 'expires_at',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_saves', [
        'game_key', 'exact_player_set_sha256', 'framework_schema_version', 'adaptation_version',
        'settings_sha256', 'state_sha256', 'mode', 'session_status',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_results', [
        'game_key', 'adaptation_version', 'mode', 'exact_player_set_sha256', 'result_sha256',
    ]) && database_migration_has_columns($pdo, 'multiplayer_game_result_pairs', [
        'result_id', 'user_id', 'opponent_user_id', 'outcome',
    ]);
}

function multiplayer_game_schema_valid(PDO $pdo): bool
{
    return multiplayer_game_build_000056_schema_valid($pdo)
        && database_migration_table_exists($pdo, 'multiplayer_game_result_corrections')
        && database_migration_has_columns($pdo, 'multiplayer_game_result_corrections', [
            'result_id', 'actor_user_id', 'reason', 'replacement_json',
            'replacement_sha256', 'created_at',
        ])
        && database_migration_has_columns($pdo, 'game_chat_messages', [
            'original_content', 'url_preview_json', 'reply_to_json',
        ]);
}

function multiplayer_game_install_game_chat_package_columns(PDO $pdo): void
{
    $columns = database_migration_columns($pdo, 'game_chat_messages');
    foreach (['original_content', 'url_preview_json', 'reply_to_json'] as $column) {
        if (in_array($column, $columns, true)) continue;
        $definition = db_uses_mysql_syntax($pdo) ? 'LONGTEXT DEFAULT NULL' : 'TEXT DEFAULT NULL';
        $pdo->exec("ALTER TABLE game_chat_messages ADD COLUMN {$column} {$definition}");
    }
}

function multiplayer_game_canonical_json(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map('multiplayer_game_canonicalize', $value);
        } else {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = multiplayer_game_canonicalize($item);
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function multiplayer_game_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('multiplayer_game_canonicalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = multiplayer_game_canonicalize($item);
    return $value;
}

function multiplayer_game_definition(PDO $pdo, string $key, bool $requireEnabled = true): array
{
    $legacyAliases = ['chess' => 'g_b60c0a02', 'checkers' => 'g_b60c0a01'];
    if (isset($legacyAliases[$key])) $key = $legacyAliases[$key];
    $registry = multiplayer_game_registry(false);
    $definition = $registry[$key] ?? multiplayer_game_extension_definition($key);
    if (!is_array($definition)) {
        throw new MultiplayerGameException('This game is not installed.', 'MULTIPLAYER_GAME_NOT_INSTALLED', 404);
    }
    $extensionId = trim((string)($definition['extensionId'] ?? ''));
    $enabled = $extensionId !== ''
        ? first_party_extension_enabled($pdo, $extensionId)
        : app_setting($pdo, multiplayer_game_setting_key($key), '1') === '1';
    if ($requireEnabled && !$enabled) {
        throw new MultiplayerGameException('This game is disabled by installation policy.', 'MULTIPLAYER_GAME_DISABLED', 403);
    }
    return $definition + ['enabled' => $enabled];
}

function multiplayer_game_extension_definition(string $key): ?array
{
    $matchedExtensionId = null;
    foreach (first_party_extension_sources() as $extensionId => $source) {
        $manifestPath = (string)($source['manifest'] ?? '');
        $size = $manifestPath !== '' && is_file($manifestPath) ? filesize($manifestPath) : false;
        if ($size === false || $size > 65536) continue;
        try {
            $candidate = json_decode((string)file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (!is_array($candidate)
            || !is_array($candidate['game'] ?? null)
            || (string)($candidate['game']['key'] ?? '') !== $key) {
            continue;
        }
        if ($matchedExtensionId !== null) {
            throw new MultiplayerGameException(
                'A game registry identity is duplicated.',
                'MULTIPLAYER_GAME_REGISTRY_DUPLICATE',
                500
            );
        }
        $matchedExtensionId = (string)$extensionId;
    }
    if ($matchedExtensionId === null) return null;

    $manifest = first_party_extension_manifest($matchedExtensionId);
    $descriptor = $manifest['game'] ?? null;
    if (!is_array($descriptor) || (string)($descriptor['key'] ?? '') !== $key) {
        throw new MultiplayerGameException(
            'The installed game descriptor is invalid.',
            'MULTIPLAYER_GAME_REGISTRY_INVALID',
            500
        );
    }
    return $descriptor + [
        'gameId' => (int)($descriptor['gameId'] ?? 0),
        'compatibility' => false,
        'extensionId' => $matchedExtensionId,
        'presentationSizing' => $descriptor['presentationSizing'] ?? multiplayer_game_default_presentation_sizing(),
    ];
}

function multiplayer_game_validate_presentation_packs(array $definition): bool
{
    $ids = [];
    foreach (($definition['presentationPacks'] ?? []) as $pack) {
        $id = (string)($pack['id'] ?? '');
        $label = trim((string)($pack['label'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) || $label === '' || isset($ids[$id])) return false;
        $ids[$id] = true;
    }
    return $ids !== [];
}

function multiplayer_game_validate_extension_metadata(array $definition): bool
{
    if (!multiplayer_game_validate_presentation_packs($definition)) return false;
    $packIds = array_column((array)$definition['presentationPacks'], 'id');
    if (array_key_exists('defaultPresentationPack', $definition)
        && !in_array((string)$definition['defaultPresentationPack'], $packIds, true)) return false;
    $selectionOwner = (string)($definition['presentationSelectionOwner'] ?? 'viewer');
    if (!in_array($selectionOwner, ['viewer', 'installation-owner'], true)) return false;
    if ($selectionOwner === 'installation-owner'
        && !preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', (string)($definition['presentationPackSettingKey'] ?? ''))) return false;
    if (array_key_exists('scoreRecordsSurface', $definition)) {
        $surface = $definition['scoreRecordsSurface'];
        if (!is_array($surface)
            || trim((string)($surface['label'] ?? '')) === ''
            || empty($surface['separate'])
            || empty($surface['presentationOnly'])) return false;
    }
    if (array_key_exists('soundDefaults', $definition)) {
        $sound = $definition['soundDefaults'];
        if (!is_array($sound)
            || !is_int($sound['masterVolume'] ?? null)
            || (int)$sound['masterVolume'] < 0
            || (int)$sound['masterVolume'] > 100
            || !is_bool($sound['musicEnabled'] ?? null)
            || !is_bool($sound['voiceEnabled'] ?? null)
            || !is_bool($sound['effectsEnabled'] ?? null)) return false;
    }
    if (array_key_exists('presentationSizing', $definition)) {
        $sizing = $definition['presentationSizing'];
        if (!is_array($sizing)
            || !is_numeric($sizing['preferredAspectRatio'] ?? null)
            || !is_int($sizing['minimumReadableWidth'] ?? null)
            || !is_int($sizing['minimumReadableHeight'] ?? null)
            || (float)$sizing['preferredAspectRatio'] < 0.5
            || (float)$sizing['preferredAspectRatio'] > 3.0
            || (int)$sizing['minimumReadableWidth'] < 320
            || (int)$sizing['minimumReadableWidth'] > 1600
            || (int)$sizing['minimumReadableHeight'] < 240
            || (int)$sizing['minimumReadableHeight'] > 1200) return false;
    }
    if (!array_key_exists('rulesHelp', $definition)) return true;
    $help = $definition['rulesHelp'];
    if (!is_array($help)) return false;
    $label = trim((string)($help['label'] ?? ''));
    $description = trim((string)($help['description'] ?? ''));
    return $label !== '' && $description !== ''
        && strlen($label) <= 96 && strlen($description) <= 1200;
}

function multiplayer_game_catalog_projection(PDO $pdo, ?int $userId = null): array
{
    $projected = [];
    foreach (multiplayer_game_registry() as $key => $definition) {
        if (!multiplayer_game_validate_extension_metadata($definition)) {
            throw new MultiplayerGameException('A game presentation descriptor is invalid.', 'MULTIPLAYER_GAME_REGISTRY_INVALID', 500);
        }
        $extensionId = trim((string)($definition['extensionId'] ?? ''));
        // Strict failures disable affected games without taking down the library.
        if ($extensionId !== '' && first_party_extension_status($pdo, $extensionId)['state'] === 'integrity-blocked') continue;
        $enabled = $extensionId !== ''
            ? first_party_extension_enabled($pdo, $extensionId)
            : app_setting($pdo, multiplayer_game_setting_key($key), '1') === '1';
        $presentation = multiplayer_game_presentation_projection($pdo, $definition, (int)($userId ?? 0));
        $defaultSettings = multiplayer_game_validate_extension_settings($pdo, $definition, [], 'practice');
        $projected[] = array_replace($definition, [
            'name' => multiplayer_game_effective_display_name($pdo, $definition),
            'enabled' => $enabled,
            'selectedPresentationPack' => (string)$presentation['effectivePack'],
            'requestedPresentationPack' => (string)$presentation['requestedPack'],
            'presentation' => $presentation,
            'registryRevision' => MULTIPLAYER_GAME_REGISTRY_REVISION,
            'presentationSizing' => $definition['presentationSizing'] ?? multiplayer_game_default_presentation_sizing(),
            'rules' => multiplayer_game_rules_projection($pdo, $definition, $defaultSettings, 'practice'),
            'settingsControls' => multiplayer_game_settings_control_projection($pdo, $definition, $defaultSettings, 'practice'),
            'defaultSettings' => $defaultSettings,
        ]);
    }
    return $projected;
}

/**
 * Read only consent-linked earlier rounds in which this viewer was a player.
 * UNION deduplicates votes and stops malformed cycles. Room/type and historical
 * membership are checked on every edge; a newly joined spectator gets no history.
 * Original message lobby and encryption envelope remain unchanged.
 */
function multiplayer_game_rematch_chat_history(PDO $pdo, string $publicId, int $userId, int $since): array
{
    $link = db_uses_mysql_syntax($pdo) ? "CONCAT('started:', h.public_id)" : "'started:' || h.public_id";
    $stmt = $pdo->prepare(
        "WITH RECURSIVE history (id,public_id,game_key,source_room_session_id) AS (
            SELECT s.id,s.public_id,s.game_key,s.source_room_session_id
              FROM multiplayer_game_sessions s
              JOIN multiplayer_game_members m ON m.game_session_id=s.id
             WHERE s.public_id=? AND m.user_id=? AND m.membership_status='active'
            UNION
            SELECT p.id,p.public_id,p.game_key,p.source_room_session_id
              FROM history h
              JOIN multiplayer_game_votes v ON v.vote_type='rematch' AND v.vote_value={$link}
              JOIN multiplayer_game_sessions p ON p.id=v.game_session_id
              JOIN multiplayer_game_members m ON m.game_session_id=p.id
             WHERE p.game_key=h.game_key AND p.source_room_session_id=h.source_room_session_id
               AND p.status IN ('completed','forfeited','abandoned')
               AND m.user_id=? AND m.role IN ('master','player')
               AND m.membership_status IN ('active','departed')
        )
        SELECT gcm.*, COALESCE(gcm.user_id,p.user_id) AS author_user_id,
               COALESCE(NULLIF(gcm.display_name,''),p.display_name,'Player') AS author_display_name,
               p.avatar_path,p.webcam_path,u.role,0 AS is_owner
          FROM game_chat_messages gcm
          JOIN history h ON h.public_id=gcm.lobby_code
          LEFT JOIN participants p ON p.id=gcm.participant_id
          LEFT JOIN users u ON u.id=COALESCE(gcm.user_id,p.user_id)
         WHERE gcm.id>? ORDER BY gcm.id ASC LIMIT 100"
    );
    $stmt->execute([$publicId,$userId,$userId,max(0,$since)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function multiplayer_game_require_member(PDO $pdo, string $publicId, int $userId, array $roles = []): array
{
    $stmt = $pdo->prepare(
        "SELECT s.*,m.role AS member_role,m.seat_number,m.membership_status,m.client_epoch
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.public_id=? AND m.user_id=? AND m.membership_status='active' LIMIT 1"
    );
    $stmt->execute([$publicId, $userId]);
    $row = $stmt->fetch();
    if (!is_array($row) || ($roles !== [] && !in_array((string)$row['member_role'], $roles, true))) {
        throw new MultiplayerGameException('Game-session access is denied.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
    }
    return $row;
}

function multiplayer_game_record_accounting(PDO $pdo, ?int $sessionId, int $userId, string $class): void
{
    $class = strtolower(trim($class));
    if (!in_array($class, ['create','join'], true)) return;
    $pdo->prepare('INSERT INTO multiplayer_game_accounting (game_session_id,user_id,action_class) VALUES (?,?,?)')
        ->execute([$sessionId, $userId, $class]);
}

function multiplayer_game_rotate_message_key_epoch(PDO $pdo, string $publicId): void
{
    $pdo->prepare(
        "UPDATE message_protection_policies
            SET key_epoch=key_epoch+1,revision=revision+1,updated_at=CURRENT_TIMESTAMP
          WHERE conversation_kind='game' AND conversation_key=?"
    )->execute([$publicId]);
}

function multiplayer_game_create_session(
    PDO $pdo,
    int $roomSessionId,
    array $participant,
    string $gameKey,
    string $mode = 'practice',
    array $settings = [],
    string $clientEpoch = ''
): array {
    $gameKey = ['tetris' => 'g_b64p3_tetris', 'spaceinvasion' => 'g_b64p3_space'][$gameKey] ?? $gameKey;
    $definition = multiplayer_game_definition($pdo, $gameKey);
    if (!in_array($mode, ['practice','recorded'], true)) {
        throw new MultiplayerGameException('Choose Practice or Recorded Play.', 'MULTIPLAYER_GAME_MODE_INVALID', 422);
    }
    if ($mode === 'recorded' && (string)$definition['profile'] === 'one-player') {
        throw new MultiplayerGameException('This one-player game supports Practice Mode.', 'MULTIPLAYER_GAME_RECORDED_REQUIRES_MULTIPLAYER', 422);
    }
    $settings = multiplayer_game_validate_extension_settings($pdo, $definition, $settings, $mode);
    $userId = (int)($participant['user_id'] ?? 0);
    $participantId = (int)($participant['id'] ?? 0);
    if ($userId < 1 || $participantId < 1) {
        throw new MultiplayerGameException('An authenticated room participant is required.', 'MULTIPLAYER_GAME_PARTICIPANT_REQUIRED', 403);
    }
    $settingsJson = multiplayer_game_canonical_json($settings);
    if (strlen($settingsJson) > 32768) {
        throw new MultiplayerGameException('Game settings are too large.', 'MULTIPLAYER_GAME_SETTINGS_TOO_LARGE', 422);
    }
    $settingsSha = strtoupper(hash('sha256', $settingsJson));
    $publicId = uuid_v4();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (MULTIPLAYER_GAME_SESSION_TTL_DAYS * 86400));
    $transaction = database_transaction_begin($pdo, true);
    try {
        $pdo->prepare(
            'INSERT INTO multiplayer_game_sessions
             (public_id,game_key,extension_id,source_room_session_id,master_user_id,mode,profile,settings_json,settings_sha256,state_json,expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $publicId, $gameKey, $definition['extensionId'], $roomSessionId, $userId, $mode,
            $definition['profile'], $settingsJson, $settingsSha, '{}', $expiresAt,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO multiplayer_game_members
             (game_session_id,user_id,participant_id,role,seat_number,client_epoch)
             VALUES (?,?,?,?,?,?)"
        )->execute([$id, $userId, $participantId, 'master', 1, substr($clientEpoch ?: uuid_v4(), 0, 128)]);
        multiplayer_game_record_accounting($pdo, $id, $userId, 'create');
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    return multiplayer_game_project_session($pdo, $publicId, $userId);
}

function multiplayer_game_join_session(
    PDO $pdo,
    string $publicId,
    array $participant,
    string $role = 'player',
    string $clientEpoch = ''
): array {
    if (!in_array($role, ['player','spectator'], true)) {
        throw new MultiplayerGameException('Choose player or spectator.', 'MULTIPLAYER_GAME_ROLE_INVALID', 422);
    }
    $userId = (int)($participant['user_id'] ?? 0);
    $participantId = (int)($participant['id'] ?? 0);
    if ($userId < 1 || $participantId < 1) {
        throw new MultiplayerGameException('An authenticated room participant is required.', 'MULTIPLAYER_GAME_PARTICIPANT_REQUIRED', 403);
    }
    $transaction = database_transaction_begin($pdo, true);
    try {
        $sessionSql = "SELECT * FROM multiplayer_game_sessions WHERE public_id=? AND status IN ('lobby','active','paused') AND expires_at>CURRENT_TIMESTAMP LIMIT 1";
        if (db_uses_mysql_syntax($pdo)) $sessionSql .= ' FOR UPDATE';
        $sessionStmt = $pdo->prepare($sessionSql);
        $sessionStmt->execute([$publicId]);
        $session = $sessionStmt->fetch();
        if (!is_array($session)) throw new MultiplayerGameException('Game session is unavailable.', 'MULTIPLAYER_GAME_SESSION_UNAVAILABLE', 404);
        $participantBinding = $pdo->prepare('SELECT 1 FROM participants WHERE id=? AND user_id=? AND session_id=? LIMIT 1');
        $participantBinding->execute([$participantId, $userId, (int)$session['source_room_session_id']]);
        if (!$participantBinding->fetchColumn()) {
            throw new MultiplayerGameException('An authenticated participant in this game room is required.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
        $peers = $pdo->prepare("SELECT user_id FROM multiplayer_game_members WHERE game_session_id=? AND membership_status='active' AND user_id<>?");
        $peers->execute([(int)$session['id'], $userId]);
        foreach (array_map('intval', $peers->fetchAll(PDO::FETCH_COLUMN)) as $peerUserId) {
            $block = $pdo->prepare('SELECT 1 FROM user_blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');
            $block->execute([$userId, $peerUserId, $peerUserId, $userId]);
            if ($block->fetchColumn()) throw new MultiplayerGameException('Game access is unavailable between blocked accounts.', 'MULTIPLAYER_GAME_BLOCKED', 403);
        }
        $existing = $pdo->prepare('SELECT id,participant_id,role,seat_number,membership_status FROM multiplayer_game_members WHERE game_session_id=? AND user_id=?');
        $existing->execute([(int)$session['id'], $userId]);
        $member = $existing->fetch();
        if (is_array($member) && (string)($member['membership_status'] ?? '') === 'departed') {
            throw new MultiplayerGameException(
                'You already exited this game. Rejoin from the room only after a new game is started.',
                'MULTIPLAYER_GAME_REJOIN_AFTER_EXIT_DENIED',
                409
            );
        }
        if (is_array($member) && (string)($member['membership_status'] ?? '') === 'active') {
            $existingRole = (string)$member['role'];
            $sameRole = $role === $existingRole
                || ($role === 'player' && $existingRole === 'master');
            if ((int)($member['participant_id'] ?? 0) !== $participantId) {
                throw new MultiplayerGameException('This game membership belongs to a different room participant.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
            }
            if (!$sameRole) {
                throw new MultiplayerGameException('Reopening a game cannot change your player or spectator role.', 'MULTIPLAYER_GAME_ROLE_CHANGE_DENIED', 409);
            }
            // This exact authenticated room participant already owns this seat.
            // Reopening must not allocate another seat, rebind, or change consent.
            $projection = multiplayer_game_project_session($pdo, $publicId, $userId);
            database_transaction_commit($pdo, $transaction);
            return $projection;
        }
        $countStmt = $pdo->prepare("SELECT role,COUNT(*) AS total FROM multiplayer_game_members WHERE game_session_id=? AND membership_status='active' GROUP BY role");
        $countStmt->execute([(int)$session['id']]);
        $counts = ['master' => 0, 'player' => 0, 'spectator' => 0];
        foreach ($countStmt->fetchAll() as $row) $counts[(string)$row['role']] = (int)$row['total'];
        $playerCount = $counts['master'] + $counts['player'];
        if ($role === 'player' && multiplayer_game_requires_host_start($definition) && $session['status'] !== 'lobby') {
            throw new MultiplayerGameException('Seats are fixed after play starts. Join the next game.', 'MULTIPLAYER_GAME_SEAT_CHOICE_LOCKED', 409);
        }
        if ($role === 'player' && (string)($definition['extensionId'] ?? '') === 'space-invasion') {
            $spaceSettings=json_decode((string)$session['settings_json'],true) ?: [];
            if((string)$session['status']!=='lobby') throw new MultiplayerGameException('Player seats are fixed after this run starts. Start a new Two-player co-op run.','MULTIPLAYER_GAME_SEAT_APPROVAL_STATE_INVALID',409);
            if($playerCount >= (int)($spaceSettings['playerCount'] ?? 1)) throw new MultiplayerGameException('Choose Two-player co-op before starting to add another player.','MULTIPLAYER_GAME_PLAYER_LIMIT',409);
        }
        if ($role === 'player' && $playerCount >= min(MULTIPLAYER_GAME_MAX_PLAYERS, (int)$definition['maxPlayers'])) {
            throw new MultiplayerGameException('All player seats are occupied.', 'MULTIPLAYER_GAME_PLAYER_LIMIT', 409);
        }
        if ($role === 'spectator' && $counts['spectator'] >= MULTIPLAYER_GAME_MAX_SPECTATORS) {
            throw new MultiplayerGameException('The spectator limit has been reached.', 'MULTIPLAYER_GAME_SPECTATOR_LIMIT', 409);
        }
        $seat = null;
        if ($role === 'player') {
            $occupiedSeats = $pdo->prepare(
                "SELECT seat_number FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active' AND seat_number IS NOT NULL ORDER BY seat_number ASC"
            );
            $occupiedSeats->execute([(int)$session['id']]);
            $usedSeats = array_fill_keys(array_map('intval', $occupiedSeats->fetchAll(PDO::FETCH_COLUMN)), true);
            $maximumSeats = min(MULTIPLAYER_GAME_MAX_PLAYERS, (int)$definition['maxPlayers']);
            for ($candidateSeat = 1; $candidateSeat <= $maximumSeats; $candidateSeat++) {
                if (!isset($usedSeats[$candidateSeat])) {
                    $seat = $candidateSeat;
                    break;
                }
            }
            if ($seat === null) {
                throw new MultiplayerGameException('All player seats are occupied.', 'MULTIPLAYER_GAME_PLAYER_LIMIT', 409);
            }
        }
        if (is_array($member)) {
            $pdo->prepare("UPDATE multiplayer_game_members SET participant_id=?,membership_status='active',client_epoch=?,last_seen_at=CURRENT_TIMESTAMP,departed_at=NULL WHERE id=?")
                ->execute([$participantId, substr($clientEpoch ?: uuid_v4(), 0, 128), (int)$member['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO multiplayer_game_members (game_session_id,user_id,participant_id,role,seat_number,client_epoch) VALUES (?,?,?,?,?,?)'
            )->execute([(int)$session['id'], $userId, $participantId, $role, $seat, substr($clientEpoch ?: uuid_v4(), 0, 128)]);
            multiplayer_game_record_accounting($pdo, (int)$session['id'], $userId, 'join');
        }
        if (!is_array($member) || (string)($member['membership_status'] ?? '') !== 'active') {
            multiplayer_game_rotate_message_key_epoch($pdo, $publicId);
        }
        if ($role === 'player' && (!is_array($member) || (string)($member['membership_status'] ?? '') !== 'active')) {
            if ((string)$session['mode'] === 'practice') {
                multiplayer_game_refresh_practice_host_acceptance($pdo, $session);
            } elseif ((string)$session['mode'] === 'recorded' && (string)$session['status'] === 'lobby') {
                // The creator already chose these rules. A new waiting player does
                // not undo that choice, but peers must consent to the changed roster.
                // Update only an existing matching host row: never manufacture lost
                // consent or carry acceptance of different rules into this lobby.
                $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=? AND user_id<>?')
                    ->execute([(int)$session['id'], (int)$session['master_user_id']]);
                $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
                $pdo->prepare('UPDATE multiplayer_game_acceptances SET player_set_sha256=? WHERE game_session_id=? AND user_id=? AND settings_sha256=?')
                    ->execute([$playerSet['sha256'], (int)$session['id'], (int)$session['master_user_id'], (string)$session['settings_sha256']]);
            } else {
                $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=?')
                    ->execute([(int)$session['id']]);
            }
        }
        if ($role === 'player'
            && (string)$session['mode'] === 'practice'
            && !multiplayer_game_requires_host_start($definition)
            && (string)$session['status'] === 'lobby') {
            try {
                $projection = multiplayer_game_start_session($pdo, $publicId, (int)$session['master_user_id']);
                database_transaction_commit($pdo, $transaction);
                return $projection;
            } catch (MultiplayerGameException $error) {
                if (!in_array($error->errorCode, ['MULTIPLAYER_GAME_MINIMUM_PLAYERS','MULTIPLAYER_GAME_ACCEPTANCE_REQUIRED'], true)) {
                    throw $error;
                }
            }
        }
        $projection = multiplayer_game_project_session($pdo, $publicId, $userId);
        database_transaction_commit($pdo, $transaction);
        return $projection;
    } catch (Throwable $error) {
        try {
            database_transaction_rollback($pdo, $transaction);
        } catch (Throwable $rollbackError) {
            // Preserve the original failure without putting private SQL or
            // participant details into the secondary rollback log.
            error_log('Game membership transaction rollback failed.');
        }
        throw $error;
    }
}

/**
 * Keep a Practice host's accepted settings bound to the current waiting
 * player set. Practice joiners do not separately consent because Practice
 * produces no competitive record; Recorded acceptance remains exact-set and
 * unchanged.
 */
function multiplayer_game_refresh_practice_host_acceptance(PDO $pdo, array $session): bool
{
    if ((string)($session['mode'] ?? '') !== 'practice'
        || (string)($session['status'] ?? '') !== 'lobby'
        || (int)($session['id'] ?? 0) < 1) return false;
    $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
    $update = $pdo->prepare(
        'UPDATE multiplayer_game_acceptances SET player_set_sha256=?,accepted_at=CURRENT_TIMESTAMP '
        . 'WHERE game_session_id=? AND user_id=? AND settings_sha256=?'
    );
    $update->execute([
        $playerSet['sha256'],
        (int)$session['id'],
        (int)$session['master_user_id'],
        (string)$session['settings_sha256'],
    ]);
    return $update->rowCount() === 1;
}

function multiplayer_game_accept_settings(PDO $pdo, string $publicId, int $userId, string $expectedSha256, ?string $expectedMode = null): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
    if (!hash_equals((string)$session['settings_sha256'], strtoupper($expectedSha256))) {
        throw new MultiplayerGameException('The game settings changed before acceptance.', 'MULTIPLAYER_GAME_SETTINGS_STALE', 409);
    }
    if ($expectedMode !== null && !hash_equals((string)$session['mode'], strtolower(trim($expectedMode)))) {
        throw new MultiplayerGameException('The play mode changed before acceptance.', 'MULTIPLAYER_GAME_MODE_STALE', 409);
    }
    $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO multiplayer_game_acceptances (game_session_id,user_id,settings_sha256,player_set_sha256) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE settings_sha256=VALUES(settings_sha256),player_set_sha256=VALUES(player_set_sha256),accepted_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO multiplayer_game_acceptances (game_session_id,user_id,settings_sha256,player_set_sha256) VALUES (?,?,?,?) ON CONFLICT(game_session_id,user_id) DO UPDATE SET settings_sha256=excluded.settings_sha256,player_set_sha256=excluded.player_set_sha256,accepted_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([(int)$session['id'], $userId, (string)$session['settings_sha256'], $playerSet['sha256']]);
    return multiplayer_game_project_session($pdo, $publicId, $userId);
}

function multiplayer_game_accept_and_start_if_ready(
    PDO $pdo,
    string $publicId,
    int $userId,
    string $expectedSha256,
    ?string $expectedMode = null
): array {
    $projection = multiplayer_game_accept_settings($pdo, $publicId, $userId, $expectedSha256, $expectedMode);
    if ((string)$projection['status'] !== 'lobby' || (string)$projection['mode'] === 'recorded') return $projection;
    if (multiplayer_game_requires_host_start(multiplayer_game_definition($pdo, (string)$projection['gameKey']))) return $projection;
    try {
        return multiplayer_game_start_session($pdo, $publicId, (int)$projection['masterUserId']);
    } catch (MultiplayerGameException $error) {
        if (!in_array($error->errorCode, ['MULTIPLAYER_GAME_MINIMUM_PLAYERS','MULTIPLAYER_GAME_ACCEPTANCE_REQUIRED'], true)) {
            throw $error;
        }
    }
    return $projection;
}

function multiplayer_game_update_settings(PDO $pdo, string $publicId, int $userId, array $settings, ?string $requestedMode = null): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master']);
    if ((string)$session['status'] !== 'lobby') {
        throw new MultiplayerGameException('Accepted game settings are locked after play begins.', 'MULTIPLAYER_GAME_SETTINGS_LOCKED', 409);
    }
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
    $mode = $requestedMode === null ? (string)$session['mode'] : strtolower(trim($requestedMode));
    if (!in_array($mode, ['practice','recorded'], true)) {
        throw new MultiplayerGameException('Choose Practice or Recorded Play.', 'MULTIPLAYER_GAME_MODE_INVALID', 422);
    }
    if ($mode === 'recorded' && (string)$definition['profile'] === 'one-player') {
        throw new MultiplayerGameException('This one-player game supports Practice Mode.', 'MULTIPLAYER_GAME_RECORDED_REQUIRES_MULTIPLAYER', 422);
    }
    $settings = multiplayer_game_validate_extension_settings($pdo, $definition, $settings, $mode);
    $settingsJson = multiplayer_game_canonical_json($settings);
    if (strlen($settingsJson) > 32768) {
        throw new MultiplayerGameException('Game settings are too large.', 'MULTIPLAYER_GAME_SETTINGS_TOO_LARGE', 422);
    }
    $settingsSha = strtoupper(hash('sha256', $settingsJson));
    $transaction = database_transaction_begin($pdo, true);
    try {
        $update = $pdo->prepare('UPDATE multiplayer_game_sessions SET mode=?,settings_json=?,settings_sha256=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status=\'lobby\'');
        $update->execute([$mode, $settingsJson, $settingsSha, (int)$session['id']]);
        if ($update->rowCount() !== 1) {
            throw new MultiplayerGameException('Accepted game settings are locked after play begins.', 'MULTIPLAYER_GAME_SETTINGS_LOCKED', 409);
        }
        $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=?')
            ->execute([(int)$session['id']]);
        // Saving validated options is the owner's explicit choice of these
        // rules. Record only that choice in the same transaction; every peer
        // must still accept the new settings and Recorded play does not start.
        $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
        $pdo->prepare('INSERT INTO multiplayer_game_acceptances (game_session_id,user_id,settings_sha256,player_set_sha256) VALUES (?,?,?,?)')
            ->execute([(int)$session['id'], $userId, $settingsSha, $playerSet['sha256']]);
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    return multiplayer_game_project_session($pdo, $publicId, $userId);
}

function multiplayer_game_request_seat(PDO $pdo, string $publicId, int $userId): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['spectator']);
    if (!in_array((string)$session['status'], ['lobby','active','paused'], true)) {
        throw new MultiplayerGameException('A player seat cannot be requested now.', 'MULTIPLAYER_GAME_SEAT_REQUEST_STATE_INVALID', 409);
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? "INSERT INTO multiplayer_game_seat_requests (game_session_id,user_id,request_status) VALUES (?,?,'pending') ON DUPLICATE KEY UPDATE request_status='pending',created_at=CURRENT_TIMESTAMP,resolved_at=NULL,resolved_by_user_id=NULL"
        : "INSERT INTO multiplayer_game_seat_requests (game_session_id,user_id,request_status) VALUES (?,?,'pending') ON CONFLICT(game_session_id,user_id) DO UPDATE SET request_status='pending',created_at=CURRENT_TIMESTAMP,resolved_at=NULL,resolved_by_user_id=NULL";
    $pdo->prepare($sql)->execute([(int)$session['id'], $userId]);
    return ['requested' => true, 'status' => 'pending', 'userId' => $userId];
}

function multiplayer_game_resolve_seat_request(PDO $pdo, string $publicId, int $masterUserId, int $requestedUserId, string $decision): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $masterUserId, ['master']);
    if (!in_array($decision, ['approve','decline'], true)) {
        throw new MultiplayerGameException('Choose approve or decline.', 'MULTIPLAYER_GAME_SEAT_DECISION_INVALID', 422);
    }
    $request = $pdo->prepare("SELECT request_status FROM multiplayer_game_seat_requests WHERE game_session_id=? AND user_id=? LIMIT 1");
    $request->execute([(int)$session['id'], $requestedUserId]);
    if ($request->fetchColumn() !== 'pending') {
        throw new MultiplayerGameException('This seat request is no longer pending.', 'MULTIPLAYER_GAME_SEAT_REQUEST_STALE', 409);
    }
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $masterUserId, ['master']);
        $request->execute([(int)$session['id'], $requestedUserId]);
        if ($request->fetchColumn() !== 'pending') {
            throw new MultiplayerGameException('This seat request is no longer pending.', 'MULTIPLAYER_GAME_SEAT_REQUEST_STALE', 409);
        }
        if ($decision === 'approve') {
            if ((string)$session['status'] !== 'lobby') {
                throw new MultiplayerGameException('A seat request can be approved before play begins.', 'MULTIPLAYER_GAME_SEAT_APPROVAL_STATE_INVALID', 409);
            }
            $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
            $count = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'");
            $count->execute([(int)$session['id']]);
            $playerCount = (int)$count->fetchColumn();
            if(($definition['extensionId'] ?? '')==='space-invasion') {
                $spaceSettings=json_decode((string)$session['settings_json'],true) ?: [];
                if($playerCount >= (int)($spaceSettings['playerCount'] ?? 1))throw new MultiplayerGameException('Choose Two-player co-op before adding another player.','MULTIPLAYER_GAME_PLAYER_LIMIT',409);
            }
            if ($playerCount >= min(MULTIPLAYER_GAME_MAX_PLAYERS, (int)$definition['maxPlayers'])) {
                throw new MultiplayerGameException('All player seats are occupied.', 'MULTIPLAYER_GAME_PLAYER_LIMIT', 409);
            }
            $occupied = $pdo->prepare("SELECT seat_number FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'");
            $occupied->execute([(int)$session['id']]);
            $usedSeats = array_fill_keys(array_map('intval', $occupied->fetchAll(PDO::FETCH_COLUMN)), true);
            $seatNumber = 1;
            while (isset($usedSeats[$seatNumber])) $seatNumber++;
            $member = $pdo->prepare("UPDATE multiplayer_game_members SET role='player',seat_number=? WHERE game_session_id=? AND user_id=? AND role='spectator' AND membership_status='active'");
            $member->execute([$seatNumber, (int)$session['id'], $requestedUserId]);
            if ($member->rowCount() !== 1) {
                throw new MultiplayerGameException('The requested spectator is no longer eligible.', 'MULTIPLAYER_GAME_SEAT_REQUEST_STALE', 409);
            }
            $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=?')->execute([(int)$session['id']]);
        }
        $pdo->prepare("UPDATE multiplayer_game_seat_requests SET request_status=?,resolved_at=CURRENT_TIMESTAMP,resolved_by_user_id=? WHERE game_session_id=? AND user_id=? AND request_status='pending'")
            ->execute([$decision === 'approve' ? 'approved' : 'declined', $masterUserId, (int)$session['id'], $requestedUserId]);
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    return ['userId' => $requestedUserId, 'decision' => $decision, 'session' => multiplayer_game_project_session($pdo, $publicId, $masterUserId)];
}

function multiplayer_game_remove_spectator(PDO $pdo, string $publicId, int $masterUserId, int $spectatorUserId, string $reason): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $masterUserId, ['master']);
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 240) {
        throw new MultiplayerGameException('A bounded removal reason is required.', 'MULTIPLAYER_GAME_SPECTATOR_REMOVAL_REASON_REQUIRED', 422);
    }
    $remove = $pdo->prepare("UPDATE multiplayer_game_members SET membership_status='removed',departed_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=? AND role='spectator' AND membership_status='active'");
    $remove->execute([(int)$session['id'], $spectatorUserId]);
    if ($remove->rowCount() !== 1) throw new MultiplayerGameException('The spectator is no longer available.', 'MULTIPLAYER_GAME_SPECTATOR_STALE', 409);
    $pdo->prepare("UPDATE multiplayer_game_seat_requests SET request_status='declined',resolved_at=CURRENT_TIMESTAMP,resolved_by_user_id=? WHERE game_session_id=? AND user_id=? AND request_status='pending'")
        ->execute([$masterUserId, (int)$session['id'], $spectatorUserId]);
    multiplayer_game_rotate_message_key_epoch($pdo, $publicId);
    log_tool($pdo, $masterUserId, 'multiplayer_game_spectator_removed', null, null, 'game:' . $publicId . '; spectator-user:' . $spectatorUserId . '; reason:' . $reason);
    return ['removed' => true, 'spectatorUserId' => $spectatorUserId];
}

/**
 * Returns the latest terminal round for this exact game and player set.
 *
 * Player-set identity is deliberately evaluated from authenticated framework
 * members rather than seats, display names, or browser-provided state. A
 * cancelled round that never reached meaningful play is retained as the same
 * round; completed, resigned/forfeited, abandoned, or meaningfully played
 * rounds advance the series once.
 */
function multiplayer_game_round_context(PDO $pdo, string $gameKey, string $publicId, array $playerUserIds): array
{
    $expected = array_values(array_unique(array_map('intval', $playerUserIds)));
    sort($expected, SORT_NUMERIC);
    $stmt = $pdo->prepare(
        "SELECT s.id,s.public_id,s.status,s.state_json,s.ended_at,s.updated_at,
                CASE WHEN EXISTS (
                    SELECT 1 FROM multiplayer_game_votes v
                     WHERE v.game_session_id=s.id AND v.vote_type='rematch' AND v.vote_value=?
                ) THEN 1 ELSE 0 END AS explicit_rematch
           FROM multiplayer_game_sessions s
          WHERE s.game_key=? AND s.public_id<>?
            AND s.status IN ('completed','forfeited','abandoned','ended')
          ORDER BY explicit_rematch DESC,COALESCE(s.ended_at,s.updated_at) DESC,s.id DESC
          LIMIT 64"
    );
    $stmt->execute(['started:' . $publicId, $gameKey, $publicId]);
    foreach ($stmt->fetchAll() as $candidate) {
        $members = $pdo->prepare(
            "SELECT user_id FROM multiplayer_game_members
              WHERE game_session_id=? AND role IN ('master','player')
                AND membership_status IN ('active','departed')
              ORDER BY user_id ASC"
        );
        $members->execute([(int)$candidate['id']]);
        $candidateUsers = array_values(array_unique(array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN))));
        sort($candidateUsers, SORT_NUMERIC);
        if ($candidateUsers !== $expected) continue;
        $state = json_decode((string)$candidate['state_json'], true);
        if (!is_array($state)) $state = [];
        $status = (string)$candidate['status'];
        $advancesSeries = in_array($status, ['completed','forfeited','abandoned'], true)
            || !empty($state['meaningfulPlay']);
        return [
            'seriesContinues' => true,
            'rematchContinues' => !empty($candidate['explicit_rematch']),
            'previousSessionPublicId' => (string)$candidate['public_id'],
            'previousStatus' => $status,
            'previousState' => $state,
            'previousRoundNumber' => max(1, (int)($state['roundNumber'] ?? $state['seriesRoundNumber'] ?? 1)),
            'advancesSeries' => $advancesSeries,
            'exactPlayerSetUserIds' => $expected,
        ];
    }
    return [
        'seriesContinues' => false,
        'rematchContinues' => false,
        'previousSessionPublicId' => null,
        'previousStatus' => null,
        'previousState' => [],
        'previousRoundNumber' => 0,
        'advancesSeries' => false,
        'exactPlayerSetUserIds' => $expected,
    ];
}

function multiplayer_game_turn_owner_from_state(array $state, array $playerUserIds, string $mode = 'recorded'): ?int
{
    if (!array_key_exists('turnIndex', $state) || $state['turnIndex'] === null) return null;
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $turnIndex = (int)$state['turnIndex'];
    $turnUserId = (int)($turnOrder[$turnIndex] ?? 0);
    $players = array_values(array_unique(array_map('intval', $playerUserIds)));
    if ($mode === 'practice' && $turnUserId < 0
        && (int)($state['bots'][(string)$turnUserId]['userId'] ?? 0) === $turnUserId) return $turnUserId;
    if ($turnUserId < 1 || !in_array($turnUserId, $players, true)) {
        throw new MultiplayerGameException('The initial turn owner is invalid.', 'MULTIPLAYER_GAME_TURN_OWNER_INVALID', 500);
    }
    return $turnUserId;
}

function multiplayer_game_shared_player_ids(array $state): array
{
    $frameworkPlayers = array_keys((array)($state['_framework']['players'] ?? []));
    if ($frameworkPlayers !== []) {
        return array_values(array_filter(
            array_unique(array_map('intval', $frameworkPlayers)),
            static fn(int $userId): bool => $userId > 0
        ));
    }
    return array_values(array_filter(
        array_unique(array_map('intval', (array)($state['turnOrder'] ?? []))),
        static fn(int $userId): bool => $userId > 0
    ));
}

function multiplayer_game_shared_clock_active(array $state): bool
{
    return (string)($state['clock']['kind'] ?? 'none') !== 'none';
}

function multiplayer_game_shared_inactivity_seconds(array $settings, array $definition, array $state): int
{
    $defaults = multiplayer_game_inactivity_defaults($definition);
    $placement = (string)($definition['extensionId'] ?? '') === 'battleship'
        && (string)($state['phase'] ?? '') === 'placement';
    $base = (int)($placement ? ($defaults['placementSeconds'] ?? $defaults['seconds']) : $defaults['seconds']);
    return match ((string)($settings['inactivityProfile'] ?? 'default')) {
        'quick' => max(15, (int)ceil($base / 2)),
        'relaxed' => min(3600, $base * 2),
        'custom' => (int)($placement
            ? ($settings['customPlacementInactivitySeconds'] ?? $base)
            : ($settings['customInactivitySeconds'] ?? $base)),
        default => $base,
    };
}

function multiplayer_game_initialize_shared_state(
    array $state,
    array $playerIds,
    array $settings,
    string $mode,
    array $definition,
    string $startedAt
): array {
    $players = [];
    foreach ($playerIds as $playerId) {
        $players[(string)(int)$playerId] = [
            'disconnectUsedSeconds' => 0,
            'disconnectedAt' => null,
            'serviceInterruptedAt' => null,
        ];
    }
    $profile = (string)($settings['inactivityProfile'] ?? ($mode === 'practice' ? 'unlimited' : 'default'));
    $inactivityEnabled = $mode === 'recorded'
        && $profile !== 'unlimited'
        && !multiplayer_game_shared_clock_active($state);
    $seconds = $inactivityEnabled
        ? multiplayer_game_shared_inactivity_seconds($settings, $definition, $state)
        : null;
    $turnOwner = multiplayer_game_turn_owner_from_state($state, $playerIds, $mode);
    $state['_framework'] = [
        'schemaVersion' => 1,
        'players' => $players,
        'pause' => [
            'mode' => 'running',
            'reason' => null,
            'proposedByUserId' => null,
            'acceptedByUserIds' => [],
            'pausedAt' => null,
            'resumeStartedByUserId' => null,
            'resumeAt' => null,
            'resumeRemainingSeconds' => null,
            'resumeNowByUserIds' => [],
        ],
        'inactivity' => [
            'enabled' => $inactivityEnabled,
            'profile' => $profile,
            'phase' => (string)($state['phase'] ?? 'turn'),
            'ownerUserId' => $turnOwner,
            'durationSeconds' => $seconds,
            'deadlineAt' => $seconds === null ? null : gmdate('c', strtotime($startedAt) + $seconds),
            'remainingSeconds' => null,
        ],
        'disconnectClaim' => null,
        'serviceInterruption' => [
            'active' => false,
            'startedAt' => null,
        ],
    ];
    return $state;
}

function multiplayer_game_shared_now(array $context): int
{
    $parsed = strtotime((string)($context['now'] ?? ''));
    return $parsed === false ? time() : $parsed;
}

function multiplayer_game_shared_disconnect_used(array $player, int $now): int
{
    $used = max(0, min(600, (int)($player['disconnectUsedSeconds'] ?? 0)));
    $disconnectedAt = strtotime((string)($player['disconnectedAt'] ?? ''));
    $serviceInterruptedAt = strtotime((string)($player['serviceInterruptedAt'] ?? ''));
    if ($disconnectedAt !== false) {
        $billThrough = $serviceInterruptedAt === false ? $now : min($now, $serviceInterruptedAt);
        $used = min(600, $used + max(0, $billThrough - $disconnectedAt));
    }
    return $used;
}

function multiplayer_game_freeze_shared_timing(array &$state, int $now): void
{
    if (isset($state['realtime']) && ($state['realtime']['frozenAtMs'] ?? null) === null) {
        $through = max((int)$state['realtime']['lastAtMs'], $now * 1000);
        $state['realtime']['pendingMs'] += $through - (int)$state['realtime']['lastAtMs'];
        $state['realtime']['lastAtMs'] = $through;
        $state['realtime']['frozenAtMs'] = $through;
        if (isset($state['ship'])) {
            $state['ship']['controls'] = ['left' => false, 'right' => false, 'fire' => false];
            $state['ship']['inputLeaseMs'] = 0;
        }
    }
    foreach ((array)($state['clocks'] ?? []) as $userId => $_clock) {
        if (!is_array($state['clocks'][$userId] ?? null)) continue;
        $started = strtotime((string)($state['clocks'][$userId]['turnStartedAt'] ?? ''));
        if ($started !== false && is_numeric($state['clocks'][$userId]['remainingSeconds'] ?? null)) {
            $state['clocks'][$userId]['remainingSeconds'] = max(
                0,
                (int)$state['clocks'][$userId]['remainingSeconds'] - max(0, $now - $started)
            );
        }
        $state['clocks'][$userId]['turnStartedAt'] = null;
    }
    $inactivity = &$state['_framework']['inactivity'];
    $deadline = strtotime((string)($inactivity['deadlineAt'] ?? ''));
    if ($deadline !== false) $inactivity['remainingSeconds'] = max(0, $deadline - $now);
    $inactivity['deadlineAt'] = null;
    $pause = &$state['_framework']['pause'];
    $resumeAt = strtotime((string)($pause['resumeAt'] ?? ''));
    if ((string)($pause['mode'] ?? '') === 'resuming' && $resumeAt !== false) {
        $pause['resumeRemainingSeconds'] = max(0, $resumeAt - $now);
        $pause['resumeAt'] = null;
    }
}

function multiplayer_game_resume_shared_timing(array &$state, int $now): void
{
    if (isset($state['realtime']) && ($state['realtime']['frozenAtMs'] ?? null) !== null
        && !in_array((string)($state['_framework']['pause']['mode'] ?? 'running'), ['paused', 'resuming', 'completed'], true)) {
        $state['realtime']['lastAtMs'] = max((int)$state['realtime']['lastAtMs'], $now * 1000);
        $state['realtime']['frozenAtMs'] = null;
    }
    $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $turnOwner = (int)($turnOrder[(int)($state['turnIndex'] ?? -1)] ?? 0);
    if (($turnOwner > 0 || isset($state['bots'][(string)$turnOwner])) && isset($state['clocks'][(string)$turnOwner])
        && is_numeric($state['clocks'][(string)$turnOwner]['remainingSeconds'] ?? null)) {
        $state['clocks'][(string)$turnOwner]['turnStartedAt'] = gmdate('c', $now);
    }
    $inactivity = &$state['_framework']['inactivity'];
    if (!empty($inactivity['enabled']) && is_numeric($inactivity['remainingSeconds'] ?? null)) {
        $inactivity['deadlineAt'] = gmdate('c', $now + max(0, (int)$inactivity['remainingSeconds']));
        $inactivity['remainingSeconds'] = null;
    }
    $pause = &$state['_framework']['pause'];
    if ((string)($pause['mode'] ?? '') === 'resuming' && is_numeric($pause['resumeRemainingSeconds'] ?? null)) {
        $pause['resumeAt'] = gmdate('c', $now + max(0, (int)$pause['resumeRemainingSeconds']));
        $pause['resumeRemainingSeconds'] = null;
    }
}

function multiplayer_game_shared_service_interruption_active(array $state): bool
{
    return !empty($state['_framework']['serviceInterruption']['active']);
}

function multiplayer_game_set_service_interruption_state(
    array &$state,
    bool $active,
    int $effectiveAt
): void {
    $state['_framework']['serviceInterruption'] ??= ['active' => false, 'startedAt' => null];
    $service = &$state['_framework']['serviceInterruption'];
    if ($active) {
        if (!empty($service['active'])) return;
        multiplayer_game_freeze_shared_timing($state, $effectiveAt);
        foreach (multiplayer_game_shared_player_ids($state) as $userId) {
            $player = &$state['_framework']['players'][(string)$userId];
            if (!empty($player['disconnectedAt'])) {
                $player['serviceInterruptedAt'] = gmdate('c', $effectiveAt);
            }
            unset($player);
        }
        $service['active'] = true;
        $service['startedAt'] = gmdate('c', $effectiveAt);
        return;
    }
    if (empty($service['active'])) return;
    foreach (multiplayer_game_shared_player_ids($state) as $userId) {
        $player = &$state['_framework']['players'][(string)$userId];
        $disconnectedAt = strtotime((string)($player['disconnectedAt'] ?? ''));
        $interruptedAt = strtotime((string)($player['serviceInterruptedAt'] ?? ''));
        if ($disconnectedAt !== false && $interruptedAt !== false) {
            $player['disconnectUsedSeconds'] = min(
                600,
                max(0, (int)($player['disconnectUsedSeconds'] ?? 0))
                    + max(0, $interruptedAt - $disconnectedAt)
            );
            $player['disconnectedAt'] = gmdate('c', $effectiveAt);
        }
        $player['serviceInterruptedAt'] = null;
        unset($player);
    }
    $service['active'] = false;
    $service['startedAt'] = null;
    $pauseMode = (string)($state['_framework']['pause']['mode'] ?? 'running');
    if ($pauseMode === 'running') {
        multiplayer_game_resume_shared_timing($state, $effectiveAt);
    } elseif ($pauseMode === 'resuming') {
        $pause = &$state['_framework']['pause'];
        if (is_numeric($pause['resumeRemainingSeconds'] ?? null)) {
            $pause['resumeAt'] = gmdate('c', $effectiveAt + max(0, (int)$pause['resumeRemainingSeconds']));
            $pause['resumeRemainingSeconds'] = null;
        }
    }
}

function multiplayer_game_shared_connected_player_ids(array $state): array
{
    $connected = [];
    foreach (multiplayer_game_shared_player_ids($state) as $userId) {
        if (empty($state['_framework']['players'][(string)$userId]['disconnectedAt'])) $connected[] = $userId;
    }
    return $connected;
}

function multiplayer_game_shared_team_for_user(array $context, int $userId): int
{
    foreach ((array)($context['members'] ?? []) as $member) {
        if ((int)($member['userId'] ?? 0) !== $userId) continue;
        $seat = max(1, (int)($member['seat'] ?? 1));
        return (($seat - 1) % 2) + 1;
    }
    return 0;
}

function multiplayer_game_shared_result(array &$state, array $winners, string $reason, ?int $now = null): array
{
    $winnerSet = array_fill_keys(array_map('intval', $winners), true);
    $draw = $winnerSet === [];
    $members = [];
    foreach (multiplayer_game_shared_player_ids($state) as $userId) {
        $won = isset($winnerSet[$userId]);
        $members[(string)$userId] = [
            'score' => $draw ? 0.5 : ($won ? 1 : 0),
            'outcome' => $draw ? 'draw' : ($won ? 'win' : 'loss'),
        ];
    }
    $state['completed'] = true;
    $state['terminalReason'] = $reason;
    $state['winnerUserId'] = count($winners) === 1 ? (int)$winners[0] : null;
    $state['_framework']['pause']['mode'] = 'completed';
    multiplayer_game_freeze_shared_timing($state, $now ?? time());
    return [
        'state' => $state,
        'turnUserId' => null,
        'terminal' => true,
        'result' => ['members' => $members],
    ];
}

function multiplayer_game_shared_disconnect_eligibility(array $state, array $context): array
{
    $now = multiplayer_game_shared_now($context);
    $disconnected = [];
    foreach (multiplayer_game_shared_player_ids($state) as $userId) {
        $player = (array)($state['_framework']['players'][(string)$userId] ?? []);
        if (empty($player['disconnectedAt'])) continue;
        $disconnected[$userId] = [
            'exhausted' => multiplayer_game_shared_disconnect_used($player, $now) >= 600,
            'team' => multiplayer_game_shared_team_for_user($context, $userId),
        ];
    }
    $connected = multiplayer_game_shared_connected_player_ids($state);
    $extensionId = (string)($context['extensionId'] ?? '');
    $eligibility = ['winByUserIds' => [], 'drawByUserIds' => [], 'targetUserIds' => array_keys($disconnected)];
    if ($disconnected === []) return $eligibility;
    if ($extensionId !== 'spades') {
        if (count(multiplayer_game_shared_player_ids($state)) === 2
            && count($disconnected) === 1
            && !empty(current($disconnected)['exhausted'])) {
            $eligibility['winByUserIds'] = $connected;
        }
        return $eligibility;
    }
    $teams = array_values(array_unique(array_filter(array_column($disconnected, 'team'))));
    if (count($teams) > 1) {
        if (!in_array(false, array_column($disconnected, 'exhausted'), true)) {
            $eligibility['drawByUserIds'] = $connected;
        }
        return $eligibility;
    }
    if (count($teams) === 1 && !in_array(false, array_column($disconnected, 'exhausted'), true)) {
        $winningTeam = $teams[0] === 1 ? 2 : 1;
        $required = [];
        foreach (multiplayer_game_shared_player_ids($state) as $userId) {
            if (multiplayer_game_shared_team_for_user($context, $userId) === $winningTeam) $required[] = $userId;
        }
        if ($required !== [] && count(array_intersect($required, $connected)) === count($required)) {
            $eligibility['winByUserIds'] = $required;
        }
    }
    return $eligibility;
}

function multiplayer_game_shared_action_names(): array
{
    return [
        'pause-game', 'accept-pause', 'decline-pause', 'pause-for-reconnect',
        'start-resume', 'resume-now', 'settle-resume', 'settle-inactivity',
        'select-disconnect-win', 'confirm-disconnect-win',
        'select-disconnect-draw', 'confirm-disconnect-draw',
    ];
}

function multiplayer_game_apply_shared_action(
    array $state,
    int $actorUserId,
    string $action,
    array $context
): array {
    $players = multiplayer_game_shared_player_ids($state);
    if (!in_array($actorUserId, $players, true)) {
        throw new MultiplayerGameException('Only an active player may use game lifecycle controls.', 'MULTIPLAYER_GAME_LIFECYCLE_PLAYER_REQUIRED', 403);
    }
    $now = multiplayer_game_shared_now($context);
    $pause = &$state['_framework']['pause'];
    $connected = multiplayer_game_shared_connected_player_ids($state);
    $actorConnected = in_array($actorUserId, $connected, true);
    if (!$actorConnected) {
        throw new MultiplayerGameException('Reconnect before using game lifecycle controls.', 'MULTIPLAYER_GAME_LIFECYCLE_RECONNECT_REQUIRED', 409);
    }
    if (multiplayer_game_shared_service_interruption_active($state)) {
        throw new MultiplayerGameException('The game is frozen for a confirmed CoreChat service interruption.', 'MULTIPLAYER_GAME_SERVICE_INTERRUPTION_ACTIVE', 409);
    }
    if ($action === 'pause-game') {
        if ((string)$pause['mode'] !== 'running') throw new MultiplayerGameException('A pause or resume decision is already active.', 'MULTIPLAYER_GAME_PAUSE_STATE_INVALID', 409);
        if (count($connected) !== count($players)) throw new MultiplayerGameException('Use Pause for reconnect while a player is disconnected.', 'MULTIPLAYER_GAME_PAUSE_RECONNECT_REQUIRED', 409);
        $pause = [
            'mode' => 'proposed', 'reason' => 'manual', 'proposedByUserId' => $actorUserId,
            'acceptedByUserIds' => [$actorUserId], 'pausedAt' => null,
            'resumeStartedByUserId' => null, 'resumeAt' => null, 'resumeRemainingSeconds' => null, 'resumeNowByUserIds' => [],
        ];
        if (count($players) === 1) {
            multiplayer_game_freeze_shared_timing($state, $now);
            $pause['mode'] = 'paused';
            $pause['pausedAt'] = gmdate('c', $now);
        }
    } elseif ($action === 'accept-pause') {
        if ((string)$pause['mode'] !== 'proposed') throw new MultiplayerGameException('There is no pause proposal to accept.', 'MULTIPLAYER_GAME_PAUSE_PROPOSAL_MISSING', 409);
        $pause['acceptedByUserIds'][] = $actorUserId;
        $pause['acceptedByUserIds'] = array_values(array_unique(array_map('intval', $pause['acceptedByUserIds'])));
        if (count(array_intersect($players, $pause['acceptedByUserIds'])) === count($players)) {
            multiplayer_game_freeze_shared_timing($state, $now);
            $pause['mode'] = 'paused';
            $pause['pausedAt'] = gmdate('c', $now);
        }
    } elseif ($action === 'decline-pause') {
        if ((string)$pause['mode'] !== 'proposed') throw new MultiplayerGameException('There is no pause proposal to decline.', 'MULTIPLAYER_GAME_PAUSE_PROPOSAL_MISSING', 409);
        $pause = [
            'mode' => 'running', 'reason' => null, 'proposedByUserId' => null,
            'acceptedByUserIds' => [], 'pausedAt' => null, 'resumeStartedByUserId' => null,
            'resumeAt' => null, 'resumeRemainingSeconds' => null, 'resumeNowByUserIds' => [],
        ];
    } elseif ($action === 'pause-for-reconnect') {
        if ((string)$pause['mode'] !== 'running') throw new MultiplayerGameException('The game is already in a pause lifecycle.', 'MULTIPLAYER_GAME_PAUSE_STATE_INVALID', 409);
        if (count($connected) === count($players)) throw new MultiplayerGameException('No player is currently disconnected.', 'MULTIPLAYER_GAME_RECONNECT_PAUSE_UNAVAILABLE', 409);
        multiplayer_game_freeze_shared_timing($state, $now);
        $pause = [
            'mode' => 'paused', 'reason' => 'reconnect', 'proposedByUserId' => $actorUserId,
            'acceptedByUserIds' => [$actorUserId], 'pausedAt' => gmdate('c', $now),
            'resumeStartedByUserId' => null, 'resumeAt' => null, 'resumeRemainingSeconds' => null, 'resumeNowByUserIds' => [],
        ];
    } elseif ($action === 'start-resume') {
        if ((string)$pause['mode'] !== 'paused') throw new MultiplayerGameException('The game is not paused.', 'MULTIPLAYER_GAME_RESUME_STATE_INVALID', 409);
        if (count($connected) !== count($players)) throw new MultiplayerGameException('Wait for every active player to reconnect before resuming.', 'MULTIPLAYER_GAME_RESUME_RECONNECT_REQUIRED', 409);
        $pause['mode'] = 'resuming';
        $pause['resumeStartedByUserId'] = $actorUserId;
        $pause['resumeAt'] = gmdate('c', $now + 60);
        $pause['resumeRemainingSeconds'] = null;
        $pause['resumeNowByUserIds'] = [$actorUserId];
    } elseif (in_array($action, ['resume-now', 'settle-resume'], true)) {
        if ((string)$pause['mode'] !== 'resuming') throw new MultiplayerGameException('A resume countdown is not active.', 'MULTIPLAYER_GAME_RESUME_STATE_INVALID', 409);
        if ($action === 'resume-now') {
            $pause['resumeNowByUserIds'][] = $actorUserId;
            $pause['resumeNowByUserIds'] = array_values(array_unique(array_map('intval', $pause['resumeNowByUserIds'])));
        }
        $resumeAt = strtotime((string)$pause['resumeAt']);
        $allWaived = count(array_intersect($connected, (array)$pause['resumeNowByUserIds'])) === count($connected);
        if (!$allWaived && ($resumeAt === false || $now < $resumeAt)) {
            if ($action === 'settle-resume') throw new MultiplayerGameException('The resume countdown has not ended.', 'MULTIPLAYER_GAME_RESUME_NOT_READY', 409);
        } else {
            // A real resume transitions out of the paused clock guard before
            // rebasing time. Restoring a paused save must not do this.
            $pause['mode'] = 'running';
            multiplayer_game_resume_shared_timing($state, $now);
            $pause = [
                'mode' => 'running', 'reason' => null, 'proposedByUserId' => null,
                'acceptedByUserIds' => [], 'pausedAt' => null, 'resumeStartedByUserId' => null,
                'resumeAt' => null, 'resumeRemainingSeconds' => null, 'resumeNowByUserIds' => [],
            ];
        }
    } elseif ($action === 'settle-inactivity') {
        $inactivity = (array)$state['_framework']['inactivity'];
        $deadline = strtotime((string)($inactivity['deadlineAt'] ?? ''));
        if (empty($inactivity['enabled']) || $deadline === false || $now < $deadline || (string)$pause['mode'] !== 'running') {
            throw new MultiplayerGameException('The inactivity deadline has not expired.', 'MULTIPLAYER_GAME_INACTIVITY_NOT_EXPIRED', 409);
        }
        $expiredUserId = (int)($inactivity['ownerUserId'] ?? 0);
        if ((string)($context['extensionId'] ?? '') === 'battleship' && $expiredUserId < 1) {
            $winners = array_values(array_filter($players, static function(int $id) use ($state): bool {
                return !empty($state['fleets'][(string)$id]['accepted']);
            }));
        } elseif ((string)($context['extensionId'] ?? '') === 'spades' && $expiredUserId > 0) {
            $expiredTeam = multiplayer_game_shared_team_for_user($context, $expiredUserId);
            $winners = array_values(array_filter($players, static function(int $id) use ($context, $expiredTeam): bool {
                return multiplayer_game_shared_team_for_user($context, $id) > 0
                    && multiplayer_game_shared_team_for_user($context, $id) !== $expiredTeam;
            }));
        } else {
            $winners = array_values(array_filter($players, static fn(int $id): bool => $id !== $expiredUserId));
        }
        return multiplayer_game_shared_result($state, $winners, 'ranked-inactivity-expiration', $now);
    } else {
        $eligibility = multiplayer_game_shared_disconnect_eligibility($state, $context);
        $claim = (array)($state['_framework']['disconnectClaim'] ?? []);
        if ($action === 'select-disconnect-win') {
            if (!in_array($actorUserId, $eligibility['winByUserIds'], true)) throw new MultiplayerGameException('A disconnect win claim is not available.', 'MULTIPLAYER_GAME_DISCONNECT_WIN_UNAVAILABLE', 409);
            $state['_framework']['disconnectClaim'] = ['kind' => 'win', 'selectedByUserId' => $actorUserId, 'confirmedByUserIds' => []];
        } elseif ($action === 'confirm-disconnect-win') {
            if (($claim['kind'] ?? '') !== 'win' || !in_array($actorUserId, $eligibility['winByUserIds'], true)) throw new MultiplayerGameException('The disconnect win claim is no longer current.', 'MULTIPLAYER_GAME_DISCONNECT_WIN_UNAVAILABLE', 409);
            $claim['confirmedByUserIds'][] = $actorUserId;
            $claim['confirmedByUserIds'] = array_values(array_unique(array_map('intval', $claim['confirmedByUserIds'])));
            $state['_framework']['disconnectClaim'] = $claim;
            if (count(array_intersect($eligibility['winByUserIds'], $claim['confirmedByUserIds'])) === count($eligibility['winByUserIds'])) {
                return multiplayer_game_shared_result($state, $eligibility['winByUserIds'], 'disconnect-allowance-expired', $now);
            }
        } elseif ($action === 'select-disconnect-draw') {
            if (!in_array($actorUserId, $eligibility['drawByUserIds'], true)) throw new MultiplayerGameException('A disconnect draw is not available.', 'MULTIPLAYER_GAME_DISCONNECT_DRAW_UNAVAILABLE', 409);
            $state['_framework']['disconnectClaim'] = ['kind' => 'draw', 'selectedByUserId' => $actorUserId, 'confirmedByUserIds' => []];
        } elseif ($action === 'confirm-disconnect-draw') {
            if (($claim['kind'] ?? '') !== 'draw' || !in_array($actorUserId, $eligibility['drawByUserIds'], true)) throw new MultiplayerGameException('The disconnect draw is no longer current.', 'MULTIPLAYER_GAME_DISCONNECT_DRAW_UNAVAILABLE', 409);
            return multiplayer_game_shared_result($state, [], 'disconnect-allowance-draw', $now);
        }
    }
    if (array_key_exists('turnUserId', $context)) {
        // Lifecycle controls do not change the authoritative gameplay/choice owner.
        $turnOwner = $context['turnUserId'] === null ? null : (int)$context['turnUserId'];
    } else {
        $turnOrder = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
        $turnOwner = !isset($state['turnIndex']) ? null : ($turnOrder[(int)$state['turnIndex']] ?? null);
    }
    return ['state' => $state, 'turnUserId' => $turnOwner];
}

function multiplayer_game_shared_progress_completed(
    string $extensionId,
    string $action,
    array $before,
    array $after,
    ?int $beforeTurn,
    ?int $afterTurn
): bool {
    if ($extensionId === 'chess') return $action === 'move';
    if ($extensionId === 'checkers') return $action === 'move' && ($beforeTurn !== $afterTurn || !empty($after['completed']));
    if ($extensionId === 'battleship') return in_array($action, ['attack','bot-step'], true);
    if ($extensionId === 'five-dice') return $action === 'score' || ($action === 'bot-step' && ($beforeTurn !== $afterTurn || !empty($after['completed'])));
    if ($extensionId === 'spades') return in_array($action, ['bid', 'offer-partner-pass', 'respond-partner-pass', 'play', 'bot-step'], true);
    if ($extensionId === 'blackjack') return in_array($action, ['bet', 'insurance', 'hit', 'stand', 'double', 'split', 'surrender', 'next-round'], true);
    if (in_array($extensionId, ['backgammon-first-party', 'acey-deucy'], true)) {
        return $beforeTurn !== $afterTurn || !empty($after['completed']);
    }
    if ($extensionId === 'hearts') {
        if ($action === 'pass') return (array)($before['pendingPasses'] ?? []) !== (array)($after['pendingPasses'] ?? []);
        return $action === 'play'
            && (int)($after['playSequence'] ?? 0) > (int)($before['playSequence'] ?? 0);
    }
    if (in_array($extensionId, ['chinese-checkers', 'nested-four'], true)) {
        // Nested Four selection commits a piece but does not complete its move.
        return ($action === 'move' || (in_array($extensionId,['chinese-checkers','nested-four'],true) && $action === 'bot-step'))
            && (int)($after['moveNumber'] ?? 0) > (int)($before['moveNumber'] ?? 0);
    }
    if ($extensionId === 'hearts' && $action === 'bot-step') return (int)($after['playSequence'] ?? 0) > (int)($before['playSequence'] ?? 0) || ($before['phase'] ?? '') !== ($after['phase'] ?? '');
    if ($extensionId === 'uno') {
        if ($action === 'bot-step') return (int)($after['playSequence'] ?? 0) > (int)($before['playSequence'] ?? 0) || ($before['phase'] ?? '') !== ($after['phase'] ?? '');
        if ($action === 'call-uno') {
            // Repeating an already-recorded declaration increments its receipt
            // sequence, but must not let the caller renew the turn indefinitely.
            return (array)($before['unoDeclared'] ?? []) !== (array)($after['unoDeclared'] ?? []);
        }
        return in_array($action, ['play', 'draw', 'pass', 'catch-uno', 'choose-color', 'accept-draw-four', 'challenge-draw-four'], true)
            && (int)($after['playSequence'] ?? 0) > (int)($before['playSequence'] ?? 0);
    }
    if ($extensionId === 'puppy-panic') {
        return in_array($action, ['bot-step','bot-deal','bot-settle','bot-settle-random','deal', 'draw', 'play', 'combo', 'counter', 'settle-action', 'settle-random', 'calm', 'eliminate', 'give-card', 'reorder', 'resign'], true)
            && (int)($after['actionSequence'] ?? 0) > (int)($before['actionSequence'] ?? 0);
    }
    return false;
}

function multiplayer_game_refresh_shared_inactivity(
    array &$state,
    array $settings,
    array $definition,
    ?int $turnUserId,
    int $now,
    bool $progressCompleted
): void {
    if (!isset($state['_framework']['inactivity'])) return;
    $inactivity = &$state['_framework']['inactivity'];
    if (empty($inactivity['enabled']) || (string)($state['_framework']['pause']['mode'] ?? 'running') !== 'running') return;
    $phase = (string)($state['phase'] ?? 'turn');
    $phaseChanged = $phase !== (string)($inactivity['phase'] ?? '');
    if (!$progressCompleted && !$phaseChanged) return;
    $duration = multiplayer_game_shared_inactivity_seconds($settings, $definition, $state);
    $inactivity['phase'] = $phase;
    $inactivity['ownerUserId'] = $turnUserId;
    $inactivity['durationSeconds'] = $duration;
    $inactivity['deadlineAt'] = gmdate('c', $now + $duration);
    $inactivity['remainingSeconds'] = null;
}

function multiplayer_game_minimum_players(array $definition, string $mode, array $settings = []): int
{
    if(($definition['extensionId'] ?? '')==='space-invasion')return ($settings['playerCount'] ?? 1)===2?2:1;
    $minimumKey = $mode === 'practice' ? 'practiceMinPlayers' : 'recordedMinPlayers';
    return (int)($definition[$minimumKey] ?? $definition['minPlayers']);
}

function multiplayer_game_start_session(PDO $pdo, string $publicId, int $userId): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $result = multiplayer_game_start_session_core($pdo, $publicId, $userId);
        game_recording_capture($pdo, $publicId, 'start', ['actorId' => $userId]);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_start_session_core(PDO $pdo, string $publicId, int $userId): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master']);
    if ((string)$session['status'] !== 'lobby') {
        throw new MultiplayerGameException('Only a waiting game can start.', 'MULTIPLAYER_GAME_START_STATE_INVALID', 409);
    }
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
    $players = $pdo->prepare("SELECT user_id,seat_number FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active' ORDER BY seat_number ASC");
    $players->execute([(int)$session['id']]);
    $playerRows = $players->fetchAll();
    $playerIds = array_map('intval', array_column($playerRows, 'user_id'));
    $humanSeats = [];
    foreach ($playerRows as $row) $humanSeats[(int)$row['seat_number']] = (int)$row['user_id'];
    if (multiplayer_game_has_seat_choices($definition) && multiplayer_game_valid_seat_requests((array)((json_decode((string)$session['state_json'], true) ?: [])['seatChangeRequests'] ?? []), array_flip($humanSeats)) !== []) {
        throw new MultiplayerGameException('Resolve or cancel pending seat swaps before starting.', 'MULTIPLAYER_GAME_SEAT_SWAP_PENDING', 409);
    }
    $minimumPlayers = multiplayer_game_minimum_players($definition, (string)$session['mode'], json_decode((string)$session['settings_json'],true) ?: []);
    if (count($playerIds) < $minimumPlayers) {
        throw new MultiplayerGameException('More players must join before the game can start.', 'MULTIPLAYER_GAME_MINIMUM_PLAYERS', 409);
    }
    $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
    if ((string)$session['mode'] === 'practice') {
        $acceptance = $pdo->prepare('SELECT COUNT(*) FROM multiplayer_game_acceptances WHERE game_session_id=? AND settings_sha256=? AND player_set_sha256=? AND user_id=?');
        $acceptance->execute([(int)$session['id'], (string)$session['settings_sha256'], $playerSet['sha256'], (int)$session['master_user_id']]);
        if ((int)$acceptance->fetchColumn() !== 1) {
            throw new MultiplayerGameException('The host must accept the current settings.', 'MULTIPLAYER_GAME_ACCEPTANCE_REQUIRED', 409);
        }
    } else {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $acceptance = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_acceptances WHERE game_session_id=? AND settings_sha256=? AND player_set_sha256=? AND user_id IN ({$placeholders})");
        $acceptance->execute(array_merge([(int)$session['id'], (string)$session['settings_sha256'], $playerSet['sha256']], $playerIds));
        if ((int)$acceptance->fetchColumn() !== count($playerIds)) {
            throw new MultiplayerGameException('Every player must accept the current settings.', 'MULTIPLAYER_GAME_ACCEPTANCE_REQUIRED', 409);
        }
    }
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $settings = json_decode((string)$session['settings_json'], true);
    $settings = multiplayer_game_validate_extension_settings(
        $pdo,
        $definition,
        is_array($settings) ? $settings : [],
        (string)$session['mode']
    );
    $startedAt = gmdate('c');
    $stateJson = (string)$session['state_json'];
    $turnUserId = null;
    if (is_array($adapter)) {
        $factory = (string)($adapter['initialState'] ?? '');
        if ($factory === '' || !function_exists($factory)) {
            throw new MultiplayerGameException('The installed game rules are unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
        }
        $state = $factory($playerIds, [
            'pdo' => $pdo,
            'humanSeats' => $humanSeats,
            'mode' => (string)$session['mode'],
            'settings' => multiplayer_game_extension_only_settings($settings),
            'gameKey' => (string)$session['game_key'],
            'extensionId' => (string)($definition['extensionId'] ?? ''),
            'sessionPublicId' => (string)$session['public_id'],
            'startedAt' => $startedAt,
            'roundContext' => multiplayer_game_round_context(
                $pdo,
                (string)$session['game_key'],
                (string)$session['public_id'],
                $playerIds
            ),
            'openingProcedure' => (string)($adapter['openingProcedure'] ?? 'extension-defined'),
            'rematchSeatRotation' => !empty($adapter['rematchSeatRotation']),
        ]);
        $state = multiplayer_game_validate_initial_state_result($state);
        $state = multiplayer_game_initialize_shared_state(
            $state,
            $playerIds,
            $settings,
            (string)$session['mode'],
            $definition,
            $startedAt
        );
    } else {
        // Compatibility clients may continue to own their game-specific payload,
        // but shared lifecycle identity is established by the server before the
        // first client action and can never be supplied or removed by a client.
        $state = [
            'turnOrder' => $playerIds,
            'turnIndex' => $playerIds === [] ? null : 0,
        ];
        $state = multiplayer_game_initialize_shared_state(
            $state,
            $playerIds,
            $settings,
            (string)$session['mode'],
            $definition,
            $startedAt
        );
    }
    multiplayer_game_assert_bot_mode((string)$session['mode'], $state, $settings);
    $stateJson = multiplayer_game_canonical_json($state);
    if (strlen($stateJson) > 262144) {
        throw new MultiplayerGameException('The authoritative game state is too large.', 'MULTIPLAYER_GAME_STATE_TOO_LARGE', 500);
    }
    $turnUserId = multiplayer_game_turn_owner_from_state($state, $playerIds, (string)$session['mode']);
    $update = $pdo->prepare("UPDATE multiplayer_game_sessions SET status='active',state_json=?,turn_user_id=?,started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,state_version=state_version+1 WHERE id=? AND status='lobby'");
    $update->execute([$stateJson, $turnUserId, (int)$session['id']]);
    if ($update->rowCount() !== 1) throw new MultiplayerGameException('The game changed elsewhere.', 'MULTIPLAYER_GAME_START_CONFLICT', 409);
    return multiplayer_game_project_session($pdo, $publicId, $userId);
}

function multiplayer_game_lock_session(PDO $pdo, string $publicId): void
{
    $sql = 'SELECT id FROM multiplayer_game_sessions WHERE public_id=?';
    if (db_uses_mysql_syntax($pdo)) $sql .= ' FOR UPDATE';
    $lock = $pdo->prepare($sql);
    $lock->execute([$publicId]);
    if (!$lock->fetchColumn()) {
        throw new MultiplayerGameException('Game session not found.', 'MULTIPLAYER_GAME_NOT_FOUND', 404);
    }
}

function multiplayer_game_action_replay(
    PDO $pdo,
    int $sessionId,
    string $requestId,
    int $userId,
    int $expectedVersion,
    string $actionType,
    string $payloadJson
): ?array {
    $replay = $pdo->prepare(
        'SELECT actor_user_id,expected_version,resulting_version,action_type,payload_json,receipt_sha256 '
        . 'FROM multiplayer_game_actions WHERE game_session_id=? AND request_id=?'
    );
    $replay->execute([$sessionId, $requestId]);
    $existing = $replay->fetch();
    if (!is_array($existing)) return null;
    if ((int)$existing['actor_user_id'] !== $userId
        || (int)$existing['expected_version'] !== $expectedVersion
        || !hash_equals((string)$existing['action_type'], $actionType)
        || !hash_equals((string)$existing['payload_json'], $payloadJson)) {
        throw new MultiplayerGameException(
            'The action identity was already used for a different request.',
            'MULTIPLAYER_GAME_REQUEST_REPLAY_CONFLICT',
            409
        );
    }
    return [
        'ok' => true,
        'idempotentReplay' => true,
        'version' => (int)$existing['resulting_version'],
        'receiptSha256' => (string)$existing['receipt_sha256'],
    ];
}

function multiplayer_game_record_compatibility_action(
    PDO $pdo,
    string $publicId,
    int $userId,
    string $requestId,
    int $expectedVersion,
    string $actionType,
    array $payload
): array {
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        if (trim((string)($session['extension_id'] ?? '')) !== '') {
            throw new MultiplayerGameException(
                'This installed game accepts only its server-authoritative actions.',
                'MULTIPLAYER_GAME_EXTENSION_ACTION_REQUIRED',
                409
            );
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)) {
            throw new MultiplayerGameException('The action identity is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
        }
        $actionType = strtolower(trim($actionType));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/', $actionType)) {
            throw new MultiplayerGameException('The action type is invalid.', 'MULTIPLAYER_GAME_ACTION_TYPE_INVALID', 422);
        }
        $payloadJson = multiplayer_game_canonical_json($payload);
        if (strlen($payloadJson) > 65536) throw new MultiplayerGameException('The game action is too large.', 'MULTIPLAYER_GAME_ACTION_TOO_LARGE', 422);
        $replay = multiplayer_game_action_replay(
            $pdo,
            (int)$session['id'],
            $requestId,
            $userId,
            $expectedVersion,
            $actionType,
            $payloadJson
        );
        if ($replay !== null) {
            database_transaction_commit($pdo, $transaction);
            return $replay;
        }
        if ((string)$session['status'] !== 'active' || (int)$session['state_version'] !== $expectedVersion) {
            throw new MultiplayerGameException('The game state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409, ['currentVersion' => (int)$session['state_version']]);
        }
        $currentState = json_decode((string)$session['state_json'], true);
        if (!is_array($currentState)) {
            throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
        }
        $nextState = $currentState;
        if (array_key_exists('state', $payload)) {
            if (!is_array($payload['state'])) {
                throw new MultiplayerGameException('The authoritative game state must be an object or list.', 'MULTIPLAYER_GAME_STATE_INVALID', 422);
            }
            $nextState = $payload['state'];
        }
        $turnUserId = array_key_exists('turnUserId', $payload)
            ? (int)$payload['turnUserId']
            : ($session['turn_user_id'] === null ? null : (int)$session['turn_user_id']);
        if (($turnUserId ?? 0) > 0) {
            $turn = $pdo->prepare("SELECT 1 FROM multiplayer_game_members WHERE game_session_id=? AND user_id=? AND role IN ('master','player') AND membership_status='active' LIMIT 1");
            $turn->execute([(int)$session['id'], $turnUserId]);
            if (!$turn->fetchColumn()) throw new MultiplayerGameException('The next turn owner is invalid.', 'MULTIPLAYER_GAME_TURN_OWNER_INVALID', 422);
        }
        // These keys are framework-owned even for legacy compatibility games.
        // Preserve them from the locked server state and derive turnIndex only
        // from the validated next turn owner, never from client-submitted state.
        $nextState['_framework'] = (array)($currentState['_framework'] ?? []);
        $nextState['turnOrder'] = array_values(array_map('intval', (array)($currentState['turnOrder'] ?? [])));
        $nextState['turnIndex'] = $turnUserId === null
            ? null
            : array_search($turnUserId, $nextState['turnOrder'], true);
        if ($turnUserId !== null && $nextState['turnIndex'] === false) {
            throw new MultiplayerGameException('The next turn owner is outside the server turn order.', 'MULTIPLAYER_GAME_TURN_OWNER_INVALID', 422);
        }
        multiplayer_game_assert_bot_mode((string)$session['mode'], $nextState);
        $nextStateJson = multiplayer_game_canonical_json($nextState);
        if (strlen($nextStateJson) > 262144) {
            throw new MultiplayerGameException('The authoritative game state is too large.', 'MULTIPLAYER_GAME_STATE_TOO_LARGE', 422);
        }
        $nextVersion = $expectedVersion + 1;
        $receipt = strtoupper(hash('sha256', multiplayer_game_canonical_json([
            'action' => $actionType, 'actor' => $userId, 'game' => $publicId,
            'payload' => $payload, 'request' => $requestId, 'version' => $nextVersion,
        ])));
        $update = $pdo->prepare("UPDATE multiplayer_game_sessions SET state_json=?,state_version=?,turn_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND state_version=? AND status='active'");
        $update->execute([$nextStateJson, $nextVersion, $turnUserId, (int)$session['id'], $expectedVersion]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The game state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        $pdo->prepare('INSERT INTO multiplayer_game_actions (game_session_id,request_id,actor_user_id,expected_version,resulting_version,action_type,payload_json,receipt_sha256) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int)$session['id'], $requestId, $userId, $expectedVersion, $nextVersion, $actionType, $payloadJson, $receipt]);
        database_transaction_commit($pdo, $transaction);
        return ['ok' => true, 'idempotentReplay' => false, 'version' => $nextVersion, 'receiptSha256' => $receipt];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_randomness_dice(string $canonicalReveal): array
{
    $dice = [];
    $counter = 0;
    while (count($dice) < 5 && $counter < 128) {
        $block = hash('sha256', $canonicalReveal . ':' . $counter, true);
        foreach (unpack('C*', $block) as $byte) {
            if ($byte >= 252) continue;
            $dice[] = ($byte % 6) + 1;
            if (count($dice) === 5) break;
        }
        $counter++;
    }
    if (count($dice) !== 5) {
        throw new MultiplayerGameException('Verified randomness could not be derived.', 'MULTIPLAYER_GAME_RANDOMNESS_DERIVATION_FAILED', 500);
    }
    return $dice;
}

/**
 * Retry an entire authoritative game action when the database reports a
 * transient writer-lock conflict. The same request identity is deliberately
 * retained across attempts so an uncertain client retry remains idempotent.
 */
function multiplayer_game_with_transient_action_retry(PDO $pdo, callable $operation): mixed
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && db_driver($pdo) === 'sqlite') {
        try {
            // Poll reads share the bounded SQLite deadline and short busy wait.
            // Mutations retain the existing transaction/retry policy below.
            return db_with_sqlite_poll_retry($pdo, $operation, 'game framework read');
        } catch (Throwable $error) {
            if (!db_is_transient_lock_error($error)) throw $error;
            throw new MultiplayerGameException(
                'The game is briefly busy. Please try again.',
                'MULTIPLAYER_GAME_DATABASE_BUSY',
                503,
                ['retryable' => true, 'retryAfterMs' => 500]
            );
        }
    }
    $delaysMs = db_driver($pdo) === 'sqlite'
        ? [25, 75, 150, 300]
        : [40, 120, 250];
    foreach ($delaysMs as $attempt => $delayMs) {
        try {
            return $operation();
        } catch (Throwable $error) {
            if (!db_is_transient_lock_error($error)) throw $error;
            if ($attempt === array_key_last($delaysMs)) {
                throw new MultiplayerGameException(
                    'The game is briefly busy. Its latest state has been restored; try the action again.',
                    'MULTIPLAYER_GAME_DATABASE_BUSY',
                    503,
                    [
                        'retryable' => true,
                        'retryAfterMs' => 500,
                    ]
                );
            }
            usleep(($delayMs + random_int(0, 20)) * 1000);
        }
    }
    throw new MultiplayerGameException(
        'The game is briefly busy. Its latest state has been restored; try the action again.',
        'MULTIPLAYER_GAME_DATABASE_BUSY',
        503,
        ['retryable' => true, 'retryAfterMs' => 500]
    );
}

function multiplayer_game_extension_action(
    PDO $pdo,
    string $publicId,
    int $userId,
    string $requestId,
    int $expectedVersion,
    string $actionType,
    array $payload,
    string $randomnessRequestId = '',
    ?callable $timing = null
): array {
    return multiplayer_game_with_transient_action_retry($pdo, static function() use (
        $pdo,
        $publicId,
        $userId,
        $requestId,
        $expectedVersion,
        $actionType,
        $payload,
        $randomnessRequestId,
        $timing
    ): array {
    // Acquire SQLite's writer reservation before any authoritative reads. On
    // MariaDB this remains the ordinary PDO transaction family and the row
    // lock below supplies serialization.
    $transaction = database_transaction_begin($pdo, true);
    if ($timing) $timing('transaction_begin');
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
        $adapter = multiplayer_game_extension_adapter($pdo, $definition);
        if ($timing) $timing('definition');
        if (!is_array($adapter)) {
            throw new MultiplayerGameException('This game uses its compatibility action owner.', 'MULTIPLAYER_GAME_COMPATIBILITY_ACTION_REQUIRED', 409);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)) {
            throw new MultiplayerGameException('The action identity is invalid.', 'MULTIPLAYER_GAME_REQUEST_INVALID', 422);
        }
        $actionType = strtolower(trim($actionType));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/', $actionType)) {
            throw new MultiplayerGameException('The action type is invalid.', 'MULTIPLAYER_GAME_ACTION_TYPE_INVALID', 422);
        }
        $randomnessPurposes = is_array($adapter['randomnessPurposes'] ?? null)
            ? $adapter['randomnessPurposes']
            : ['roll' => (string)($adapter['randomnessPurpose'] ?? '')];
        $randomnessPurpose = trim((string)($randomnessPurposes[$actionType] ?? ''));
        $actionRecord = ['payload' => $payload];
        if ($randomnessPurpose !== '') $actionRecord['randomnessRequestId'] = $randomnessRequestId;
        $payloadJson = multiplayer_game_canonical_json($actionRecord);
        if (strlen($payloadJson) > 65536) {
            throw new MultiplayerGameException('The game action is too large.', 'MULTIPLAYER_GAME_ACTION_TOO_LARGE', 422);
        }
        $replay = multiplayer_game_action_replay(
            $pdo,
            (int)$session['id'],
            $requestId,
            $userId,
            $expectedVersion,
            $actionType,
            $payloadJson
        );
        if ($replay !== null) {
            database_transaction_commit($pdo, $transaction);
            return $replay;
        }
        $requestExpectedVersion = $expectedVersion;
        // Independent input sequences permit simultaneous arcade commands while
        // the session lock and original request envelope still protect replay.
        if (!empty($adapter['allowsConcurrentInputs'])
            && in_array($actionType, ['arcade-input', 'arcade-tick'], true)
            && $expectedVersion >= 0 && $expectedVersion <= (int)$session['state_version']) {
            $expectedVersion = (int)$session['state_version'];
        }
        if (!empty($adapter['allowsConcurrentInputs'])
            && in_array($actionType, multiplayer_game_shared_action_names(), true)
            && $expectedVersion >= 0 && $expectedVersion < (int)$session['state_version']
            && (int)$session['state_version'] - $expectedVersion <= 1000) {
            // Only pure gameplay ticks may be crossed. A different pause vote,
            // restore, reconnect or other lifecycle transition still invalidates
            // the old consent; missing action history also fails closed.
            $intervening = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_actions WHERE game_session_id=? AND resulting_version>? AND resulting_version<=? AND action_type IN ('arcade-input','arcade-tick')");
            $intervening->execute([(int)$session['id'], $expectedVersion, (int)$session['state_version']]);
            if ((int)$intervening->fetchColumn() === (int)$session['state_version'] - $expectedVersion) {
                $expectedVersion = (int)$session['state_version'];
            }
        }
        if ((string)$session['status'] !== 'active' || (int)$session['state_version'] !== $expectedVersion) {
            throw new MultiplayerGameException('The game state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409, ['currentVersion' => (int)$session['state_version']]);
        }
        $state = json_decode((string)$session['state_json'], true);
        if (!is_array($state)) {
            throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
        }
        $memberRows = $pdo->prepare(
            "SELECT user_id,role,seat_number,membership_status,reconnect_deadline_at,last_seen_at "
            . "FROM multiplayer_game_members WHERE game_session_id=? ORDER BY seat_number ASC,user_id ASC"
        );
        $memberRows->execute([(int)$session['id']]);
        $context = [
            'mode' => (string)$session['mode'],
            'settings' => json_decode((string)$session['settings_json'], true) ?: [],
            'gameKey' => (string)$session['game_key'],
            'extensionId' => (string)($definition['extensionId'] ?? ''),
            'sessionPublicId' => (string)$session['public_id'],
            'stateVersion' => (int)$session['state_version'],
            'deferBotActions' => true,
            'turnUserId' => $session['turn_user_id'] === null ? null : (int)$session['turn_user_id'],
            'members' => array_map(static fn(array $row): array => [
                'userId' => (int)$row['user_id'],
                'role' => (string)$row['role'],
                'seat' => $row['seat_number'] === null ? null : (int)$row['seat_number'],
                'membershipStatus' => (string)$row['membership_status'],
                'reconnectDeadlineAt' => $row['reconnect_deadline_at'] ?? null,
                'lastSeenAt' => $row['last_seen_at'] ?? null,
            ], $memberRows->fetchAll()),
            'now' => gmdate('c'),
            'nowUnixMs' => (int)floor(microtime(true) * 1000),
        ];
        if (!isset($state['_framework']) || !is_array($state['_framework'])) {
            $validatedSettings = multiplayer_game_validate_extension_settings(
                $pdo,
                $definition,
                (array)$context['settings'],
                (string)$session['mode']
            );
            $state = multiplayer_game_initialize_shared_state(
                $state,
                multiplayer_game_shared_player_ids($state),
                $validatedSettings,
                (string)$session['mode'],
                $definition,
                (string)($session['started_at'] ?? $context['now'])
            );
        }
        if ($randomnessPurpose !== '') {
            if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $randomnessRequestId)) {
                throw new MultiplayerGameException('A verified randomness receipt is required.', 'MULTIPLAYER_GAME_RANDOMNESS_REQUIRED', 409);
            }
            $random = $pdo->prepare('SELECT actor_user_id,mode,purpose,state_version,reveal_json,expires_at FROM multiplayer_game_randomness WHERE game_session_id=? AND request_id=? LIMIT 1');
            $random->execute([(int)$session['id'], $randomnessRequestId]);
            $receipt = $random->fetch();
            if (!is_array($receipt)
                || (int)$receipt['actor_user_id'] !== $userId
                || (string)$receipt['mode'] !== (string)$session['mode']
                || (string)$receipt['purpose'] !== $randomnessPurpose
                || (int)$receipt['state_version'] !== $expectedVersion
                || $receipt['reveal_json'] === null
                || strtotime((string)$receipt['expires_at']) < time()) {
                throw new MultiplayerGameException('A current verified randomness receipt is required.', 'MULTIPLAYER_GAME_RANDOMNESS_REQUIRED', 409);
            }
            $context['randomnessRequestId'] = $randomnessRequestId;
            $context['authoritativeReveal'] = json_decode((string)$receipt['reveal_json'], true);
            $derive = trim((string)($adapter['deriveRandomness'] ?? ''));
            if ($derive !== '') {
                if (!function_exists($derive)) {
                    throw new MultiplayerGameException('The installed game randomness owner is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
                }
                $derived = $derive((string)$receipt['reveal_json'], $actionType, $payload, $context);
                if (!is_array($derived)) {
                    throw new MultiplayerGameException('Verified randomness could not be derived.', 'MULTIPLAYER_GAME_RANDOMNESS_DERIVATION_FAILED', 500);
                }
                $context['authoritativeRandomness'] = $derived;
                if (isset($derived['dice']) && is_array($derived['dice'])) $context['authoritativeDice'] = $derived['dice'];
            } else {
                $context['authoritativeDice'] = multiplayer_game_randomness_dice((string)$receipt['reveal_json']);
                $context['authoritativeRandomness'] = ['dice' => $context['authoritativeDice']];
            }
        } elseif ($randomnessRequestId !== '') {
            throw new MultiplayerGameException('This action does not accept a randomness receipt.', 'MULTIPLAYER_GAME_RANDOMNESS_UNEXPECTED', 422);
        }
        $recordingSteps = [];
        $recordingStepsBytes = 0;
        $recordingStepsIncomplete = false;
        $recordingGame = game_recording_game((string)($definition['extensionId'] ?? ''));
        if (game_recording_enabled($pdo, $recordingGame)) {
            $context['recordingCollector'] = static function(array $step) use (&$recordingSteps, &$recordingStepsBytes, &$recordingStepsIncomplete): void {
                try {
                    if (!empty($step['recordingError'])) { $recordingStepsIncomplete = true; return; }
                    $bytes = strlen(game_recording_json($step));
                    if ($recordingStepsBytes + $bytes > 3000000 || count($recordingSteps) >= 256) { $recordingStepsIncomplete = true; return; }
                    $recordingStepsBytes += $bytes;
                    $recordingSteps[] = $step;
                } catch (Throwable $ignored) { $recordingStepsIncomplete = true; }
            };
        }
        multiplayer_game_assert_bot_mode((string)$session['mode'], $state, (array)$context['settings']);
        $beforeState = $state;
        if ($timing) $timing('action_context');
        $beforeTurnUserId = $session['turn_user_id'] === null ? null : (int)$session['turn_user_id'];
        if (in_array($actionType, multiplayer_game_shared_action_names(), true)) {
            $applied = multiplayer_game_apply_shared_action($state, $userId, $actionType, $context);
        } else {
            $pauseMode = (string)($state['_framework']['pause']['mode'] ?? 'running');
            $resignation = $actionType === 'resign';
            if (!$resignation && (in_array($pauseMode, ['paused', 'resuming'], true)
                || multiplayer_game_shared_service_interruption_active($state))) {
                throw new MultiplayerGameException('The game is paused.', 'MULTIPLAYER_GAME_PAUSED', 409);
            }
            $apply = (string)($adapter['applyAction'] ?? '');
            if ($apply === '' || !function_exists($apply)) {
                throw new MultiplayerGameException('The installed game rules are unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
            }
            $applied = $apply($state, $userId, $actionType, $payload, $context);
            if (is_array($applied) && is_array($applied['state'] ?? null)) {
                $afterTurnUserId = ($applied['turnUserId'] ?? null) === null ? null : (int)$applied['turnUserId'];
                $progressCompleted = multiplayer_game_shared_progress_completed(
                    (string)($definition['extensionId'] ?? ''),
                    $actionType,
                    $beforeState,
                    $applied['state'],
                    $beforeTurnUserId,
                    $afterTurnUserId
                );
                multiplayer_game_refresh_shared_inactivity(
                    $applied['state'],
                    (array)$context['settings'],
                    $definition,
                    $afterTurnUserId,
                    multiplayer_game_shared_now($context),
                    $progressCompleted
                );
            }
        }
        if ($timing) $timing('simulation');
        if (!is_array($applied) || !is_array($applied['state'] ?? null)) {
            throw new MultiplayerGameException('The installed game returned invalid state.', 'MULTIPLAYER_GAME_RULE_RESULT_INVALID', 500);
        }
        if ($actionType === 'resign') {
            // Preserve the authoritative actor identity for every reducer-owned
            // resignation so all viewers receive truthful terminal wording.
            $applied['state']['resignedUserId'] = $userId;
        }
        if (!empty($applied['terminal'])) {
            $applied['state']['_framework']['pause']['mode'] = 'completed';
            $applied['state']['_framework']['pause']['reason'] = 'completed';
            multiplayer_game_freeze_shared_timing($applied['state'], multiplayer_game_shared_now($context));
        }
        multiplayer_game_assert_bot_mode((string)$session['mode'], $applied['state']);
        $nextStateJson = multiplayer_game_canonical_json($applied['state']);
        if (strlen($nextStateJson) > 262144) {
            throw new MultiplayerGameException('The authoritative game state is too large.', 'MULTIPLAYER_GAME_STATE_TOO_LARGE', 500);
        }
        $turnUserId = $applied['turnUserId'] ?? null;
        if (($turnUserId ?? 0) > 0) {
            $turn = $pdo->prepare("SELECT 1 FROM multiplayer_game_members WHERE game_session_id=? AND user_id=? AND role IN ('master','player') AND membership_status='active' LIMIT 1");
            $turn->execute([(int)$session['id'], (int)$turnUserId]);
            if (!$turn->fetchColumn()) {
                throw new MultiplayerGameException('The next turn owner is invalid.', 'MULTIPLAYER_GAME_TURN_OWNER_INVALID', 500);
            }
        }
        $nextVersion = $expectedVersion + 1;
        $receiptSha = strtoupper(hash('sha256', multiplayer_game_canonical_json([
            'action' => $actionType,
            'actor' => $userId,
            'game' => $publicId,
            'payload' => $payload,
            'randomnessRequest' => $randomnessRequestId,
            'request' => $requestId,
            'state' => $applied['state'],
            'version' => $nextVersion,
        ])));
        $update = $pdo->prepare("UPDATE multiplayer_game_sessions SET state_json=?,state_version=?,turn_user_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND state_version=? AND status='active'");
        if ($timing) $timing('serialization');
        $update->execute([$nextStateJson, $nextVersion, $turnUserId, (int)$session['id'], $expectedVersion]);
        if ($update->rowCount() !== 1) {
            throw new MultiplayerGameException('The game state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        }
        $pdo->prepare('INSERT INTO multiplayer_game_actions (game_session_id,request_id,actor_user_id,expected_version,resulting_version,action_type,payload_json,receipt_sha256) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int)$session['id'], $requestId, $userId, $requestExpectedVersion, $nextVersion, $actionType, $payloadJson, $receiptSha]);
        $completion = null;
        if ($timing) $timing('state_write');
        if (!empty($applied['terminal'])) {
            $completion = multiplayer_game_complete_session(
                $pdo,
                $publicId,
                (int)$session['master_user_id'],
                is_array($applied['result'] ?? null) ? $applied['result'] : [],
                true
            );
        }
        if (isset($context['recordingCollector']) && $recordingSteps === []) {
            game_recording_observe($context, $recordingGame, $beforeState, $userId, $actionType, $payload, $applied['state']);
        }
        game_recording_capture($pdo, $publicId, 'action', ['identity' => $requestId,
            'actorId' => $userId, 'steps' => $recordingSteps, 'stepsIncomplete' => $recordingStepsIncomplete]);
        database_transaction_commit($pdo, $transaction);
        if ($timing) $timing('commit');
        return [
            'ok' => true,
            'idempotentReplay' => false,
            'version' => $nextVersion,
            'receiptSha256' => $receiptSha,
            'terminal' => !empty($applied['terminal']),
            'completion' => $completion,
        ];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
    });
}

function multiplayer_game_randomness(
    PDO $pdo,
    string $publicId,
    int $userId,
    string $requestId,
    ?string $practiceCommitment = null,
    string $purpose = 'gameplay'
): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        if ((string)$session['status'] !== 'active') throw new MultiplayerGameException('The game is not active.', 'MULTIPLAYER_GAME_NOT_ACTIVE', 409);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,96}$/', $requestId)) {
            throw new MultiplayerGameException('The randomness request identity is invalid.', 'MULTIPLAYER_GAME_RANDOM_REQUEST_INVALID', 422);
        }
        $purpose = strtolower(trim($purpose));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/', $purpose)) {
            throw new MultiplayerGameException('The randomness purpose is invalid.', 'MULTIPLAYER_GAME_RANDOM_PURPOSE_INVALID', 422);
        }
        $mode = (string)$session['mode'];
        $requestedCommitment = $mode === 'practice' ? strtoupper((string)$practiceCommitment) : null;
        if ($mode === 'practice' && !preg_match('/^[A-F0-9]{64}$/', (string)$requestedCommitment)) {
            throw new MultiplayerGameException('Practice randomness requires a SHA-256 commitment.', 'MULTIPLAYER_GAME_PRACTICE_COMMITMENT_REQUIRED', 422);
        }
        $replay = $pdo->prepare('SELECT * FROM multiplayer_game_randomness WHERE game_session_id=? AND request_id=? LIMIT 1');
        $replay->execute([(int)$session['id'], $requestId]);
        $existing = $replay->fetch();
        if (is_array($existing)) {
            if ((int)$existing['actor_user_id'] !== $userId
                || (string)$existing['purpose'] !== $purpose
                || (string)$existing['mode'] !== $mode
                || ($mode === 'practice' && !hash_equals((string)$existing['commitment_sha256'], (string)$requestedCommitment))) {
                throw new MultiplayerGameException('The randomness request identity is already bound.', 'MULTIPLAYER_GAME_RANDOM_REQUEST_CONFLICT', 409);
            }
            $result = [
                'mode' => (string)$existing['mode'],
                'purpose' => (string)$existing['purpose'],
                'stateVersion' => (int)$existing['state_version'],
                'commitmentSha256' => (string)$existing['commitment_sha256'],
                'reveal' => $existing['reveal_json'] === null ? null : json_decode((string)$existing['reveal_json'], true),
                'receiptSha256' => $existing['receipt_sha256'],
                'serverGenerated' => (string)$existing['mode'] === 'recorded',
                'idempotentReplay' => true,
            ];
            database_transaction_commit($pdo, $transaction);
            return $result;
        }
        $expiresAt = gmdate('Y-m-d H:i:s', time() + MULTIPLAYER_GAME_RANDOMNESS_TTL_SECONDS);
        if ($mode === 'practice') {
            $pdo->prepare('INSERT INTO multiplayer_game_randomness (game_session_id,request_id,actor_user_id,mode,purpose,state_version,commitment_sha256,expires_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([(int)$session['id'], $requestId, $userId, $mode, $purpose, (int)$session['state_version'], $requestedCommitment, $expiresAt]);
            database_transaction_commit($pdo, $transaction);
            return ['mode' => 'practice', 'purpose' => $purpose, 'stateVersion' => (int)$session['state_version'], 'commitmentSha256' => $requestedCommitment, 'serverGenerated' => false, 'idempotentReplay' => false];
        }
        $bytes = random_bytes(32);
        $reveal = ['base64' => base64_encode($bytes), 'generatedAt' => gmdate('c')];
        $revealJson = multiplayer_game_canonical_json($reveal);
        $commitment = strtoupper(hash('sha256', $revealJson));
        $receipt = strtoupper(hash('sha256', $publicId . ':' . $requestId . ':' . $commitment));
        $pdo->prepare('INSERT INTO multiplayer_game_randomness (game_session_id,request_id,actor_user_id,mode,purpose,state_version,commitment_sha256,reveal_json,receipt_sha256,expires_at,revealed_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)')
            ->execute([(int)$session['id'], $requestId, $userId, $mode, $purpose, (int)$session['state_version'], $commitment, $revealJson, $receipt, $expiresAt]);
        database_transaction_commit($pdo, $transaction);
        return ['mode' => 'recorded', 'purpose' => $purpose, 'stateVersion' => (int)$session['state_version'], 'commitmentSha256' => $commitment, 'reveal' => $reveal, 'receiptSha256' => $receipt, 'serverGenerated' => true, 'idempotentReplay' => false];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_reveal_practice_randomness(PDO $pdo, string $publicId, int $userId, string $requestId, mixed $reveal): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        $stmt = $pdo->prepare("SELECT * FROM multiplayer_game_randomness WHERE game_session_id=? AND request_id=? AND mode='practice' LIMIT 1");
        $stmt->execute([(int)$session['id'], $requestId]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int)$row['actor_user_id'] !== $userId) {
            throw new MultiplayerGameException('The Practice randomness commitment was not found.', 'MULTIPLAYER_GAME_PRACTICE_COMMITMENT_NOT_FOUND', 404);
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            throw new MultiplayerGameException('The Practice randomness commitment expired.', 'MULTIPLAYER_GAME_PRACTICE_COMMITMENT_EXPIRED', 409);
        }
        $revealJson = multiplayer_game_canonical_json($reveal);
        $commitment = strtoupper(hash('sha256', $revealJson));
        if (!hash_equals((string)$row['commitment_sha256'], $commitment)) {
            throw new MultiplayerGameException('The Practice randomness reveal does not match its commitment.', 'MULTIPLAYER_GAME_PRACTICE_REVEAL_MISMATCH', 409);
        }
        if ($row['reveal_json'] !== null) {
            if (!hash_equals((string)$row['reveal_json'], $revealJson)) {
                throw new MultiplayerGameException('The Practice randomness request was already revealed differently.', 'MULTIPLAYER_GAME_PRACTICE_REVEAL_CONFLICT', 409);
            }
            database_transaction_commit($pdo, $transaction);
            return ['mode' => 'practice', 'purpose' => (string)$row['purpose'], 'reveal' => $reveal, 'receiptSha256' => (string)$row['receipt_sha256'], 'idempotentReplay' => true];
        }
        $receipt = strtoupper(hash('sha256', $publicId . ':' . $requestId . ':' . $commitment . ':practice'));
        $update = $pdo->prepare('UPDATE multiplayer_game_randomness SET reveal_json=?,receipt_sha256=?,revealed_at=CURRENT_TIMESTAMP WHERE id=? AND reveal_json IS NULL');
        $update->execute([$revealJson, $receipt, (int)$row['id']]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The Practice randomness reveal changed elsewhere.', 'MULTIPLAYER_GAME_PRACTICE_REVEAL_CONFLICT', 409);
        database_transaction_commit($pdo, $transaction);
        return ['mode' => 'practice', 'purpose' => (string)$row['purpose'], 'reveal' => $reveal, 'receiptSha256' => $receipt, 'idempotentReplay' => false];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_complete_session(
    PDO $pdo,
    string $publicId,
    int $userId,
    array $result,
    bool $extensionAuthorized = false
): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
    multiplayer_game_lock_session($pdo, $publicId);
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master']);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
    $displayName = multiplayer_game_effective_display_name($pdo, $definition);
    multiplayer_game_assert_bot_mode((string)$session['mode'], json_decode((string)$session['state_json'], true) ?: [], json_decode((string)$session['settings_json'], true) ?: []);
    if (trim((string)($session['extension_id'] ?? '')) !== '' && !$extensionAuthorized) {
        throw new MultiplayerGameException(
            'Installed game results are completed by their server-authoritative rules.',
            'MULTIPLAYER_GAME_EXTENSION_COMPLETION_REQUIRED',
            409
        );
    }
    if ((string)$session['status'] === 'completed') {
        if ((string)$session['mode'] === 'practice') {
            database_transaction_commit($pdo, $transaction);
            return ['recorded' => false, 'practice' => true, 'displayName' => $displayName, 'idempotentReplay' => true];
        }
        $existing = $pdo->prepare('SELECT public_id,result_sha256,exact_player_set_sha256 FROM multiplayer_game_results WHERE game_session_public_id=? LIMIT 1');
        $existing->execute([$publicId]);
        $row = $existing->fetch();
        if (is_array($row)) {
            database_transaction_commit($pdo, $transaction);
            return ['resultPublicId' => (string)$row['public_id'], 'resultSha256' => (string)$row['result_sha256'], 'exactPlayerSetSha256' => (string)$row['exact_player_set_sha256'], 'displayName' => $displayName, 'immutable' => true, 'idempotentReplay' => true];
        }
    }
    if (!in_array((string)$session['status'], ['active','paused','forfeited'], true)) throw new MultiplayerGameException('The game cannot be completed from its current state.', 'MULTIPLAYER_GAME_COMPLETE_STATE_INVALID', 409);
    if ((string)$session['mode'] === 'practice') {
        $update = $pdo->prepare("UPDATE multiplayer_game_sessions SET status='completed',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','paused','forfeited') AND result_public_id IS NULL");
        $update->execute([(int)$session['id']]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The game completion changed elsewhere.', 'MULTIPLAYER_GAME_COMPLETE_CONFLICT', 409);
        database_transaction_commit($pdo, $transaction);
        return ['recorded' => false, 'practice' => true, 'displayName' => $displayName, 'idempotentReplay' => false];
    }
    $members = $pdo->prepare("SELECT user_id,seat_number FROM multiplayer_game_members WHERE game_session_id=? AND role IN ('master','player') AND membership_status IN ('active','departed') ORDER BY user_id ASC");
    $members->execute([(int)$session['id']]);
    $memberRows = $members->fetchAll();
    $userIds = array_map('intval', array_column($memberRows, 'user_id'));
    $seatByUserId = [];
    foreach ($memberRows as $memberRow) {
        $seatByUserId[(int)$memberRow['user_id']] = (int)($memberRow['seat_number'] ?? 0);
    }
    $submitted = is_array($result['members'] ?? null) ? $result['members'] : [];
    if (count($submitted) !== count($userIds)) {
        throw new MultiplayerGameException('Recorded completion requires one score for every exact player.', 'MULTIPLAYER_GAME_RESULT_PLAYER_SET_MISMATCH', 422);
    }
    $scores = [];
    foreach ($userIds as $memberUserId) {
        $entry = $submitted[(string)$memberUserId] ?? $submitted[$memberUserId] ?? null;
        if (!is_array($entry) || !array_key_exists('score', $entry) || !is_numeric($entry['score'])) {
            throw new MultiplayerGameException('Recorded completion requires valid numeric scores.', 'MULTIPLAYER_GAME_RESULT_SCORE_INVALID', 422);
        }
        $score = (float)$entry['score'];
        if (!is_finite($score)) throw new MultiplayerGameException('Recorded completion requires finite scores.', 'MULTIPLAYER_GAME_RESULT_SCORE_INVALID', 422);
        $scores[$memberUserId] = $score;
    }
    $highest = max($scores);
    $leaders = array_keys(array_filter($scores, static fn(float $score): bool => $score === $highest));
    $spadesTeamResult = $extensionAuthorized && (string)($definition['extensionId'] ?? '') === 'spades';
    $normalizedMembers = [];
    if ($spadesTeamResult) {
        $outcomes = [];
        foreach ($userIds as $memberUserId) {
            $entry = $submitted[(string)$memberUserId] ?? $submitted[$memberUserId] ?? null;
            $outcome = strtolower(trim((string)($entry['outcome'] ?? '')));
            if (!in_array($outcome, ['win', 'loss', 'draw'], true)) {
                throw new MultiplayerGameException('A server-authoritative Spades result requires an exact team outcome for every player.', 'MULTIPLAYER_GAME_RESULT_OUTCOME_INVALID', 422);
            }
            $outcomes[$memberUserId] = $outcome;
        }
        $counts = array_count_values($outcomes);
        $allDraw = ($counts['draw'] ?? 0) === count($userIds);
        $teamWin = ($counts['draw'] ?? 0) === 0 && ($counts['win'] ?? 0) > 0 && ($counts['loss'] ?? 0) > 0;
        if (!$allDraw && !$teamWin) {
            throw new MultiplayerGameException('The server-authoritative Spades team result is inconsistent.', 'MULTIPLAYER_GAME_RESULT_OUTCOME_INVALID', 422);
        }
        foreach ($scores as $memberUserId => $score) {
            $normalizedMembers[(string)$memberUserId] = ['score' => $score, 'outcome' => $outcomes[$memberUserId]];
        }
    } else {
        foreach ($scores as $memberUserId => $score) {
            $normalizedMembers[(string)$memberUserId] = [
                'score' => $score,
                'outcome' => count($leaders) > 1 && in_array($memberUserId, $leaders, true) ? 'draw' : ($memberUserId === $leaders[0] ? 'win' : 'loss'),
            ];
        }
    }
    $pairOutcomes = [];
    foreach ($userIds as $memberUserId) {
        foreach ($userIds as $opponentUserId) {
            if ($memberUserId === $opponentUserId) continue;
            if ($spadesTeamResult) {
                $memberTeam = (($seatByUserId[$memberUserId] ?? 1) - 1) % 2;
                $opponentTeam = (($seatByUserId[$opponentUserId] ?? 1) - 1) % 2;
                if ($memberTeam === $opponentTeam) continue;
                $outcome = (string)$normalizedMembers[(string)$memberUserId]['outcome'];
            } else {
                $memberIsLeader = in_array($memberUserId, $leaders, true);
                $opponentIsLeader = in_array($opponentUserId, $leaders, true);
                if (count($leaders) === count($userIds) || ($memberIsLeader && $opponentIsLeader)) {
                    $outcome = 'draw';
                } elseif ($memberIsLeader && !$opponentIsLeader) {
                    $outcome = 'win';
                } elseif (!$memberIsLeader && $opponentIsLeader) {
                    $outcome = 'loss';
                } else {
                    continue;
                }
            }
            $pairOutcomes[] = ['userId' => $memberUserId, 'opponentUserId' => $opponentUserId, 'outcome' => $outcome];
        }
    }
    $normalizedResult = [
        'members' => $normalizedMembers,
        'terminalStateVersion' => (int)$session['state_version'],
        'displayNameSnapshot' => multiplayer_game_effective_display_name($pdo, $definition),
    ];
    $recordClass = strtolower(trim((string)($result['recordClass'] ?? 'standard')));
    if (!in_array($recordClass, ['standard', 'custom'], true)) {
        throw new MultiplayerGameException('The recorded rules classification is invalid.', 'MULTIPLAYER_GAME_RESULT_CLASS_INVALID', 422);
    }
    $normalizedResult['recordClass'] = $recordClass;
    $playerSetSha = strtoupper(hash('sha256', implode(':', $userIds)));
    $resultJson = multiplayer_game_canonical_json($normalizedResult);
    $resultSha = strtoupper(hash('sha256', $resultJson));
    $resultId = uuid_v4();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (MULTIPLAYER_GAME_RESULT_RETENTION_DAYS * 86400));
        $pdo->prepare('INSERT INTO multiplayer_game_results (public_id,game_session_public_id,game_key,adaptation_version,mode,exact_player_set_sha256,result_json,result_sha256,expires_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$resultId, $publicId, $session['game_key'], $definition['adaptationVersion'], $session['mode'], $playerSetSha, $resultJson, $resultSha, $expiresAt]);
        $rowId = (int)$pdo->lastInsertId();
        foreach ($userIds as $memberUserId) {
            $outcome = (string)$normalizedMembers[(string)$memberUserId]['outcome'];
            $score = $normalizedMembers[(string)$memberUserId]['score'];
            $pdo->prepare('INSERT INTO multiplayer_game_result_members (result_id,user_id,outcome,score_value) VALUES (?,?,?,?)')
                ->execute([$rowId, $memberUserId, $outcome, $score]);
        }
        foreach ($pairOutcomes as $pair) {
            $pdo->prepare('INSERT INTO multiplayer_game_result_pairs (result_id,user_id,opponent_user_id,outcome) VALUES (?,?,?,?)')
                ->execute([$rowId, $pair['userId'], $pair['opponentUserId'], $pair['outcome']]);
            $aggregateSql = db_uses_mysql_syntax($pdo)
                ? 'INSERT INTO multiplayer_game_result_aggregates (game_key,outcome,total_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE total_count=total_count+1,updated_at=CURRENT_TIMESTAMP'
                : 'INSERT INTO multiplayer_game_result_aggregates (game_key,outcome,total_count) VALUES (?,?,1) ON CONFLICT(game_key,outcome) DO UPDATE SET total_count=total_count+1,updated_at=CURRENT_TIMESTAMP';
            $pdo->prepare($aggregateSql)->execute([(string)$session['game_key'], $pair['outcome']]);
        }
        $complete = $pdo->prepare("UPDATE multiplayer_game_sessions SET status='completed',result_public_id=?,ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND result_public_id IS NULL");
        $complete->execute([$resultId, (int)$session['id']]);
        if ($complete->rowCount() !== 1) throw new MultiplayerGameException('The game completion changed elsewhere.', 'MULTIPLAYER_GAME_COMPLETE_CONFLICT', 409);
        database_transaction_commit($pdo, $transaction);
        return ['resultPublicId' => $resultId, 'resultSha256' => $resultSha, 'exactPlayerSetSha256' => $playerSetSha, 'displayName' => $displayName, 'members' => $normalizedMembers, 'opponentPairs' => $pairOutcomes, 'immutable' => true, 'idempotentReplay' => false];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_reconcile_master(PDO $pdo, int $sessionId): array
{
    $stmt = $pdo->prepare('SELECT id,public_id,master_user_id,status FROM multiplayer_game_sessions WHERE id=? LIMIT 1');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!is_array($session) || !in_array((string)$session['status'], ['lobby','active','paused'], true)) return ['changed' => false];
    $master = $pdo->prepare("SELECT membership_status FROM multiplayer_game_members WHERE game_session_id=? AND user_id=? LIMIT 1");
    $master->execute([$sessionId, (int)$session['master_user_id']]);
    if ($master->fetchColumn() === 'active') return ['changed' => false];
    $successor = $pdo->prepare("SELECT user_id FROM multiplayer_game_members WHERE game_session_id=? AND role='player' AND membership_status='active' ORDER BY seat_number ASC,user_id ASC LIMIT 1");
    $successor->execute([$sessionId]);
    $successorUserId = (int)($successor->fetchColumn() ?: 0);
    if ($successorUserId < 1) {
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='abandoned',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('lobby','active','paused')")
            ->execute([$sessionId]);
        return ['changed' => true, 'status' => 'abandoned', 'masterUserId' => null];
    }
    $pdo->prepare("UPDATE multiplayer_game_members SET role='master' WHERE game_session_id=? AND user_id=? AND role='player' AND membership_status='active'")
        ->execute([$sessionId, $successorUserId]);
    $newStatus = (string)$session['status'] === 'active' ? 'paused' : (string)$session['status'];
    $pdo->prepare('UPDATE multiplayer_game_sessions SET master_user_id=?,status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
        ->execute([$successorUserId, $newStatus, $sessionId]);
    $pdo->prepare('DELETE FROM multiplayer_game_acceptances WHERE game_session_id=?')->execute([$sessionId]);
    return ['changed' => true, 'status' => $newStatus, 'masterUserId' => $successorUserId, 'voteRequired' => $newStatus === 'paused'];
}

function multiplayer_game_exit_session(PDO $pdo, string $publicId, int $userId, string $reason = 'explicit-exit'): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $sessionStmt = $pdo->prepare('SELECT * FROM multiplayer_game_sessions WHERE public_id=? LIMIT 1');
        $sessionStmt->execute([$publicId]);
        $session = $sessionStmt->fetch();
        if (!is_array($session)) {
            throw new MultiplayerGameException('Game session not found.', 'MULTIPLAYER_GAME_NOT_FOUND', 404);
        }

        $memberStmt = $pdo->prepare(
            'SELECT id,role,membership_status FROM multiplayer_game_members WHERE game_session_id=? AND user_id=? LIMIT 1'
        );
        $memberStmt->execute([(int)$session['id'], $userId]);
        $member = $memberStmt->fetch();
        if (!is_array($member)) {
            throw new MultiplayerGameException('Game membership required.', 'MULTIPLAYER_GAME_MEMBERSHIP_REQUIRED', 403);
        }

        $hostExit = (string)$member['role'] === 'master' || (int)$session['master_user_id'] === $userId;
        $alreadyDeparted = (string)$member['membership_status'] === 'departed';
        $alreadyEnded = (string)$session['status'] === 'ended';
        $changed = false;
        $roundState = json_decode((string)($session['state_json'] ?? '{}'), true);
        $roundPlayerIds = is_array($roundState) ? multiplayer_game_shared_player_ids($roundState) : [];
        // A seated member waiting for the next round has nothing to resign.
        // Remove that membership without asking the current-round reducer.
        $playingThisRound = in_array($userId, $roundPlayerIds, true);

        if (!$alreadyDeparted && !$alreadyEnded
            && $playingThisRound
            && in_array((string)$member['role'], ['master', 'player'], true)
            && in_array((string)$session['status'], ['active', 'paused'], true)
            && multiplayer_game_supports_reducer_resignation($session)) {
            multiplayer_game_forfeit($pdo, $publicId, $userId);
        }

        if (!$alreadyDeparted && !$alreadyEnded) {
            $pdo->prepare(
                "UPDATE multiplayer_game_members
                    SET membership_status='departed',seat_number=NULL,departed_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP
                  WHERE game_session_id=? AND user_id=? AND membership_status='active'"
            )->execute([(int)$session['id'], $userId]);
            $changed = true;
            if (!$hostExit && (string)$session['status'] === 'lobby' && (string)$session['mode'] === 'practice') {
                multiplayer_game_refresh_practice_host_acceptance($pdo, $session);
            }
        }

        $activePlayersStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM multiplayer_game_members
              WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'"
        );
        $activePlayersStmt->execute([(int)$session['id']]);
        $activePlayers = (int)$activePlayersStmt->fetchColumn();
        $shouldEnd = $alreadyEnded || $hostExit || $activePlayers === 0;

        if ($shouldEnd && !$alreadyEnded) {
            $pdo->prepare(
                "UPDATE multiplayer_game_members
                    SET membership_status='departed',seat_number=NULL,departed_at=COALESCE(departed_at,CURRENT_TIMESTAMP),last_seen_at=CURRENT_TIMESTAMP
                  WHERE game_session_id=? AND membership_status='active'"
            )->execute([(int)$session['id']]);
            $pdo->prepare(
                "UPDATE multiplayer_game_sessions
                    SET status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP
                  WHERE id=?"
            )->execute([(int)$session['id']]);
            $pdo->prepare(
                'UPDATE game_sessions SET ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP) WHERE room_session_id=? AND lobby_code=?'
            )->execute([(int)$session['source_room_session_id'], $publicId]);
            $pdo->prepare(
                'UPDATE game_lobbies SET status="ended",updated_at=CURRENT_TIMESTAMP WHERE lobby_code=?'
            )->execute([$publicId]);
            $pdo->prepare('DELETE FROM game_moves WHERE lobby_code=?')->execute([$publicId]);
            $pdo->prepare('DELETE FROM game_state WHERE lobby_code=?')->execute([$publicId]);
            $pdo->prepare('DELETE FROM game_chat_messages WHERE lobby_code=?')->execute([$publicId]);
            $changed = true;
        }

        if ($changed) multiplayer_game_rotate_message_key_epoch($pdo, $publicId);
        if ($changed) game_recording_capture($pdo, $publicId, 'exit', ['actorId' => $userId]);
        database_transaction_commit($pdo, $transaction);
        return [
            'ok' => true,
            'departed' => true,
            'ended' => $shouldEnd,
            'event' => $shouldEnd ? 'game_end' : 'game_update',
            'hostExit' => $hostExit,
            'idempotent' => !$changed,
            'reason' => substr($reason, 0, 64),
        ];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_depart(PDO $pdo, string $publicId, int $userId, string $reason = 'departed'): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId);
    if(($session['extension_id'] ?? '')==='space-invasion' && in_array((string)$session['status'],['active','paused'],true)) {
        $seat=$pdo->prepare("SELECT role FROM multiplayer_game_members WHERE game_session_id=? AND user_id=?");$seat->execute([(int)$session['id'],$userId]);
        if(in_array($seat->fetchColumn(),['master','player'],true))multiplayer_game_forfeit($pdo,$publicId,$userId);
        $seat->closeCursor();
    }
    $pdo->prepare("UPDATE multiplayer_game_members SET membership_status='departed',seat_number=NULL,departed_at=CURRENT_TIMESTAMP,last_seen_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=?")
        ->execute([(int)$session['id'], $userId]);
    if ((int)$session['master_user_id'] !== $userId
        && (string)$session['status'] === 'lobby'
        && (string)$session['mode'] === 'practice') {
        multiplayer_game_refresh_practice_host_acceptance($pdo, $session);
    }
    multiplayer_game_rotate_message_key_epoch($pdo, $publicId);
    $masterDisposition = (int)$session['master_user_id'] === $userId
        ? multiplayer_game_reconcile_master($pdo, (int)$session['id'])
        : ['changed' => false];
    game_recording_capture($pdo, $publicId, 'depart', ['actorId' => $userId]);
    return ['ok' => true, 'status' => 'departed', 'reason' => substr($reason, 0, 64), 'masterDisposition' => $masterDisposition];
}

function multiplayer_game_set_presentation_pack(PDO $pdo, int $userId, string $gameKey, string $packId): array
{
    $definition = multiplayer_game_definition($pdo, $gameKey, false);
    if (($definition['presentationSelectionOwner'] ?? 'viewer') === 'installation-owner') {
        throw new MultiplayerGameException(
            'This game appearance is managed by the Installation Owner in Settings.',
            'MULTIPLAYER_GAME_PRESENTATION_INSTALLATION_OWNER_REQUIRED',
            403
        );
    }
    $packs = array_column($definition['presentationPacks'], null, 'id');
    if (!isset($packs[$packId])) throw new MultiplayerGameException('This visual pack is unavailable.', 'MULTIPLAYER_GAME_PRESENTATION_PACK_INVALID', 422);
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO multiplayer_game_presentation_preferences (user_id,game_key,pack_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pack_id=VALUES(pack_id),updated_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO multiplayer_game_presentation_preferences (user_id,game_key,pack_id) VALUES (?,?,?) ON CONFLICT(user_id,game_key) DO UPDATE SET pack_id=excluded.pack_id,updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$userId, $gameKey, $packId]);
    return ['gameKey' => $gameKey, 'packId' => $packId, 'presentationOnly' => true];
}

function multiplayer_game_player_set(PDO $pdo, int $sessionId, bool $includeDeparted = false): array
{
    $statuses = $includeDeparted ? "('active','departed')" : "('active')";
    $stmt = $pdo->prepare(
        "SELECT user_id FROM multiplayer_game_members
         WHERE game_session_id=? AND role IN ('master','player')
           AND membership_status IN {$statuses} ORDER BY user_id ASC"
    );
    $stmt->execute([$sessionId]);
    $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    return [
        'userIds' => $ids,
        'sha256' => strtoupper(hash('sha256', implode(':', $ids))),
    ];
}

function multiplayer_game_save(PDO $pdo, string $publicId, int $userId, int $expectedVersion): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $result = multiplayer_game_save_core($pdo, $publicId, $userId, $expectedVersion);
        game_recording_capture($pdo, $publicId, 'save', ['actorId' => $userId]);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_save_core(PDO $pdo, string $publicId, int $userId, int $expectedVersion): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
    if (!in_array((string)$session['status'], ['active','paused'], true)
        || (int)$session['state_version'] !== $expectedVersion) {
        throw new MultiplayerGameException('The game state changed before it could be saved.', 'MULTIPLAYER_GAME_SAVE_STALE', 409, ['currentVersion' => (int)$session['state_version']]);
    }
    $set = multiplayer_game_player_set($pdo, (int)$session['id'], true);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
    $stateSha = strtoupper(hash('sha256', (string)$session['state_json']));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (MULTIPLAYER_GAME_SESSION_TTL_DAYS * 86400));
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO multiplayer_game_saves (game_key,exact_player_set_sha256,game_session_public_id,saved_by_user_id,framework_schema_version,adaptation_version,settings_sha256,state_sha256,mode,session_status,state_json,state_version,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE game_session_public_id=VALUES(game_session_public_id),saved_by_user_id=VALUES(saved_by_user_id),framework_schema_version=VALUES(framework_schema_version),adaptation_version=VALUES(adaptation_version),settings_sha256=VALUES(settings_sha256),state_sha256=VALUES(state_sha256),mode=VALUES(mode),session_status=VALUES(session_status),state_json=VALUES(state_json),state_version=VALUES(state_version),saved_at=CURRENT_TIMESTAMP,expires_at=VALUES(expires_at)'
        : 'INSERT INTO multiplayer_game_saves (game_key,exact_player_set_sha256,game_session_public_id,saved_by_user_id,framework_schema_version,adaptation_version,settings_sha256,state_sha256,mode,session_status,state_json,state_version,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(game_key,exact_player_set_sha256) DO UPDATE SET game_session_public_id=excluded.game_session_public_id,saved_by_user_id=excluded.saved_by_user_id,framework_schema_version=excluded.framework_schema_version,adaptation_version=excluded.adaptation_version,settings_sha256=excluded.settings_sha256,state_sha256=excluded.state_sha256,mode=excluded.mode,session_status=excluded.session_status,state_json=excluded.state_json,state_version=excluded.state_version,saved_at=CURRENT_TIMESTAMP,expires_at=excluded.expires_at';
    $pdo->prepare($sql)->execute([
        $session['game_key'], $set['sha256'], $publicId, $userId,
        MULTIPLAYER_GAME_FRAMEWORK_SCHEMA_VERSION, (string)$definition['adaptationVersion'],
        (string)$session['settings_sha256'], $stateSha, (string)$session['mode'],
        (string)$session['status'], (string)$session['state_json'], (int)$session['state_version'], $expiresAt,
    ]);
    return [
        'saved' => true,
        'gameKey' => $session['game_key'],
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'exactPlayerSetSha256' => $set['sha256'],
        'stateVersion' => (int)$session['state_version'],
        'expiresAt' => $expiresAt,
    ];
}

function multiplayer_game_resume(PDO $pdo, string $publicId, int $userId): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
    $set = multiplayer_game_player_set($pdo, (int)$session['id'], true);
    $stmt = $pdo->prepare('SELECT * FROM multiplayer_game_saves WHERE game_key=? AND exact_player_set_sha256=? AND expires_at>CURRENT_TIMESTAMP LIMIT 1');
    $stmt->execute([$session['game_key'], $set['sha256']]);
    $save = $stmt->fetch();
    if (!is_array($save)) throw new MultiplayerGameException('No compatible saved game is available.', 'MULTIPLAYER_GAME_SAVE_NOT_FOUND', 404);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
    if ((int)$save['framework_schema_version'] !== MULTIPLAYER_GAME_FRAMEWORK_SCHEMA_VERSION
        || !hash_equals((string)$definition['adaptationVersion'], (string)$save['adaptation_version'])
        || !hash_equals((string)$session['settings_sha256'], (string)$save['settings_sha256'])
        || !hash_equals(strtoupper(hash('sha256', (string)$save['state_json'])), (string)$save['state_sha256'])
        || !hash_equals((string)$session['mode'], (string)$save['mode'])) {
        throw new MultiplayerGameException('The saved game is not compatible with this exact game version and settings.', 'MULTIPLAYER_GAME_SAVE_INCOMPATIBLE', 409);
    }
    return [
        'gameKey' => (string)$save['game_key'],
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'gameSessionPublicId' => (string)$save['game_session_public_id'],
        'exactPlayerSetSha256' => (string)$save['exact_player_set_sha256'],
        'state' => json_decode((string)$save['state_json'], true) ?: [],
        'stateVersion' => (int)$save['state_version'],
        'sessionStatus' => (string)$save['session_status'],
        'savedAt' => (string)$save['saved_at'],
        'expiresAt' => (string)$save['expires_at'],
    ];
}

function multiplayer_game_restore_saved_game(PDO $pdo, string $publicId, int $userId, int $expectedVersion): array
{
    $transaction = database_transaction_begin($pdo, false);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        if (!in_array((string)$session['status'], ['active','paused'], true)
            || (int)$session['state_version'] !== $expectedVersion) {
            throw new MultiplayerGameException(
                'The game state changed before the saved game could be resumed.',
                'MULTIPLAYER_GAME_RESUME_STALE',
                409,
                ['currentVersion' => (int)$session['state_version']]
            );
        }

        $snapshot = multiplayer_game_resume($pdo, $publicId, $userId);
        $state = is_array($snapshot['state'] ?? null) ? $snapshot['state'] : [];
        multiplayer_game_assert_bot_mode((string)$session['mode'], $state);
        if ($state === [] || !empty($state['completed'])) {
            throw new MultiplayerGameException(
                'The saved game cannot be resumed.',
                'MULTIPLAYER_GAME_SAVE_INCOMPATIBLE',
                409
            );
        }

        $savedAt = strtotime((string)($snapshot['savedAt'] ?? ''));
        if (isset($state['_framework']) && is_array($state['_framework'])) {
            multiplayer_game_freeze_shared_timing($state, $savedAt === false ? time() : $savedAt);
            if ((string)($snapshot['sessionStatus'] ?? 'active') === 'active') {
                multiplayer_game_resume_shared_timing($state, time());
            }
        }

        $set = multiplayer_game_player_set($pdo, (int)$session['id'], true);
        $turnUserId = multiplayer_game_turn_owner_from_state($state, (array)$set['userIds'], (string)$session['mode']);
        $nextStatus = (string)($snapshot['sessionStatus'] ?? 'active') === 'paused' ? 'paused' : 'active';
        $nextStateJson = multiplayer_game_canonical_json($state);
        $update = $pdo->prepare(
            "UPDATE multiplayer_game_sessions
                SET state_json=?,state_version=state_version+1,turn_user_id=?,status=?,result_public_id=NULL,ended_at=NULL,updated_at=CURRENT_TIMESTAMP
              WHERE id=? AND state_version=? AND status IN ('active','paused')"
        );
        $update->execute([
            $nextStateJson,
            $turnUserId > 0 ? $turnUserId : null,
            $nextStatus,
            (int)$session['id'],
            $expectedVersion,
        ]);
        if ($update->rowCount() !== 1) {
            throw new MultiplayerGameException(
                'The game state changed before the saved game could be resumed.',
                'MULTIPLAYER_GAME_RESUME_STALE',
                409
            );
        }
        game_recording_capture($pdo, $publicId, 'restore', ['actorId' => $userId, 'sourceGameSessionId' => $snapshot['gameSessionPublicId']]);
        database_transaction_commit($pdo, $transaction);
        return [
            'restored' => true,
            'gameKey' => (string)$snapshot['gameKey'],
            'displayName' => (string)$snapshot['displayName'],
            'stateVersion' => $expectedVersion + 1,
            'status' => $nextStatus,
            'savedAt' => (string)$snapshot['savedAt'],
        ];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_disconnect(PDO $pdo, string $publicId, int $userId): array
{
    $transaction = database_transaction_begin($pdo, false);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId);
        $now = time();
        if (!in_array((string)$session['member_role'], ['master', 'player'], true)) {
            $pdo->prepare('UPDATE multiplayer_game_members SET last_seen_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=?')
                ->execute([(int)$session['id'], $userId]);
            game_recording_capture($pdo, $publicId, 'disconnect');
        database_transaction_commit($pdo, $transaction);
            return ['status' => 'spectator-disconnected', 'deadlineAt' => null, 'seconds' => 0];
        }
        $state = json_decode((string)$session['state_json'], true);
        if (!is_array($state)) throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
        if (!isset($state['_framework']) || !is_array($state['_framework'])) {
            $playerStmt = $pdo->prepare(
                "SELECT user_id FROM multiplayer_game_members
                  WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'
                  ORDER BY seat_number ASC,id ASC"
            );
            $playerStmt->execute([(int)$session['id']]);
            $playerIds = array_values(array_unique(array_map('intval', $playerStmt->fetchAll(PDO::FETCH_COLUMN))));
            if (!in_array($userId, $playerIds, true)) $playerIds[] = $userId;
            $settings = json_decode((string)$session['settings_json'], true);
            if (!is_array($settings)) $settings = [];
            // A legacy active session may predate shared reconnect state. Its
            // disconnect migration must not depend on the current extension
            // adapter or introduce an inactivity loss while recovering.
            $settings['inactivityProfile'] = 'unlimited';
            $state = multiplayer_game_initialize_shared_state(
                $state,
                $playerIds,
                $settings,
                (string)$session['mode'],
                [],
                (string)($session['started_at'] ?? gmdate('c', $now))
            );
        }
        $player = &$state['_framework']['players'][(string)$userId];
        if (!is_array($player)) throw new MultiplayerGameException('The reconnect owner is unavailable.', 'MULTIPLAYER_GAME_RECONNECT_STATE_INVALID', 500);
        if (!empty($player['disconnectedAt'])) {
            $remaining = max(0, 600 - multiplayer_game_shared_disconnect_used($player, $now));
            game_recording_capture($pdo, $publicId, 'disconnect');
        database_transaction_commit($pdo, $transaction);
            return [
                'status' => 'reconnect-grace',
                'deadlineAt' => multiplayer_game_shared_service_interruption_active($state) ? null : gmdate('c', $now + $remaining),
                'seconds' => $remaining,
                'cumulative' => true,
            ];
        }
        $used = max(0, min(600, (int)($player['disconnectUsedSeconds'] ?? 0)));
        $remaining = max(0, 600 - $used);
        $player['disconnectedAt'] = gmdate('c', $now);
        $player['serviceInterruptedAt'] = multiplayer_game_shared_service_interruption_active($state)
            ? gmdate('c', $now)
            : null;
        $state['_framework']['disconnectClaim'] = null;
        if ((string)($state['_framework']['pause']['mode'] ?? '') === 'proposed') {
            $state['_framework']['pause'] = [
                'mode' => 'running', 'reason' => null, 'proposedByUserId' => null,
                'acceptedByUserIds' => [], 'pausedAt' => null,
                'resumeStartedByUserId' => null, 'resumeAt' => null, 'resumeRemainingSeconds' => null, 'resumeNowByUserIds' => [],
            ];
        } elseif ((string)($state['_framework']['pause']['mode'] ?? '') === 'resuming') {
            $state['_framework']['pause']['mode'] = 'paused';
            $state['_framework']['pause']['reason'] = 'reconnect';
            $state['_framework']['pause']['resumeStartedByUserId'] = null;
            $state['_framework']['pause']['resumeAt'] = null;
            $state['_framework']['pause']['resumeRemainingSeconds'] = null;
            $state['_framework']['pause']['resumeNowByUserIds'] = [];
        }
        $deadline = multiplayer_game_shared_service_interruption_active($state)
            ? null
            : gmdate('Y-m-d H:i:s', $now + $remaining);
        $pdo->prepare('UPDATE multiplayer_game_members SET reconnect_deadline_at=?,last_seen_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=? AND membership_status=\'active\'')
            ->execute([$deadline, (int)$session['id'], $userId]);
        $nextStateJson = multiplayer_game_canonical_json($state);
        $update = $pdo->prepare('UPDATE multiplayer_game_sessions SET state_json=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND state_version=?');
        $update->execute([$nextStateJson, (int)$session['id'], (int)$session['state_version']]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The disconnect state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        game_recording_capture($pdo, $publicId, 'disconnect');
        database_transaction_commit($pdo, $transaction);
        return [
            'status' => 'reconnect-grace',
            'deadlineAt' => $deadline === null ? null : gmdate('c', $now + $remaining),
            'seconds' => $remaining,
            'cumulative' => true,
        ];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_touch_connection(PDO $pdo, string $publicId, int $userId): bool
{
    $staleBefore = gmdate('Y-m-d H:i:s', time() - 15);
    $current = $pdo->prepare(
        "SELECT m.last_seen_at
           FROM multiplayer_game_members m
           JOIN multiplayer_game_sessions s ON s.id=m.game_session_id
          WHERE s.public_id=? AND m.user_id=? AND m.membership_status='active'
          LIMIT 1"
    );
    $current->execute([$publicId, $userId]);
    $member = $current->fetch();
    $current->closeCursor();
    if (!$member) return false;
    $lastSeenAt = $member['last_seen_at'] ?? null;
    if ($lastSeenAt !== null && (string)$lastSeenAt >= $staleBefore) return false;

    $touch = $pdo->prepare(
        "UPDATE multiplayer_game_members
            SET last_seen_at=CURRENT_TIMESTAMP
          WHERE game_session_id=(SELECT id FROM multiplayer_game_sessions WHERE public_id=? LIMIT 1)
            AND user_id=? AND membership_status='active'
            AND (last_seen_at IS NULL OR last_seen_at < ?)"
    );
    $touch->execute([$publicId, $userId, $staleBefore]);
    return $touch->rowCount() === 1;
}

function multiplayer_game_disconnect_room_participant(
    PDO $pdo,
    int $roomSessionId,
    int $participantId,
    int $userId,
    string $reason
): array {
    if ($roomSessionId < 1 || $userId < 1) return ['changed' => false, 'sessions' => []];
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.public_id,s.status,m.role
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.source_room_session_id=?
            AND s.status IN ('lobby','active','paused')
            AND m.user_id=? AND m.membership_status='active'
            AND (m.participant_id=? OR ?<1)"
    );
    $stmt->execute([$roomSessionId, $userId, $participantId, $participantId]);
    $sessions = [];
    $warnings = [];
    foreach ($stmt->fetchAll() as $row) {
        $publicId = (string)$row['public_id'];
        if ((string)$row['status'] === 'lobby' || (string)$row['role'] === 'spectator') {
            multiplayer_game_depart($pdo, $publicId, $userId, $reason);
            $status = 'departed';
        } else {
            $result = multiplayer_game_disconnect($pdo, $publicId, $userId);
            $status = (string)($result['status'] ?? 'reconnect-grace');
        }
        $sessions[] = ['publicId' => $publicId, 'status' => $status];
    }
    return ['changed' => $sessions !== [], 'sessions' => $sessions];
}

function multiplayer_game_reconcile_stale_connections(PDO $pdo, int $roomSessionId, int $ageSeconds = 35): array
{
    if ($roomSessionId < 1) return ['changed' => false, 'sessions' => []];
    $cutoff = gmdate('Y-m-d H:i:s', time() - max(15, min(300, $ageSeconds)));
    $stmt = $pdo->prepare(
        "SELECT s.public_id,s.state_json,m.user_id
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.source_room_session_id=?
            AND s.status IN ('active','paused')
            AND m.role IN ('master','player')
            AND m.membership_status='active'
            AND m.last_seen_at<?"
    );
    $stmt->execute([$roomSessionId, $cutoff]);
    $sessions = [];
    foreach ($stmt->fetchAll() as $row) {
        $userId = (int)$row['user_id'];
        $state = json_decode((string)$row['state_json'], true);
        if (!is_array($state)
            || !empty($state['_framework']['players'][(string)$userId]['disconnectedAt'])) {
            continue;
        }
        $publicId = (string)$row['public_id'];
        try {
            $result = multiplayer_game_disconnect($pdo, $publicId, $userId);
        } catch (Throwable $error) {
            $message = strtolower($error->getMessage());
            if ($error instanceof PDOException) {
                if (str_contains($message, 'no such table') || str_contains($message, 'no such column')) {
                    $category = 'database-schema';
                } elseif (str_contains($message, 'transaction')) {
                    $category = 'database-transaction';
                } elseif (str_contains($message, 'locked') || str_contains($message, 'busy')) {
                    $category = 'database-lock';
                } elseif (str_contains($message, 'constraint')) {
                    $category = 'database-constraint';
                } else {
                    $category = 'database-other';
                }
            } elseif ($error instanceof JsonException) {
                $category = 'state-json';
            } elseif ($error instanceof MultiplayerGameException) {
                if (str_contains($message, 'reconnect owner') || str_contains($message, 'access is denied')) {
                    $category = 'game-membership';
                } elseif (str_contains($message, 'not installed') || str_contains($message, 'disabled')) {
                    $category = 'game-installation';
                } elseif (str_contains($message, 'settings') || str_contains($message, 'rules')) {
                    $category = 'game-settings';
                } else {
                    $category = 'game-state';
                }
            } elseif ($error instanceof TypeError) {
                $category = 'type';
            } else {
                $category = 'runtime-other';
            }
            $reference = strtoupper(substr(hash('sha256', 'stale-game|' . get_class($error) . '|' . $error->getMessage()), 0, 16));
            error_log(sprintf(
                'stale game reconciliation failure [%s] room_session=%d game=%s user=%d %s: %s',
                $reference,
                $roomSessionId,
                $publicId,
                $userId,
                get_class($error),
                $error->getMessage()
            ));
            $warnings[] = ['phase' => 'stale-game-' . $category, 'reference' => $reference];
            continue;
        }
        $sessions[] = [
            'publicId' => $publicId,
            'userId' => $userId,
            'status' => (string)($result['status'] ?? 'reconnect-grace'),
        ];
    }
    return ['changed' => $sessions !== [], 'sessions' => $sessions, 'warnings' => $warnings ?? []];
}

function multiplayer_game_reconnect(PDO $pdo, string $publicId, array $participant, string $clientEpoch): array
{
    $userId = (int)($participant['user_id'] ?? 0);
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId);
        $participantBinding = $pdo->prepare('SELECT 1 FROM participants WHERE id=? AND user_id=? AND session_id=? LIMIT 1');
        $participantBinding->execute([(int)($participant['id'] ?? 0), $userId, (int)$session['source_room_session_id']]);
        $boundParticipant = (bool)$participantBinding->fetchColumn();
        $participantBinding->closeCursor();
        if (!$boundParticipant) {
            throw new MultiplayerGameException('An authenticated participant in this game room is required.', 'MULTIPLAYER_GAME_ACCESS_DENIED', 403);
        }
        $state = json_decode((string)$session['state_json'], true);
        if (!is_array($state)) throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
        $now = time();
        $stateChanged = false;
        if (isset($state['_framework']['players'][(string)$userId])) {
            $player = &$state['_framework']['players'][(string)$userId];
            $started = strtotime((string)($player['disconnectedAt'] ?? ''));
            if ($started !== false) {
                $player['disconnectUsedSeconds'] = multiplayer_game_shared_disconnect_used($player, $now);
                $player['disconnectedAt'] = null;
                $player['serviceInterruptedAt'] = null;
                $state['_framework']['disconnectClaim'] = null;
                $stateChanged = true;
                $allConnected = true;
                foreach (multiplayer_game_shared_player_ids($state) as $playerId) {
                    if (!empty($state['_framework']['players'][(string)$playerId]['disconnectedAt'])) $allConnected = false;
                }
                $pause = &$state['_framework']['pause'];
                if ($allConnected && (string)($pause['mode'] ?? '') === 'paused' && (string)($pause['reason'] ?? '') === 'reconnect') {
                    $pause['mode'] = 'resuming';
                    $pause['resumeStartedByUserId'] = $userId;
                    $pause['resumeAt'] = gmdate('c', $now + 60);
                    $pause['resumeRemainingSeconds'] = null;
                    $pause['resumeNowByUserIds'] = [$userId];
                }
            }
        }
        $pdo->prepare('UPDATE multiplayer_game_members SET participant_id=?,client_epoch=?,reconnect_deadline_at=NULL,last_seen_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=?')
            ->execute([(int)$participant['id'], substr($clientEpoch ?: uuid_v4(), 0, 128), (int)$session['id'], $userId]);
        if ($stateChanged) {
            $nextStateJson = multiplayer_game_canonical_json($state);
            $update = $pdo->prepare('UPDATE multiplayer_game_sessions SET state_json=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND state_version=?');
            $update->execute([$nextStateJson, (int)$session['id'], (int)$session['state_version']]);
            if ($update->rowCount() !== 1) throw new MultiplayerGameException('The reconnect state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        }
        game_recording_capture($pdo, $publicId, 'reconnect');
        database_transaction_commit($pdo, $transaction);
        return multiplayer_game_project_session($pdo, $publicId, $userId);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_set_service_interruption(
    PDO $pdo,
    string $publicId,
    array $actor,
    bool $active,
    ?string $startedAt = null
): array {
    if ((string)($actor['role'] ?? '') !== 'admin') {
        throw new MultiplayerGameException('Administrator authorization is required.', 'MULTIPLAYER_GAME_ADMIN_REQUIRED', 403);
    }
    security_require_recent_authentication();
    $actorUserId = (int)($actor['id'] ?? 0);
    $transaction = database_transaction_begin($pdo, false);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $actorUserId);
        if (!in_array((string)$session['status'], ['active', 'paused'], true)) {
            throw new MultiplayerGameException('Only an active game can record a service interruption.', 'MULTIPLAYER_GAME_SERVICE_INTERRUPTION_STATE_INVALID', 409);
        }
        $state = json_decode((string)$session['state_json'], true);
        if (!is_array($state)) throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
        $definition = multiplayer_game_definition($pdo, (string)$session['game_key']);
        if (!isset($state['_framework']) || !is_array($state['_framework'])) {
            $settings = multiplayer_game_validate_extension_settings(
                $pdo,
                $definition,
                json_decode((string)$session['settings_json'], true) ?: [],
                (string)$session['mode']
            );
            $state = multiplayer_game_initialize_shared_state(
                $state,
                multiplayer_game_shared_player_ids($state),
                $settings,
                (string)$session['mode'],
                $definition,
                (string)($session['started_at'] ?? gmdate('c'))
            );
        }
        $currentlyActive = multiplayer_game_shared_service_interruption_active($state);
        if ($currentlyActive === $active) {
            game_recording_capture($pdo, $publicId, 'service-interruption');
        database_transaction_commit($pdo, $transaction);
            return multiplayer_game_project_session($pdo, $publicId, $actorUserId)
                + ['serviceInterruptionChanged' => false];
        }
        $now = time();
        $effectiveAt = $now;
        if ($active && $startedAt !== null && trim($startedAt) !== '') {
            $parsed = strtotime($startedAt);
            if ($parsed === false || $parsed > $now) {
                throw new MultiplayerGameException('The service interruption start time is invalid.', 'MULTIPLAYER_GAME_SERVICE_INTERRUPTION_TIME_INVALID', 422);
            }
            $sessionStarted = strtotime((string)($session['started_at'] ?? $session['created_at']));
            $effectiveAt = $sessionStarted === false ? $parsed : max($sessionStarted, $parsed);
        }
        multiplayer_game_set_service_interruption_state($state, $active, $effectiveAt);
        $nextStateJson = multiplayer_game_canonical_json($state);
        $update = $pdo->prepare('UPDATE multiplayer_game_sessions SET state_json=?,state_version=state_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND state_version=?');
        $update->execute([$nextStateJson, (int)$session['id'], (int)$session['state_version']]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The service interruption state changed elsewhere.', 'MULTIPLAYER_GAME_STATE_STALE', 409);
        log_tool(
            $pdo,
            $actorUserId,
            $active ? 'multiplayer_game_service_interruption_started' : 'multiplayer_game_service_interruption_ended',
            null,
            null,
            'game:' . $publicId . '; effective-at:' . gmdate('c', $effectiveAt)
        );
        game_recording_capture($pdo, $publicId, 'service-interruption');
        database_transaction_commit($pdo, $transaction);
        return multiplayer_game_project_session($pdo, $publicId, $actorUserId)
            + ['serviceInterruptionChanged' => true];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_vote(PDO $pdo, string $publicId, int $userId, string $voteType, string $voteValue): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $result = multiplayer_game_vote_core($pdo, $publicId, $userId, $voteType, $voteValue);
        game_recording_capture($pdo, $publicId, 'vote', ['actorId' => $userId, 'identity' => $voteType . ':' . $voteValue . ':' . $userId, 'lifecycle' => ['voteType' => $voteType, 'voteValue' => $voteValue]]);
        database_transaction_commit($pdo, $transaction);
        return $result;
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_vote_core(PDO $pdo, string $publicId, int $userId, string $voteType, string $voteValue): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
    if (!in_array($voteType, ['master-departure','continuation'], true)
        || !in_array($voteValue, ['save','forfeit','void','abandon','continue'], true)) {
        throw new MultiplayerGameException('The game vote is invalid.', 'MULTIPLAYER_GAME_VOTE_INVALID', 422);
    }
    $sql = db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO multiplayer_game_votes (game_session_id,user_id,vote_type,vote_value) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE vote_value=VALUES(vote_value),created_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO multiplayer_game_votes (game_session_id,user_id,vote_type,vote_value) VALUES (?,?,?,?) ON CONFLICT(game_session_id,user_id,vote_type) DO UPDATE SET vote_value=excluded.vote_value,created_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([(int)$session['id'], $userId, $voteType, $voteValue]);
    $players = multiplayer_game_player_set($pdo, (int)$session['id']);
    $totals = $pdo->prepare('SELECT vote_value,COUNT(*) AS total FROM multiplayer_game_votes WHERE game_session_id=? AND vote_type=? GROUP BY vote_value ORDER BY vote_value');
    $totals->execute([(int)$session['id'], $voteType]);
    $counts = [];
    foreach ($totals->fetchAll() as $row) $counts[(string)$row['vote_value']] = (int)$row['total'];
    $majority = intdiv(count($players['userIds']), 2) + 1;
    $resolved = ($counts[$voteValue] ?? 0) >= $majority ? $voteValue : null;
    if (in_array($resolved, ['void','abandon'], true)) {
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='abandoned',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('lobby','active','paused')")
            ->execute([(int)$session['id']]);
    } elseif ($resolved === 'continue') {
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='active',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='paused'")
            ->execute([(int)$session['id']]);
    } elseif ($resolved === 'save') {
        multiplayer_game_save($pdo, $publicId, $userId, (int)$session['state_version']);
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='paused',updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='active'")
            ->execute([(int)$session['id']]);
    } elseif ($resolved === 'forfeit') {
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='forfeited',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','paused')")
            ->execute([(int)$session['id']]);
    }
    return ['voteType' => $voteType, 'vote' => $voteValue, 'counts' => $counts, 'required' => $majority, 'resolved' => $resolved];
}

/**
 * Records unanimous player consent and creates one idempotent successor round.
 *
 * The successor preserves authenticated identities, seats, settings, mode,
 * room ownership, and framework records. Extension initial-state owners receive
 * the exact-player-set round context and alone decide color/side/dealer/opening
 * procedure. Spectators are not rematch voters or successor players.
 */
function multiplayer_game_request_rematch(PDO $pdo, string $publicId, int $userId): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        if (!in_array((string)$session['status'], ['completed','forfeited','abandoned'], true)) {
            throw new MultiplayerGameException('A rematch is available after this round ends.', 'MULTIPLAYER_GAME_REMATCH_STATE_INVALID', 409);
        }
        $started = $pdo->prepare("SELECT vote_value FROM multiplayer_game_votes WHERE game_session_id=? AND vote_type='rematch' AND vote_value LIKE 'started:%' LIMIT 1");
        $started->execute([(int)$session['id']]);
        $startedValue = (string)($started->fetchColumn() ?: '');
        if (str_starts_with($startedValue, 'started:')) {
            $nextPublicId = substr($startedValue, strlen('started:'));
            $projection = multiplayer_game_project_session($pdo, $nextPublicId, $userId);
            database_transaction_commit($pdo, $transaction);
            return ['status' => 'started', 'required' => count(multiplayer_game_player_set($pdo, (int)$session['id'])['userIds']), 'consented' => true, 'idempotentReplay' => true, 'session' => $projection];
        }

        $players = $pdo->prepare(
            "SELECT user_id,participant_id,role,seat_number FROM multiplayer_game_members
              WHERE game_session_id=? AND role IN ('master','player') AND membership_status='active'
              ORDER BY seat_number ASC,user_id ASC"
        );
        $players->execute([(int)$session['id']]);
        $playerRows = $players->fetchAll();
        if (count($playerRows) < 1 || !array_filter($playerRows, static fn(array $row): bool => (int)$row['user_id'] === $userId)) {
            throw new MultiplayerGameException('Only current players may request a rematch.', 'MULTIPLAYER_GAME_REMATCH_ACCESS_DENIED', 403);
        }
        $sql = db_uses_mysql_syntax($pdo)
            ? "INSERT INTO multiplayer_game_votes (game_session_id,user_id,vote_type,vote_value) VALUES (?,?,'rematch','requested') ON DUPLICATE KEY UPDATE vote_value='requested',created_at=CURRENT_TIMESTAMP"
            : "INSERT INTO multiplayer_game_votes (game_session_id,user_id,vote_type,vote_value) VALUES (?,?,'rematch','requested') ON CONFLICT(game_session_id,user_id,vote_type) DO UPDATE SET vote_value='requested',created_at=CURRENT_TIMESTAMP";
        $pdo->prepare($sql)->execute([(int)$session['id'], $userId]);
        $consents = $pdo->prepare("SELECT COUNT(*) FROM multiplayer_game_votes WHERE game_session_id=? AND vote_type='rematch' AND vote_value='requested'");
        $consents->execute([(int)$session['id']]);
        $consented = (int)$consents->fetchColumn();
        $required = count($playerRows);
        if ($consented !== $required) {
            database_transaction_commit($pdo, $transaction);
            return ['status' => 'waiting', 'required' => $required, 'consented' => $consented, 'idempotentReplay' => false, 'message' => "Rematch consent recorded ({$consented} of {$required})."];
        }

        $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
        $nextPublicId = uuid_v4();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (MULTIPLAYER_GAME_SESSION_TTL_DAYS * 86400));
        $pdo->prepare(
            'INSERT INTO multiplayer_game_sessions
             (public_id,game_key,extension_id,source_room_session_id,master_user_id,mode,profile,settings_json,settings_sha256,state_json,expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $nextPublicId, (string)$session['game_key'], $session['extension_id'] ?? null,
            (int)$session['source_room_session_id'], (int)$session['master_user_id'],
            (string)$session['mode'], (string)$session['profile'], (string)$session['settings_json'],
            (string)$session['settings_sha256'], '{}', $expiresAt,
        ]);
        $nextId = (int)$pdo->lastInsertId();
        foreach ($playerRows as $row) {
            if ((int)($row['participant_id'] ?? 0) < 1) {
                throw new MultiplayerGameException('Every rematch player must still have an authenticated room participant.', 'MULTIPLAYER_GAME_REMATCH_PARTICIPANT_INVALID', 409);
            }
            $pdo->prepare('INSERT INTO multiplayer_game_members (game_session_id,user_id,participant_id,role,seat_number,client_epoch) VALUES (?,?,?,?,?,?)')
                ->execute([$nextId, (int)$row['user_id'], (int)$row['participant_id'], (string)$row['role'], (int)$row['seat_number'], 'rematch-' . uuid_v4()]);
            multiplayer_game_record_accounting($pdo, $nextId, (int)$row['user_id'], (string)$row['role'] === 'master' ? 'create' : 'join');
        }
        $nextPlayerSet = multiplayer_game_player_set($pdo, $nextId);
        foreach ($playerRows as $row) {
            $pdo->prepare('INSERT INTO multiplayer_game_acceptances (game_session_id,user_id,settings_sha256,player_set_sha256) VALUES (?,?,?,?)')
                ->execute([$nextId, (int)$row['user_id'], (string)$session['settings_sha256'], $nextPlayerSet['sha256']]);
        }

        $host = null;
        if (database_migration_table_exists($pdo, 'game_sessions') && database_migration_table_exists($pdo, 'game_lobbies')) {
            $hostStmt = $pdo->prepare('SELECT started_by_participant_id FROM game_sessions WHERE lobby_code=? LIMIT 1');
            $hostStmt->execute([$publicId]);
            $host = $hostStmt->fetch();
            if (is_array($host)) {
                $participantIds = array_values(array_map(static fn(array $row): int => (int)$row['participant_id'], $playerRows));
                $pdo->prepare('INSERT INTO game_sessions (room_session_id,game_type,lobby_code,started_by_participant_id) VALUES (?,?,?,?)')
                    ->execute([(int)$session['source_room_session_id'], (string)$session['game_key'], $nextPublicId, (int)$host['started_by_participant_id']]);
                $pdo->prepare('INSERT INTO game_lobbies (lobby_code,game_id,user1_id,user2_id,round_number,status) VALUES (?,?,?,?,?,?)')
                    ->execute([$nextPublicId, (int)$definition['gameId'], $participantIds[0] ?? null, $participantIds[1] ?? null, 1, 'waiting']);
            }
        }

        // Bind the successor to this exact predecessor before initial-state
        // construction.  The surrounding transaction makes the linkage and
        // successor start atomic, and prevents an unrelated later game with
        // the same player set from inheriting short-lived series state.
        $pdo->prepare("UPDATE multiplayer_game_votes SET vote_value=? WHERE game_session_id=? AND vote_type='rematch'")
            ->execute(['started:' . $nextPublicId, (int)$session['id']]);
        $projection = multiplayer_game_start_session($pdo, $nextPublicId, (int)$session['master_user_id']);
        $roundNumber = max(1, (int)($projection['state']['roundNumber'] ?? 1));
        if (is_array($host)) {
            $pdo->prepare("UPDATE game_lobbies SET status='ended',updated_at=CURRENT_TIMESTAMP WHERE lobby_code=?")->execute([$publicId]);
            $pdo->prepare('UPDATE game_sessions SET ended_at=CURRENT_TIMESTAMP WHERE lobby_code=? AND ended_at IS NULL')->execute([$publicId]);
            $pdo->prepare("UPDATE game_lobbies SET status='active',round_number=?,updated_at=CURRENT_TIMESTAMP WHERE lobby_code=?")
                ->execute([$roundNumber, $nextPublicId]);
        }
        database_transaction_commit($pdo, $transaction);
        return ['status' => 'started', 'required' => $required, 'consented' => $required, 'idempotentReplay' => false, 'session' => $projection, 'message' => "All {$required} players accepted. Round {$roundNumber} started."];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_supports_reducer_resignation(array $session): bool
{
    $extensionId = (string)($session['extension_id'] ?? '');
    return in_array($extensionId, [
        'five-dice', 'chess', 'checkers', 'battleship', 'nested-four',
        'spades', 'blackjack', 'hearts', 'uno', 'chinese-checkers',
        'puppy-panic', 'acey-deucy', 'backgammon-first-party', 'space-invasion',
    ], true);
}

function multiplayer_game_forfeit(PDO $pdo, string $publicId, int $userId): array
{
    $transaction = database_transaction_begin($pdo, true);
    try {
        multiplayer_game_lock_session($pdo, $publicId);
        $session = multiplayer_game_require_member($pdo, $publicId, $userId, ['master','player']);
        if (multiplayer_game_supports_reducer_resignation($session)) {
            // Resign while the actor still owns their seat. The existing action
            // owner retains pause, clock, version, result and rollback rules.
            $result = multiplayer_game_extension_action(
                $pdo, $publicId, $userId, 'forfeit:' . uuid_v4(),
                (int)$session['state_version'], 'resign', []
            );
            game_recording_capture($pdo, $publicId, 'forfeit');
        database_transaction_commit($pdo, $transaction);
            return array_merge($result, [
                'status' => !empty($result['terminal']) ? 'completed' : 'active',
                'forfeitedByUserId' => $userId,
                'recordedResultRequired' => false,
            ]);
        }
        // Preserve compatibility and unresolved game-specific forfeit policy.
        $update = $pdo->prepare("UPDATE multiplayer_game_sessions SET status='forfeited',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('active','paused')");
        $update->execute([(int)$session['id']]);
        if ($update->rowCount() !== 1) throw new MultiplayerGameException('The game cannot be forfeited now.', 'MULTIPLAYER_GAME_FORFEIT_STATE_INVALID', 409);
        game_recording_capture($pdo, $publicId, 'forfeit');
        database_transaction_commit($pdo, $transaction);
        return ['status' => 'forfeited', 'forfeitedByUserId' => $userId, 'recordedResultRequired' => (string)$session['mode'] === 'recorded'];
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function multiplayer_game_options(PDO $pdo, int $userId, string $gameKey, ?array $input = null): array
{
    $definition = multiplayer_game_definition($pdo, $gameKey, false);
    if ($input !== null) {
        $master = max(0, min(100, (int)($input['masterVolume'] ?? 100)));
        $categories = is_array($input['categories'] ?? null) ? $input['categories'] : [];
        $individual = is_array($input['individual'] ?? null) ? $input['individual'] : [];
        $categoryJson = multiplayer_game_canonical_json($categories);
        $individualJson = multiplayer_game_canonical_json($individual);
        if (strlen($categoryJson) > 8192 || strlen($individualJson) > 16384) throw new MultiplayerGameException('Game sound settings are too large.', 'MULTIPLAYER_GAME_OPTIONS_TOO_LARGE', 422);
        $values = [$userId, $gameKey, $master, !empty($input['musicEnabled']) ? 1 : 0, !empty($input['voiceEnabled']) ? 1 : 0, !empty($input['effectsEnabled']) ? 1 : 0, $categoryJson, $individualJson];
        $sql = db_uses_mysql_syntax($pdo)
            ? 'INSERT INTO multiplayer_game_options (user_id,game_key,master_volume,music_enabled,voice_enabled,effects_enabled,category_json,individual_json) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE master_volume=VALUES(master_volume),music_enabled=VALUES(music_enabled),voice_enabled=VALUES(voice_enabled),effects_enabled=VALUES(effects_enabled),category_json=VALUES(category_json),individual_json=VALUES(individual_json),updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO multiplayer_game_options (user_id,game_key,master_volume,music_enabled,voice_enabled,effects_enabled,category_json,individual_json) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(user_id,game_key) DO UPDATE SET master_volume=excluded.master_volume,music_enabled=excluded.music_enabled,voice_enabled=excluded.voice_enabled,effects_enabled=excluded.effects_enabled,category_json=excluded.category_json,individual_json=excluded.individual_json,updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute($values);
    }
    $stmt = $pdo->prepare('SELECT * FROM multiplayer_game_options WHERE user_id=? AND game_key=?');
    $stmt->execute([$userId, $gameKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        $defaults = array_replace([
            'masterVolume' => 100,
            'musicEnabled' => true,
            'voiceEnabled' => true,
            'effectsEnabled' => true,
        ], is_array($definition['soundDefaults'] ?? null) ? $definition['soundDefaults'] : []);
        return [
            'gameKey' => $gameKey,
            'masterVolume' => max(0, min(100, (int)$defaults['masterVolume'])),
            'musicEnabled' => (bool)$defaults['musicEnabled'],
            'voiceEnabled' => (bool)$defaults['voiceEnabled'],
            'effectsEnabled' => (bool)$defaults['effectsEnabled'],
            'categories' => [],
            'individual' => [],
            'default' => true,
        ];
    }
    return ['gameKey' => $gameKey, 'masterVolume' => (int)$row['master_volume'], 'musicEnabled' => (bool)$row['music_enabled'], 'voiceEnabled' => (bool)$row['voice_enabled'], 'effectsEnabled' => (bool)$row['effects_enabled'], 'categories' => json_decode((string)$row['category_json'], true) ?: [], 'individual' => json_decode((string)$row['individual_json'], true) ?: [], 'default' => false];
}

function multiplayer_game_records(PDO $pdo, int $userId, string $gameKey): array
{
    multiplayer_game_definition($pdo, $gameKey, false);
    $stmt = $pdo->prepare(
        "SELECT r.public_id,r.recorded_at,r.result_sha256,r.result_json,pair.outcome,me.score_value,
                pair.opponent_user_id,COALESCE(opponent.display_name,'Member') AS opponent_display_name
           FROM multiplayer_game_result_pairs pair
           JOIN multiplayer_game_results r ON r.id=pair.result_id
           LEFT JOIN multiplayer_game_result_members me ON me.result_id=r.id AND me.user_id=pair.user_id
           LEFT JOIN users opponent ON opponent.id=pair.opponent_user_id
          WHERE pair.user_id=? AND r.game_key=?
          ORDER BY r.recorded_at DESC,r.id DESC,pair.opponent_user_id ASC"
    );
    $stmt->execute([$userId, $gameKey]);
    $rows = $stmt->fetchAll();
    $totals = ['win' => 0, 'loss' => 0, 'draw' => 0, 'recorded' => 0];
    $recordClasses = [
        'standard' => ['win' => 0, 'loss' => 0, 'draw' => 0, 'recorded' => 0],
        'custom' => ['win' => 0, 'loss' => 0, 'draw' => 0, 'recorded' => 0],
    ];
    $opponents = [];
    foreach ($rows as &$row) {
        $result = json_decode((string)($row['result_json'] ?? ''), true);
        $row['displayNameSnapshot'] = is_array($result)
            ? (string)($result['displayNameSnapshot'] ?? '')
            : '';
        $row['recordClass'] = is_array($result) && in_array((string)($result['recordClass'] ?? ''), ['standard', 'custom'], true)
            ? (string)$result['recordClass']
            : 'standard';
        unset($row['result_json']);
        $outcome = (string)$row['outcome'];
        $totals[$outcome] = ($totals[$outcome] ?? 0) + 1;
        $totals['recorded']++;
        $recordClasses[$row['recordClass']][$outcome]++;
        $recordClasses[$row['recordClass']]['recorded']++;
        $opponentId = (int)$row['opponent_user_id'];
        if ($opponentId > 0) {
            $opponents[$opponentId] ??= [
                'userId' => $opponentId,
                'displayName' => (string)($row['opponent_display_name'] ?? 'Member'),
                'win' => 0,
                'loss' => 0,
                'draw' => 0,
                'recorded' => 0,
            ];
            $opponents[$opponentId][$outcome] = ($opponents[$opponentId][$outcome] ?? 0) + 1;
            $opponents[$opponentId]['recorded']++;
        }
        unset($row['opponent_display_name']);
    }
    unset($row);
    $definition = multiplayer_game_definition($pdo, $gameKey, false);
    return [
        'gameKey' => $gameKey,
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'lifetime' => $totals,
        'recordClasses' => $recordClasses,
        'opponents' => array_values($opponents),
        'results' => $rows,
    ];
}

function multiplayer_game_correct_result(PDO $pdo, array $actor, string $resultPublicId, string $reason, array $replacement): array
{
    if ((string)($actor['role'] ?? '') !== 'admin') throw new MultiplayerGameException('Administrator authorization is required.', 'MULTIPLAYER_GAME_ADMIN_REQUIRED', 403);
    security_require_recent_authentication();
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 500) throw new MultiplayerGameException('A bounded correction reason is required.', 'MULTIPLAYER_GAME_CORRECTION_REASON_REQUIRED', 422);
    $result = $pdo->prepare('SELECT id,result_sha256 FROM multiplayer_game_results WHERE public_id=? LIMIT 1');
    $result->execute([$resultPublicId]);
    $row = $result->fetch();
    if (!is_array($row)) throw new MultiplayerGameException('The result was not found.', 'MULTIPLAYER_GAME_RESULT_NOT_FOUND', 404);
    $replacementJson = multiplayer_game_canonical_json($replacement);
    $replacementSha = strtoupper(hash('sha256', $replacementJson));
    $pdo->prepare('INSERT INTO multiplayer_game_result_corrections (result_id,actor_user_id,reason,replacement_json,replacement_sha256) VALUES (?,?,?,?,?)')
        ->execute([(int)$row['id'], (int)$actor['id'], $reason, $replacementJson, $replacementSha]);
    log_tool($pdo, (int)$actor['id'], 'multiplayer_game_result_correction', null, null, 'result:' . $resultPublicId . '; replacement-sha256:' . $replacementSha . '; reason:' . $reason);
    return ['resultPublicId' => $resultPublicId, 'originalResultSha256' => (string)$row['result_sha256'], 'replacementSha256' => $replacementSha, 'originalImmutable' => true];
}

function multiplayer_game_project_shared_state(
    array $state,
    array $definition,
    array $members,
    int $viewerUserId
): array {
    if (!isset($state['_framework']) || !is_array($state['_framework'])) return $state;
    $now = time();
    foreach (multiplayer_game_shared_player_ids($state) as $userId) {
        $player = (array)($state['_framework']['players'][(string)$userId] ?? []);
        $used = multiplayer_game_shared_disconnect_used($player, $now);
        $state['_framework']['players'][(string)$userId]['disconnectUsedSeconds'] = $used;
        $state['_framework']['players'][(string)$userId]['disconnectRemainingSeconds'] = max(0, 600 - $used);
        $state['_framework']['players'][(string)$userId]['disconnected'] = !empty($player['disconnectedAt']);
    }
    $pause = &$state['_framework']['pause'];
    $resumeAt = strtotime((string)($pause['resumeAt'] ?? ''));
    $pause['resumeRemainingSeconds'] = $resumeAt === false
        ? ($pause['resumeRemainingSeconds'] ?? null)
        : max(0, $resumeAt - $now);
    $inactivity = &$state['_framework']['inactivity'];
    $deadline = strtotime((string)($inactivity['deadlineAt'] ?? ''));
    $inactivity['remainingProjectedSeconds'] = $deadline === false
        ? ($inactivity['remainingSeconds'] ?? null)
        : max(0, $deadline - $now);
    $eligibility = multiplayer_game_shared_disconnect_eligibility($state, [
        'now' => gmdate('c', $now),
        'extensionId' => (string)($definition['extensionId'] ?? ''),
        'members' => $members,
    ]);
    $claim = (array)($state['_framework']['disconnectClaim'] ?? []);
    $connectedPlayerIds = multiplayer_game_shared_connected_player_ids($state);
    $viewerConnected = in_array($viewerUserId, $connectedPlayerIds, true);
    $serviceInterrupted = multiplayer_game_shared_service_interruption_active($state);
    $state['_framework']['actions'] = [
        'canPause' => multiplayer_game_shared_can_pause($state, $viewerUserId),
        'canAcceptPause' => $viewerConnected && !$serviceInterrupted
            && (string)($pause['mode'] ?? '') === 'proposed'
            && !in_array($viewerUserId, (array)($pause['acceptedByUserIds'] ?? []), true),
        'canDeclinePause' => $viewerConnected && !$serviceInterrupted
            && (string)($pause['mode'] ?? '') === 'proposed'
            && $viewerUserId !== (int)($pause['proposedByUserId'] ?? 0),
        'canPauseForReconnect' => $viewerConnected && !$serviceInterrupted
            && (string)($pause['mode'] ?? 'running') === 'running'
            && count($connectedPlayerIds) < count(multiplayer_game_shared_player_ids($state)),
        'canStartResume' => $viewerConnected && !$serviceInterrupted
            && (string)($pause['mode'] ?? '') === 'paused'
            && count($connectedPlayerIds) === count(multiplayer_game_shared_player_ids($state)),
        'canResumeNow' => $viewerConnected && !$serviceInterrupted
            && (string)($pause['mode'] ?? '') === 'resuming'
            && !in_array($viewerUserId, (array)($pause['resumeNowByUserIds'] ?? []), true),
        'canSelectDisconnectWin' => $viewerConnected && !$serviceInterrupted && in_array($viewerUserId, $eligibility['winByUserIds'], true) && ($claim['kind'] ?? '') !== 'win',
        'canConfirmDisconnectWin' => $viewerConnected && !$serviceInterrupted && in_array($viewerUserId, $eligibility['winByUserIds'], true) && ($claim['kind'] ?? '') === 'win',
        'canSelectDisconnectDraw' => $viewerConnected && !$serviceInterrupted && in_array($viewerUserId, $eligibility['drawByUserIds'], true) && ($claim['kind'] ?? '') !== 'draw',
        'canConfirmDisconnectDraw' => $viewerConnected && !$serviceInterrupted && in_array($viewerUserId, $eligibility['drawByUserIds'], true) && ($claim['kind'] ?? '') === 'draw',
    ];
    return $state;
}

function multiplayer_game_shared_can_pause(array $state, int $viewerUserId): bool
{
    $connectedPlayerIds = multiplayer_game_shared_connected_player_ids($state);
    return in_array($viewerUserId, $connectedPlayerIds, true)
        && !multiplayer_game_shared_service_interruption_active($state)
        && count($connectedPlayerIds) === count(multiplayer_game_shared_player_ids($state))
        && (string)($state['_framework']['pause']['mode'] ?? 'running') === 'running';
}

function multiplayer_game_project_virtual_members(PDO $pdo, array $definition, array $state, array $context): array
{
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $callback = is_array($adapter) ? trim((string)($adapter['projectVirtualMembers'] ?? '')) : '';
    if ($callback === '') return [];
    if (!function_exists($callback)) {
        throw new MultiplayerGameException('The installed game virtual-player projection is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
    }
    $rawMembers = $callback($pdo, $state, $context);
    if (!is_array($rawMembers)) {
        throw new MultiplayerGameException('The installed game virtual-player projection is invalid.', 'MULTIPLAYER_GAME_RULE_RESULT_INVALID', 500);
    }
    $fallbackAvatarUrl = resolve_avatar('preset:Default');
    $members = [];
    $seen = [];
    foreach ($rawMembers as $raw) {
        if (!is_array($raw)) continue;
        $userId = (int)($raw['userId'] ?? 0);
        $seat = (int)($raw['seat'] ?? 0);
        $displayName = trim((string)($raw['displayName'] ?? 'Practice Bot'));
        if ($userId >= 0 || $seat < 1 || $displayName === '' || isset($seen[$userId])) {
            throw new MultiplayerGameException('The installed game returned an invalid virtual player.', 'MULTIPLAYER_GAME_RULE_RESULT_INVALID', 500);
        }
        $seen[$userId] = true;
        $members[] = [
            'userId' => $userId,
            'participantId' => 0,
            'displayName' => function_exists('mb_substr') ? mb_substr($displayName, 0, 80) : substr($displayName, 0, 80),
            'role' => 'player',
            'seat' => $seat,
            'membershipStatus' => 'active',
            'accepted' => true,
            'reconnectDeadlineAt' => null,
            'lastSeenAt' => null,
            'online' => true,
            'avatarPath' => 'preset:Default',
            'avatarUrl' => $fallbackAvatarUrl,
            'avatarFallbackUrl' => $fallbackAvatarUrl,
            'avatarHidden' => false,
            'webcamPath' => null,
            'bot' => true,
            'botDifficulty' => (string)($raw['difficulty'] ?? 'normal'),
        ];
    }
    return $members;
}

function multiplayer_game_rematch_successor_projection(PDO $pdo, array $session, int $viewerUserId): ?string
{
    if (!in_array((string)($session['status'] ?? ''), ['completed', 'forfeited', 'abandoned'], true)
        || !in_array((string)($session['member_role'] ?? ''), ['master', 'player'], true)
        || $viewerUserId < 1) {
        return null;
    }
    $vote = $pdo->prepare("SELECT vote_value FROM multiplayer_game_votes WHERE game_session_id=? AND user_id=? AND vote_type='rematch' LIMIT 1");
    $vote->execute([(int)$session['id'], $viewerUserId]);
    $value = (string)($vote->fetchColumn() ?: '');
    if (!str_starts_with($value, 'started:')) return null;
    $successorPublicId = substr($value, strlen('started:'));
    if ($successorPublicId === '' || $successorPublicId === (string)$session['public_id']) return null;
    $successor = $pdo->prepare(
        "SELECT s.public_id FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.public_id=? AND s.source_room_session_id=? AND s.game_key=?
            AND m.user_id=? AND m.membership_status='active' AND m.role IN ('master','player') LIMIT 1"
    );
    $successor->execute([$successorPublicId, (int)$session['source_room_session_id'], (string)$session['game_key'], $viewerUserId]);
    $visibleSuccessor = $successor->fetchColumn();
    return is_string($visibleSuccessor) && $visibleSuccessor !== '' ? $visibleSuccessor : null;
}

function multiplayer_game_acceptance_projection_basis(array $session, array $currentPlayerSet): array
{
    if (($session['status'] ?? null) === 'lobby') {
        return ['scope' => 'current-roster', 'sha256' => $currentPlayerSet['sha256'], 'userIds' => $currentPlayerSet['userIds']];
    }
    $unknown = ['scope' => 'history-unavailable', 'sha256' => null, 'userIds' => []];
    if (!in_array($session['status'] ?? null, ['active', 'paused', 'completed', 'forfeited', 'abandoned'], true)
        || !is_string($session['started_at'] ?? null) || trim($session['started_at']) === '') {
        return $unknown;
    }
    $state = json_decode((string)($session['state_json'] ?? ''), true);
    $framework = is_array($state) ? ($state['_framework'] ?? null) : null;
    if (!is_array($framework) || ($framework['schemaVersion'] ?? null) !== 1
        || !is_array($framework['players'] ?? null) || $framework['players'] === []) {
        return $unknown;
    }
    $ids = [];
    foreach ($framework['players'] as $key => $value) {
        $text = (string)$key;
        if (!is_array($value) || !preg_match('/^[1-9][0-9]*$/D', $text)) return $unknown;
        $id = (int)$text;
        if ($id < 1 || (string)$id !== $text) return $unknown;
        $ids[] = $id;
    }
    if (count($ids) !== count(array_unique($ids))) return $unknown;
    sort($ids, SORT_NUMERIC);
    return ['scope' => 'starting-roster', 'sha256' => strtoupper(hash('sha256', implode(':', $ids))), 'userIds' => $ids];
}
function multiplayer_game_acceptance_display_status(array $session, array $basis, array $member): string
{
    $recorded = (bool)($member['acceptance_record_exists'] ?? false);
    if ($basis['scope'] === 'current-roster') {
        if (($session['mode'] ?? null) === 'practice' && ($member['role'] ?? null) === 'player') return 'not-required-in-practice';
        return $recorded ? 'accepted-current-options' : 'acceptance-needed';
    }
    if ($basis['scope'] !== 'starting-roster') return 'history-unavailable';
    if (!in_array((int)($member['user_id'] ?? 0), $basis['userIds'], true)) return 'not-in-starting-roster';
    if ($recorded) return 'accepted-at-start';
    // Absence is not consent, nor proof that this current role was the original host.
    return ($session['mode'] ?? null) === 'practice' ? 'not-recorded-in-practice' : 'history-unavailable';
}

function multiplayer_game_session_envelope_actual_type(mixed $value, bool $present = true): string
{
    if (!$present) return 'missing';
    if ($value === null) return 'null';
    if (is_bool($value)) return 'boolean';
    if (is_int($value)) return $value < 0 ? 'negative-integer' : 'integer';
    if (is_float($value)) return 'number';
    if (is_string($value)) {
        if ($value === '') return 'empty-string';
        return preg_match('/^\d+$/', $value) === 1 ? 'string' : 'non-integer-string';
    }
    if (is_array($value)) return array_is_list($value) ? 'array' : 'object';
    if (is_object($value)) return 'object';
    if (is_resource($value)) return 'resource';
    return 'unknown';
}

function multiplayer_game_session_envelope_problem(mixed $projection): ?array
{
    $problem = static function(string $field, string $expected, mixed $actual = null, bool $present = true): array {
        return [
            'offendingField' => $field,
            'expectedType' => $expected,
            'actualType' => multiplayer_game_session_envelope_actual_type($actual, $present),
        ];
    };
    if (!is_array($projection) || array_is_list($projection)) return $problem('session', 'object', $projection);

    foreach (['publicId', 'status'] as $field) {
        if (!array_key_exists($field, $projection)) return $problem('session.' . $field, 'non-empty-string', null, false);
        if (!is_string($projection[$field]) || trim($projection[$field]) === '') {
            return $problem('session.' . $field, 'non-empty-string', $projection[$field]);
        }
    }
    if (!array_key_exists('settingsSha256', $projection)) return $problem('session.settingsSha256', 'string', null, false);
    if (!is_string($projection['settingsSha256'])) return $problem('session.settingsSha256', 'string', $projection['settingsSha256']);

    if (!array_key_exists('stateVersion', $projection)) return $problem('session.stateVersion', 'non-negative-integer', null, false);
    $stateVersion = $projection['stateVersion'];
    if ((!is_int($stateVersion) || $stateVersion < 0)
        && (!is_string($stateVersion) || preg_match('/^\d+$/', $stateVersion) !== 1)) {
        return $problem('session.stateVersion', 'non-negative-integer', $stateVersion);
    }

    if (!array_key_exists('members', $projection)) return $problem('session.members', 'array-of-objects', null, false);
    if (!is_array($projection['members']) || !array_is_list($projection['members'])) {
        return $problem('session.members', 'array-of-objects', $projection['members']);
    }
    foreach ($projection['members'] as $member) {
        if (!is_array($member) || ($member !== [] && array_is_list($member))) {
            return $problem('session.members[]', 'object', $member);
        }
    }

    if (!array_key_exists('state', $projection)) return $problem('session.state', 'object', null, false);
    if (!is_array($projection['state'])) return $problem('session.state', 'object', $projection['state']);
    $frameworkPresent = array_key_exists('_framework', $projection['state']);
    $framework = $frameworkPresent ? $projection['state']['_framework'] : null;
    if ($frameworkPresent && $framework !== null && !is_array($framework)) {
        return $problem('session.state._framework', 'object', $framework);
    }
    if (is_array($framework) && array_key_exists('players', $framework) && $framework['players'] !== null) {
        $players = $framework['players'];
        if (!is_array($players)) return $problem('session.state._framework.players', 'object', $players);
        foreach ($players as $player) {
            if (!is_array($player) || ($player !== [] && array_is_list($player))) {
                return $problem('session.state._framework.players[]', 'object', $player);
            }
        }
    }
    return null;
}

function multiplayer_game_project_session(PDO $pdo, string $publicId, int $viewerUserId): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $viewerUserId);
    $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
    $acceptanceBasis = multiplayer_game_acceptance_projection_basis($session, $playerSet);
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
    $members = $pdo->prepare(
        "SELECT m.user_id,m.participant_id,m.role,m.seat_number,m.membership_status,m.reconnect_deadline_at,m.last_seen_at,
                COALESCE(p.display_name,u.display_name,'Member') AS display_name,
                COALESCE(p.avatar_path,u.avatar_path,'preset:Default') AS avatar_path,p.webcam_path,
                CASE WHEN a.user_id IS NULL THEN 0 ELSE 1 END AS accepted
           FROM multiplayer_game_members m
           JOIN users u ON u.id=m.user_id
           LEFT JOIN participants p ON p.id=m.participant_id
           LEFT JOIN multiplayer_game_acceptances a ON a.game_session_id=m.game_session_id AND a.user_id=m.user_id AND a.settings_sha256=? AND a.player_set_sha256=?
          WHERE m.game_session_id=? ORDER BY CASE m.role WHEN 'master' THEN 0 WHEN 'player' THEN 1 ELSE 2 END,m.seat_number,m.id"
    );
    $members->execute([(string)$session['settings_sha256'], $acceptanceBasis['sha256'], (int)$session['id']]);
    $memberRows = $members->fetchAll();
    $fallbackAvatarUrl = resolve_avatar('preset:Default');
    $practiceMode = (string)$session['mode'] === 'practice';
    $projectedMembers = array_map(static function(array $row) use ($pdo, $viewerUserId, $fallbackAvatarUrl, $practiceMode, $session, $acceptanceBasis): array {
        $targetUserId = (int)$row['user_id'];
        $policy = avatar_visibility_effective($pdo, $viewerUserId, $targetUserId);
        $avatarPath = (string)($row['avatar_path'] ?? 'preset:Default');
        $hidden = !empty($policy['hidden']);
        return [
            'userId' => $targetUserId, 'participantId' => (int)($row['participant_id'] ?? 0),
            'displayName' => (string)$row['display_name'], 'role' => (string)$row['role'],
            'seat' => $row['seat_number'] === null ? null : (int)$row['seat_number'],
            'membershipStatus' => (string)$row['membership_status'],
            'accepted' => $practiceMode && (string)$row['role'] === 'player' ? true : (bool)$row['accepted'],
            'acceptanceStatus' => multiplayer_game_acceptance_display_status(
                $session,
                $acceptanceBasis,
                ['acceptance_record_exists' => (bool)$row['accepted']] + $row
            ),
            'reconnectDeadlineAt' => $row['reconnect_deadline_at'] ?? null,
            'lastSeenAt' => $row['last_seen_at'] ?? null,
            'avatarPath' => $hidden ? null : $avatarPath,
            'avatarUrl' => $hidden ? $fallbackAvatarUrl : resolve_avatar($avatarPath),
            'avatarFallbackUrl' => $fallbackAvatarUrl,
            'avatarHidden' => $hidden,
            'webcamPath' => $row['webcam_path'] ?? null,
        ];
    }, $memberRows);
    $seatRequests = [];
    if ((string)$session['member_role'] === 'master') {
        $requests = $pdo->prepare("SELECT user_id,request_status,created_at,resolved_at FROM multiplayer_game_seat_requests WHERE game_session_id=? ORDER BY created_at ASC,user_id ASC");
        $requests->execute([(int)$session['id']]);
        $seatRequests = array_map(static fn(array $row): array => [
            'userId' => (int)$row['user_id'],
            'status' => (string)$row['request_status'],
            'createdAt' => (string)$row['created_at'],
            'resolvedAt' => $row['resolved_at'] ?? null,
        ], $requests->fetchAll());
    } elseif ((string)$session['member_role'] === 'spectator') {
        $requests = $pdo->prepare('SELECT user_id,request_status,created_at,resolved_at FROM multiplayer_game_seat_requests WHERE game_session_id=? AND user_id=? LIMIT 1');
        $requests->execute([(int)$session['id'], $viewerUserId]);
        $row = $requests->fetch();
        if (is_array($row)) $seatRequests[] = ['userId' => $viewerUserId, 'status' => (string)$row['request_status'], 'createdAt' => (string)$row['created_at'], 'resolvedAt' => $row['resolved_at'] ?? null];
    }
    $settings = json_decode((string)$session['settings_json'], true);
    if (!is_array($settings)) {
        throw new MultiplayerGameException('The authoritative game settings are unavailable.', 'MULTIPLAYER_GAME_SETTINGS_INVALID', 500);
    }
    $state = json_decode((string)$session['state_json'], true);
    if (!is_array($state)) {
        throw new MultiplayerGameException('The authoritative game state is unavailable.', 'MULTIPLAYER_GAME_STATE_INVALID', 500);
    }
    $viewerRole = (string)$session['member_role'];
    $projectionContext = [
        'sessionPublicId' => (string)$session['public_id'],
        'stateVersion' => (int)$session['state_version'],
        'mode' => (string)$session['mode'],
        'settings' => $settings,
        'members' => $projectedMembers,
        'viewerRole' => $viewerRole,
        'status' => (string)$session['status'],
    ];
    $adapter = multiplayer_game_extension_adapter($pdo, $definition);
    $memberMetadataCallback = is_array($adapter)
        ? trim((string)($adapter['projectMemberMetadata'] ?? ''))
        : '';
    if ($memberMetadataCallback !== '') {
        if (!function_exists($memberMetadataCallback)) {
            throw new MultiplayerGameException('The installed game member metadata projection is unavailable.', 'MULTIPLAYER_GAME_RULES_UNAVAILABLE', 500);
        }
        $memberMetadata = $memberMetadataCallback(
            $pdo,
            $state,
            array_values(array_unique(array_map(
                static fn(array $member): int => (int)$member['userId'],
                array_filter($projectedMembers, static fn(array $member): bool => in_array((string)$member['role'], ['master', 'player'], true))
            ))),
            $projectionContext
        );
        if (!is_array($memberMetadata)) {
            throw new MultiplayerGameException('The installed game member metadata projection is invalid.', 'MULTIPLAYER_GAME_RULE_RESULT_INVALID', 500);
        }
        $projectionContext['memberMetadata'] = $memberMetadata;
    }
    if (!isset($state['_framework']) || !is_array($state['_framework'])) {
        $validatedSettings = multiplayer_game_validate_extension_settings(
            $pdo,
            $definition,
            $settings,
            (string)$session['mode']
        );
        $state = multiplayer_game_initialize_shared_state(
            $state,
            multiplayer_game_shared_player_ids($state),
            $validatedSettings,
            (string)$session['mode'],
            $definition,
            (string)($session['started_at'] ?? $session['created_at'])
        );
    }
    $state = multiplayer_game_project_shared_state($state, $definition, $projectedMembers, $viewerUserId);
    $virtualMembers = multiplayer_game_project_virtual_members($pdo, $definition, $state, $projectionContext);
    if ($virtualMembers !== []) {
        $projectedMembers = array_merge($projectedMembers, $virtualMembers);
        usort($projectedMembers, static fn(array $left, array $right): int => [(int)($left['seat'] ?? 999), (int)$left['userId']] <=> [(int)($right['seat'] ?? 999), (int)$right['userId']]);
        $projectionContext['members'] = $projectedMembers;
    }
    if (in_array((string)($state['_framework']['pause']['mode'] ?? 'running'), ['paused', 'resuming'], true)
        || multiplayer_game_shared_service_interruption_active($state)) {
        $projectionContext['status'] = 'paused';
    }
    $projectedState = multiplayer_game_project_extension_state($pdo, $definition, $state, $viewerUserId, $projectionContext);
    return [
        'publicId' => (string)$session['public_id'],
        'gameKey' => (string)$session['game_key'],
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'sourceRoomSessionId' => (int)$session['source_room_session_id'],
        'masterUserId' => (int)$session['master_user_id'],
        'mode' => (string)$session['mode'],
        'profile' => (string)$session['profile'],
        'minimumPlayers' => multiplayer_game_minimum_players($definition, (string)$session['mode'], $settings),
        'status' => (string)$session['status'],
        'settings' => $settings,
        'settingsSha256' => (string)$session['settings_sha256'],
        'settingsControls' => multiplayer_game_settings_control_projection($pdo, $definition, $settings, (string)$session['mode']),
        'seating' => multiplayer_game_seating_projection($definition, $session, $projectedMembers, $state, $viewerUserId),
        'botSeats' => multiplayer_game_bot_lobby_projection($definition, $session, $projectedMembers, $viewerUserId, $playerSet['sha256']),
        'playerSetSha256' => $playerSet['sha256'],
        'frameworkSchemaVersion' => MULTIPLAYER_GAME_FRAMEWORK_SCHEMA_VERSION,
        'adaptationVersion' => (string)$definition['adaptationVersion'],
        'presentation' => multiplayer_game_presentation_projection($pdo, $definition, $viewerUserId),
        'rules' => multiplayer_game_rules_projection($pdo, $definition, $settings, (string)$session['mode']),
        'state' => $projectedState,
        'stateVersion' => (int)$session['state_version'],
        'turnUserId' => $session['turn_user_id'] === null ? null : (int)$session['turn_user_id'],
        'viewerRole' => $viewerRole,
        'members' => $projectedMembers,
        'seatRequests' => $seatRequests,
        'rematchSuccessorPublicId' => multiplayer_game_rematch_successor_projection($pdo, $session, $viewerUserId),
        'createdAt' => (string)$session['created_at'],
        'startedAt' => $session['started_at'] ?? null,
        'expiresAt' => (string)$session['expires_at'],
        'endedAt' => $session['ended_at'] ?? null,
    ];
}

function multiplayer_game_room_projection(PDO $pdo, string $publicId, int $roomSessionId, int $viewerUserId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM multiplayer_game_sessions
         WHERE public_id=? AND source_room_session_id=?
           AND status IN ('lobby','active','paused','completed','forfeited','abandoned') LIMIT 1"
    );
    $stmt->execute([$publicId, $roomSessionId]);
    $session = $stmt->fetch();
    if (!is_array($session)) return null;
    $definition = multiplayer_game_definition($pdo, (string)$session['game_key'], false);
    $playerSet = multiplayer_game_player_set($pdo, (int)$session['id']);
    $acceptanceBasis = multiplayer_game_acceptance_projection_basis($session, $playerSet);
    $members = $pdo->prepare(
        "SELECT m.user_id,m.participant_id,m.role,m.seat_number,m.membership_status,
                m.reconnect_deadline_at,m.last_seen_at AS member_last_seen_at,
                p.last_seen_at AS participant_last_seen_at,
                COALESCE(p.display_name,u.display_name,'Member') AS display_name,
                CASE WHEN a.user_id IS NULL THEN 0 ELSE 1 END AS accepted
           FROM multiplayer_game_members m
           JOIN users u ON u.id=m.user_id
           LEFT JOIN participants p ON p.id=m.participant_id
           LEFT JOIN multiplayer_game_acceptances a ON a.game_session_id=m.game_session_id AND a.user_id=m.user_id AND a.settings_sha256=? AND a.player_set_sha256=?
          WHERE m.game_session_id=? ORDER BY CASE m.role WHEN 'master' THEN 0 WHEN 'player' THEN 1 ELSE 2 END,m.seat_number,m.id"
    );
    $members->execute([(string)$session['settings_sha256'], $acceptanceBasis['sha256'], (int)$session['id']]);
    $sessionState = json_decode((string)$session['state_json'], true) ?: [];
    $requiresGameHeartbeat = (string)$session['status'] !== 'lobby';
    $practiceMode = (string)$session['mode'] === 'practice';
    $projectedMembers = array_map(static function(array $row) use ($sessionState, $requiresGameHeartbeat, $practiceMode, $session, $acceptanceBasis): array {
        $userId = (int)$row['user_id'];
        $roomSeenAt = strtotime((string)($row['participant_last_seen_at'] ?? ''));
        $gameSeenAt = strtotime((string)($row['member_last_seen_at'] ?? ''));
        return [
            'userId' => $userId,
            'participantId' => (int)($row['participant_id'] ?? 0),
            'displayName' => (string)$row['display_name'],
            'role' => (string)$row['role'],
            'seat' => $row['seat_number'] === null ? null : (int)$row['seat_number'],
            'membershipStatus' => (string)$row['membership_status'],
            'accepted' => $practiceMode && (string)$row['role'] === 'player' ? true : (bool)$row['accepted'],
            'acceptanceStatus' => multiplayer_game_acceptance_display_status(
                $session,
                $acceptanceBasis,
                ['acceptance_record_exists' => (bool)$row['accepted']] + $row
            ),
            'reconnectDeadlineAt' => $row['reconnect_deadline_at'] ?? null,
            // A fresh authenticated game-session poll is direct evidence that
            // the board is connected. A historical reconnect marker can lag
            // behind that heartbeat and must not make room presence stale.
            'online' => $roomSeenAt !== false && $roomSeenAt >= time() - 35
                && (!$requiresGameHeartbeat
                    || ($gameSeenAt !== false && $gameSeenAt >= time() - 35)),
        ];
    }, $members->fetchAll());
    $viewer = current(array_filter($projectedMembers, static fn(array $member): bool => $member['userId'] === $viewerUserId));
    $settings = json_decode((string)$session['settings_json'], true) ?: [];
    $virtualMembers = multiplayer_game_project_virtual_members($pdo, $definition, $sessionState, [
        'mode' => (string)$session['mode'],
        'settings' => $settings,
        'members' => $projectedMembers,
        'status' => (string)$session['status'],
    ]);
    if ($virtualMembers !== []) {
        $projectedMembers = array_merge($projectedMembers, $virtualMembers);
        usort($projectedMembers, static fn(array $left, array $right): int => [(int)($left['seat'] ?? 999), (int)$left['userId']] <=> [(int)($right['seat'] ?? 999), (int)$right['userId']]);
    }
    $votes = $pdo->prepare(
        'SELECT vote_type,vote_value,COUNT(*) AS total FROM multiplayer_game_votes '
        . 'WHERE game_session_id=? GROUP BY vote_type,vote_value ORDER BY vote_type,vote_value'
    );
    $votes->execute([(int)$session['id']]);
    $voteSummary = [];
    foreach ($votes->fetchAll() as $vote) {
        $voteSummary[(string)$vote['vote_type']][(string)$vote['vote_value']] = (int)$vote['total'];
    }
    $save = $pdo->prepare(
        'SELECT saved_at,expires_at,state_version FROM multiplayer_game_saves '
        . 'WHERE game_key=? AND exact_player_set_sha256=? AND expires_at>CURRENT_TIMESTAMP LIMIT 1'
    );
    $save->execute([(string)$session['game_key'], $playerSet['sha256']]);
    $savedGame = $save->fetch();
    $result = $pdo->prepare(
        'SELECT public_id,recorded_at,result_sha256 FROM multiplayer_game_results '
        . 'WHERE game_session_public_id=? ORDER BY id DESC LIMIT 1'
    );
    $result->execute([$publicId]);
    $latestResult = $result->fetch();
    $activePlayerCount = count(array_filter(
        $projectedMembers,
        static fn(array $member): bool => in_array($member['role'], ['master','player'], true)
            && $member['membershipStatus'] === 'active'
    ));
    $onlinePlayerCount = count(array_filter(
        $projectedMembers,
        static fn(array $member): bool => in_array($member['role'], ['master','player'], true)
            && $member['membershipStatus'] === 'active'
            && $member['online'] === true
    ));
    $minimumKey = (string)$session['mode'] === 'practice' ? 'practiceMinPlayers' : 'recordedMinPlayers';
    $minimumPlayers = max(
        1,
        min(
            MULTIPLAYER_GAME_MAX_PLAYERS,
            multiplayer_game_minimum_players($definition, (string)$session['mode'], $settings)
        )
    );
    return [
        'publicId' => $publicId,
        'sourceRoomSessionId' => (int)$session['source_room_session_id'],
        'gameKey' => (string)$session['game_key'],
        'displayName' => multiplayer_game_effective_display_name($pdo, $definition),
        'mode' => (string)$session['mode'],
        'modeLabel' => (string)$session['mode'] === 'recorded' ? 'Ranked or Recorded Play' : 'Practice Mode',
        'profile' => (string)$session['profile'],
        'status' => (string)$session['status'],
        'actions' => [
            'canPause' => multiplayer_game_shared_can_pause($sessionState, $viewerUserId),
        ],
        'settingsSha256' => (string)$session['settings_sha256'],
        'settings' => $settings,
        'settingsControls' => multiplayer_game_settings_control_projection($pdo, $definition, $settings, (string)$session['mode']),
        'rules' => multiplayer_game_rules_projection($pdo, $definition, $settings, (string)$session['mode']),
        'seating' => multiplayer_game_seating_projection($definition, $session, $projectedMembers, $sessionState, $viewerUserId),
        'botSeats' => multiplayer_game_bot_lobby_projection($definition, $session, $projectedMembers, $viewerUserId, $playerSet['sha256']),
        'playerSetSha256' => $playerSet['sha256'],
        'stateVersion' => (int)$session['state_version'],
        'masterUserId' => (int)$session['master_user_id'],
        'viewerRole' => is_array($viewer) ? (string)$viewer['role'] : null,
        'viewerMembershipStatus' => is_array($viewer) ? (string)$viewer['membershipStatus'] : null,
        'members' => $projectedMembers,
        'spectatorCount' => count(array_filter($projectedMembers, static fn(array $member): bool => $member['role'] === 'spectator' && $member['membershipStatus'] === 'active')),
        'activePlayerCount' => $activePlayerCount,
        'onlinePlayerCount' => $onlinePlayerCount,
        'minimumPlayers' => $minimumPlayers,
        'maximumPlayers' => min(MULTIPLAYER_GAME_MAX_PLAYERS, (int)$definition['maxPlayers']),
        'savedGame' => is_array($savedGame) ? [
            'available' => true,
            'savedAt' => (string)$savedGame['saved_at'],
            'expiresAt' => (string)$savedGame['expires_at'],
            'stateVersion' => (int)$savedGame['state_version'],
        ] : ['available' => false],
        'votes' => $voteSummary,
        'voteRequired' => intdiv(max(1, $activePlayerCount), 2) + 1,
        'result' => is_array($latestResult) ? [
            'publicId' => (string)$latestResult['public_id'],
            'recordedAt' => (string)$latestResult['recorded_at'],
            'sha256' => (string)$latestResult['result_sha256'],
        ] : null,
    ];
}

function multiplayer_game_list_room_sessions(PDO $pdo, int $roomSessionId, int $viewerUserId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.public_id
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
      LEFT JOIN game_sessions gs ON gs.lobby_code=s.public_id
               AND gs.room_session_id=s.source_room_session_id
      LEFT JOIN game_lobbies gl ON gl.lobby_code=s.public_id
          WHERE s.source_room_session_id=? AND s.status IN ('lobby','active','paused')
            AND s.expires_at>CURRENT_TIMESTAMP AND m.user_id=? AND m.membership_status='active'
            AND (gs.lobby_code IS NULL OR gs.ended_at IS NULL)
            AND (gl.lobby_code IS NULL OR gl.status<>'ended')
          ORDER BY s.updated_at DESC"
    );
    $stmt->execute([$roomSessionId, $viewerUserId]);
    return array_map(fn(array $row): array => multiplayer_game_project_session($pdo, (string)$row['public_id'], $viewerUserId), $stmt->fetchAll());
}

/**
 * Return each user's newest still-active player game in this room.
 *
 * This is only a room-list projection. Older sessions, saves, records, and
 * memberships remain recoverable and authoritative.
 */
function multiplayer_game_room_list_current_player_sessions(PDO $pdo, int $roomSessionId): array
{
    $stmt = $pdo->prepare(
        "SELECT m.user_id,s.public_id,s.created_at,s.id
           FROM multiplayer_game_sessions s
           JOIN game_sessions gs ON gs.lobby_code=s.public_id
                AND gs.room_session_id=s.source_room_session_id
                AND gs.ended_at IS NULL
           JOIN game_lobbies gl ON gl.lobby_code=s.public_id AND gl.status<>'ended'
           JOIN multiplayer_game_members m ON m.game_session_id=s.id
          WHERE s.source_room_session_id=?
            AND s.status IN ('lobby','active','paused')
            AND s.expires_at>CURRENT_TIMESTAMP
            AND m.role IN ('master','player')
            AND m.membership_status='active'
          ORDER BY m.user_id ASC,s.created_at DESC,s.id DESC"
    );
    $stmt->execute([$roomSessionId]);
    $current = [];
    foreach ($stmt->fetchAll() as $row) {
        $userId = (int)$row['user_id'];
        if ($userId > 0 && !isset($current[$userId])) $current[$userId] = (string)$row['public_id'];
    }
    return $current;
}

function multiplayer_game_cleanup(PDO $pdo): array
{
    $now = gmdate('Y-m-d H:i:s');
    $grace = $pdo->prepare("UPDATE multiplayer_game_members SET membership_status='departed',departed_at=CURRENT_TIMESTAMP WHERE membership_status='active' AND reconnect_deadline_at IS NOT NULL AND reconnect_deadline_at<?");
    $grace->execute([$now]);
    $masterSessions = $pdo->query(
        "SELECT s.id FROM multiplayer_game_sessions s
          JOIN multiplayer_game_members m ON m.game_session_id=s.id AND m.user_id=s.master_user_id
         WHERE s.status IN ('lobby','active','paused') AND m.membership_status<>'active'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $masterReconciled = 0;
    foreach (array_map('intval', $masterSessions) as $sessionId) {
        if (!empty(multiplayer_game_reconcile_master($pdo, $sessionId)['changed'])) $masterReconciled++;
    }
    $expired = $pdo->prepare("UPDATE multiplayer_game_sessions SET status='ended',ended_at=COALESCE(ended_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE expires_at<? AND status IN ('lobby','active','paused')");
    $expired->execute([$now]);
    $results = $pdo->prepare('DELETE FROM multiplayer_game_results WHERE expires_at<?');
    $results->execute([$now]);
    $saves = $pdo->prepare('DELETE FROM multiplayer_game_saves WHERE expires_at<?');
    $saves->execute([$now]);
    $sessionCutoff = gmdate('Y-m-d H:i:s', time() - (MULTIPLAYER_GAME_SESSION_TTL_DAYS * 86400));
    game_recording_synchronize($pdo, true);
    $sessions = $pdo->prepare('DELETE FROM multiplayer_game_sessions WHERE ended_at IS NOT NULL AND ended_at<?');
    $sessions->execute([$sessionCutoff]);
    return [
        'expiredReconnectGrace' => $grace->rowCount(),
        'reconciledMasterDepartures' => $masterReconciled,
        'expiredSessions' => $expired->rowCount(),
        'deletedSessions' => $sessions->rowCount(),
        'expiredSaves' => $saves->rowCount(),
        'expiredResults' => $results->rowCount(),
    ];
}

function multiplayer_game_terminate_user(PDO $pdo, int $userId): int
{
    if ($userId < 1 || !database_migration_table_exists($pdo, 'multiplayer_game_members')) return 0;
    $stmt = $pdo->prepare("SELECT DISTINCT s.id,s.master_user_id FROM multiplayer_game_sessions s JOIN multiplayer_game_members m ON m.game_session_id=s.id WHERE m.user_id=? AND s.status IN ('lobby','active','paused')");
    $stmt->execute([$userId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $pdo->prepare("UPDATE multiplayer_game_members SET membership_status='removed',departed_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id=?")
            ->execute([(int)$row['id'], $userId]);
        $sessionPublicId = $pdo->prepare('SELECT public_id FROM multiplayer_game_sessions WHERE id=?');
        $sessionPublicId->execute([(int)$row['id']]);
        $publicId = (string)($sessionPublicId->fetchColumn() ?: '');
        if ($publicId !== '') multiplayer_game_rotate_message_key_epoch($pdo, $publicId);
        if ((int)$row['master_user_id'] === $userId) multiplayer_game_reconcile_master($pdo, (int)$row['id']);
        $count++;
    }
    return $count;
}

function multiplayer_game_terminate_pair(PDO $pdo, int $firstUserId, int $secondUserId): int
{
    if ($firstUserId < 1 || $secondUserId < 1 || !database_migration_table_exists($pdo, 'multiplayer_game_members')) return 0;
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.id,s.public_id
           FROM multiplayer_game_sessions s
           JOIN multiplayer_game_members a ON a.game_session_id=s.id AND a.user_id=? AND a.membership_status='active'
           JOIN multiplayer_game_members b ON b.game_session_id=s.id AND b.user_id=? AND b.membership_status='active'
          WHERE s.status IN ('lobby','active','paused')"
    );
    $stmt->execute([$firstUserId, $secondUserId]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        $pdo->prepare("UPDATE multiplayer_game_members SET membership_status='removed',departed_at=CURRENT_TIMESTAMP WHERE game_session_id=? AND user_id IN (?,?)")
            ->execute([(int)$row['id'], $firstUserId, $secondUserId]);
        $pdo->prepare("UPDATE multiplayer_game_sessions SET status='abandoned',ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(int)$row['id']]);
        multiplayer_game_rotate_message_key_epoch($pdo, (string)$row['public_id']);
        $count++;
    }
    return $count;
}

function database_migration_apply_build_000056_multiplayer_game_framework(PDO $pdo, array $context = []): array
{
    multiplayer_game_install_schema($pdo);
    return ['schemaValid' => multiplayer_game_schema_valid($pdo), 'registryRevision' => MULTIPLAYER_GAME_REGISTRY_REVISION];
}

function database_migration_validate_build_000056_multiplayer_game_framework(PDO $pdo, array $context = []): bool
{
    return multiplayer_game_build_000056_schema_valid($pdo);
}

function database_migration_apply_build_000063_multiplayer_game_schema_completion(PDO $pdo, array $context = []): array
{
    multiplayer_game_install_schema($pdo);
    return ['schemaValid' => multiplayer_game_schema_valid($pdo)];
}

function database_migration_validate_build_000063_multiplayer_game_schema_completion(PDO $pdo, array $context = []): bool
{
    return multiplayer_game_schema_valid($pdo);
}
