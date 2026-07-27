<?php
declare(strict_types=1);

/**
 * Installation-private owner for the optional proactive database/release
 * compatibility gate.
 *
 * The policy deliberately lives outside the configured database so replacing
 * or migrating that database cannot reset the owner's choice. Invalid or
 * unreadable state always resolves to the safe product default: Disabled.
 */

const DATABASE_COMPATIBILITY_POLICY_SCHEMA = 'corechat-database-compatibility-policy-v1';
const DATABASE_COMPATIBILITY_POLICY_MAX_BYTES = 65536;
const DATABASE_COMPATIBILITY_POLICY_LOG_MAX_BYTES = 4194304;

function database_compatibility_policy_root(): string
{
    return security_private_storage_directory('database-compatibility-policy');
}

function database_compatibility_policy_path(): string
{
    return database_compatibility_policy_root() . DIRECTORY_SEPARATOR . 'policy.json';
}

function database_compatibility_policy_default_status(
    string $diagnosticCode = 'DATABASE_COMPATIBILITY_POLICY_UNINITIALIZED'
): array {
    return [
        'schema' => DATABASE_COMPATIBILITY_POLICY_SCHEMA,
        'revision' => 0,
        'enabled' => false,
        'initialized' => false,
        'valid' => true,
        'diagnosticCode' => $diagnosticCode,
        'updatedAt' => null,
        'lastRequestId' => null,
    ];
}

function database_compatibility_policy_status(): array
{
    $path = database_compatibility_policy_path();
    if (!is_file($path)) return database_compatibility_policy_default_status();

    clearstatcache(true, $path);
    $size = filesize($path);
    if (!is_int($size) || $size < 2 || $size > DATABASE_COMPATIBILITY_POLICY_MAX_BYTES) {
        return array_replace(
            database_compatibility_policy_default_status('DATABASE_COMPATIBILITY_POLICY_SIZE_INVALID'),
            ['valid' => false]
        );
    }
    $json = file_get_contents($path);
    if (!is_string($json) || strlen($json) !== $size) {
        return array_replace(
            database_compatibility_policy_default_status('DATABASE_COMPATIBILITY_POLICY_UNREADABLE'),
            ['valid' => false]
        );
    }
    try {
        $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return array_replace(
            database_compatibility_policy_default_status('DATABASE_COMPATIBILITY_POLICY_JSON_INVALID'),
            ['valid' => false]
        );
    }
    if (!is_array($state)
        || ($state['schema'] ?? '') !== DATABASE_COMPATIBILITY_POLICY_SCHEMA
        || !is_int($state['revision'] ?? null)
        || (int)$state['revision'] < 1
        || !is_bool($state['enabled'] ?? null)
        || !is_string($state['updated_at'] ?? null)
        || !is_string($state['last_request_id'] ?? null)
        || !is_string($state['last_request_hash'] ?? null)
        || !preg_match('/^[a-f0-9-]{36}$/i', (string)$state['last_request_id'])
        || !preg_match('/^[A-F0-9]{64}$/', (string)$state['last_request_hash'])) {
        return array_replace(
            database_compatibility_policy_default_status('DATABASE_COMPATIBILITY_POLICY_SCHEMA_INVALID'),
            ['valid' => false]
        );
    }
    return [
        'schema' => DATABASE_COMPATIBILITY_POLICY_SCHEMA,
        'revision' => (int)$state['revision'],
        'enabled' => (bool)$state['enabled'],
        'initialized' => true,
        'valid' => true,
        'diagnosticCode' => null,
        'updatedAt' => (string)$state['updated_at'],
        'lastRequestId' => (string)$state['last_request_id'],
        '_lastRequestHash' => (string)$state['last_request_hash'],
    ];
}

function database_compatibility_policy_enabled(): bool
{
    return !empty(database_compatibility_policy_status()['enabled']);
}

function database_compatibility_policy_public_status(): array
{
    $status = database_compatibility_policy_status();
    return [
        'schemaId' => 'corechat.database-compatibility-policy',
        'schemaVersion' => 1,
        'revision' => (int)$status['revision'],
        'enabled' => (bool)$status['enabled'],
        'defaultEnabled' => false,
        'initialized' => (bool)$status['initialized'],
        'valid' => (bool)$status['valid'],
        'diagnosticCode' => $status['diagnosticCode'],
        'updatedAt' => $status['updatedAt'],
    ];
}

function database_compatibility_policy_request_hash(bool $enabled, bool $restoreDefault): string
{
    return strtoupper(hash(
        'sha256',
        database_migrations_canonical_json([
            'schema' => 'corechat-database-compatibility-policy-request-v1',
            'enabled' => $enabled,
            'restore_default' => $restoreDefault,
        ])
    ));
}

