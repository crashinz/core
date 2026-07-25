<?php
declare(strict_types=1);

/**
 * Build 000049 authoritative room/community event persistence owner.
 *
 * Callers retain semantic event ownership, payload selection, ordering, and
 * their surrounding transaction. This owner writes only through the passed
 * PDO and does not acquire connection or transaction ownership.
 */

function emit_event(PDO $pdo, int $sessionId, string $type, array $payload): void {
    $stmt = $pdo->prepare('INSERT INTO events (session_id, type, payload) VALUES (?,?,?)');
    $stmt->execute([$sessionId, $type, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
}

function emit_community_event(PDO $pdo, string $scope, ?int $sessionId, ?string $linkKey, string $type, array $payload): void {
    $stmt = $pdo->prepare('INSERT INTO community_events (scope, session_id, link_key, type, payload) VALUES (?,?,?,?,?)');
    $stmt->execute([$scope, $sessionId, $linkKey, $type, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
}
