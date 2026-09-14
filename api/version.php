<?php
require_once __DIR__ . '/../includes/base.php';

$version = chatspace_application_version();
$attribution = public_room_version_attribution(db());

json_out([
    'version' => $version,
    'attribution' => $attribution,
    'displayVersion' => private_site_branding_projection(db(), 'room')['room_version_label'] ?: $version,
]);
