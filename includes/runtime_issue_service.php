<?php
declare(strict_types=1);

/**
 * Chat Runtime Framework
 * Build: 000045
 * Owner: RuntimeIssueService
 */

const RUNTIME_ISSUE_STATUSES = ['new', 'confirmed', 'investigating', 'fixed-pending-verification', 'resolved', 'expected', 'ignored', 'regressed'];
const RUNTIME_ISSUE_SEVERITIES = ['info', 'warning', 'error', 'critical'];
const RUNTIME_ISSUE_MAX_EVIDENCE_BYTES = 24576;
const RUNTIME_ISSUE_MAX_OCCURRENCES = 100;
const RUNTIME_ISSUE_MAX_SCREENSHOT_BYTES = 1572864;
const RUNTIME_ISSUE_MAX_SCREENSHOT_WIDTH = 1600;
const RUNTIME_ISSUE_MAX_SCREENSHOT_HEIGHT = 1200;

function runtime_issue_install_schema(PDO $pdo): void
{
    if (db_uses_mysql_syntax($pdo)) {
        $statements = [
            "CREATE TABLE IF NOT EXISTS runtime_issues (id INT AUTO_INCREMENT PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL UNIQUE, category VARCHAR(64) NOT NULL, component VARCHAR(96) NOT NULL, error_code VARCHAR(96) NOT NULL, normalized_message VARCHAR(512) NOT NULL, title VARCHAR(191) NOT NULL, severity VARCHAR(32) NOT NULL DEFAULT 'error', status VARCHAR(32) NOT NULL DEFAULT 'new', reporter_user_id INT DEFAULT NULL, occurrence_count INT NOT NULL DEFAULT 0, first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issues_status (status, last_seen_at), CONSTRAINT fk_runtime_issues_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_occurrences (id INT AUTO_INCREMENT PRIMARY KEY, issue_id INT NOT NULL, reporter_user_id INT DEFAULT NULL, evidence_json LONGTEXT NOT NULL, build_id VARCHAR(96) DEFAULT NULL, request_correlation VARCHAR(96) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issue_occurrences_issue (issue_id, id), CONSTRAINT fk_runtime_occurrence_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_occurrence_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_status_history (id INT AUTO_INCREMENT PRIMARY KEY, issue_id INT NOT NULL, from_status VARCHAR(32) DEFAULT NULL, to_status VARCHAR(32) NOT NULL, actor_user_id INT DEFAULT NULL, reason VARCHAR(512) DEFAULT NULL, verification_reference VARCHAR(191) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_runtime_issue_history_issue (issue_id, id), CONSTRAINT fk_runtime_history_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS runtime_issue_screenshots (id INT AUTO_INCREMENT PRIMARY KEY, public_id VARCHAR(64) NOT NULL UNIQUE, issue_id INT NOT NULL, occurrence_id INT DEFAULT NULL, owner_user_id INT NOT NULL, storage_name VARCHAR(191) NOT NULL UNIQUE, mime_type VARCHAR(64) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME DEFAULT NULL, INDEX idx_runtime_issue_screenshots_issue (issue_id, deleted_at), CONSTRAINT fk_runtime_screenshot_issue FOREIGN KEY (issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, CONSTRAINT fk_runtime_screenshot_occurrence FOREIGN KEY (occurrence_id) REFERENCES runtime_issue_occurrences(id) ON DELETE SET NULL, CONSTRAINT fk_runtime_screenshot_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS runtime_issues (id INTEGER PRIMARY KEY AUTOINCREMENT, fingerprint TEXT NOT NULL UNIQUE, category TEXT NOT NULL, component TEXT NOT NULL, error_code TEXT NOT NULL, normalized_message TEXT NOT NULL, title TEXT NOT NULL, severity TEXT NOT NULL DEFAULT 'error', status TEXT NOT NULL DEFAULT 'new', reporter_user_id INTEGER DEFAULT NULL, occurrence_count INTEGER NOT NULL DEFAULT 0, first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_occurrences (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL, reporter_user_id INTEGER DEFAULT NULL, evidence_json TEXT NOT NULL, build_id TEXT DEFAULT NULL, request_correlation TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(reporter_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, issue_id INTEGER NOT NULL, from_status TEXT DEFAULT NULL, to_status TEXT NOT NULL, actor_user_id INTEGER DEFAULT NULL, reason TEXT DEFAULT NULL, verification_reference TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE TABLE IF NOT EXISTS runtime_issue_screenshots (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, issue_id INTEGER NOT NULL, occurrence_id INTEGER DEFAULT NULL, owner_user_id INTEGER NOT NULL, storage_name TEXT NOT NULL UNIQUE, mime_type TEXT NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, byte_size INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at TEXT DEFAULT NULL, FOREIGN KEY(issue_id) REFERENCES runtime_issues(id) ON DELETE CASCADE, FOREIGN KEY(occurrence_id) REFERENCES runtime_issue_occurrences(id) ON DELETE SET NULL, FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE)",
            'CREATE INDEX IF NOT EXISTS idx_runtime_issues_status ON runtime_issues(status, last_seen_at)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_occurrences_issue ON runtime_issue_occurrences(issue_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_history_issue ON runtime_issue_status_history(issue_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_runtime_issue_screenshots_issue ON runtime_issue_screenshots(issue_id, deleted_at)',
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
}

function runtime_issue_clean_string(mixed $value, int $max = 512): string
{
    $text = trim((string)$value);
    $text = preg_replace('#https?://\S+#i', '[url]', $text) ?? $text;
    $text = preg_replace('/\b(?:[A-Za-z]:\\\\|\/(?:Users|home|tmp)\/)\S+/i', '[private-path]', $text) ?? $text;
    $text = preg_replace('/\b(?:cookie|authorization|password|secret|token|csrf)\s*[:=]\s*\S+/i', '$1=[redacted]', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    if (function_exists('mb_substr')) return mb_substr($text, 0, $max, 'UTF-8');
    if (preg_match_all('/./us', $text, $characters) === false) return substr($text, 0, $max);
    return implode('', array_slice($characters[0], 0, $max));
}

function runtime_issue_sanitize_value(mixed $value, string $key = '', int $depth = 0): mixed
{
    if (preg_match('/authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i', $key)) return '[redacted]';
    if ($depth >= 5) return '[truncated]';
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) return runtime_issue_clean_string($value);
    if (!is_array($value)) return runtime_issue_clean_string(get_debug_type($value), 80);
    $result = [];
    $count = 0;
    foreach ($value as $childKey => $childValue) {
        if ($count++ >= 64) { $result['__truncated'] = true; break; }
        $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '-', (string)$childKey) ?: 'value';
        if (preg_match('/authorization|cookie|csrf|password|secret|token|deviceid|groupid|sdp|candidate|message|content|private/i', $safeKey)) continue;
        $result[$safeKey] = runtime_issue_sanitize_value($childValue, $safeKey, $depth + 1);
    }
    return $result;
}

function runtime_issue_sanitize_evidence(array $evidence): array
{
    $sanitized = runtime_issue_sanitize_value($evidence);
    $json = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > RUNTIME_ISSUE_MAX_EVIDENCE_BYTES) return ['truncated' => true, 'original_bytes' => is_string($json) ? strlen($json) : null];
    return is_array($sanitized) ? $sanitized : [];
}

