<?php
declare(strict_types=1);

/**
 * Build 000045 role-color appearance policy. This policy never grants access.
 */

function role_color_default_palette(): array
{
    return [
        'admin' => ['background' => '#560819', 'text' => '#ffffff'],
        'developer' => ['background' => '#142d74', 'text' => '#ffffff'],
        'guide' => ['background' => '#095e3a', 'text' => '#ffffff'],
        'owner' => ['background' => '#9a6914', 'text' => '#ffffff'],
        'user' => ['background' => '#232630', 'text' => '#ffffff'],
    ];
}

function role_color_setting_defaults(): array
{
    $settings = ['role_colors_mode' => 'enabled'];
    foreach (role_color_default_palette() as $role => $colors) {
        $settings["role_color_{$role}_bg"] = $colors['background'];
        $settings["role_color_{$role}_text"] = $colors['text'];
    }
    return $settings;
}

function role_color_hex(mixed $value): ?string
{
    $value = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
}

function role_color_luminance(string $hex): float
{
    $channels = [];
    foreach ([1, 3, 5] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }
    return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
}

function role_color_contrast(string $background, string $text): float
{
    $a = role_color_luminance($background);
    $b = role_color_luminance($text);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

function role_color_validate_settings(array $input, bool $reset = false): array
{
    $defaults = role_color_default_palette();
    $mode = $reset ? 'enabled' : strtolower(trim((string)($input['role_colors_mode'] ?? 'enabled')));
    if (!in_array($mode, ['enabled', 'disabled', 'custom'], true)) {
        return ['ok' => false, 'error' => 'Invalid role-color mode.', 'http_status' => 400];
    }
    $palette = [];
    foreach ($defaults as $role => $default) {
        $background = $reset ? $default['background'] : role_color_hex($input["role_color_{$role}_bg"] ?? $default['background']);
        $text = $reset ? $default['text'] : role_color_hex($input["role_color_{$role}_text"] ?? $default['text']);
        if ($background === null || $text === null || role_color_contrast($background, $text) < 4.5) {
            return ['ok' => false, 'error' => "Choose readable six-digit colors for {$role} (minimum contrast 4.5:1).", 'http_status' => 400];
        }
        $palette[$role] = ['background' => $background, 'text' => $text];
    }
    return ['ok' => true, 'mode' => $mode, 'palette' => $palette];
}

function role_color_settings(PDO $pdo): array
{
    $defaults = role_color_setting_defaults();
    $input = [];
    foreach ($defaults as $key => $value) $input[$key] = app_setting($pdo, $key, $value);
    $result = role_color_validate_settings($input);
    return !empty($result['ok']) ? $result : role_color_validate_settings([], true);
}

function role_color_css_variables(PDO $pdo): string
{
    $settings = role_color_settings($pdo);
    $variables = ['--role-colors-enabled:' . ($settings['mode'] === 'disabled' ? '0' : '1')];
    foreach ($settings['palette'] as $role => $colors) {
        $variables[] = "--role-{$role}-bg:{$colors['background']}";
        $variables[] = "--role-{$role}-text:{$colors['text']}";
    }
    return implode(';', $variables) . ';';
}
