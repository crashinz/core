<?php
declare(strict_types=1);
// Public, database-free desktop handshake. No account or installation secrets.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'product' => 'CoreChat',
    'desktopProtocol' => 1,
    'loginPath' => 'login.php',
], JSON_UNESCAPED_SLASHES);
