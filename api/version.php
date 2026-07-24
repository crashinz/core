<?php
require_once __DIR__ . '/../includes/base.php';

$version = chatspace_application_version();
$attribution = public_room_version_attribution();

json_out([
    'version' => $version,
    'attribution' => $attribution,
]);
