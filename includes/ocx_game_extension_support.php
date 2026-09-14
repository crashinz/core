<?php
declare(strict_types=1);

/**
 * Shared Build 000060 support for independently authored OCX-derived games.
 *
 * This owner contains presentation/settings helpers only. Individual extension
 * adapters retain their own rules and never load or execute an OCX binary.
 */

const OCX_GAME_EXTENSION_IDENTITIES = [
    'checkers' => ['key' => 'g_b60c0a01', 'name' => 'Checkers'],
    'chess' => ['key' => 'g_b60c0a02', 'name' => 'Chess'],
    'acey-deucy' => ['key' => 'g_b60c0a03', 'name' => 'Acey Deucy'],
    'battleship' => ['key' => 'g_b60c0a04', 'name' => 'Battleship'],
    'spades' => ['key' => 'g_b60c0a05', 'name' => 'Spades'],
    'backgammon-first-party' => ['key' => 'g_b61c0a01', 'name' => 'Backgammon'],
    'blackjack' => ['key' => 'g_b63c0a01', 'name' => 'Blackjack'],
    'hearts' => ['key' => 'g_b63c0a02', 'name' => 'Hearts'],
    'uno' => ['key' => 'g_b63c0a03', 'name' => 'UNO'],
];

function ocx_game_extension_identity(string $extensionId): array
{
    $identity = OCX_GAME_EXTENSION_IDENTITIES[$extensionId] ?? null;
    if (!is_array($identity)) {
        throw new MultiplayerGameException('The installed game identity is unavailable.', 'MULTIPLAYER_GAME_NOT_INSTALLED', 404);
    }
    return $identity + ['extensionId' => $extensionId];
}

function ocx_game_extension_setting_defaults(): array
{
    $defaults = [];
    foreach (['checkers', 'chess', 'acey-deucy', 'battleship', 'spades'] as $extensionId) {
        $identity = OCX_GAME_EXTENSION_IDENTITIES[$extensionId];
        $key = (string)$identity['key'];
        $defaults['multiplayer_game_display_name_' . $key] = (string)$identity['name'];
        $defaults['multiplayer_game_appearance_' . $key] = 'built-in';
        $defaults['ocx_game_media_pack_active_generation_' . $extensionId] = '';
    }
    return $defaults;
}

function backgammon_extension_setting_defaults(): array
{
    $identity = OCX_GAME_EXTENSION_IDENTITIES['backgammon-first-party'];
    $key = (string)$identity['key'];
    return [
        'multiplayer_game_display_name_' . $key => (string)$identity['name'],
        'multiplayer_game_appearance_' . $key => 'built-in',
        'ocx_game_media_pack_active_generation_backgammon-first-party' => '',
    ];
}

function backgammon_extension_install_settings(PDO $pdo): int
{
    $inserted = 0;
    foreach (backgammon_extension_setting_defaults() as $key => $value) {
        $statement = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
        $statement->execute([$key]);
        if ($statement->fetchColumn() !== false) continue;
        set_app_setting($pdo, $key, $value);
        $inserted++;
    }
    return $inserted;
}

function backgammon_extension_settings_valid(PDO $pdo): bool
{
    $lookup = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
    foreach (backgammon_extension_setting_defaults() as $key => $_fallback) {
        $lookup->execute([$key]);
        $stored = $lookup->fetchColumn();
        if ($stored === false) return false;
        $value = (string)$stored;
        if (str_contains($key, '_appearance_') && !in_array($value, ['built-in', 'classic'], true)) return false;
        if (str_contains($key, '_display_name_')
            && (trim($value) === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value))) return false;
        if (str_contains($key, '_active_generation_') && $value !== ''
            && !preg_match('/^generation-[a-f0-9-]{36}$/', $value)) return false;
    }
    return true;
}

function ocx_game_extension_install_settings(PDO $pdo): int
{
    $inserted = 0;
    foreach (ocx_game_extension_setting_defaults() as $key => $value) {
        $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() !== false) continue;
        set_app_setting($pdo, $key, $value);
        $inserted++;
    }
    return $inserted;
}

