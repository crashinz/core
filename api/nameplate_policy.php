<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/base.php';
require_once __DIR__ . '/../includes/nameplate_policy.php';
require_user();
security_protect_private_response();
json_out(['nameplatePolicy' => nameplate_upload_policy(db())]);
