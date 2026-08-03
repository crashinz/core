<?php
declare(strict_types=1);

/**
 * Capability-restricted sanitized aggregate System Health projection.
 * This owner returns counts, bounded sizes, state labels and recommendations;
 * it never returns report/evidence content, identities, addresses or paths.
 */

function system_health_safe_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable) {
        return 0;
    }
}

function system_health_database_size(PDO $pdo): array
{
    try {
        if (db_driver($pdo) === 'sqlite') {
            $pages = (int)$pdo->query('PRAGMA page_count')->fetchColumn();
            $pageSize = (int)$pdo->query('PRAGMA page_size')->fetchColumn();
            return ['bytes' => max(0, $pages * $pageSize), 'method' => 'database-page-accounting'];
        }
        $bytes = $pdo->query(
            'SELECT COALESCE(SUM(data_length + index_length), 0)
               FROM information_schema.tables
              WHERE table_schema = DATABASE()'
        )->fetchColumn();
        return ['bytes' => max(0, (int)$bytes), 'method' => 'database-table-accounting'];
    } catch (Throwable) {
        return ['bytes' => null, 'method' => 'unavailable'];
    }
}

function system_health_private_storage_size(): array
{
    try {
        $root = runtime_issue_private_root();
        if (!is_dir($root)) return ['bytes' => 0, 'fileCount' => 0, 'bounded' => true];
        $bytes = 0;
        $count = 0;
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item->isFile()) continue;
            $count++;
            if ($count > 10000) return ['bytes' => $bytes, 'fileCount' => 10000, 'bounded' => false];
            $bytes += max(0, $item->getSize());
        }
        return ['bytes' => $bytes, 'fileCount' => $count, 'bounded' => true];
    } catch (Throwable) {
        return ['bytes' => null, 'fileCount' => null, 'bounded' => false];
    }
}

function system_health_migration(PDO $pdo): array
{
    try {
        $status = database_migration_status($pdo);
        return [
            'kind' => (string)$status['kind'],
            'current' => !empty($status['current']),
            'engine' => (string)$status['engine'],
            'storedSchemaVersion' => $status['stored_schema_version'],
            'requiredSchemaVersion' => $status['required_schema_version'],
            'pendingCount' => (int)$status['pending_count'],
            'releaseComplete' => !empty($status['release_complete']),
            'defectCount' => count((array)$status['defects']),
            'lockStatus' => (string)$status['lock_status'],
            'backupReady' => !empty($status['backup_readiness']['ok']),
        ];
    } catch (Throwable) {
        return [
            'kind' => 'unavailable', 'current' => false, 'engine' => db_driver($pdo),
            'storedSchemaVersion' => null, 'requiredSchemaVersion' => CHATSPACE_SCHEMA_VERSION,
            'pendingCount' => null, 'releaseComplete' => false, 'defectCount' => null,
            'lockStatus' => 'unknown', 'backupReady' => false,
        ];
    }
}

