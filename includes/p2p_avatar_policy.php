<?php
declare(strict_types=1);

require_once __DIR__ . '/nameplate_policy.php';

/**
 * Build 000054 server-authoritative P2P Avatar policy and pair authorization.
 *
 * Avatar payload bytes never enter this owner. It projects short-lived signed
 * pair authorization and validates the source immediately before browser-to-
 * browser delivery.
 */

const P2P_AVATAR_ENABLED_SETTING = 'p2p_avatar_enabled';
const P2P_AVATAR_TOKEN_SECONDS = 90;

final class P2PAvatarPolicyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'P2P_AVATAR_POLICY_FAILED',
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

function p2p_avatar_setting_defaults(): array
{
    return [P2P_AVATAR_ENABLED_SETTING => '0'] + p2p_transport_setting_defaults();
}

function p2p_avatar_url_list(mixed $value, array $schemes): array
{
    try {
        return p2p_transport_url_list($value, $schemes);
    } catch (P2PTransportPolicyException $error) {
        throw new P2PAvatarPolicyException(
            $error->getMessage(),
            $error->errorCode,
            $error->httpStatus
        );
    }
}

function p2p_avatar_validate_settings(PDO $pdo, array $values): array
{
    return p2p_transport_validate_settings($pdo, $values);
}

function p2p_avatar_policy(PDO $pdo, bool $includeCredential = false): array
{
    $delivery = moderation_safety_delivery_policy($pdo, 'avatar');
    $enabled = app_setting($pdo, P2P_AVATAR_ENABLED_SETTING, '0') === '1';
    $transport = p2p_transport_policy($pdo, $includeCredential);
    $p2pSelected = (string)$delivery['effectiveMode'] === 'p2p-plus-built-in-generated';
    $effective = $enabled && $p2pSelected && !empty($transport['configurationValid']);
    $sizePolicy = avatar_size_policy($pdo);
    return [
        'enabled' => $enabled,
        'effectiveEnabled' => $effective,
        'deliveryMode' => (string)$delivery['effectiveMode'],
        'storedDeliveryMode' => (string)$delivery['storedMode'],
        'directFirst' => $transport['directFirst'],
        'stunConfigured' => $effective && $transport['stunConfigured'],
        'stunDefault' => $transport['stunDefault'],
        'stunUsingDefault' => $effective && $transport['stunUsingDefault'],
        'turnEnabled' => $transport['turnEnabled'],
        'turnAcknowledged' => $transport['turnAcknowledged'],
        'turnConfigured' => $transport['turnConfigured'],
        'relayAllowed' => $effective && $transport['relayAllowed'],
        'iceServers' => $effective ? $transport['iceServers'] : [],
        'receivedStorage' => 'session-memory-only',
        'fallback' => 'built-in-generated',
        'serverPayloadStorage' => false,
        'tokenLifetimeSeconds' => P2P_AVATAR_TOKEN_SECONDS,
        'maxBytes' => $sizePolicy['avatarMaxBytes'],
        'maxBytesEnforced' => $sizePolicy['avatarMaxBytes'] !== null,
        'maxWidth' => (int)$sizePolicy['avatarUploadMaxWidthPx'],
        'maxHeight' => (int)$sizePolicy['avatarUploadMaxHeightPx'],
        'nameplateMaxWidth' => NAMEPLATE_UPLOAD_MAX_WIDTH_PX,
        'nameplateMaxHeight' => NAMEPLATE_UPLOAD_MAX_HEIGHT_PX,
    ];
}

function p2p_avatar_private_key(): string
{
    $directory = security_private_storage_directory('p2p-avatar');
    $path = $directory . DIRECTORY_SEPARATOR . 'pair-authorization-key-v1.bin';
    if (!is_file($path)) {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $key = random_bytes(32);
        $written = file_put_contents($temporary, $key, LOCK_EX) === 32;
        $installed = $written && @rename($temporary, $path);
        if (!$installed && is_file($path)) {
            @unlink($temporary);
        } elseif (!$installed) {
            @unlink($temporary);
            throw new P2PAvatarPolicyException('Avatar synchronization authorization is unavailable.', 'P2P_AVATAR_PRIVATE_KEY_UNAVAILABLE', 503);
        }
        @chmod($path, 0600);
    }
    $key = file_get_contents($path);
    if (!is_string($key) || strlen($key) !== 32) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization is unavailable.', 'P2P_AVATAR_PRIVATE_KEY_UNAVAILABLE', 503);
    }
    return $key;
}

function p2p_avatar_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function p2p_avatar_base64url_decode(string $value): string|false
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return false;
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function p2p_avatar_pair_blocked(PDO $pdo, int $leftUserId, int $rightUserId): bool
{
    if ($leftUserId <= 0 || $rightUserId <= 0) return true;
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks
          WHERE (blocker_user_id=? AND blocked_user_id=?)
             OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1'
    );
    $stmt->execute([$leftUserId, $rightUserId, $rightUserId, $leftUserId]);
    return (bool)$stmt->fetchColumn();
}

