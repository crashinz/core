<?php
require_once __DIR__ . '/../includes/base.php';

$me = require_user();
if (!in_array($me['role'] ?? 'user', ['admin', 'developer'], true)) {
    json_out(['error' => 'Admin required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download') {
    if (db_driver() !== 'sqlite') json_out(['error' => 'Database download is available for SQLite installs. Use your MySQL/MariaDB backup tool for server databases.'], 400);
    $dbPath = sqlite_path();
    if (!is_file($dbPath)) json_out(['error' => 'Database not found'], 404);
    log_tool(db(), (int)$me['id'], 'admin_database_download', null, null, 'Downloaded database backup');
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="chatspace-' . gmdate('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Unsupported method'], 405);
if (db_driver() !== 'sqlite') json_out(['error' => 'Database restore is available for SQLite installs. Use your MySQL/MariaDB restore process for server databases.'], 400);

if (empty($_FILES['database']['tmp_name']) || !is_uploaded_file($_FILES['database']['tmp_name'])) {
    json_out(['error' => 'Database file required'], 400);
}

$tmp = $_FILES['database']['tmp_name'];
$checkPath = sys_get_temp_dir() . '/chatspace-restore-' . bin2hex(random_bytes(8)) . '.sqlite';
if (!move_uploaded_file($tmp, $checkPath)) json_out(['error' => 'Could not read uploaded database'], 500);

try {
    $check = new PDO('sqlite:' . $checkPath);
    $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $result = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
    $hasUsers = (int)$check->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    $hasRooms = (int)$check->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='rooms'")->fetchColumn();
    $check = null;
    if ($result !== 'ok' || !$hasUsers || !$hasRooms) {
        @unlink($checkPath);
        json_out(['error' => 'Uploaded file is not a valid ChatSpace database'], 400);
    }
} catch (Throwable $e) {
    @unlink($checkPath);
    json_out(['error' => 'Uploaded file is not a valid SQLite database'], 400);
}

$dbPath = sqlite_path();
$backup = $dbPath . '.pre-restore-' . gmdate('Ymd-His') . '.bak';
if (is_file($dbPath)) copy($dbPath, $backup);
if (!copy($checkPath, $dbPath)) {
    @unlink($checkPath);
    json_out(['error' => 'Could not restore database'], 500);
}
@unlink($checkPath);
log_tool(db(), (int)$me['id'], 'admin_database_restore', null, null, 'Restored database; prior copy: ' . basename($backup));
json_out(['ok' => true]);