function runtime_issue_identity(array $input): array
{
    $category = strtolower(runtime_issue_clean_string($input['category'] ?? 'runtime', 64));
    $component = strtolower(runtime_issue_clean_string($input['component'] ?? 'application', 96));
    $code = strtoupper(runtime_issue_clean_string($input['error_code'] ?? $input['code'] ?? 'ERROR', 96));
    $message = strtolower(runtime_issue_clean_string($input['message'] ?? 'Runtime failure', 512));
    $message = preg_replace('/\b\d{2,}\b/', '#', $message) ?? $message;
    $message = preg_replace('/:\d+:\d+\b/', ':#:#', $message) ?? $message;
    $title = runtime_issue_clean_string($input['title'] ?? $input['message'] ?? $code, 191);
    $severity = strtolower(runtime_issue_clean_string($input['severity'] ?? 'error', 32));
    if (!in_array($severity, RUNTIME_ISSUE_SEVERITIES, true)) $severity = 'error';
    return ['fingerprint' => hash('sha256', implode("\n", [$category, $component, $code, $message])), 'category' => $category ?: 'runtime', 'component' => $component ?: 'application', 'error_code' => $code ?: 'ERROR', 'normalized_message' => $message ?: 'runtime failure', 'title' => $title ?: 'Runtime failure', 'severity' => $severity];
}

