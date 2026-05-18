<?php
require_once __DIR__ . '/../includes/base.php';

$versionPath = __DIR__ . '/../VERSION';
$version = is_file($versionPath) ? trim((string)file_get_contents($versionPath)) : 'ChatSpace Community Edition';

json_out([
    'version' => $version,
]);