function p2p_avatar_source_asset(string $path, string $kind = 'avatar'): ?array
{
    if ($path === '' || str_starts_with($path, 'preset:')) return null;
    $file = avatar_source_file($path);
    if (!$file || !is_file($file)) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: '';
    $allowed = ['image/gif' => IMAGETYPE_GIF, 'image/webp' => IMAGETYPE_WEBP];
    $dimensions = @getimagesize($file);
    $size = filesize($file);
    $identity = hash_file('sha256', $file);
    if (!isset($allowed[$mime]) || !$dimensions || (int)$dimensions[2] !== $allowed[$mime]
        || !is_int($size) || $size < 1 || !is_string($identity)
        || !avatar_identity_is_valid($identity)) return null;
    if ($kind === 'nameplate'
        && ((int)$dimensions[0] > NAMEPLATE_UPLOAD_MAX_WIDTH_PX
            || (int)$dimensions[1] > NAMEPLATE_UPLOAD_MAX_HEIGHT_PX)) return null;
    return [
        'kind' => $kind,
        'path' => $path,
        'file' => $file,
        'identity' => $identity,
        'mime' => $mime,
        'size' => $size,
        'width' => max(1, (int)$dimensions[0]),
        'height' => max(1, (int)$dimensions[1]),
    ];
}

function p2p_avatar_pair_claims(PDO $pdo, int $sessionId, int $viewerParticipantId, int $sourceParticipantId): array
{
    $stmt = $pdo->prepare(
        'SELECT id,user_id,avatar_path,avatar_identity,avatar_source_width_px,avatar_source_height_px,nameplate_path
           FROM participants WHERE session_id=? AND id IN (?,?) ORDER BY id'
    );
    $stmt->execute([$sessionId, $viewerParticipantId, $sourceParticipantId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) $rows[(int)$row['id']] = $row;
    $viewer = $rows[$viewerParticipantId] ?? null;
    $source = $rows[$sourceParticipantId] ?? null;
    if (!$viewer || !$source || $viewerParticipantId === $sourceParticipantId) {
        throw new P2PAvatarPolicyException('Avatar synchronization is unavailable.', 'P2P_AVATAR_PAIR_UNAVAILABLE', 404);
    }
    $identity = avatar_identity_is_valid($source['avatar_identity'] ?? null)
        ? (string)$source['avatar_identity']
        : avatar_identity_ensure_user($pdo, (int)$source['user_id'])['identity'];
    $avatarAsset = p2p_avatar_source_asset((string)($source['avatar_path'] ?? ''), 'avatar');
    $nameplateAsset = p2p_avatar_source_asset((string)($source['nameplate_path'] ?? ''), 'nameplate');
    return [
        'session_id' => $sessionId,
        'viewer_participant_id' => $viewerParticipantId,
        'viewer_user_id' => (int)$viewer['user_id'],
        'source_participant_id' => $sourceParticipantId,
        'source_user_id' => (int)$source['user_id'],
        'avatar_identity' => $identity,
        'avatar_path' => (string)($source['avatar_path'] ?? 'preset:Default'),
        'width' => max(1, (int)($source['avatar_source_width_px'] ?? 150)),
        'height' => max(1, (int)($source['avatar_source_height_px'] ?? 150)),
        'avatar_asset' => $avatarAsset,
        'nameplate_asset' => $nameplateAsset,
        'nameplate_identity' => (string)($nameplateAsset['identity'] ?? ''),
        'nameplate_path' => (string)($nameplateAsset['path'] ?? ''),
    ];
}

function p2p_avatar_pair_asset_allowed(PDO $pdo, array $claims, string $kind): bool
{
    if (!p2p_avatar_policy($pdo)['effectiveEnabled']) return false;
    if (p2p_avatar_pair_blocked($pdo, (int)$claims['viewer_user_id'], (int)$claims['source_user_id'])) return false;
    $visibility = $kind === 'nameplate'
        ? nameplate_visibility_effective($pdo, (int)$claims['viewer_user_id'], (int)$claims['source_user_id'])
        : avatar_visibility_effective($pdo, (int)$claims['viewer_user_id'], (int)$claims['source_user_id']);
    if ($visibility['hidden']) return false;
    return is_array($claims[$kind . '_asset'] ?? null);
}

function p2p_avatar_pair_allowed(PDO $pdo, array $claims): bool
{
    return p2p_avatar_pair_asset_allowed($pdo, $claims, 'avatar')
        || p2p_avatar_pair_asset_allowed($pdo, $claims, 'nameplate');
}

function p2p_avatar_issue_token(PDO $pdo, array $claims): string
{
    $payload = [
        'v' => 1,
        'sid' => (int)$claims['session_id'],
        'vp' => (int)$claims['viewer_participant_id'],
        'vu' => (int)$claims['viewer_user_id'],
        'sp' => (int)$claims['source_participant_id'],
        'su' => (int)$claims['source_user_id'],
        'aid' => (string)$claims['avatar_identity'],
        'nid' => (string)($claims['nameplate_identity'] ?? ''),
        'exp' => time() + P2P_AVATAR_TOKEN_SECONDS,
        'nonce' => bin2hex(random_bytes(8)),
    ];
    $encoded = p2p_avatar_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $signature = p2p_avatar_base64url_encode(hash_hmac('sha256', $encoded, p2p_avatar_private_key(), true));
    return $encoded . '.' . $signature;
}

function p2p_avatar_validate_token(PDO $pdo, string $token): array
{
    if (strlen($token) > 2048 || substr_count($token, '.') !== 1) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization is invalid.', 'P2P_AVATAR_TOKEN_INVALID', 403);
    }
    [$encoded, $signature] = explode('.', $token, 2);
    $expected = p2p_avatar_base64url_encode(hash_hmac('sha256', $encoded, p2p_avatar_private_key(), true));
    if (!hash_equals($expected, $signature)) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization is invalid.', 'P2P_AVATAR_TOKEN_INVALID', 403);
    }
    $decoded = p2p_avatar_base64url_decode($encoded);
    $payload = is_string($decoded) ? json_decode($decoded, true) : null;
    if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1 || (int)($payload['exp'] ?? 0) < time()) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization expired.', 'P2P_AVATAR_TOKEN_EXPIRED', 403);
    }
    $claims = p2p_avatar_pair_claims(
        $pdo,
        (int)($payload['sid'] ?? 0),
        (int)($payload['vp'] ?? 0),
        (int)($payload['sp'] ?? 0)
    );
    if ((int)$payload['vu'] !== (int)$claims['viewer_user_id']
        || (int)$payload['su'] !== (int)$claims['source_user_id']
        || !hash_equals((string)$payload['aid'], (string)$claims['avatar_identity'])
        || !hash_equals((string)($payload['nid'] ?? ''), (string)($claims['nameplate_identity'] ?? ''))) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization is stale.', 'P2P_AVATAR_TOKEN_STALE', 409);
    }
    if (!p2p_avatar_pair_allowed($pdo, $claims)) {
        throw new P2PAvatarPolicyException('Avatar synchronization is unavailable.', 'P2P_AVATAR_PAIR_DENIED', 403);
    }
    return $claims + ['expires_at' => (int)$payload['exp']];
}

