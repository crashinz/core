<?php
declare(strict_types=1);

/** Bounded, best-effort recent evidence. Aggregate counts remain authoritative. */
function limit_event_sample_metadata(PDO $pdo, string $fingerprint, string $settingId, string $outcome, string $scope, array $metadata): string {
    $safe = json_decode(limit_event_safe_metadata($metadata), true) ?: [];
    if (str_contains($settingId, 'idle') || !in_array($outcome, ['warning','warned','blocked','rejected','throttled'], true)) return json_encode($safe);
    try {
        $statement = $pdo->prepare('SELECT metadata_json FROM limit_events WHERE fingerprint=? LIMIT 1');
        $statement->execute([$fingerprint]);
        $previous = json_decode((string)$statement->fetchColumn(), true) ?: [];
        $samples = array_slice(is_array($previous['_recent_samples'] ?? null) ? $previous['_recent_samples'] : [], -11);
        $actorId = preg_match('/^user:(\d+)$/D', $scope, $match) ? (int)$match[1] : (int)($_SESSION['user_id'] ?? 0);
        $samples[] = ['time' => gmdate('Y-m-d H:i:s') . ' UTC', 'userId' => max(0, $actorId), 'measurements' => $safe];
        $safe['_recent_samples'] = $samples;
        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded) && strlen($encoded) <= LIMIT_EVENT_MAX_METADATA_BYTES) return $encoded;
    } catch (Throwable) {}
    return limit_event_safe_metadata($metadata);
}

function limit_event_details(PDO $pdo, string $publicId): array {
    $statement = $pdo->prepare('SELECT * FROM limit_events WHERE public_id=? AND deleted_at IS NULL LIMIT 1');
    $statement->execute([$publicId]);
    $row = $statement->fetch();
    if (!$row) return ['error' => 'Limit event not found.'];
    $metadata = json_decode((string)$row['metadata_json'], true) ?: [];
    $samples = [];
    if (!str_contains((string)$row['setting_id'], 'idle')) {
        foreach (array_slice(is_array($metadata['_recent_samples'] ?? null) ? $metadata['_recent_samples'] : [], -12) as $sample) {
            if (!is_array($sample)) continue;
            $userId = max(0, (int)($sample['userId'] ?? 0));
            $name = '';
            if ($userId) {
                $user = $pdo->prepare('SELECT display_name,username FROM users WHERE id=? LIMIT 1');
                $user->execute([$userId]);
                $account = $user->fetch();
                $name = $account ? (string)($account['display_name'] ?: $account['username']) : 'Deleted account';
            }
            $samples[] = ['time' => limit_event_clean($sample['time'] ?? '', 40), 'user' => $name,
                'measurements' => json_decode(limit_event_safe_metadata(is_array($sample['measurements'] ?? null) ? $sample['measurements'] : []), true) ?: []];
        }
    }
    return ['limitName' => $row['limit_name'], 'occurrenceCount' => (int)$row['occurrence_count'],
        'explanation' => 'These are operational limit observations, not moderation warnings, strikes, or penalties. Slow requests describe server timing and do not establish member misconduct. Recent samples are bounded and may be incomplete during simultaneous requests; historical individual occurrences cannot be reconstructed from aggregate counts.',
        'latest' => ['Outcome' => $row['outcome'], 'Scope' => $row['scope_kind'], 'First reached' => $row['first_reached_at'], 'Last reached' => $row['last_reached_at'], 'Threshold' => json_decode($row['threshold_json'], true), 'Measurements' => json_decode(limit_event_safe_metadata($metadata), true)],
        'samples' => $samples];
}
