<?php
declare(strict_types=1);

/**
 * Shared server-authoritative P2P connection configuration.
 *
 * Build 000055 transfers STUN/TURN ownership out of the avatar-specific
 * policy while retaining every existing app-setting key and saved value.
 * Payload transport remains owned by each consuming capability.
 */

const P2P_TRANSPORT_CLOUDFLARE_STUN = 'stun:stun.cloudflare.com:3478';
const P2P_TRANSPORT_STUN_URLS_SETTING = 'p2p_avatar_stun_urls';
const P2P_TRANSPORT_TURN_ENABLED_SETTING = 'p2p_avatar_turn_enabled';
const P2P_TRANSPORT_TURN_ACKNOWLEDGED_SETTING = 'p2p_avatar_turn_warning_acknowledged';
const P2P_TRANSPORT_TURN_URLS_SETTING = 'p2p_avatar_turn_urls';
const P2P_TRANSPORT_TURN_USERNAME_SETTING = 'p2p_avatar_turn_username';
const P2P_TRANSPORT_TURN_CREDENTIAL_SETTING = 'p2p_avatar_turn_credential';
const P2P_TRANSPORT_MAX_SIGNAL_URLS = 8;

// Stable Build 000054 compatibility names. These are aliases, not duplicate
// persistence owners.
const P2P_AVATAR_STUN_URLS_SETTING = P2P_TRANSPORT_STUN_URLS_SETTING;
const P2P_AVATAR_TURN_ENABLED_SETTING = P2P_TRANSPORT_TURN_ENABLED_SETTING;
const P2P_AVATAR_TURN_ACKNOWLEDGED_SETTING = P2P_TRANSPORT_TURN_ACKNOWLEDGED_SETTING;
const P2P_AVATAR_TURN_URLS_SETTING = P2P_TRANSPORT_TURN_URLS_SETTING;
const P2P_AVATAR_TURN_USERNAME_SETTING = P2P_TRANSPORT_TURN_USERNAME_SETTING;
const P2P_AVATAR_TURN_CREDENTIAL_SETTING = P2P_TRANSPORT_TURN_CREDENTIAL_SETTING;

final class P2PTransportPolicyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'P2P_TRANSPORT_POLICY_FAILED',
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

function p2p_transport_setting_defaults(): array
{
    return [
        P2P_TRANSPORT_STUN_URLS_SETTING => P2P_TRANSPORT_CLOUDFLARE_STUN,
        P2P_TRANSPORT_TURN_ENABLED_SETTING => '0',
        P2P_TRANSPORT_TURN_ACKNOWLEDGED_SETTING => '0',
        P2P_TRANSPORT_TURN_URLS_SETTING => '',
        P2P_TRANSPORT_TURN_USERNAME_SETTING => '',
        P2P_TRANSPORT_TURN_CREDENTIAL_SETTING => '',
    ];
}

function p2p_transport_url_list(mixed $value, array $schemes): array
{
    $tokens = preg_split('/[\r\n,]+/', trim((string)$value)) ?: [];
    $urls = [];
    $seen = [];
    foreach ($tokens as $token) {
        $url = trim($token);
        if ($url === '') continue;
        if (strlen($url) > 500 || preg_match('/\s/', $url)) {
            throw new P2PTransportPolicyException(
                'A configured connection server URL is invalid.',
                'P2P_AVATAR_ICE_URL_INVALID',
                422
            );
        }
        $schemePattern = implode('|', array_map(
            static fn(string $scheme): string => preg_quote($scheme, '/'),
            $schemes
        ));
        if (!preg_match('/^(?:' . $schemePattern . '):(?:\/\/)?[A-Za-z0-9.\-\[\]:]+(?:\?[^\s]*)?$/i', $url)) {
            throw new P2PTransportPolicyException(
                'A configured connection server URL is invalid.',
                'P2P_AVATAR_ICE_URL_INVALID',
                422
            );
        }
        $key = strtolower($url);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $urls[] = $url;
    }
    if (count($urls) > P2P_TRANSPORT_MAX_SIGNAL_URLS) {
        throw new P2PTransportPolicyException(
            'Too many connection server URLs were configured.',
            'P2P_AVATAR_ICE_URL_LIMIT',
            422
        );
    }
    return $urls;
}

function p2p_transport_validate_settings(PDO $pdo, array $values): array
{
    try {
        p2p_transport_url_list(
            $values[P2P_TRANSPORT_STUN_URLS_SETTING] ?? '',
            ['stun', 'stuns']
        );
        $turnEnabled = !empty($values[P2P_TRANSPORT_TURN_ENABLED_SETTING]);
        $turnUrls = p2p_transport_url_list(
            $values[P2P_TRANSPORT_TURN_URLS_SETTING] ?? '',
            ['turn', 'turns']
        );
        if ($turnEnabled) {
            if (empty($values[P2P_TRANSPORT_TURN_ACKNOWLEDGED_SETTING])) {
                throw new P2PTransportPolicyException(
                    'Review and acknowledge that encrypted packets may pass through the configured relay.',
                    'P2P_AVATAR_TURN_ACKNOWLEDGEMENT_REQUIRED',
                    409
                );
            }
            $username = trim((string)($values[P2P_TRANSPORT_TURN_USERNAME_SETTING] ?? ''));
            $credential = (string)($values[P2P_TRANSPORT_TURN_CREDENTIAL_SETTING] ?? '');
            if ($credential === '') {
                $credential = app_setting($pdo, P2P_TRANSPORT_TURN_CREDENTIAL_SETTING, '');
            }
            if (!$turnUrls || $username === '' || $credential === '') {
                throw new P2PTransportPolicyException(
                    'TURN fallback requires a valid relay URL, username, and credential.',
                    'P2P_AVATAR_TURN_CONFIGURATION_REQUIRED',
                    422
                );
            }
        }
        return ['ok' => true];
    } catch (P2PTransportPolicyException $error) {
        return [
            'ok' => false,
            'code' => $error->errorCode,
            'error' => $error->getMessage(),
            'http_status' => $error->httpStatus,
        ];
    }
}

