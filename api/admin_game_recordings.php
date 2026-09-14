<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/base.php';
$actor = require_staff(['admin']);
$pdo = db();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');
        if ($action === 'list') json_out(game_recording_catalog($pdo, max(0, (int)($_GET['offset'] ?? 0))));
        if ($action === 'export') {
            security_require_recent_authentication();
            $id = (string)($_GET['id'] ?? '');
            $row = game_recording_require_closed($pdo, $id);
            if ((int)$row['deleted'] !== 0) throw new MultiplayerGameException('Recording deletion is pending. Retry deletion to finish removing its files.', 'RECORDING_DELETION_PENDING', 409);
            if ((int)$row['archived_count'] !== (int)$row['event_count']) throw new MultiplayerGameException('Some events are still waiting for file storage. Refresh the recording list first.', 'RECORDING_PENDING', 409);
            $lock = game_recording_lock();
            if ($lock === null) throw new MultiplayerGameException('Recording storage is busy. Try again.', 'RECORDING_BUSY', 409);
            try {
                $row = game_recording_require_closed($pdo, $id);
                game_recording_verify_archive($row);
                header('Content-Type: application/x-ndjson; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $row['game'] . '-' . substr($id, 0, 12) . '.jsonl"');
                header('Cache-Control: private, no-store');
                header('X-Content-Type-Options: nosniff');
                foreach (game_recording_archive_lines($row) as $line) echo $line;
                echo game_recording_json(['kind' => 'archive-summary', 'formatVersion' => GAME_RECORDING_VERSION,
                    'events' => (int)$row['event_count'], 'gapCount' => (int)$row['gap_count'],
                    'complete' => (int)$row['gap_count'] === 0, 'status' => $row['status'], 'lastHash' => $row['last_hash']]) . "\n";
            } finally { flock($lock, LOCK_UN); fclose($lock); }
            exit;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        security_require_recent_authentication();
        $input = input_json();
        if (!csrf_verify($input)) csrf_failure_response();
        if (($input['action'] ?? '') === 'delete') {
            if (($input['confirmed'] ?? false) !== true) throw new MultiplayerGameException('Confirm deletion of this recording.', 'RECORDING_CONFIRMATION_REQUIRED', 409);
            game_recording_delete($pdo, (string)($input['id'] ?? ''));
            log_tool($pdo, (int)$actor['id'], 'game_recording_deleted', null, null, (string)$input['id']);
            json_out(['ok' => true]);
        }
    }
    json_out(['error' => 'Unknown recording action.'], 400);
} catch (MultiplayerGameException $error) {
    json_out(['error' => $error->getMessage(), 'code' => $error->errorCode], $error->httpStatus);
} catch (SecurityPolicyViolation $error) {
    json_out(['error' => 'Administrator reauthentication is required for this action.'], 403);
} catch (Throwable $error) {
    json_out(['error' => 'Recording storage is unavailable or failed verification. Games can continue.', 'code' => 'RECORDING_STORAGE_UNAVAILABLE'], 503);
}
