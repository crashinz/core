<?php
declare(strict_types=1);

/**
 * Stable identity boundary for the Five Dice first-party extension.
 *
 * The opaque game key is persistence identity only and must not be used as an
 * ordinary user-facing label. Presentation resolves through the configured
 * display-name owner.
 */

const FIVE_DICE_EXTENSION_ID = 'five-dice';
const FIVE_DICE_GAME_KEY = 'g_4f8c2d71';
const FIVE_DICE_DISPLAY_NAME_SETTING = 'multiplayer_game_display_name_g_4f8c2d71';
const FIVE_DICE_APPEARANCE_SETTING = 'multiplayer_game_appearance_g_4f8c2d71';
const FIVE_DICE_DEFAULT_DISPLAY_NAME = 'Five Dice';
const FIVE_DICE_DEFAULT_APPEARANCE = 'classic';

function five_dice_setting_defaults(): array
{
    return [
        'first_party_extension.five-dice.enabled' => '1',
        'first_party_extension.five-dice.lifecycle_revision' => '1',
        'first_party_extension.five-dice.storage_schema' => '1',
        'first_party_extension.five-dice.last_failure' => '',
        FIVE_DICE_DISPLAY_NAME_SETTING => FIVE_DICE_DEFAULT_DISPLAY_NAME,
        FIVE_DICE_APPEARANCE_SETTING => FIVE_DICE_DEFAULT_APPEARANCE,
    ];
}

function five_dice_install_settings(PDO $pdo): int
{
    $inserted = 0;
    $lookup = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
    $insert = $pdo->prepare('INSERT INTO app_settings (setting_key,value) VALUES (?,?)');
    foreach (five_dice_setting_defaults() as $key => $value) {
        $lookup->execute([$key]);
        if ($lookup->fetchColumn() !== false) continue;
        $insert->execute([$key, $value]);
        $inserted++;
    }
    return $inserted;
}

function five_dice_settings_valid(PDO $pdo): bool
{
    $values = [];
    $lookup = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key=?');
    foreach (array_keys(five_dice_setting_defaults()) as $key) {
        $lookup->execute([$key]);
        $value = $lookup->fetchColumn();
        if ($value === false) return false;
        $values[$key] = (string)$value;
    }
    $displayName = trim($values[FIVE_DICE_DISPLAY_NAME_SETTING]);
    return in_array($values['first_party_extension.five-dice.enabled'], ['0', '1'], true)
        && ctype_digit($values['first_party_extension.five-dice.lifecycle_revision'])
        && (int)$values['first_party_extension.five-dice.lifecycle_revision'] >= 1
        && $values['first_party_extension.five-dice.storage_schema'] === '1'
        && $displayName !== ''
        && strlen($displayName) <= 64
        && !preg_match('/[\x00-\x1F\x7F]/', $displayName)
        && in_array($values[FIVE_DICE_APPEARANCE_SETTING], ['classic', 'built-in'], true);
}

function database_migration_apply_build_000059_five_dice_extension(PDO $pdo, array $context = []): array
{
    return [
        'inserted_settings' => five_dice_install_settings($pdo),
        'preserved_existing_values' => true,
        'opaque_identity' => FIVE_DICE_GAME_KEY,
    ];
}

function database_migration_validate_build_000059_five_dice_extension(PDO $pdo, array $context = []): bool
{
    return five_dice_settings_valid($pdo);
}
