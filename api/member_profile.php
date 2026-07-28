<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/base.php';

$viewer = current_user();
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
    $targetUserId = member_profiles_user_id_for_public_profile_id(
        db(),
        $_GET['profile_id'] ?? ''
    );
    json_out([
        'profile' => member_profiles_projection(
            db(),
            (int)$viewer['id'],
            (int)$targetUserId
        ),
    ]);
} catch (MemberProfileException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
}
