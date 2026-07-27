<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_staff();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
if ((string)$me['role'] !== 'admin') json_out(['error' => 'Administrator required'], 403);
security_require_recent_authentication_or_json();
first_party_extension_assert_capability($pdo, PRIVATE_SITE_BRANDING_ID, 'branding.asset.reference');
security_authorize_outside_content_or_json(
    $pdo,
    $me,
    'admin_branding',
    ['source' => 'admin-private-site-branding']
);

$path = '';
$previousPath = trim(app_setting($pdo, 'community_logo_path', ''));
try {
    $path = private_site_branding_store_logo_upload(
        (array)($_FILES['community_logo'] ?? []),
        'admin_branding'
    );
    $result = settings_registry_update(
        $pdo,
        ['operation' => 'set', 'values' => ['community_logo_path' => $path]],
        $_POST['expected_revision'] ?? null,
        (int)$me['id'],
        'admin',
        ['validated_asset_upload' => true]
    );
    if (empty($result['ok'])) {
        $absolute = dirname(__DIR__) . $path;
        if (private_site_branding_valid_asset_reference($path)) @unlink($absolute);
        $status = max(400, (int)($result['http_status'] ?? 400));
        unset($result['http_status']);
        json_out($result, $status);
    }
    if ($previousPath !== '' && $previousPath !== $path) {
        private_site_branding_remove_managed_asset($previousPath);
    }
    json_out($result + ['logoPath' => $path]);
} catch (Throwable $error) {
    if ($path !== '' && private_site_branding_valid_asset_reference($path)) {
        @unlink(dirname(__DIR__) . $path);
    }
    json_out(['error' => $error->getMessage()], 400);
}
