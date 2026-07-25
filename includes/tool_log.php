<?php
declare(strict_types=1);

/**
 * Build 000049 authoritative Tool Log persistence owner.
 *
 * Callers retain authorization, action/fact selection, redaction, and their
 * surrounding transaction. This owner writes only through the passed PDO.
 */

function log_tool(PDO $pdo, ?int $actorUserId, string $action, ?int $targetUserId = null, ?int $roomId = null, ?string $detail = null): void {
    $stmt = $pdo->prepare('INSERT INTO tool_logs (actor_user_id, target_user_id, room_id, action, detail) VALUES (?,?,?,?,?)');
    $stmt->execute([$actorUserId, $targetUserId, $roomId, $action, $detail]);
}
