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

$targetUserId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
if ($targetUserId === false || (int)$targetUserId < 1) {
    json_out([
        'error' => 'Choose a valid community member.',
        'code' => 'MEMBER_PROFILE_TARGET_INVALID',
    ], 400);
}

try {
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