function system_health_projection(PDO $pdo): array
{
    $capacity = operational_capacity_projection($pdo);
    $capabilities = host_capabilities_public_projection(host_capabilities($pdo));
    $transport = transport_policy_projection($pdo);
    $diagnosticPolicy = runtime_diagnostic_policy_projection($pdo);
    $retention = runtime_diagnostic_retention_projection($pdo);
    $issueCounts = [
        'total' => system_health_safe_count($pdo, 'SELECT COUNT(*) FROM runtime_issues'),
        'unresolved' => system_health_safe_count(
            $pdo,
            "SELECT COUNT(*) FROM runtime_issues WHERE status NOT IN ('resolved', 'expected', 'ignored')"
        ),
        'critical' => system_health_safe_count(
            $pdo,
            "SELECT COUNT(*) FROM runtime_issues WHERE severity = 'critical' AND status NOT IN ('resolved', 'expected', 'ignored')"
        ),
        'screenshots' => system_health_safe_count(
            $pdo,
            'SELECT COUNT(*) FROM runtime_issue_screenshots WHERE deleted_at IS NULL'
        ),
        'recurrences' => system_health_safe_count(
            $pdo,
            'SELECT COALESCE(SUM(recurrence_count),0) FROM runtime_issues'
        ),
        'regressed' => system_health_safe_count(
            $pdo,
            "SELECT COUNT(*) FROM runtime_issues WHERE status = 'regressed'"
        ),
        'held' => system_health_safe_count(
            $pdo,
            'SELECT COUNT(*) FROM runtime_diagnostic_retention WHERE hold_active = 1'
        ),
    ];
    $active = operational_capacity_counts($pdo);
    $firstParty = first_party_extension_statuses($pdo);
    $enabledExtensions = count(array_filter($firstParty, static fn(array $row): bool => !empty($row['effectiveEnabled'])));
    $p2pTransfer = p2p_transfer_policy($pdo);
    $p2pProvenance = p2p_transfer_provenance($pdo, true);
    return [
        'schemaId' => 'chatspace.system-health',
        'schemaVersion' => 1,
        'generatedAt' => gmdate('c'),
        'build' => [
            'schemaVersion' => CHATSPACE_SCHEMA_VERSION,
            'migration' => system_health_migration($pdo),
        ],
        'capacity' => $capacity,
        'hostCapabilities' => $capabilities,
        'activity' => [
            'activeRooms' => $active['activeRooms'],
            'activeUsers' => $active['activeUsers'],
            'activeParticipants' => $active['activeParticipants'],
            'roomSessions' => system_health_safe_count($pdo, 'SELECT COUNT(*) FROM room_sessions'),
            'eventLedgerRows' => system_health_safe_count($pdo, 'SELECT COUNT(*) FROM server_events'),
            'pendingReports' => system_health_safe_count(
                $pdo,
                "SELECT COUNT(*) FROM moderation_reports WHERE status IN ('submitted', 'triaged', 'investigating')"
            ),
        ],
        'storage' => [
            'database' => system_health_database_size($pdo),
            'privateDiagnosticStorage' => system_health_private_storage_size(),
            'disk' => $capabilities['storage']['disk'],
            'pathsIncluded' => false,
        ],
        'operationalSignals' => [
            'slowRequestThresholdMs' => $capacity['values']['capacity_slow_request_ms'],
            'slowRequestCount' => 0,
            'retryCount' => 0,
            'delayCount' => 0,
            'pollLagStatus' => 'not separately persisted',
            'replayLagStatus' => 'not separately persisted',
            'configuredTransportMode' => $transport['configuredMode'],
            'configuredTransportModeLabel' => $transport['configuredModeLabel'],
            'selectedTransport' => $transport['selectedAdapter'],
            'activeTransport' => $transport['activeAdapter'],
            'fallbackAdapter' => $transport['fallbackAdapter'],
            'fallbackReason' => $transport['fallbackReason'],
        ],
        'maintenance' => [
            'runtimeDiagnostics' => $retention,
            'orphanFiles' => ['status' => 'not detected by this bounded projection'],
            'backup' => ['ready' => system_health_migration($pdo)['backupReady']],
            'cleanupRunsOnOrdinaryGet' => false,
        ],
        'diagnostics' => [
            'policy' => $diagnosticPolicy,
            'issueCounts' => $issueCounts,
            'contentIncluded' => false,
        ],
        'extensions' => [
            'installedCount' => count($firstParty),
            'enabledCount' => $enabledExtensions,
            'items' => array_map(static fn(array $row): array => [
                'id' => (string)($row['id'] ?? ''),
                'label' => (string)($row['label'] ?? $row['id'] ?? ''),
                'enabled' => !empty($row['effectiveEnabled']),
            ], $firstParty),
        ],
        'components' => [
            [
                'id' => 'p2p-file-transfer',
                'label' => 'Direct file transfer',
                'status' => !empty($p2pTransfer['effectiveEnabled']) ? 'Enabled' : 'Disabled',
                'version' => (string)$p2pProvenance['adaptationVersion'],
                'manageLabel' => 'Manage in P2P Connections',
                'manageView' => 'voice-media-players',
                'manageSettingId' => 'file_transfer_source_version',
                'readOnly' => true,
                'mutationOwner' => false,
            ],
        ],
        'privacy' => [
            'reportOrEvidenceContentIncluded' => false,
            'diagnosticPayloadIncluded' => false,
            'screenshotsIncluded' => false,
            'rawNetworkAddressesIncluded' => false,
            'proxyChainsIncluded' => false,
            'credentialsOrConfigurationIncluded' => false,
            'filesystemPathsIncluded' => false,
            'databaseConnectionDetailsIncluded' => false,
            'memberIdentityOrMessagesIncluded' => false,
        ],
    ];
}