function ocx_game_extension_settings_valid(PDO $pdo): bool
{
    $lookup = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
    foreach (ocx_game_extension_setting_defaults() as $key => $_fallback) {
        $lookup->execute([$key]);
        $stored = $lookup->fetchColumn();
        if ($stored === false) return false;
        $value = (string)$stored;
        if (str_contains($key, '_appearance_') && !in_array($value, ['built-in', 'classic', 'corechat'], true)) return false;
        if (str_contains($key, '_display_name_')
            && (trim($value) === '' || strlen($value) > 64 || preg_match('/[\x00-\x1F\x7F]/', $value))) return false;
        if (str_contains($key, '_active_generation_') && $value !== ''
            && !preg_match('/^generation-[a-f0-9-]{36}$/', $value)) return false;
    }
    return true;
}

function database_migration_apply_build_000060_ocx_game_extensions(PDO $pdo, array $context = []): array
{
    $inserted = ocx_game_extension_install_settings($pdo);
    $aliases = ['checkers' => 'g_b60c0a01', 'chess' => 'g_b60c0a02'];
    $updated = 0;
    foreach ($aliases as $legacy => $replacement) {
        foreach ([
            'multiplayer_game_sessions', 'multiplayer_game_saves', 'multiplayer_game_results',
            'multiplayer_game_result_aggregates', 'multiplayer_game_presentation_preferences',
            'multiplayer_game_options',
        ] as $table) {
            if (!database_migration_table_exists($pdo, $table)
                || !in_array('game_key', database_migration_columns($pdo, $table), true)) continue;
            $stmt = $pdo->prepare("UPDATE {$table} SET game_key=? WHERE game_key=?");
            $stmt->execute([$replacement, $legacy]);
            $updated += $stmt->rowCount();
        }
    }
    return ['inserted_settings' => $inserted, 'migrated_legacy_game_keys' => $updated];
}

function database_migration_validate_build_000060_ocx_game_extensions(PDO $pdo, array $context = []): bool
{
    if (!ocx_game_extension_settings_valid($pdo)) return false;
    foreach (['chess', 'checkers'] as $legacy) {
        foreach (['multiplayer_game_sessions', 'multiplayer_game_saves', 'multiplayer_game_results'] as $table) {
            if (!database_migration_table_exists($pdo, $table)) continue;
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE game_key=?");
            $stmt->execute([$legacy]);
            if ((int)$stmt->fetchColumn() !== 0) return false;
        }
    }
    return true;
}

function database_migration_apply_build_000061_backgammon(PDO $pdo, array $context = []): array
{
    $inserted = backgammon_extension_install_settings($pdo);
    $updated = 0;
    foreach ([
        'multiplayer_game_sessions', 'multiplayer_game_saves', 'multiplayer_game_results',
        'multiplayer_game_result_aggregates', 'multiplayer_game_presentation_preferences',
        'multiplayer_game_options',
    ] as $table) {
        if (!database_migration_table_exists($pdo, $table)
            || !in_array('game_key', database_migration_columns($pdo, $table), true)) continue;
        $statement = $pdo->prepare("UPDATE {$table} SET game_key=? WHERE game_key=?");
        $statement->execute(['g_b61c0a01', 'backgammon']);
        $updated += $statement->rowCount();
    }
    return ['inserted_settings' => $inserted, 'migrated_legacy_game_keys' => $updated];
}

function database_migration_validate_build_000061_backgammon(PDO $pdo, array $context = []): bool
{
    if (!backgammon_extension_settings_valid($pdo)) return false;
    foreach (['multiplayer_game_sessions', 'multiplayer_game_saves', 'multiplayer_game_results'] as $table) {
        if (!database_migration_table_exists($pdo, $table)) continue;
        $statement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE game_key=?");
        $statement->execute(['backgammon']);
        if ((int)$statement->fetchColumn() !== 0) return false;
    }
    return true;
}