function p2p_avatar_signal_authorized(
    PDO $pdo,
    int $sessionId,
    int $fromParticipantId,
    int $toParticipantId,
    string $token
): bool {
    try {
        $claims = p2p_avatar_validate_token($pdo, $token);
        if ((int)$claims['session_id'] !== $sessionId) return false;
        $pair = [(int)$claims['viewer_participant_id'], (int)$claims['source_participant_id']];
        return in_array($fromParticipantId, $pair, true)
            && in_array($toParticipantId, $pair, true)
            && $fromParticipantId !== $toParticipantId;
    } catch (P2PAvatarPolicyException) {
        return false;
    }
}

function p2p_avatar_signal_uses_relay(string $type, array $data): bool
{
    $candidate = '';
    if ($type === 'ice') {
        $candidate = (string)($data['candidate']['candidate'] ?? '');
    } elseif (in_array($type, ['offer', 'answer'], true)) {
        $candidate = (string)($data['description']['sdp'] ?? '');
    }
    return $candidate !== '' && preg_match('/(?:^|\s)typ\s+relay(?:\s|$)/i', $candidate) === 1;
}

function p2p_avatar_signal_payload_allowed(PDO $pdo, string $type, array $data): bool
{
    if (!p2p_avatar_signal_uses_relay($type, $data)) return true;
    return p2p_avatar_policy($pdo)['relayAllowed'] === true;
}