function database_compatibility_policy_validate_request_id(string $requestId): string
{
    $requestId = strtolower(trim($requestId));
    if (!preg_match('/^[a-f0-9-]{36}$/', $requestId)) {
        throw new CoreMigrationException(
            'A valid durable request identity is required.',
            'DATABASE_COMPATIBILITY_POLICY_REQUEST_ID_REQUIRED',
            400
        );
    }
    return $requestId;
}

function database_compatibility_policy_claim(PDO $pdo): array
{
    $recoveryClaim = database_recovery_claim($pdo);
    $handle = null;
    try {
        $path = database_compatibility_policy_root() . DIRECTORY_SEPARATOR . 'policy.lock';
        $handle = fopen($path, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new CoreMigrationException(
                'Another compatibility-policy owner is active.',
                'DATABASE_COMPATIBILITY_POLICY_ALREADY_ACTIVE',
                409
            );
        }
        return ['policy_handle' => $handle, 'recovery_claim' => $recoveryClaim];
    } catch (Throwable $error) {
        database_recovery_release_claim($recoveryClaim);
        throw $error;
    }
}

function database_compatibility_policy_release_claim(array $claim): void
{
    $handle = $claim['policy_handle'] ?? null;
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    database_recovery_release_claim((array)($claim['recovery_claim'] ?? []));
}

function database_compatibility_policy_previous_bytes(): array
{
    $path = database_compatibility_policy_path();
    if (!is_file($path)) return ['existed' => false, 'content' => null];
    clearstatcache(true, $path);
    $size = filesize($path);
    if (!is_int($size) || $size < 2 || $size > DATABASE_COMPATIBILITY_POLICY_MAX_BYTES) {
        throw new CoreMigrationException(
            'The invalid compatibility-policy state cannot be repaired automatically within its bounded size contract.',
            'DATABASE_COMPATIBILITY_POLICY_RECOVERY_REQUIRED',
            409
        );
    }
    $content = file_get_contents($path);
    if (!is_string($content) || strlen($content) !== $size) {
        throw new CoreMigrationException(
            'The compatibility-policy state is unreadable and cannot be repaired automatically.',
            'DATABASE_COMPATIBILITY_POLICY_RECOVERY_REQUIRED',
            409
        );
    }
    return ['existed' => true, 'content' => $content];
}

function database_compatibility_policy_log(
    string $action,
    int $revision,
    bool $enabled,
    int $actorUserId,
    string $source,
    string $requestHash
): void {
    $event = [
        'schema' => 'corechat-database-compatibility-policy-tool-log-v1',
        'event_id' => uuid_v4(),
        'created_at' => gmdate('c'),
        'action' => $action,
        'revision' => $revision,
        'enabled' => $enabled,
        'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
        'source' => substr(preg_replace('/[^a-z0-9._-]/i', '', $source) ?? '', 0, 48),
        'request_sha256' => $requestHash,
    ];
    $line = database_recovery_canonical_json($event);
    if (strlen($line) > 4096) {
        throw new CoreMigrationException(
            'Compatibility-policy Tool Log record exceeds its bounded size.',
            'DATABASE_COMPATIBILITY_POLICY_LOG_INVALID',
            500
        );
    }
    $path = database_compatibility_policy_root() . DIRECTORY_SEPARATOR . 'events.jsonl';
    $existing = '';
    if (is_file($path)) {
        clearstatcache(true, $path);
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > DATABASE_COMPATIBILITY_POLICY_LOG_MAX_BYTES) {
            throw new CoreMigrationException(
                'Compatibility-policy Tool Log requires bounded owner recovery.',
                'DATABASE_COMPATIBILITY_POLICY_LOG_RECOVERY_REQUIRED',
                409
            );
        }
        $existing = file_get_contents($path);
        if (!is_string($existing) || strlen($existing) !== $size) {
            throw new CoreMigrationException(
                'Compatibility-policy Tool Log is unavailable.',
                'DATABASE_COMPATIBILITY_POLICY_LOG_UNAVAILABLE',
                500
            );
        }
    }
    if (strlen($existing) + strlen($line) > DATABASE_COMPATIBILITY_POLICY_LOG_MAX_BYTES) {
        throw new CoreMigrationException(
            'Compatibility-policy Tool Log requires bounded owner recovery.',
            'DATABASE_COMPATIBILITY_POLICY_LOG_RECOVERY_REQUIRED',
            409
        );
    }
    database_recovery_atomic_write($path, $existing . $line);
    @chmod($path, 0600);
}

function database_compatibility_policy_restore_previous(array $previous): void
{
    $path = database_compatibility_policy_path();
    if (!empty($previous['existed'])) {
        database_recovery_atomic_write($path, (string)($previous['content'] ?? ''));
    } elseif (is_file($path) && !unlink($path)) {
        throw new CoreMigrationException(
            'The compatibility-policy rollback could not remove newly initialized state.',
            'DATABASE_COMPATIBILITY_POLICY_ROLLBACK_FAILED',
            500
        );
    }
}

