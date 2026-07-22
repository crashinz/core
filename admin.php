<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/base.php';

require_staff();
$return = (string)($_GET['return'] ?? 'lobby');
$roomKey = trim((string)($_GET['id'] ?? ''));
if (!preg_match('/\A[A-Za-z0-9_-]{1,120}\z/', $roomKey)) $roomKey = '';
$query = ['admin' => '1'];
if ($return === 'room' && $roomKey !== '') {
    $query['return'] = 'room';
    $query['id'] = $roomKey;
}

redirect_to('/lobby.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
