<?php
declare(strict_types=1);

const CHATSPACE_MEDIA_CLIENT_LEASE_SECONDS = 30;
const CHATSPACE_MEDIA_CLIENT_REFRESH_SECONDS = 20;

function media_client_epoch(mixed $value): string
{
    $epoch = trim((string)$value);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,95}$/', $epoch)) {
        json_out(['error' => 'Invalid media client epoch'], 400);
    }
    return $epoch;
}

function media_signal_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function media_signal_expiry(): string
{
    return gmdate('Y-m-d H:i:s', time() + 600);
}

function media_signal_register_client(PDO $pdo, int $sessionId, int $participantId, string $clientEpoch): array
{
    $now = media_signal_now();
    $refreshBefore = gmdate('Y-m-d H:i:s', time() - CHATSPACE_MEDIA_CLIENT_REFRESH_SECONDS);
    $select = $pdo->prepare(
        'SELECT client_epoch, started_at, updated_at
           FROM media_signal_clients
          WHERE participant_id = ? AND session_id = ? LIMIT 1'
    );
    $select->execute([$participantId, $sessionId]);
    $current = $select->fetch() ?: null;
    if ($current
        && hash_equals((string)$current['client_epoch'], $clientEpoch)
        && (string)$current['updated_at'] > $refreshBefore) {
        return ['epoch' => $clientEpoch, 'started_at' => (string)$current['started_at'], 'refreshed' => false];
    }

    return db_with_sqlite_lock_retry($pdo, function () use (
        $pdo, $sessionId, $participantId, $clientEpoch, $now, $current, $select
    ): array {
        $startedAt = $current && hash_equals((string)$current['client_epoch'], $clientEpoch)
            ? (string)$current['started_at']
            : $now;
        if (!$current) {
            try {
                $pdo->prepare(
                    'INSERT INTO media_signal_clients
                        (participant_id, session_id, client_epoch, started_at, updated_at)
                     VALUES (?,?,?,?,?)'
                )->execute([$participantId, $sessionId, $clientEpoch, $startedAt, $now]);
                return ['epoch' => $clientEpoch, 'started_at' => $startedAt, 'refreshed' => true];
            } catch (PDOException $error) {
                if (!in_array((string)$error->getCode(), ['19', '23000'], true)) throw $error;
            }
        } else {
            $update = $pdo->prepare(
                'UPDATE media_signal_clients
                    SET client_epoch = ?, started_at = ?, updated_at = ?
                  WHERE participant_id = ? AND session_id = ?
                    AND client_epoch = ? AND updated_at = ?'
            );
            $update->execute([
                $clientEpoch, $startedAt, $now, $participantId, $sessionId,
                (string)$current['client_epoch'], (string)$current['updated_at'],
            ]);
            if ($update->rowCount() === 1) {
                return ['epoch' => $clientEpoch, 'started_at' => $startedAt, 'refreshed' => true];
            }
        }

        $select->execute([$participantId, $sessionId]);
        $resolved = $select->fetch() ?: null;
        if (!$resolved) throw new RuntimeException('Media client lease could not be established.');
        return [
            'epoch' => (string)$resolved['client_epoch'],
            'started_at' => (string)$resolved['started_at'],
            'refreshed' => false,
        ];
    }, 'media-client-lease');
}

function media_signal_recipient_epoch(PDO $pdo, int $sessionId, int $participantId): ?string
{
    if ($participantId <= 0) return null;
    $stmt = $pdo->prepare(
        'SELECT client_epoch FROM media_signal_clients
          WHERE participant_id = ? AND session_id = ? AND updated_at >= ? LIMIT 1'
    );
    $stmt->execute([$participantId, $sessionId, gmdate('Y-m-d H:i:s', time() - CHATSPACE_MEDIA_CLIENT_LEASE_SECONDS)]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

function media_signal_insert(PDO $pdo, int $sessionId, string $media, int $from, int $to, string $type, mixed $data, ?string $senderEpoch = null): array
{
    $recipientEpoch = media_signal_recipient_epoch($pdo, $sessionId, $to);
    $pdo->prepare(
        'INSERT INTO media_signals
            (session_id, media, from_participant_id, to_participant_id, sender_epoch, recipient_epoch, type, data, expires_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $sessionId, $media, $from, $to, $senderEpoch, $recipientEpoch, $type,
        json_encode($data, JSON_UNESCAPED_SLASHES), media_signal_expiry(),
    ]);
    return ['signal_id' => (int)$pdo->lastInsertId(), 'recipient_epoch' => $recipientEpoch];
}

function media_signal_cleanup_expired(PDO $pdo, ?int $sessionId = null): array
{
    $signalSql = 'DELETE FROM media_signals WHERE expires_at IS NOT NULL AND expires_at < ?';
    $clientSql = 'DELETE FROM media_signal_clients WHERE updated_at < ?';
    $signalArgs = [media_signal_now()];
    $clientArgs = [gmdate('Y-m-d H:i:s', time() - 86400)];
    if ($sessionId !== null) {
        $signalSql .= ' AND session_id = ?';
        $clientSql .= ' AND session_id = ?';
        $signalArgs[] = $sessionId;
        $clientArgs[] = $sessionId;
    }
    $signals = $pdo->prepare($signalSql);
    $signals->execute($signalArgs);
    $clients = $pdo->prepare($clientSql);
    $clients->execute($clientArgs);
    return ['signals' => $signals->rowCount(), 'clients' => $clients->rowCount()];
}
