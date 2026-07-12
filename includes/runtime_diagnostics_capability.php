<?php
declare(strict_types=1);

/**
 * Chat Runtime Framework
 * Build: 000043 Part 2
 * Owner: Runtime diagnostics server capability
 */

function runtime_diagnostics_capability(?array $environment = null): array
{
    $environment ??= runtime_diagnostics_environment();
    $enabled = (bool)($environment['enabled'] ?? false);
    $requestedMode = strtolower(trim((string)($environment['mode'] ?? 'standard')));
    $mode = in_array($requestedMode, ['standard', 'verification'], true)
        ? $requestedMode
        : 'standard';
    $runtimeHost = runtime_diagnostics_host(
        (string)($environment['runtime_host'] ?? '')
    );
    $loopback = in_array($runtimeHost, ['127.0.0.1', '::1', 'localhost'], true);
    $controlsRequested = (bool)($environment['verification_controls'] ?? false);

    return [
        'enabled' => $enabled,
        'mode' => $enabled ? $mode : 'disabled',
        'verification_controls' => $enabled
            && $mode === 'verification'
            && $controlsRequested
            && $loopback,
    ];
}

function runtime_diagnostics_environment(): array
{
    return [
        'enabled' => defined('CHATSPACE_RUNTIME_DIAGNOSTICS_ENABLED')
            && CHATSPACE_RUNTIME_DIAGNOSTICS_ENABLED === true,
        'mode' => defined('CHATSPACE_RUNTIME_DIAGNOSTICS_MODE')
            ? CHATSPACE_RUNTIME_DIAGNOSTICS_MODE
            : 'standard',
        'verification_controls' => defined('CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED')
            && CHATSPACE_RUNTIME_VERIFICATION_CONTROLS_ENABLED === true,
        'runtime_host' => (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
    ];
}

function runtime_diagnostics_host(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    return strtolower((string)(parse_url('http://' . $value, PHP_URL_HOST) ?: ''));
}