function database_compatibility_policy_begin_update(
    PDO $pdo,
    bool $enabled,
    int $expectedRevision,
    bool $disableConfirmed,
    bool $restoreDefault,
    int $actorUserId,
    string $requestId,
    string $source
): array {
    $requestId = database_compatibility_policy_validate_request_id($requestId);
    $requestHash = database_compatibility_policy_request_hash($enabled, $restoreDefault);
    $claim = database_compatibility_policy_claim($pdo);
    $previous = null;
    $stateWritten = false;
    try {
        $status = database_compatibility_policy_status();
        if (!empty($status['initialized'])
            && is_string($status['lastRequestId'])
            && hash_equals((string)$status['lastRequestId'], $requestId)) {
            if (!hash_equals((string)($status['_lastRequestHash'] ?? ''), $requestHash)) {
                throw new CoreMigrationException(
                    'That request identity was already used for a different policy transition.',
                    'DATABASE_COMPATIBILITY_POLICY_REQUEST_CONFLICT',
                    409
                );
            }
            return [
                'claim' => $claim,
                'previous' => database_compatibility_policy_previous_bytes(),
                'changed' => false,
                'idempotent' => true,
                'status' => database_compatibility_policy_public_status(),
            ];
        }
        if ((int)$status['revision'] !== $expectedRevision) {
            throw new CoreMigrationException(
                'The compatibility policy changed. Refresh and try again.',
                'DATABASE_COMPATIBILITY_POLICY_STALE',
                409
            );
        }
        if (!empty($status['enabled']) && !$enabled && !$restoreDefault && !$disableConfirmed) {
            throw new CoreMigrationException(
                'Disabling proactive compatibility enforcement can expose ordinary runtime failures when application files and the database do not match. Review and confirm this risk.',
                'DATABASE_COMPATIBILITY_DISABLE_CONFIRMATION_REQUIRED',
                409
            );
        }

        $previous = database_compatibility_policy_previous_bytes();
        $next = [
            'schema' => DATABASE_COMPATIBILITY_POLICY_SCHEMA,
            'revision' => (int)$status['revision'] + 1,
            'enabled' => $enabled,
            'updated_at' => gmdate('c'),
            'last_request_id' => $requestId,
            'last_request_hash' => $requestHash,
            'last_actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'last_source' => substr(preg_replace('/[^a-z0-9._-]/i', '', $source) ?? '', 0, 48),
            'last_operation' => $restoreDefault ? 'restore-default' : 'set',
        ];
        database_recovery_atomic_write(
            database_compatibility_policy_path(),
            database_recovery_canonical_json($next)
        );
        $stateWritten = true;
        database_compatibility_policy_log(
            $restoreDefault ? 'restore-default' : 'set',
            (int)$next['revision'],
            $enabled,
            $actorUserId,
            $source,
            $requestHash
        );
        return [
            'claim' => $claim,
            'previous' => $previous,
            'changed' => true,
            'idempotent' => false,
            'status' => database_compatibility_policy_public_status(),
        ];
    } catch (Throwable $error) {
        if ($stateWritten && is_array($previous)) {
            try {
                database_compatibility_policy_restore_previous($previous);
            } catch (Throwable $rollbackError) {
                database_compatibility_policy_release_claim($claim);
                throw $rollbackError;
            }
        }
        database_compatibility_policy_release_claim($claim);
        throw $error;
    }
}

function database_compatibility_policy_commit_update(array $transaction): void
{
    database_compatibility_policy_release_claim((array)($transaction['claim'] ?? []));
}

function database_compatibility_policy_rollback_update(array $transaction): void
{
    try {
        if (!empty($transaction['changed'])) {
            $previous = (array)($transaction['previous'] ?? []);
            database_compatibility_policy_restore_previous($previous);
            $status = database_compatibility_policy_status();
            database_compatibility_policy_log(
                'rollback',
                (int)$status['revision'],
                (bool)$status['enabled'],
                0,
                'transaction-rollback',
                str_repeat('0', 64)
            );
        }
    } finally {
        database_compatibility_policy_release_claim((array)($transaction['claim'] ?? []));
    }
}

function database_compatibility_policy_update(
    PDO $pdo,
    bool $enabled,
    int $expectedRevision,
    bool $disableConfirmed,
    bool $restoreDefault,
    int $actorUserId,
    string $requestId,
    string $source
): array {
    $transaction = database_compatibility_policy_begin_update(
        $pdo,
        $enabled,
        $expectedRevision,
        $disableConfirmed,
        $restoreDefault,
        $actorUserId,
        $requestId,
        $source
    );
    database_compatibility_policy_commit_update($transaction);
    return [
        'ok' => true,
        'idempotent' => !empty($transaction['idempotent']),
        'policy' => $transaction['status'],
    ];
}