function ocx_game_presentation_status(PDO $pdo, string $extensionId, ?string $requestedPack = null): array
{
    $identity = ocx_game_extension_identity($extensionId);
    $status = function_exists('ocx_game_media_pack_status')
        ? ocx_game_media_pack_status($pdo, $extensionId)
        : ['classicComplete' => false, 'installedCount' => 0, 'requiredCount' => 0];
    $requested = $requestedPack ?? app_setting($pdo, 'multiplayer_game_appearance_' . $identity['key'], 'built-in');
    if ($extensionId === 'chess' && $requested === 'corechat') {
        return [
            'requestedPack' => 'corechat', 'effectivePack' => 'corechat',
            'classicAvailable' => !empty($status['classicComplete']), 'fallbackApplied' => false,
            'presentationOnly' => true, 'mediaPack' => $status,
        ];
    }
    $effective = $requested === 'classic' && !empty($status['classicComplete']) ? 'classic' : 'built-in';
    return [
        'requestedPack' => $requested,
        'effectivePack' => $effective,
        'classicAvailable' => !empty($status['classicComplete']),
        'fallbackApplied' => $requested === 'classic' && $effective !== 'classic',
        'presentationOnly' => true,
        'mediaPack' => $status,
    ];
}

function ocx_game_random_bytes(string $canonicalReveal, string $domain, int $count): array
{
    if ($count < 1 || $count > 4096) {
        throw new MultiplayerGameException('The randomness request is outside its safe boundary.', 'MULTIPLAYER_GAME_RANDOMNESS_DERIVATION_FAILED', 500);
    }
    $bytes = [];
    for ($counter = 0; count($bytes) < $count; $counter++) {
        foreach (unpack('C*', hash('sha256', $domain . ':' . $counter . ':' . $canonicalReveal, true)) as $byte) {
            $bytes[] = $byte;
            if (count($bytes) === $count) break;
        }
    }
    return $bytes;
}

function ocx_game_random_dice(string $canonicalReveal, int $count, string $domain): array
{
    $dice = [];
    $counter = 0;
    while (count($dice) < $count && $counter < 128) {
        foreach (ocx_game_random_bytes($canonicalReveal, $domain . ':' . $counter, 32) as $byte) {
            if ($byte >= 252) continue;
            $dice[] = ($byte % 6) + 1;
            if (count($dice) === $count) break;
        }
        $counter++;
    }
    if (count($dice) !== $count) {
        throw new MultiplayerGameException('Verified dice could not be derived.', 'MULTIPLAYER_GAME_RANDOMNESS_DERIVATION_FAILED', 500);
    }
    return $dice;
}

function ocx_game_random_permutation(string $canonicalReveal, array $values, string $domain): array
{
    $values = array_values($values);
    $bytes = ocx_game_random_bytes($canonicalReveal, $domain, max(1, count($values) * 4));
    for ($index = count($values) - 1; $index > 0; $index--) {
        $offset = (count($values) - 1 - $index) * 4;
        $number = ($bytes[$offset] << 24) | ($bytes[$offset + 1] << 16) | ($bytes[$offset + 2] << 8) | $bytes[$offset + 3];
        $swap = (int)(sprintf('%u', $number) % ($index + 1));
        [$values[$index], $values[$swap]] = [$values[$swap], $values[$index]];
    }
    return $values;
}

function ocx_game_result_from_scores(array $scores): array
{
    if ($scores === []) return ['members' => []];
    $maximum = max(array_map('floatval', $scores));
    $leaderCount = count(array_filter($scores, static fn(mixed $score): bool => (float)$score === $maximum));
    $members = [];
    foreach ($scores as $userId => $score) {
        $isLeader = (float)$score === $maximum;
        $members[(string)(int)$userId] = [
            'score' => (float)$score,
            'outcome' => $isLeader ? ($leaderCount > 1 ? 'draw' : 'win') : 'loss',
        ];
    }
    return ['members' => $members];
}

function ocx_game_assert_turn(array $state, int $actorUserId): void
{
    $order = array_values(array_map('intval', (array)($state['turnOrder'] ?? [])));
    $index = (int)($state['turnIndex'] ?? -1);
    if (!isset($order[$index]) || $order[$index] !== $actorUserId) {
        throw new MultiplayerGameException('Wait for your turn.', 'MULTIPLAYER_GAME_NOT_YOUR_TURN', 409);
    }
}

function ocx_game_advance_turn(array &$state): int
{
    $order = array_values(array_map('intval', (array)$state['turnOrder']));
    $state['turnIndex'] = ((int)$state['turnIndex'] + 1) % count($order);
    return $order[(int)$state['turnIndex']];
}
