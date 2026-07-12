<?php
declare(strict_types=1);

/**
 * Chat Runtime Framework
 * Build: 000043 Part 1
 * Owner: Imported room private-player server capability
 */

const INNER_TRANQUILLITY_PLAYER_FILES = [
    'css' => 'Generic.css',
    'jquery' => 'jquery-3.2.1.min.js',
    'player' => 'player.js',
];

function inner_tranquillity_player_capability(array $room, ?array $environment = null): array
{
    $importUrl = trim((string)($room['import_url'] ?? ''));
    $sourceHost = strtolower((string)(parse_url($importUrl, PHP_URL_HOST) ?: ''));
    $relevant = $importUrl !== '' && $sourceHost !== ''
        && host_matches_domain($sourceHost, 'inner-tranquillity.net');

    $environment ??= inner_tranquillity_player_environment();
    $enabled = (bool)($environment['enabled'] ?? false);
    $runtimeHost = inner_tranquillity_runtime_host((string)($environment['runtime_host'] ?? ''));
    $assetBase = inner_tranquillity_player_asset_base((string)($environment['asset_base'] ?? '/player'));
    $documentRoot = rtrim((string)($environment['document_root'] ?? dirname(__DIR__)), '/\\');
    $runtimeHosts = inner_tranquillity_player_runtime_hosts($environment['runtime_hosts'] ?? []);
    $loopback = inner_tranquillity_loopback_host($runtimeHost);

    $reason = 'unrelated-import-source';
    $available = false;

    if ($relevant && !$enabled) {
        $reason = 'capability-disabled';
    } elseif ($relevant && !$loopback && !in_array($runtimeHost, $runtimeHosts, true)) {
        $reason = 'runtime-host-not-authorized';
    } elseif ($relevant && $loopback && !inner_tranquillity_local_player_complete($documentRoot)) {
        $reason = 'local-package-incomplete';
    } elseif ($relevant) {
        $available = true;
        $reason = $loopback ? 'available-local-package' : 'available-authorized-runtime';
    }

    $assets = [];
    if ($available) {
        foreach (INNER_TRANQUILLITY_PLAYER_FILES as $key => $file) {
            $assets[$key] = $assetBase . '/' . $file;
        }
    }

    return [
        'relevant' => $relevant,
        'available' => $available,
        'reason' => $reason,
        'assets' => $assets,
    ];
}

function inner_tranquillity_player_environment(): array
{
    return [
        'enabled' => defined('CHATSPACE_INNER_TRANQUILLITY_PLAYER_ENABLED')
            && CHATSPACE_INNER_TRANQUILLITY_PLAYER_ENABLED === true,
        'asset_base' => defined('CHATSPACE_INNER_TRANQUILLITY_PLAYER_ASSET_BASE')
            ? CHATSPACE_INNER_TRANQUILLITY_PLAYER_ASSET_BASE
            : '/player',
        'runtime_hosts' => defined('CHATSPACE_INNER_TRANQUILLITY_PLAYER_RUNTIME_HOSTS')
            ? CHATSPACE_INNER_TRANQUILLITY_PLAYER_RUNTIME_HOSTS
            : [],
        'runtime_host' => (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
        'document_root' => dirname(__DIR__),
    ];
}

function inner_tranquillity_runtime_host(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    return strtolower((string)(parse_url('http://' . $value, PHP_URL_HOST) ?: ''));
}

function inner_tranquillity_player_runtime_hosts(mixed $value): array
{
    $values = is_array($value) ? $value : explode(',', (string)$value);
    $hosts = [];
    foreach ($values as $host) {
        $normalized = inner_tranquillity_runtime_host((string)$host);
        if ($normalized !== '') $hosts[] = $normalized;
    }
    return array_values(array_unique($hosts));
}

function inner_tranquillity_player_asset_base(string $value): string
{
    $value = rtrim(trim($value), '/');
    if ($value === '') return '/player';
    if (str_starts_with($value, '/') || preg_match('#^https://#i', $value)) return $value;
    return '/player';
}

function inner_tranquillity_loopback_host(string $host): bool
{
    return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
}

function inner_tranquillity_local_player_complete(string $documentRoot): bool
{
    foreach (INNER_TRANQUILLITY_PLAYER_FILES as $file) {
        if (!is_file($documentRoot . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . $file)) {
            return false;
        }
    }
    return true;
}
