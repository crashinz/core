<?php
declare(strict_types=1);

/**
 * Build 000049 authoritative request rate-limit owner.
 *
 * Login, recovery, database-update, gesture, and outside-content callers retain
 * their stable function APIs. This owner uses the caller's PDO and does not
 * acquire connection, transaction, request-authorization, or response
 * ownership.
 */

function client_ip_address(): string {
    if (function_exists('network_privacy_client_ip')) {
        return network_privacy_client_ip();
    }
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
}

function auth_rate_seconds(int $seconds): string {
    $seconds = max(1, $seconds);
    if ($seconds < 60) return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    $hours = (int)ceil($minutes / 60);
    return $hours . ' hour' . ($hours === 1 ? '' : 's');
}

function auth_rate_scope_max(PDO $pdo, string $scope): int {
    if (str_starts_with($scope, 'gesture:')) {
        return max(1, (int)app_setting_float($pdo, 'gesture_mutation_rate_limit', 120));
    }
    if (str_starts_with($scope, 'outside:')) {
        return max(1, (int)app_setting_float($pdo, 'outside_content_rate_limit', 60));
    }
    $key = $scope === 'recovery' ? 'auth_recovery_max_attempts' : 'auth_login_max_attempts';
    return max(1, (int)app_setting_float($pdo, $key, $scope === 'recovery' ? 5 : 5));
}

function auth_rate_ip_max(PDO $pdo, string $scope = ''): int {
    if (str_starts_with($scope, 'gesture:')) {
        return max(1, (int)app_setting_float($pdo, 'gesture_mutation_ip_rate_limit', 600));
    }
    if (str_starts_with($scope, 'outside:')) {
        return max(1, (int)app_setting_float($pdo, 'outside_content_ip_rate_limit', 300));
    }
    return max(1, (int)app_setting_float($pdo, 'auth_ip_max_attempts', 30));
}

function auth_rate_window_minutes(PDO $pdo): float {
    return max(1.0, app_setting_float($pdo, 'auth_attempt_window_minutes', 15));
}

function auth_rate_lockout_minutes(PDO $pdo): float {
    return max(1.0, app_setting_float($pdo, 'auth_lockout_minutes', 15));
}

function auth_rate_key_hash(string $scope, string $dimension, string $value): string {
    $normalized = strtolower(trim($scope)) . "\n" . strtolower(trim($dimension)) . "\n" . strtolower(trim($value));
    return hash('sha256', $normalized);
}

function auth_rate_keys(string $scope, string $identifier): array {
    $identifier = trim($identifier) !== '' ? trim($identifier) : '(blank)';
    return [
        ['dimension' => 'identifier', 'hash' => auth_rate_key_hash($scope, 'identifier', $identifier)],
        ['dimension' => 'ip', 'hash' => auth_rate_key_hash($scope, 'ip', client_ip_address())],
    ];
}

function auth_rate_database_storage_available(PDO $pdo): bool {
    return function_exists('database_migration_table_exists')
        && database_migration_table_exists($pdo, 'auth_attempts');
}

function auth_rate_private_path(string $scope, string $dimension, string $keyHash): string {
    $directory = security_private_storage_directory('pre-migration-auth-rate-limits');
    $identity = hash('sha256', strtolower(trim($scope)) . "\n" . $dimension . "\n" . $keyHash);
    return $directory . DIRECTORY_SEPARATOR . $identity . '.json';
}

function auth_rate_private_read(string $path): array {
    if (!is_file($path)) return [];
    $handle = fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        $bytes = stream_get_contents($handle, 4097);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
    if (!is_string($bytes) || strlen($bytes) > 4096) {
        throw new RuntimeException('Private authentication rate-limit state is invalid.');
    }
    $decoded = $bytes === '' ? [] : json_decode($bytes, true);
    if (!is_array($decoded)) throw new RuntimeException('Private authentication rate-limit state is invalid.');
    return $decoded;
}

