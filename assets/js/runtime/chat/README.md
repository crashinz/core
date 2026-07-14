# Chat Runtime

ChatRuntime owns chat state, workflows, rendering coordination, routing, and
channel navigation behind its public runtime API.

## Relationship Group Channels

Build 000044 Part 2 establishes one private relationship channel per active
relationship group:

- channel identity is `link:<conversation-public-id>`;
- `conversation_public_id` comes only from the authoritative relationship
  snapshot;
- ChatPrivateChatService is the sole relationship group-channel and tab
  lifecycle owner;
- all active normal members and lap occupants share that one channel;
- direct messages retain separate `dm:` channels;
- existing message state, unread, reply, composer, typing, media, action,
  polling, routing, rendering, and navigation services retain their existing
  ownership.

ChatRuntime does not own relationship identity, membership, permissions,
requests, lifecycle, server authorization, or visibility-boundary policy.
Those are server responsibilities owned through `includes/base.php` and
`api/avatar_relationships.php`. AvatarRelationshipService exposes the immutable
viewer relationship/chat-access snapshot consumed by ChatRuntime.

`room.js` may render tab DOM and wire host callbacks, but it does not own a
second relationship tab, unread state, conversation identity, or message-state
model.

Build 000044 Part 3 may change multi-member avatar presentation and movement,
but it must consume this channel contract unchanged and must not move
relationship chat ownership into AvatarRuntime or `room.js`.
