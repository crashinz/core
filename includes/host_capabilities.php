<?php
declare(strict_types=1);

/**
 * Bounded, non-destructive and sanitized host/runtime capability projection.
 * Unknown facts remain unknown; function availability alone never proves that
 * a deployable realtime adapter is supported.
 */

function host_capability_bool_label(?bool $value): string
{
    return $value === null ? 'Unknown' : ($value ? 'Supported' : 'Unavailable');
}

function host_capability_database(PDO $pdo): array
{
    $driver = db_driver($pdo);
    $version = null;
    try {
        $raw = $driver === 'sqlite'
            ? (string)$pdo->query('SELECT sqlite_version()')->fetchColumn()
            : (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/\d+(?:\.\d+){0,2}/', $raw, $match)) $version = $match[0];
    } catch (Throwable) {
        $version = null;
    }
    return [
        'engine' => $driver === 'sqlite' ? 'SQLite' : 'MariaDB/MySQL-compatible',
        'version' => $version,
        'transactionalMigrations' => true,
        'privateConnectionDetailsIncluded' => false,
    ];
}

function host_capability_storage_bucket(int|float|false $bytes): array
{
    if ($bytes === false || $bytes < 0) return ['status' => 'unknown', 'availableMiB' => null, 'bucket' => 'Unknown'];
    $mib = (int)floor($bytes / 1048576);
    $bucket = match (true) {
        $mib < 128 => 'Critical',
        $mib < 512 => 'Low',
        $mib < 2048 => 'Moderate',
        default => 'Healthy',
    };
    return ['status' => strtolower($bucket), 'availableMiB' => min($mib, 1048576), 'bucket' => $bucket];
}

function host_capabilities(PDO $pdo, ?array $server = null): array
{
    $server ??= $_SERVER;
    $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off';
    $policy = network_privacy_policy();
    $trustedProxyCount = count((array)($policy['trustedProxies'] ?? []));
    $forwardedPresented = trim((string)($server['HTTP_FORWARDED'] ?? $server['HTTP_X_FORWARDED_FOR'] ?? '')) !== '';
    $remote = network_privacy_normalize_ip((string)($server['REMOTE_ADDR'] ?? ''));
    $trustedRemote = $remote !== null && network_privacy_remote_is_trusted($remote, (array)($policy['trustedProxies'] ?? []));
    $forwardedSafe = !$forwardedPresented || $trustedRemote;
    $root = dirname(__DIR__);
    $free = @disk_free_space($root);
    $writableDeployable = is_writable($root);
    $privateRootAvailable = true;
    try {
        $privateRoot = runtime_issue_private_root();
        $privateParent = dirname($privateRoot);
        $privateRootAvailable = is_dir($privateParent) ? is_writable($privateParent) : is_writable(dirname($privateParent));
    } catch (Throwable) {
        $privateRootAvailable = false;
    }
    $outputBuffering = ini_get('output_buffering');
    $compression = strtolower((string)ini_get('zlib.output_compression'));
    $bufferingKnownDisabled = ($outputBuffering === '' || $outputBuffering === '0')
        && in_array($compression, ['', '0', 'off'], true);
    $proxySseSupport = !$forwardedPresented
        ? 'direct'
        : ($trustedRemote ? 'unknown' : 'unsafe');
    $sseEligible = $https
        && $bufferingKnownDisabled
        && $forwardedSafe
        && $proxySseSupport === 'direct';

    return [
        'schemaId' => 'chatspace.host-capabilities',
        'schemaVersion' => 1,
        'runtime' => [
            'phpVersion' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            'sapiClass' => PHP_SAPI === 'cli' ? 'command-line' : 'web',
            'database' => host_capability_database($pdo),
        ],
        'transportSecurity' => [
            'httpsActive' => $https,
            'hstsPolicyEnabled' => network_privacy_should_send_hsts(),
            'forwardedHeadersPresented' => $forwardedPresented,
            'forwardedHeadersSafelyOwned' => $forwardedSafe,
            'trustedProxyCount' => $trustedProxyCount,
            'addressesIncluded' => false,
        ],
        'streaming' => [
            'responseBufferingKnownDisabled' => $bufferingKnownDisabled,
            'sseEligible' => $sseEligible,
            'sseEligibilityLabel' => $sseEligible
                ? 'Eligible: HTTPS, direct request ownership, and buffering policy are proven'
                : 'Unsupported until HTTPS, buffering, and direct or proven proxy ownership are established',
            'proxySseSupport' => $proxySseSupport,
        ],
        'persistentProcess' => [
            'repositoryWebSocketProcessPresent' => false,
            'lifecycleOwnerProven' => false,
            'socketFunctionAvailable' => function_exists('stream_socket_server'),
            'wssEligible' => false,
            'proxyWssSupport' => 'unknown',
            'reason' => 'No persistent WebSocket service has completed the required deployment and lifecycle checks.',
        ],
        'storage' => [
            'deployableWritable' => $writableDeployable,
            'privateStorageWritable' => $privateRootAvailable,
            'disk' => host_capability_storage_bucket($free),
            'pathsIncluded' => false,
        ],
        'limits' => [
            'memoryLimit' => runtime_issue_clean_string((string)ini_get('memory_limit'), 32),
            'maxExecutionSeconds' => max(0, (int)ini_get('max_execution_time')),
            'uploadMaxFilesize' => runtime_issue_clean_string((string)ini_get('upload_max_filesize'), 32),
            'postMaxSize' => runtime_issue_clean_string((string)ini_get('post_max_size'), 32),
        ],
        'transport' => [
            'configuredMode' => 'polling-only',
            'activeAdapter' => 'polling',
            'fallbackAdapter' => 'polling',
            'fallbackReason' => 'Polling is the mandatory default and permanent fallback. Optional realtime adapters require separate exact capability proof.',
        ],
        'probePolicy' => [
            'externalHostsContacted' => false,
            'listenerOpened' => false,
            'destructiveProbeUsed' => false,
            'unknownRemainsUnsupported' => true,
        ],
    ];
}
