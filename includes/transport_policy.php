<?php
declare(strict_types=1);

/**
 * Build 000052 transport selection and capability owner.
 *
 * Polling is mandatory, the stored default, and the permanent fallback.
 * Optional adapters are eligible only from source-backed host capability
 * proof. This owner never opens a listener or contacts an external service.
 */

const REALTIME_TRANSPORT_MODE_SETTING = 'realtime_transport_mode';
const REALTIME_TRANSPORT_MODES = ['polling-only', 'automatic-best'];

function transport_policy_setting_defaults(): array
{
    return [REALTIME_TRANSPORT_MODE_SETTING => 'polling-only'];
}

function transport_policy_normalize_mode(string $mode): string
{
    return in_array($mode, REALTIME_TRANSPORT_MODES, true)
        ? $mode
        : 'polling-only';
}

function transport_policy_projection(PDO $pdo, ?array $server = null): array
{
    $capabilities = host_capabilities($pdo, $server);
    $configuredMode = transport_policy_normalize_mode(
        app_setting($pdo, REALTIME_TRANSPORT_MODE_SETTING, 'polling-only')
    );
    $wssEligible = !empty($capabilities['persistentProcess']['wssEligible'])
        && !empty($capabilities['persistentProcess']['repositoryWebSocketProcessPresent'])
        && !empty($capabilities['persistentProcess']['lifecycleOwnerProven'])
        && ($capabilities['persistentProcess']['proxyWssSupport'] ?? 'unknown') !== 'unknown';
    $sseEligible = !empty($capabilities['streaming']['sseEligible'])
        && in_array(
            (string)($capabilities['streaming']['proxySseSupport'] ?? 'unknown'),
            ['direct', 'proven'],
            true
        );

    $selected = 'polling';
    $reason = 'Polling only is configured. Polling is the mandatory default and permanent fallback.';
    if ($configuredMode === 'automatic-best') {
        if ($wssEligible) {
            $selected = 'websocket';
            $reason = 'Automatic best available selected the verified secure WebSocket transport.';
        } elseif ($sseEligible) {
            $selected = 'sse';
            $reason = 'Automatic best available selected proven HTTPS Server-Sent Events; Polling remains the fallback.';
        } else {
            $reason = 'Automatic best available found no proven optional adapter and safely selected Polling.';
        }
    }

    return [
        'schemaId' => 'chatspace.transport-policy',
        'schemaVersion' => 1,
        'configuredMode' => $configuredMode,
        'configuredModeLabel' => $configuredMode === 'automatic-best'
            ? 'Automatic best available'
            : 'Polling only — Default',
        'selectionOrder' => ['websocket', 'sse', 'polling'],
        'selectedAdapter' => $selected,
        'activeAdapter' => $selected,
        'fallbackAdapter' => 'polling',
        'fallbackReason' => $reason,
        'adapters' => [
            'polling' => [
                'eligible' => true,
                'mandatory' => true,
                'permanentFallback' => true,
                'endpoint' => '/api/poll.php',
                'security' => 'HTTPS required in public production',
            ],
            'sse' => [
                'eligible' => $sseEligible,
                'mandatory' => false,
                'permanentFallback' => false,
                'endpoint' => $sseEligible ? '/api/events_sse.php' : null,
                'security' => 'HTTPS required',
                'reason' => (string)$capabilities['streaming']['sseEligibilityLabel'],
            ],
            'websocket' => [
                'eligible' => $wssEligible,
                'mandatory' => false,
                'permanentFallback' => false,
                'endpoint' => null,
                'security' => 'WSS required',
                'reason' => (string)$capabilities['persistentProcess']['reason'],
            ],
        ],
        'transportEncryptionIsEndToEndEncryption' => false,
        'hostCapabilities' => $capabilities,
    ];
}