function auth_rate_private_write(string $path, array $state): void {
    $bytes = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($bytes) || strlen($bytes) > 4096) {
        throw new RuntimeException('Private authentication rate-limit state exceeded its bounded format.');
    }
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            throw new RuntimeException('Private authentication rate-limit state could not be replaced.');
        }
        $written = fwrite($handle, $bytes);
        if ($written !== strlen($bytes) || !fflush($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be persisted.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be synchronized.');
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function auth_rate_private_increment(
    string $path,
    int $windowCutoff,
    int $now,
    int $lockedUntil,
    int $max
): void {
    $handle = fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Private authentication rate-limit state is unavailable.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Private authentication rate-limit state could not be locked.');
        $bytes = stream_get_contents($handle, 4097);
        if (!is_string($bytes) || strlen($bytes) > 4096) {
            throw new RuntimeException('Private authentication rate-limit state is invalid.');
        }
        $state = $bytes === '' ? [] : json_decode($bytes, true);
        if (!is_array($state)) throw new RuntimeException('Private authentication rate-limit state is invalid.');
        $attempts = (int)($state['last_attempt_at'] ?? 0) >= $windowCutoff
            ? ((int)($state['attempts'] ?? 0)) + 1
            : 1;
        $replacement = json_encode([
            'attempts' => $attempts,
            'last_attempt_at' => $now,
            'locked_until' => $attempts >= $max ? $lockedUntil : 0,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($replacement) || strlen($replacement) > 4096) {
            throw new RuntimeException('Private authentication rate-limit state exceeded its bounded format.');
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            throw new RuntimeException('Private authentication rate-limit state could not be replaced.');
        }
        $written = fwrite($handle, $replacement);
        if ($written !== strlen($replacement) || !fflush($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be persisted.');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('Private authentication rate-limit state could not be synchronized.');
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function auth_rate_private_status(string $scope, string $identifier): array {
    $now = time();
    $blockedUntil = 0;
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $state = auth_rate_private_read(
            auth_rate_private_path($scope, (string)$key['dimension'], (string)$key['hash'])
        );
        $blockedUntil = max($blockedUntil, (int)($state['locked_until'] ?? 0));
    }
    if ($blockedUntil > $now) {
        return [
            'allowed' => false,
            'retry_after' => $blockedUntil - $now,
            'message' => 'Too many attempts. Try again in ' . auth_rate_seconds($blockedUntil - $now) . '.',
        ];
    }
    return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
}

function auth_rate_private_record_failure(PDO $pdo, string $scope, string $identifier): void {
    $now = time();
    $windowCutoff = $now - (int)ceil(auth_rate_window_minutes($pdo) * 60);
    $lockedUntil = $now + (int)ceil(auth_rate_lockout_minutes($pdo) * 60);
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $path = auth_rate_private_path($scope, (string)$key['dimension'], (string)$key['hash']);
        $max = $key['dimension'] === 'ip'
            ? auth_rate_ip_max($pdo, $scope)
            : auth_rate_scope_max($pdo, $scope);
        auth_rate_private_increment($path, $windowCutoff, $now, $lockedUntil, $max);
    }
}

function auth_rate_cleanup(PDO $pdo): void {
    $windowMinutes = auth_rate_window_minutes($pdo);
    $cutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil($windowMinutes * 60));
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('DELETE FROM auth_attempts WHERE last_attempt_at < ? AND (locked_until IS NULL OR locked_until < ?)');
    $stmt->execute([$cutoff, $now]);
}

function auth_rate_limit_status(PDO $pdo, string $scope, string $identifier): array {
    if (!auth_rate_database_storage_available($pdo)) {
        return auth_rate_private_status($scope, $identifier);
    }
    auth_rate_cleanup($pdo);
    $now = time();
    $blockedUntil = 0;
    $stmt = $pdo->prepare('SELECT dimension, locked_until FROM auth_attempts WHERE scope = ? AND dimension = ? AND key_hash = ? LIMIT 1');
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $stmt->execute([$scope, $key['dimension'], $key['hash']]);
        $row = $stmt->fetch();
        if (!$row || empty($row['locked_until'])) continue;
        $until = strtotime((string)$row['locked_until']) ?: 0;
        if ($until > $blockedUntil) $blockedUntil = $until;
    }
    if ($blockedUntil > $now) {
        return [
            'allowed' => false,
            'retry_after' => $blockedUntil - $now,
            'message' => 'Too many attempts. Try again in ' . auth_rate_seconds($blockedUntil - $now) . '.',
        ];
    }
    return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
}

function auth_rate_record_failure(PDO $pdo, string $scope, string $identifier): void {
    if (!auth_rate_database_storage_available($pdo)) {
        auth_rate_private_record_failure($pdo, $scope, $identifier);
        return;
    }
    $now = gmdate('Y-m-d H:i:s');
    $windowCutoff = gmdate('Y-m-d H:i:s', time() - (int)ceil(auth_rate_window_minutes($pdo) * 60));
    $lockedUntil = gmdate('Y-m-d H:i:s', time() + (int)ceil(auth_rate_lockout_minutes($pdo) * 60));
    $select = $pdo->prepare('SELECT attempts, last_attempt_at FROM auth_attempts WHERE scope = ? AND dimension = ? AND key_hash = ? LIMIT 1');
    $write = $pdo->prepare(db_uses_mysql_syntax($pdo)
        ? 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt_at = VALUES(last_attempt_at), locked_until = VALUES(locked_until)'
        : 'INSERT INTO auth_attempts (scope, dimension, key_hash, attempts, last_attempt_at, locked_until) VALUES (?,?,?,?,?,?) ON CONFLICT(scope, dimension, key_hash) DO UPDATE SET attempts = excluded.attempts, last_attempt_at = excluded.last_attempt_at, locked_until = excluded.locked_until'
    );
    foreach (auth_rate_keys($scope, $identifier) as $key) {
        $select->execute([$scope, $key['dimension'], $key['hash']]);
        $row = $select->fetch();
        $attempts = 1;
        if ($row && (string)($row['last_attempt_at'] ?? '') >= $windowCutoff) {
            $attempts = ((int)$row['attempts']) + 1;
        }
        $max = $key['dimension'] === 'ip' ? auth_rate_ip_max($pdo, $scope) : auth_rate_scope_max($pdo, $scope);
        $lock = $attempts >= $max ? $lockedUntil : null;
        $write->execute([$scope, $key['dimension'], $key['hash'], $attempts, $now, $lock]);
    }
}

function auth_rate_clear_identifier(PDO $pdo, string $scope, string $identifier): void {
    $hash = auth_rate_key_hash($scope, 'identifier', trim($identifier) !== '' ? trim($identifier) : '(blank)');
    if (!auth_rate_database_storage_available($pdo)) {
        auth_rate_private_write(
            auth_rate_private_path($scope, 'identifier', $hash),
            ['attempts' => 0, 'last_attempt_at' => 0, 'locked_until' => 0]
        );
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM auth_attempts WHERE scope = ? AND dimension = 'identifier' AND key_hash = ?");
    $stmt->execute([$scope, $hash]);
}