function p2p_avatar_project_participant(
    PDO $pdo,
    int $sessionId,
    array $viewer,
    array $participant
): array {
    $policy = p2p_avatar_policy($pdo);
    $viewerParticipantId = (int)$viewer['id'];
    $sourceParticipantId = (int)($participant['id'] ?? $participant['participant_id'] ?? 0);
    $sourceUserId = (int)($participant['user_id'] ?? 0);
    if ($sourceParticipantId <= 0) return $participant;
    if ($sourceUserId <= 0) {
        $sourceStmt = $pdo->prepare('SELECT user_id FROM participants WHERE id=? AND session_id=? LIMIT 1');
        $sourceStmt->execute([$sourceParticipantId, $sessionId]);
        $sourceUserId = (int)($sourceStmt->fetchColumn() ?: 0);
        if ($sourceUserId <= 0) return $participant;
        $participant['user_id'] = $sourceUserId;
    }
    if ($sourceParticipantId === $viewerParticipantId) {
        $participant['avatar_delivery'] = 'owner-source';
        $participant['nameplate_delivery'] = 'owner-source';
        return $participant;
    }
    if ((string)$policy['deliveryMode'] !== 'p2p-plus-built-in-generated') {
        $participant['avatar_delivery'] = (string)$policy['deliveryMode'];
        $participant['nameplate_delivery'] = (string)$policy['deliveryMode'];
        if ((string)$policy['deliveryMode'] === 'built-in-generated-only') {
            $participant['nameplate_path'] = null;
            $participant['nameplate_url'] = null;
        }
        return $participant;
    }
    $participant['avatar_path'] = null;
    $participant['avatar_url'] = null;
    $participant['avatar_delivery'] = 'built-in-generated-fallback';
    $participant['nameplate_path'] = null;
    $participant['nameplate_url'] = null;
    $participant['nameplate_delivery'] = 'none-fallback';
    try {
        $claims = p2p_avatar_pair_claims($pdo, $sessionId, $viewerParticipantId, $sourceParticipantId);
        if (!p2p_avatar_pair_allowed($pdo, $claims)) return $participant;
        $authorization = p2p_avatar_issue_token($pdo, $claims);
        if (p2p_avatar_pair_asset_allowed($pdo, $claims, 'avatar')) {
            $participant['p2p_avatar'] = [
                'identity' => (string)$claims['avatar_identity'],
                'width' => (int)$claims['width'],
                'height' => (int)$claims['height'],
                'authorization' => $authorization,
                'expiresInSeconds' => P2P_AVATAR_TOKEN_SECONDS,
            ];
            $participant['avatar_delivery'] = 'p2p-prefetch';
        }
        if (p2p_avatar_pair_asset_allowed($pdo, $claims, 'nameplate')) {
            $nameplate = $claims['nameplate_asset'];
            $participant['p2p_nameplate'] = [
                'identity' => (string)$nameplate['identity'],
                'width' => (int)$nameplate['width'],
                'height' => (int)$nameplate['height'],
                'authorization' => $authorization,
                'expiresInSeconds' => P2P_AVATAR_TOKEN_SECONDS,
            ];
            $participant['nameplate_delivery'] = 'p2p-prefetch';
        }
    } catch (P2PAvatarPolicyException) {
        // Deliberately neutral fallback; never disclose private denial detail.
    }
    return $participant;
}

function p2p_avatar_authorize_source(
    PDO $pdo,
    array $sourceParticipant,
    int $sessionId,
    string $token,
    string $assetKind = 'avatar'
): array {
    if (!in_array($assetKind, ['avatar', 'nameplate'], true)) {
        throw new P2PAvatarPolicyException('Identity asset synchronization is invalid.', 'P2P_AVATAR_ASSET_INVALID', 400);
    }
    $claims = p2p_avatar_validate_token($pdo, $token);
    if ((int)$claims['session_id'] !== $sessionId
        || (int)$claims['source_participant_id'] !== (int)$sourceParticipant['id']
        || (int)$claims['source_user_id'] !== (int)$sourceParticipant['user_id']) {
        throw new P2PAvatarPolicyException('Avatar synchronization authorization is invalid.', 'P2P_AVATAR_SOURCE_DENIED', 403);
    }
    if (!p2p_avatar_pair_asset_allowed($pdo, $claims, $assetKind)) {
        throw new P2PAvatarPolicyException('The current identity asset is unavailable.', 'P2P_AVATAR_SOURCE_UNAVAILABLE', 404);
    }
    $asset = $claims[$assetKind . '_asset'];
    $avatarMaxBytes = avatar_size_policy($pdo)['avatarMaxBytes'];
    if ($avatarMaxBytes !== null && (int)$asset['size'] > $avatarMaxBytes) {
        throw new P2PAvatarPolicyException('The current identity asset did not pass validation.', 'P2P_AVATAR_SOURCE_INVALID', 422);
    }
    return [
        'ok' => true,
        'assetKind' => $assetKind,
        'sourceUrl' => $assetKind === 'nameplate'
            ? resolve_nameplate((string)$asset['path'])
            : resolve_avatar((string)$asset['path']),
        'identity' => (string)$asset['identity'],
        'mime' => (string)$asset['mime'],
        'size' => (int)$asset['size'],
        'width' => (int)$asset['width'],
        'height' => (int)$asset['height'],
        'viewerParticipantId' => (int)$claims['viewer_participant_id'],
        'expiresAt' => (int)$claims['expires_at'],
    ];
}
