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
    $configuredEnabled = $environment['enabled'] ?? null;
    $enabled = $configuredEnabled === true;
    $runtimeHost = inner_tranquillity_runtime_host((string)($environment['runtime_host'] ?? ''));
    $assetBase = inner_tranquillity_player_asset_base((string)($environment['asset_base'] ?? '/player'));
    $documentRoot = rtrim((string)($environment['document_root'] ?? dirname(__DIR__)), '/\\');
    $runtimeHosts = inner_tranquillity_player_runtime_hosts($environment['runtime_hosts'] ?? []);
    $loopback = inner_tranquillity_loopback_host($runtimeHost);

    // Older owner installations already serve this private package at /player
    // but predate the optional configuration constants. Missing is not an
    // explicit opt-out. Never discover or load it for another hosting domain.
    $legacyOwnerPackage = $relevant
        && $configuredEnabled === null
        && in_array($runtimeHost, ['inner-tranquillity.net', 'www.inner-tranquillity.net'], true)
        && $assetBase === '/player'
        && inner_tranquillity_website_player_complete(
            (string)($environment['website_document_root'] ?? '')
        );
    if ($legacyOwnerPackage) {
        $enabled = true;
        $runtimeHosts[] = $runtimeHost;
    }

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
        $reason = $legacyOwnerPackage
            ? 'available-legacy-owner-package'
            : ($loopback ? 'available-local-package' : 'available-authorized-runtime');
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
            ? CHATSPACE_INNER_TRANQUILLITY_PLAYER_ENABLED === true
            : null,
        'asset_base' => defined('CHATSPACE_INNER_TRANQUILLITY_PLAYER_ASSET_BASE')
            ? CHATSPACE_INNER_TRANQUILLITY_PLAYER_ASSET_BASE
            : '/player',
        'runtime_hosts' => defined('CHATSPACE_INNER_TRANQUILLITY_PLAYER_RUNTIME_HOSTS')
            ? CHATSPACE_INNER_TRANQUILLITY_PLAYER_RUNTIME_HOSTS
            : [],
        'runtime_host' => (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
        'document_root' => dirname(__DIR__),
        'website_document_root' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
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

function inner_tranquillity_website_player_complete(string $documentRoot): bool
{
    // A verified filesystem root avoids depending on the CoreChat directory
    // name or making a network request during every room load.
    if (trim($documentRoot) === '') return false;
    $root = realpath($documentRoot);
    if ($root === false || !is_dir($root)) return false;
    foreach (INNER_TRANQUILLITY_PLAYER_FILES as $file) {
        $path = $root . DIRECTORY_SEPARATOR . 'player' . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) return false;
    }
    return true;
}
