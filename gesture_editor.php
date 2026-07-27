<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/base.php';

$user = require_user();
$pdo = db();
$publicId = trim((string)($_GET['id'] ?? ''));
$admin = in_array(strtolower((string)($_GET['admin'] ?? '')), ['1', 'true', 'yes'], true);
if ($publicId !== '' && !preg_match('/^[A-Za-z0-9-]{8,64}$/', $publicId)) {
    http_response_code(400);
    exit('Gesture identity is invalid.');
}
if ($admin && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Administrator authorization is required.');
}
$features = gesture_part4_feature_flags($pdo);
if (!$admin && empty($features['editor'])) {
    http_response_code(403);
    exit('Gesture Maker and Editor are disabled.');
}
if (!$admin) {
    try {
        $capability = gesture_catalog_require_user_mutation($pdo);
        gesture_capability_require_scope($capability, 'personal');
    } catch (GestureCatalogException $error) {
        http_response_code($error->httpStatus);
        exit(e($error->getMessage()));
    }
}

try {
    $adapter = first_party_extension_adapter(
        $pdo,
        'gesture-maker',
        'presentation.gesture-maker'
    );
    $service = first_party_extension_service_facade(
        $pdo,
        'gesture-maker',
        'gesture.editor.commands'
    );
    $renderer = (string)($adapter['renderEditor'] ?? '');
    if ($renderer === '' || !is_callable($renderer)) {
        throw new RuntimeException('Gesture Maker presentation is unavailable.');
    }
    $renderer([
        'publicId' => $publicId,
        'admin' => $admin,
        'editorApi' => (string)$service['endpoint'],
    ]);
} catch (Throwable) {
    http_response_code(503);
    exit('Gesture Maker presentation is safely unavailable.');
}