function runtime_issue_submit(PDO $pdo, int $reporterUserId, array $input): array
{
    $recent = $pdo->prepare('SELECT COUNT(*) FROM runtime_issue_occurrences WHERE reporter_user_id = ? AND created_at >= ?');
    $recent->execute([$reporterUserId, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ((int)$recent->fetchColumn() >= 10) throw new RuntimeException('Diagnostic report rate limit reached.');
    $identity = runtime_issue_identity($input);
    $evidence = runtime_issue_sanitize_evidence(is_array($input['evidence'] ?? null) ? $input['evidence'] : []);
    $buildId = runtime_issue_clean_string($input['build_id'] ?? '', 96) ?: null;
    $correlation = runtime_issue_clean_string($input['request_correlation'] ?? '', 96) ?: null;
    $pdo->beginTransaction();
    try {
        $lookup = $pdo->prepare('SELECT * FROM runtime_issues WHERE fingerprint = ? LIMIT 1');
        $lookup->execute([$identity['fingerprint']]);
        $issue = $lookup->fetch();
        if (!$issue) {
            try {
                $pdo->prepare('INSERT INTO runtime_issues (fingerprint, category, component, error_code, normalized_message, title, severity, reporter_user_id, occurrence_count) VALUES (?,?,?,?,?,?,?,?,1)')->execute([$identity['fingerprint'], $identity['category'], $identity['component'], $identity['error_code'], $identity['normalized_message'], $identity['title'], $identity['severity'], $reporterUserId]);
                $issueId = (int)$pdo->lastInsertId();
            } catch (PDOException $error) {
                $lookup->execute([$identity['fingerprint']]);
                $issue = $lookup->fetch();
                if (!$issue) throw $error;
                $issueId = (int)$issue['id'];
                $pdo->prepare('UPDATE runtime_issues SET occurrence_count = occurrence_count + 1, last_seen_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$issueId]);
            }
        } else {
            $issueId = (int)$issue['id'];
            if ((string)$issue['status'] === 'resolved') {
                $pdo->prepare("UPDATE runtime_issues SET status = 'regressed', occurrence_count = occurrence_count + 1, last_seen_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$issueId]);
                $pdo->prepare('INSERT INTO runtime_issue_status_history (issue_id, from_status, to_status, reason) VALUES (?,?,?,?)')->execute([$issueId, 'resolved', 'regressed', 'New matching occurrence after resolution']);
            } else {
                $pdo->prepare('UPDATE runtime_issues SET occurrence_count = occurrence_count + 1, last_seen_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$issueId]);
            }
        }
        $occurrence = $pdo->prepare('INSERT INTO runtime_issue_occurrences (issue_id, reporter_user_id, evidence_json, build_id, request_correlation) VALUES (?,?,?,?,?)');
        $occurrence->execute([$issueId, $reporterUserId, json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $buildId, $correlation]);
        $occurrenceId = (int)$pdo->lastInsertId();
        $ids = $pdo->prepare('SELECT id FROM runtime_issue_occurrences WHERE issue_id = ? ORDER BY id DESC');
        $ids->execute([$issueId]);
        $stale = array_slice(array_map('intval', array_column($ids->fetchAll(), 'id')), RUNTIME_ISSUE_MAX_OCCURRENCES);
        if ($stale) $pdo->prepare('DELETE FROM runtime_issue_occurrences WHERE id IN (' . implode(',', array_fill(0, count($stale), '?')) . ')')->execute($stale);
        $pdo->commit();
        return ['issue_id' => $issueId, 'occurrence_id' => $occurrenceId, 'fingerprint' => $identity['fingerprint']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function runtime_issue_project_row(array $row): array
{
    return ['id' => (int)$row['id'], 'fingerprint' => $row['fingerprint'], 'category' => $row['category'], 'component' => $row['component'], 'errorCode' => $row['error_code'], 'title' => $row['title'], 'severity' => $row['severity'], 'status' => $row['status'], 'occurrenceCount' => (int)$row['occurrence_count'], 'firstSeenAt' => $row['first_seen_at'], 'lastSeenAt' => $row['last_seen_at'], 'updatedAt' => $row['updated_at']];
}

function runtime_issue_list(PDO $pdo, ?string $status = null, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    if ($status !== null && in_array($status, RUNTIME_ISSUE_STATUSES, true)) {
        $stmt = $pdo->prepare('SELECT * FROM runtime_issues WHERE status = ? ORDER BY last_seen_at DESC LIMIT ' . $limit);
        $stmt->execute([$status]);
    } else $stmt = $pdo->query('SELECT * FROM runtime_issues ORDER BY last_seen_at DESC LIMIT ' . $limit);
    return array_map('runtime_issue_project_row', $stmt->fetchAll());
}

function runtime_issue_detail(PDO $pdo, int $issueId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM runtime_issues WHERE id = ? LIMIT 1');
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch();
    if (!$issue) return null;
    $occurrences = $pdo->prepare('SELECT id, evidence_json, build_id, request_correlation, created_at FROM runtime_issue_occurrences WHERE issue_id = ? ORDER BY id DESC LIMIT 100');
    $occurrences->execute([$issueId]);
    $history = $pdo->prepare('SELECT h.*, u.display_name AS actor_name FROM runtime_issue_status_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.issue_id = ? ORDER BY h.id DESC');
    $history->execute([$issueId]);
    $screenshots = $pdo->prepare('SELECT public_id, mime_type, width, height, byte_size, created_at FROM runtime_issue_screenshots WHERE issue_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    $screenshots->execute([$issueId]);
    $projectedOccurrences = [];
    foreach ($occurrences->fetchAll() as $row) {
        $decoded = json_decode((string)$row['evidence_json'], true);
        $projectedOccurrences[] = ['id' => (int)$row['id'], 'evidence' => is_array($decoded) ? $decoded : [], 'buildId' => $row['build_id'], 'requestCorrelation' => $row['request_correlation'], 'createdAt' => $row['created_at']];
    }
    return ['issue' => runtime_issue_project_row($issue), 'occurrences' => $projectedOccurrences, 'history' => array_map(fn(array $row): array => ['fromStatus' => $row['from_status'], 'toStatus' => $row['to_status'], 'actorName' => $row['actor_name'] ?: 'System', 'reason' => $row['reason'], 'verificationReference' => $row['verification_reference'], 'createdAt' => $row['created_at']], $history->fetchAll()), 'screenshots' => array_map(fn(array $row): array => ['publicId' => $row['public_id'], 'mimeType' => $row['mime_type'], 'width' => (int)$row['width'], 'height' => (int)$row['height'], 'byteSize' => (int)$row['byte_size'], 'createdAt' => $row['created_at']], $screenshots->fetchAll())];
}

function runtime_issue_update_status(PDO $pdo, int $issueId, string $status, int $actorUserId, string $reason = '', string $verificationReference = ''): array
{
    if (!in_array($status, RUNTIME_ISSUE_STATUSES, true)) throw new InvalidArgumentException('Invalid issue status.');
    $reason = runtime_issue_clean_string($reason, 512);
    $verificationReference = runtime_issue_clean_string($verificationReference, 191);
    if ($status === 'ignored' && $reason === '') throw new InvalidArgumentException('Ignored issues require a reason.');
    if (in_array($status, ['fixed-pending-verification', 'resolved'], true) && $verificationReference === '') throw new InvalidArgumentException('Verification evidence is required for this status.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT status FROM runtime_issues WHERE id = ? LIMIT 1');
        $stmt->execute([$issueId]);
        $from = $stmt->fetchColumn();
        if ($from === false) throw new RuntimeException('Issue not found.');
        if ($from !== $status) {
            $pdo->prepare('UPDATE runtime_issues SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$status, $issueId]);
            $pdo->prepare('INSERT INTO runtime_issue_status_history (issue_id, from_status, to_status, actor_user_id, reason, verification_reference) VALUES (?,?,?,?,?,?)')->execute([$issueId, $from, $status, $actorUserId, $reason ?: null, $verificationReference ?: null]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if ($status === 'resolved') runtime_issue_delete_screenshots_for_issue($pdo, $issueId, $actorUserId);
    return runtime_issue_detail($pdo, $issueId) ?? [];
}

function runtime_issue_private_root(): string
{
    $publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $configured = defined('CHATSPACE_PRIVATE_STORAGE_PATH') ? (string)CHATSPACE_PRIVATE_STORAGE_PATH : dirname($publicRoot) . DIRECTORY_SEPARATOR . 'chatspace-private';
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
    $public = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRoot), DIRECTORY_SEPARATOR));
    $candidate = strtolower($root);
    if ($root === '' || $candidate === $public || str_starts_with($candidate . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) throw new RuntimeException('Private diagnostic storage must be outside the public application root.');
    return $root . DIRECTORY_SEPARATOR . 'runtime-issue-screenshots';
}

function runtime_issue_store_screenshot(PDO $pdo, int $issueId, int $occurrenceId, int $ownerUserId, string $dataUrl): array
{
    if (app_setting($pdo, 'diagnostic_screenshots_enabled', '0') !== '1') throw new RuntimeException('Diagnostic screenshots are disabled.');
    $retention = (int)app_setting($pdo, 'diagnostic_screenshot_retention_days', '0');
    if ($retention < 1 || $retention > 365) throw new RuntimeException('Choose screenshot retention before enabling capture.');
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) throw new InvalidArgumentException('Only censored PNG screenshots are accepted.');
    $bytes = base64_decode($match[1], true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > RUNTIME_ISSUE_MAX_SCREENSHOT_BYTES) throw new InvalidArgumentException('Screenshot exceeds the allowed size.');
    $size = @getimagesizefromstring($bytes);
    if (!$size || ($size['mime'] ?? '') !== 'image/png') throw new InvalidArgumentException('Screenshot is not a valid PNG.');
    [$width, $height] = array_map('intval', $size);
    if ($width < 1 || $height < 1 || $width > RUNTIME_ISSUE_MAX_SCREENSHOT_WIDTH || $height > RUNTIME_ISSUE_MAX_SCREENSHOT_HEIGHT) throw new InvalidArgumentException('Screenshot dimensions are outside the allowed range.');
    $recent = $pdo->prepare('SELECT COUNT(*) FROM runtime_issue_screenshots WHERE owner_user_id = ? AND created_at >= ?');
    $recent->execute([$ownerUserId, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$recent->fetchColumn() >= 3) throw new RuntimeException('Diagnostic screenshot rate limit reached.');
    $check = $pdo->prepare('SELECT id FROM runtime_issue_occurrences WHERE id = ? AND issue_id = ? AND reporter_user_id = ? LIMIT 1');
    $check->execute([$occurrenceId, $issueId, $ownerUserId]);
    if (!$check->fetchColumn()) throw new InvalidArgumentException('Screenshot occurrence does not belong to the issue.');
    $directory = runtime_issue_private_root();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Could not create private diagnostic storage.');
    $publicId = bin2hex(random_bytes(16));
    $storageName = bin2hex(random_bytes(24)) . '.png';
    $path = $directory . DIRECTORY_SEPARATOR . $storageName;
    if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Could not store censored screenshot.');
    try {
        $pdo->prepare('INSERT INTO runtime_issue_screenshots (public_id, issue_id, occurrence_id, owner_user_id, storage_name, mime_type, width, height, byte_size) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$publicId, $issueId, $occurrenceId, $ownerUserId, $storageName, 'image/png', $width, $height, strlen($bytes)]);
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
    return ['publicId' => $publicId, 'width' => $width, 'height' => $height, 'byteSize' => strlen($bytes)];
}

function runtime_issue_screenshot_record(PDO $pdo, string $publicId): ?array
{
    $stmt = $pdo->prepare('SELECT s.*, i.status AS issue_status FROM runtime_issue_screenshots s JOIN runtime_issues i ON i.id = s.issue_id WHERE s.public_id = ? AND s.deleted_at IS NULL LIMIT 1');
    $stmt->execute([$publicId]);
    return $stmt->fetch() ?: null;
}

function runtime_issue_delete_screenshot(PDO $pdo, string $publicId, int $actorUserId, bool $isAdmin): bool
{
    $record = runtime_issue_screenshot_record($pdo, $publicId);
    if (!$record) return false;
    if (!$isAdmin && (int)$record['owner_user_id'] !== $actorUserId) throw new RuntimeException('Screenshot deletion is not authorized.');
    $path = runtime_issue_private_root() . DIRECTORY_SEPARATOR . basename((string)$record['storage_name']);
    if (is_file($path)) @unlink($path);
    $pdo->prepare('UPDATE runtime_issue_screenshots SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int)$record['id']]);
    return true;
}

function runtime_issue_delete_screenshots_for_issue(PDO $pdo, int $issueId, int $actorUserId): void
{
    $stmt = $pdo->prepare('SELECT public_id FROM runtime_issue_screenshots WHERE issue_id = ? AND deleted_at IS NULL');
    $stmt->execute([$issueId]);
    foreach ($stmt->fetchAll() as $row) runtime_issue_delete_screenshot($pdo, (string)$row['public_id'], $actorUserId, true);
}

function runtime_issue_cleanup_screenshots(PDO $pdo): int
{
    $days = (int)app_setting($pdo, 'diagnostic_screenshot_retention_days', '0');
    if ($days < 1 || $days > 365) return 0;
    $stmt = $pdo->prepare('SELECT public_id FROM runtime_issue_screenshots WHERE deleted_at IS NULL AND created_at < ?');
    $stmt->execute([gmdate('Y-m-d H:i:s', time() - ($days * 86400))]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) if (runtime_issue_delete_screenshot($pdo, (string)$row['public_id'], 0, true)) $count++;
    return $count;
}

function runtime_issue_support_bundle(PDO $pdo, int $issueId): array
{
    $detail = runtime_issue_detail($pdo, $issueId);
    if (!$detail) throw new RuntimeException('Issue not found.');
    return ['schemaId' => 'chatspace.runtime-issue-support-bundle', 'schemaVersion' => 1, 'generatedAt' => gmdate('c'), 'privacy' => ['chatContentsIncluded' => false, 'credentialsIncluded' => false, 'sdpIceIncluded' => false, 'rawMediaIncluded' => false, 'screenshotsIncluded' => false], 'issue' => $detail['issue'], 'occurrences' => $detail['occurrences'], 'history' => $detail['history'], 'screenshotMetadata' => $detail['screenshots']];
}

function runtime_issue_install_server_capture(): void
{
    static $installed = false;
    if ($installed) return;
    $installed = true;
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if (!$error || !in_array((int)($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) return;
        try {
            runtime_issue_submit(db(), $userId, [
                'category' => 'server',
                'component' => basename((string)($_SERVER['SCRIPT_NAME'] ?? 'php-runtime')),
                'error_code' => 'PHP_FATAL_' . (int)$error['type'],
                'message' => (string)($error['message'] ?? 'Fatal PHP failure'),
                'severity' => 'critical',
                'evidence' => ['errorType' => (int)$error['type'], 'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')],
            ]);
        } catch (Throwable) {
            // Diagnostics must never replace or suppress the original fatal failure.
        }
    });
}
