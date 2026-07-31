<?php
declare(strict_types=1);

/**
 * Build 000052 common server-authoritative event delivery contract.
 *
 * The existing room/community ledgers retain persistence and ordering truth.
 * This owner authenticates each bounded delivery request and performs the one
 * authorization, filtering, projection, and cursor contract consumed by all
 * transport framing adapters.
 */

const EVENT_DELIVERY_BATCH_LIMIT = 200;
const EVENT_DELIVERY_POLL_ATTEMPTS = 20;
const EVENT_DELIVERY_POLL_SLEEP_MICROSECONDS = 100000;

final class EventDeliveryAuthorizationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
        public readonly string $actionUrl
    ) {
        parent::__construct($message);
    }
}

function event_delivery_authorized_viewer(
    PDO $pdo,
    int $sessionId,
    string $joinToken
): array {
    $viewer = auth_participant($pdo, $sessionId, $joinToken);
    $userId = (int)$viewer['user_id'];
    if (!moderation_identity_policy_acceptance_current($pdo, $userId)) {
        throw new EventDeliveryAuthorizationException(
            'Review and accept the complete current Terms and Community Rules.',
            'POLICY_REACCEPTANCE_REQUIRED',
            428,
            app_url('/policy.php')
        );
    }
    $authorization = moderation_account_session_authorization($pdo, $userId);
    if (empty($authorization['ordinaryAccessAllowed'])) {
        throw new EventDeliveryAuthorizationException(
            'Ordinary access is unavailable while this account is suspended.',
            'ACCOUNT_SUSPENDED',
            403,
            app_url('/account.php')
        );
    }
    return $viewer;
}

function event_delivery_room_deleted_notice(
    PDOStatement $noticeStatement,
    string $sessionPublicId,
    string $joinToken
): ?array {
    if ($sessionPublicId === '' || $joinToken === '') return null;
    $noticeStatement->execute([$sessionPublicId, $joinToken]);
    $notice = $noticeStatement->fetch();
    if (!$notice) return null;
    return [
        'id' => (int)$notice['id'],
        'type' => 'room_deleted',
        'payload' => json_decode((string)$notice['payload'], true) ?: [],
    ];
}

function event_delivery_map_room_event(PDO $pdo, int $sessionId, array $viewer, array $event): array
{
    $payload = json_decode((string)$event['payload'], true) ?: [];
    if (in_array((string)$event['type'], ['relationship', 'link'], true)
        && isset($payload['relationship'])
        && !empty($payload['relationship_id'])
        && !empty($payload['relationship_version'])) {
        $relationshipStatement = $pdo->prepare(
            'SELECT id, version FROM avatar_relationships
              WHERE session_id = ? AND relationship_public_id = ? LIMIT 1'
        );
        $relationshipStatement->execute([$sessionId, (string)$payload['relationship_id']]);
        $relationship = $relationshipStatement->fetch() ?: null;
        if ($relationship && (int)$relationship['version'] === (int)$payload['relationship_version']) {
            $payload['relationship'] = avatar_relationship_payload(
                $pdo,
                (int)$relationship['id'],
                (int)$viewer['id']
            );
        }
    }
    $payload = message_protection_project_event($pdo, $payload);
    $payload = gesture_capability_project_message_payload(
        $pdo,
        (int)$viewer['user_id'],
        $payload
    );
    return [
        'id' => (int)$event['id'],
        'type' => (string)$event['type'],
        'payload' => avatar_visibility_project_payload(
            $pdo,
            (int)$viewer['user_id'],
            $payload
        ),
    ];
}

function event_delivery_map_community_event(PDO $pdo, array $viewer, array $event): array
{
    $payload = json_decode((string)$event['payload'], true) ?: [];
    $payload = message_protection_project_event($pdo, $payload);
    $payload = gesture_capability_project_message_payload(
        $pdo,
        (int)$viewer['user_id'],
        $payload
    );
    return [
        'id' => (int)$event['id'],
        'type' => (string)$event['type'],
        'payload' => avatar_visibility_project_payload(
            $pdo,
            (int)$viewer['user_id'],
            $payload
        ),
    ];
}