function p2p_transport_applicable_capabilities(PDO $pdo): array
{
    $avatarDelivery = moderation_safety_delivery_policy($pdo, 'avatar');
    $gestureDelivery = moderation_safety_delivery_policy($pdo, 'gesture');
    $avatar = app_setting($pdo, P2P_AVATAR_ENABLED_SETTING, '0') === '1'
        && (string)$avatarDelivery['effectiveMode'] === 'p2p-plus-built-in-generated';
    $gesture = (string)$gestureDelivery['effectiveMode'] === 'p2p-personal-plus-built-in';
    $fileMode = app_setting($pdo, defined('SERVER_MEDIA_FILE_MODE') ? SERVER_MEDIA_FILE_MODE : 'direct_file_delivery_mode', 'p2p-only');
    $gestureMode = app_setting($pdo, defined('SERVER_MEDIA_GESTURE_MODE') ? SERVER_MEDIA_GESTURE_MODE : 'send_gesture_delivery_mode', 'p2p-only');
    $directFiles = app_setting($pdo, defined('P2P_TRANSFER_FILES_ENABLED_SETTING') ? P2P_TRANSFER_FILES_ENABLED_SETTING : 'p2p_direct_files_enabled', '1') === '1'
        && in_array($fileMode, ['p2p-only','both'], true);
    $sendGesture = app_setting($pdo, defined('P2P_TRANSFER_SEND_GESTURE_ENABLED_SETTING') ? P2P_TRANSFER_SEND_GESTURE_ENABLED_SETTING : 'p2p_send_gesture_enabled', '1') === '1'
        && in_array($gestureMode, ['p2p-only','both'], true);
    return [
        'p2pAvatar' => $avatar,
        'p2pGestureLocalMatch' => $gesture,
        'p2pDirectFiles' => $directFiles,
        'p2pSendGesture' => $sendGesture,
    ];
}

function p2p_transport_policy(PDO $pdo, bool $includeCredential = false): array
{
    $capabilities = p2p_transport_applicable_capabilities($pdo);
    $effective = in_array(true, $capabilities, true);
    $storedStunValue = app_setting($pdo, P2P_TRANSPORT_STUN_URLS_SETTING, P2P_TRANSPORT_CLOUDFLARE_STUN);
    $storedStunUrls = [];
    $stunUrls = [];
    $turnUrls = [];
    $configurationValid = true;
    try {
        $storedStunUrls = p2p_transport_url_list($storedStunValue, ['stun', 'stuns']);
        $stunUrls = $storedStunUrls;
        if ($effective && !$stunUrls) $stunUrls = [P2P_TRANSPORT_CLOUDFLARE_STUN];
        $turnUrls = p2p_transport_url_list(
            app_setting($pdo, P2P_TRANSPORT_TURN_URLS_SETTING, ''),
            ['turn', 'turns']
        );
    } catch (P2PTransportPolicyException) {
        $configurationValid = false;
        $storedStunUrls = [];
        $stunUrls = [];
        $turnUrls = [];
    }
    $turnEnabled = app_setting($pdo, P2P_TRANSPORT_TURN_ENABLED_SETTING, '0') === '1';
    $acknowledged = app_setting($pdo, P2P_TRANSPORT_TURN_ACKNOWLEDGED_SETTING, '0') === '1';
    $turnUsername = trim(app_setting($pdo, P2P_TRANSPORT_TURN_USERNAME_SETTING, ''));
    $turnCredential = app_setting($pdo, P2P_TRANSPORT_TURN_CREDENTIAL_SETTING, '');
    $turnConfigured = $configurationValid
        && $turnUrls !== []
        && $turnUsername !== ''
        && $turnCredential !== '';
    $iceServers = [];
    if ($effective && $configurationValid && $stunUrls) {
        $iceServers[] = ['urls' => $stunUrls];
    }
    if ($effective && $turnEnabled && $acknowledged && $turnConfigured) {
        $turn = ['urls' => $turnUrls, 'username' => $turnUsername];
        if ($includeCredential) $turn['credential'] = $turnCredential;
        $iceServers[] = $turn;
    }
    return [
        'effectiveEnabled' => $effective && $configurationValid,
        'applicableCapabilities' => $capabilities,
        'directFirst' => true,
        'storedStunUrls' => $storedStunUrls,
        'stunUrls' => $effective ? $stunUrls : [],
        'stunConfigured' => $effective && $stunUrls !== [],
        'stunDefault' => P2P_TRANSPORT_CLOUDFLARE_STUN,
        'stunUsingDefault' => $effective && (
            trim($storedStunValue) === ''
            || strtolower(trim($storedStunValue)) === strtolower(P2P_TRANSPORT_CLOUDFLARE_STUN)
        ),
        'turnEnabled' => $turnEnabled,
        'turnAcknowledged' => $acknowledged,
        'turnConfigured' => $turnConfigured,
        'relayAllowed' => $effective && $configurationValid && $turnEnabled && $acknowledged && $turnConfigured,
        'iceServers' => $iceServers,
        'serverPayloadStorage' => false,
        'providerLogin' => false,
        'anonymousTurnAllowed' => false,
        'credentialProjection' => $includeCredential ? 'authorized-active-client-only' : 'redacted',
        'credentialIssuerBoundary' => 'server-side-static-now-short-lived-compatible',
        'configurationValid' => $configurationValid,
    ];
}
