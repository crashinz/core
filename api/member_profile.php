<?php
declare(strict_types=1);

$profileRequestStartedAt = hrtime(true);
require_once __DIR__ . '/../includes/base.php';
$profileBootstrapAt = hrtime(true);

$viewer = current_user();
$profileAuthenticatedAt = hrtime(true);
if (!$viewer) {
    json_out([
        'error' => 'Authentication required.',
        'code' => 'AUTH_REQUIRED',
    ], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'Unsupported method'], 405);
}

try {
    $pdo = db();
    try {
        $targetUserId = member_profiles_user_id_for_public_profile_id(
            $pdo,
            $_GET['profile_id'] ?? ''
        );
    } catch (MemberProfileException $error) {
        flood_protection_consume($pdo, 'member-profile-read', (int)$viewer['id']);
        throw $error;
    }
    // Self and other-member reads share one actor/network budget so repeatedly
    // opening the same profile cannot bypass the configured protection.
    flood_protection_consume($pdo, 'member-profile-read', (int)$viewer['id']);
    $profileLookupAt = hrtime(true);
    $profile = member_profiles_projection($pdo, (int)$viewer['id'], (int)$targetUserId);
    $profileProjectedAt = hrtime(true);
    $profileTiming = [];
    foreach ([
        'bootstrap' => [$profileRequestStartedAt, $profileBootstrapAt],
        'auth' => [$profileBootstrapAt, $profileAuthenticatedAt],
        'lookup' => [$profileAuthenticatedAt, $profileLookupAt],
        'projection' => [$profileLookupAt, $profileProjectedAt],
        'total' => [$profileRequestStartedAt, $profileProjectedAt],
    ] as $stage => [$begin, $end]) {
        $profileTiming[] = 'cc_profile_' . $stage . ';dur=' . number_format(max(0, $end - $begin) / 1000000, 3, '.', '');
    }
    // Authenticated successful reads only; fixed duration labels, no identities.
    header('Server-Timing: ' . implode(', ', $profileTiming), false);
    json_out(['profile' => $profile]);
} catch (MemberProfileException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
} catch (FloodProtectionException $error) {
    auth_rate_retry_after_header($error->retryAfter);
    json_out([
        'error' => $error->getMessage(),
        'code' => $error->errorCode,
        'retry_after' => $error->retryAfter,
        'control' => $error->control,
    ], $error->httpStatus);
}