function event_delivery_projection(PDO $pdo, array $viewer, array $events, array $communityEvents): array
{
    $roomCursor = 0;
    foreach ($events as $event) $roomCursor = max($roomCursor, (int)($event['id'] ?? 0));
    $communityCursor = 0;
    foreach ($communityEvents as $event) {
        $communityCursor = max($communityCursor, (int)($event['id'] ?? 0));
    }
    return [
        'schema_id' => 'chatspace.event-delivery',
        'schema_version' => 1,
        'events' => $events,
        'community_events' => $communityEvents,
        'cursor' => [
            'room' => $roomCursor,
            'community' => $communityCursor,
        ],
        'avatar_visibility_preferences' => avatar_visibility_preferences(
            $pdo,
            (int)$viewer['user_id']
        ),
        'gesture_preferences' => gesture_catalog_preferences_payload(
            $pdo,
            (int)$viewer['user_id']
        ),
        'gesture_capabilities' => gesture_capability_policy($pdo),
    ];
}

function event_delivery_collect(
    PDO $pdo,
    array $request,
    int $attempts = EVENT_DELIVERY_POLL_ATTEMPTS,
    int $sleepMicroseconds = EVENT_DELIVERY_POLL_SLEEP_MICROSECONDS
): array {
    $sessionPublicId = trim((string)($request['session_id'] ?? ''));
    $joinToken = trim((string)($request['join_token'] ?? ''));
    $lastRoomEventId = max(0, (int)($request['last_event_id'] ?? 0));
    $lastCommunityEventId = max(0, (int)($request['last_community_event_id'] ?? 0));
    $attempts = max(1, min(EVENT_DELIVERY_POLL_ATTEMPTS, $attempts));
    $sleepMicroseconds = max(0, min(250000, $sleepMicroseconds));

    $noticeStatement = $pdo->prepare(
        'SELECT id, payload FROM room_deletion_notices
          WHERE session_public_id = ? AND join_token = ?
          ORDER BY id DESC LIMIT 1'
    );
    if ($notice = event_delivery_room_deleted_notice(
        $noticeStatement,
        $sessionPublicId,
        $joinToken
    )) {
        return [
            'schema_id' => 'chatspace.event-delivery',
            'schema_version' => 1,
            'events' => [$notice],
            'community_events' => [],
            'cursor' => ['room' => (int)$notice['id'], 'community' => 0],
        ];
    }

    $sessionId = resolve_session_id($pdo, $sessionPublicId);
    $viewer = event_delivery_authorized_viewer($pdo, $sessionId, $joinToken);
    $dmLeft = 'dm:' . (int)$viewer['user_id'] . ':%';
    $dmRight = 'dm:%:' . (int)$viewer['user_id'];
    $initialLinkAccess = avatar_relationship_chat_access($pdo, $sessionId, (int)$viewer['id']);
    $linkConversationId = (string)($initialLinkAccess['conversation_id'] ?? '');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $roomStatement = $pdo->prepare(
        'SELECT id, type, payload FROM events
          WHERE session_id = ? AND id > ? ORDER BY id ASC LIMIT '
        . EVENT_DELIVERY_BATCH_LIMIT
    );
    $communityStatement = $pdo->prepare(
        "SELECT ce.id, ce.scope, ce.link_key, ce.type, ce.payload FROM community_events ce
         WHERE ce.id > ?
           AND (
             ce.scope = 'community'
             OR (
               ce.scope = 'link'
               AND ce.session_id = ?
               AND ce.link_key IN (
                 SELECT ar.conversation_public_id
                   FROM avatar_relationship_members viewer_membership
                   JOIN avatar_relationships ar ON ar.id = viewer_membership.relationship_id
                  WHERE ar.session_id = ? AND ar.status = 'active'
                    AND ar.divergence_status = 'synced'
                    AND viewer_membership.participant_id = ?
                    AND viewer_membership.membership_status = 'active'
                    AND viewer_membership.active_participant_id = ?
                    AND NOT EXISTS (
                      SELECT 1
                        FROM avatar_relationship_members other_membership
                        JOIN participants other_participant ON other_participant.id = other_membership.participant_id
                        JOIN user_blocks ub
                          ON (ub.blocker_user_id = ? AND ub.blocked_user_id = other_participant.user_id)
                          OR (ub.blocked_user_id = ? AND ub.blocker_user_id = other_participant.user_id)
                       WHERE other_membership.relationship_id = ar.id
                         AND other_membership.membership_status = 'active'
                         AND other_membership.participant_id <> viewer_membership.participant_id
                    )
               )
             )
             OR (
               ce.scope = 'dm'
               AND (ce.link_key LIKE ? OR ce.link_key LIKE ?)
             )
             OR (
               ce.scope = 'game'
               AND ce.session_id = ?
               AND ce.link_key IN (
                 SELECT gl.lobby_code
                   FROM game_lobbies gl
                   JOIN game_sessions gs ON gs.lobby_code = gl.lobby_code
                  WHERE gs.room_session_id = ?
                    AND gs.ended_at IS NULL
                    AND gl.status <> 'ended'
                    AND (gl.user1_id = ? OR gl.user2_id = ?)
               )
             )
           )
         ORDER BY ce.id ASC LIMIT " . EVENT_DELIVERY_BATCH_LIMIT
    );

    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($notice = event_delivery_room_deleted_notice(
            $noticeStatement,
            $sessionPublicId,
            $joinToken
        )) {
            return [
                'schema_id' => 'chatspace.event-delivery',
                'schema_version' => 1,
                'events' => [$notice],
                'community_events' => [],
                'cursor' => ['room' => (int)$notice['id'], 'community' => 0],
            ];
        }
        // A fresh authorization read on every bounded wait iteration prevents
        // a transport connection from outliving a restriction or revocation.
        $viewer = event_delivery_authorized_viewer($pdo, $sessionId, $joinToken);
        $roomStatement->execute([$sessionId, $lastRoomEventId]);
        $roomRows = $roomStatement->fetchAll();
        $linkAccess = $linkConversationId !== ''
            ? avatar_relationship_chat_access(
                $pdo,
                $sessionId,
                (int)$viewer['id'],
                $linkConversationId
            )
            : null;
        $communityStatement->execute([
            $lastCommunityEventId,
            $sessionId,
            $sessionId,
            (int)$viewer['id'],
            (int)$viewer['id'],
            (int)$viewer['user_id'],
            (int)$viewer['user_id'],
            $dmLeft,
            $dmRight,
            $sessionId,
            $sessionId,
            (int)$viewer['id'],
            (int)$viewer['id'],
        ]);
        $communityRows = array_values(array_filter(
            $communityStatement->fetchAll(),
            static function(array $event) use ($linkAccess): bool {
                if ((string)($event['scope'] ?? '') !== 'link') return true;
                if (!$linkAccess
                    || (string)($event['link_key'] ?? '')
                        !== (string)$linkAccess['conversation_id']) {
                    return false;
                }
                $payload = json_decode((string)$event['payload'], true) ?: [];
                $messageId = (int)($payload['message_id'] ?? $payload['id'] ?? 0);
                return $messageId <= 0
                    || $messageId > (int)$linkAccess['visible_after_message_id'];
            }
        ));
        if ($roomRows || $communityRows) {
            return event_delivery_projection(
                $pdo,
                $viewer,
                array_map(
                    static fn(array $event): array =>
                        event_delivery_map_room_event($pdo, $sessionId, $viewer, $event),
                    $roomRows
                ),
                array_map(
                    static fn(array $event): array =>
                        event_delivery_map_community_event($pdo, $viewer, $event),
                    $communityRows
                )
            );
        }
        if ($attempt + 1 < $attempts && $sleepMicroseconds > 0) {
            usleep($sleepMicroseconds);
        }
    }

    return event_delivery_projection($pdo, $viewer, [], []);
}
