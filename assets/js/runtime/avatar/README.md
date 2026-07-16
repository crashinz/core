# Avatar Runtime

The Avatar Runtime is the first runtime implementation of the Chat Runtime Framework.

It owns all avatar-related runtime behavior while remaining isolated from application-level code.

The Avatar Runtime is responsible for:

- avatar relationships
- relationship management presentation and mutation lifecycle
- finite static relationship formations and transition lifecycle
- member ordering
- layout calculation
- avatar rendering
- avatar interaction
- aura workflow
- avatar/webcam display policy projection

The Avatar Runtime does not own:

- networking
- chat messages
- authentication
- moderation
- room lifecycle
- application routing

Those responsibilities remain outside the runtime.

---

# Avatar And Webcam Display Sizing

Build 000044 Part 8 adds `AvatarDisplayPolicyService` as the runtime owner of
installation policy projection, effective per-participant display constraints,
one-time eligible normal-member webcam size matching, and diagnostics.

The permanent boundary is:

- server policy: upload width/height, avatar display cap, webcam display caps;
- user state: nullable avatar edge and webcam width/height preferences plus
  expected version;
- `AvatarRenderer`: actual natural/no-upscale avatar geometry and rectangular
  webcam presentation geometry;
- `AvatarLayoutService`: relationship, formation, lap, anchor, and stage bounds;
- `AvatarDragController`: rendered-size drag bounds;
- host shell: accessible settings/context UI and API adaptation;
- VoiceRuntime/host capture: tracks, peers, transceivers, SDP, ICE, and signals.

Defaults are a 200px avatar display cap, a 200x200 webcam display cap, and a
separate source-proven 250x250 upload envelope. Lap occupants are excluded from
one-time webcam matching. Size changes never advance avatar source version.

Part 8 exact-final Chrome passed 75 automated checks with zero defects;
canonical SHA-256 is
`B27CFB65BAA806A95A3496A127C13F64AF90DAB4470AD21BBB55AEDE0019B4AB`.

---

# Relationship Persistence Boundary

Build 000044 Part 6, including the owner-approved Part 6E extension, adds finite
formation and transition configuration without
changing the relationship persistence boundary:

- public options are
  `{schemaVersion: 2, rowSpacing, formation, transition}`;
- schema version 1 migrates deterministically to `horizontal-row` and `snap`;
- formations are `horizontal-row`, `bottom-center-trio`, `top-center-trio`, and
  `grid` only;
- transitions are `snap`, `glide`, and `fade-reposition` only;
- `AvatarFormationService` owns registry, applicability, fallback, validation,
  and diagnostics, while stateless strategies only propose geometry;
- `AvatarLayoutService` owns host-unit/lap geometry, anchor preservation, stage
  clamping, and position mutation;
- `AvatarTransitionService` owns active animation state, exact-final
  cancellation, stale/duplicate suppression, diagnostics, and teardown;
- `AvatarRenderer` supplies visual targets and `AvatarCoordinator` sequences
  configuration, drag, movement, refresh, and reconciliation boundaries;
- the existing expected-version configure transaction atomically persists
  order, options, and exact positions under one version and event.
- temporary non-renderability preserves selected formation identity, while a
  permanent authoritative normal-membership change that invalidates a trio is
  versioned and normalized to `horizontal-row`; lap-only changes do not
  invalidate trios.

Build 000044 Part 7 adds finite server-authoritative avatar image orientation.
`AvatarRenderer` applies orientation to image pixels only. Orientation must not
alter authoritative rendered dimensions, containers, labels, icons, frames,
webcam presentation, transition targets, geometry, drag bounds, or persisted
relationship positions.

`users.avatar_orientation` is the durable authority and active participant
rows are room projections. The finite values are `original`,
`flip-horizontal`, `flip-vertical`, and `flip-both`. The existing avatar API
uses expected-current conflict protection, while participant join, room config,
and avatar events synchronize initial and remote state. Uploading a replacement
avatar preserves orientation. CSS individual `scale` leaves source animation
`transform` and relationship transition `translate`/opacity independent.

Build 000044 Part 6E and Part 7 are Engineering Complete. Exact-final
integrated Edge/Chrome certification passed 142 automated checks with zero
defects; production fingerprint is
`B2C821539F8618CC7C667E4A7CF9B63E2A0CEE0AC4A249FD381F0B0ADA89B6D7`.
Human visual review remains owner-pending.

Build 000044 Part 5 completes dual-side lap ownership within the existing
relationship boundary:

- finite persisted side values are `bottom-left` and `bottom-right`;
- each normal host has at most one active occupant per side;
- direct occupants and join-request actors choose a side, while invitation
  targets choose during acceptance;
- pending operations do not reserve seats;
- occupant switching is atomic, versioned, and idempotent;
- valid legacy single-lap state migrates to `bottom-right`;
- `AvatarLayoutService` owns mirrored mixed-size host units and complete-group
  clamping, while `AvatarRenderer` owns side classes and stacking;
- host departure removes both occupants, and one occupant departure preserves
  the other;
- stable relationship chat, history boundaries, normal order, row spacing,
  explicit unlink, direct messages, and media ownership remain unchanged.

Build 000044 Parts 1 and 2 establish the current relationship boundary:

- `AvatarRelationshipService.relationshipEligibility()` is the authoritative,
  side-effect-free client policy.
- The policy uses active persisted relationship membership as authority and
  retains legacy edges only as an unmigrated/divergent compatibility fallback.
  It returns structured reasons, allowed modes, and a state fingerprint.
- `AvatarCoordinator` owns pending choice identity, stale invalidation,
  completion-time revalidation, and server-acceptance-before-local-commit.
- `AvatarDragController` consumes coordinator decisions and never owns a second
  relationship rule.
- `room.js` may present or close modal DOM, but it does not own pending state or
  eligibility.
- `AvatarRelationshipService` owns versioned persisted snapshots, stale update
  rejection, terminal tombstones, and full current member queries.
- Persisted group identity is stable across refresh, order, and role changes.
  Valid multi-member membership is represented without creating additional
  legacy pair edges.
- Active membership, membership history, permission roles, request lifecycle,
  expected-version mutations, creator succession, deterministic member order,
  lap-host attachment, dissolution, and relationship chat boundaries are
  authoritative server state owned through `includes/base.php`.
- The server independently enforces current creation constraints in an atomic
  transaction and never replaces an existing relationship implicitly.
- The dedicated relationship lifecycle API owns persistent requests,
  invitations, open/approval policy, version checks, and atomic add-member
  acceptance. `api/users.php` is not a second lifecycle owner.
- The same lifecycle API owns member removal/leave, permission changes, creator
  succession, and dissolution. Server transactions own history, request
  cleanup, role protection, minimum membership, dependent removal when a lap
  host departs, and
  legacy pair projection.
- `RoomEventRouter` routes versioned `relationship` events to
  `AvatarCoordinator`; the coordinator reconciles current snapshots through
  `AvatarRelationshipService` and leaves unsupported multi-member geometry
  unprojected.
- `AvatarCoordinator` validates that lifecycle event ID, version, and status
  envelopes exactly match their embedded authoritative snapshots before any
  cache mutation. Older events are stale no-ops, duplicate delivery is
  idempotent, and exact authoritative snapshots safely bridge version gaps.
- Current members receive all relationship permission roles through
  authenticated snapshots; non-members receive redacted roles. Dissolution
  invalidation uses prior and incoming membership before installing a terminal
  tombstone.
- `conversation_public_id` is the sole stable relationship conversation
  identity. AvatarRelationshipService exposes viewer membership/chat-access
  metadata but does not own messages, tabs, unread state, or authorization.
- Active normal-member order is persisted for Part 3. Lap occupants remain
  relationship and chat members outside the normal ordered row.
- `AvatarRelationshipManagementService` owns the one accessible management
  interface, its entry points, pending mutation/confirmation state, focus
  lifecycle, ordering controls, spacing controls, diagnostics, and cleanup.
- `AvatarRelationshipService` supplies one immutable viewer management
  projection. Creator/manager/member actions derive from authoritative state;
  the server independently enforces every permission.
- One expected-version `configure` operation persists a complete normal-member
  permutation, allowlisted public options, and exact visible-member positions
  under one version and event. No database schema or free-form positioning was
  added.
- `AvatarCoordinator` proposes and reconciles configuration through
  `AvatarLayoutService`, preserving the current left group anchor as closely as
  stage bounds allow while retaining lap attachment and Part 3 movement.

Part 4 management, persistence, ordering, positioning, contention, and
reconciliation are certified on SQLite, MariaDB, and Chrome. Callers must not add independent
`linked_to` eligibility, permission, request, conversation, history-boundary,
or snapshot-version rules.

---

# Public Entry Point

The Avatar Runtime exposes a single runtime module.

```
avatar-runtime.js
```

This module participates in the Core lifecycle through `RuntimeModule`.

Application code SHALL interact with the Avatar Runtime through its registered runtime module.

Internal implementation details SHALL remain private.

---

# Directory Structure

```
avatar/

    avatar-runtime.js

    services/

        avatar-relationship-service.js

        avatar-relationship-management-service.js

        avatar-order-service.js

        avatar-layout-service.js

        avatar-aura-service.js

    render/

        avatar-renderer.js

    interaction/

        avatar-drag-controller.js

    internal/

        relationship-graph.js

        drag-state.js

        layout-cache.js

    models/

    strategies/
```

---

# Directory Responsibilities

## services/

Contains runtime business logic.

Each service owns exactly one runtime responsibility.

Examples:

- relationships
- relationship management
- ordering
- layout

Services do not manipulate the DOM directly.

---

## render/

Contains rendering logic.

Renderers apply runtime state to the user interface.

Renderers do not calculate layout.

Renderers do not modify relationship state.

---

## interaction/

Contains user interaction logic.

Examples include:

- dragging
- pointer interaction
- gesture handling

Interaction components request runtime changes.

They do not own runtime state.

---

## internal/

Contains implementation details that are not part of the runtime's public API.

Examples include:

- graph structures
- runtime caches
- temporary runtime state

Application code MUST NOT depend on anything inside this directory.

---

## models/

Contains runtime data models when required.

Models represent runtime data.

Models do not contain business logic.

This directory intentionally begins empty.

---

## strategies/

Contains interchangeable runtime algorithms.

Examples may include:

- layout strategies
- animation strategies
- formation strategies

This directory intentionally begins empty.

---

# Ownership

The Avatar Runtime owns avatar behavior.

Each internal component owns one responsibility.

Ownership SHALL NOT overlap.

Examples:

Relationship Service

Owns:

- linking
- unlinking
- validation
- relationship capabilities
- relationship metadata contract normalization
- legacy directed-edge metadata translation

Relationship Management Service

Owns:

- one accessible relationship-management interface
- immutable management projection consumption
- entry points, pending mutation state, confirmations, and focus lifecycle
- lifecycle, permission, ordering, and row-spacing controls
- host mutation callback coordination and diagnostics

Layout Service

Owns:

- positioning
- spacing
- layout calculation
- relationship geometry strategy execution
- relationship anchor metadata consumption
- relationship bounds and clamping

Renderer

Owns:

- DOM updates
- visual synchronization
- avatar stage link icon presentation
- avatar relationship presentation refreshes
- renderer-owned relationship presentation element caches

Drag Controller

Owns:

- pointer interaction
- drag lifecycle
- active drag state
- drag-to-link target detection
- drag completion sequencing

Delegates:

- relationship lifecycle sequencing to AvatarCoordinator
- layout calculation to AvatarLayoutService through AvatarCoordinator
- rendering synchronization to AvatarRenderer through AvatarCoordinator

Aura Service

Owns:

- aura catalog state
- aura module loading and cache ownership
- current aura selection state
- aura API workflow
- participant aura application coordination
- aura diagnostics

Delegates:

- visual aura layer rendering to AvatarRenderer
- host modal presentation to room.js

---

# Runtime Philosophy

The Avatar Runtime follows the principles defined by:

- FRAMEWORK_SPECIFICATION.md
- ENGINEERING_STANDARD.md
- FRAMEWORK_DECISIONS.md

In addition:

- One Runtime — One Owner
- Stable Public APIs
- Progressive Extraction
- Compatibility First
- Preserve Proven Code

---

# Access Rules

The Avatar Runtime exposes one public runtime module.

Internal components SHALL NOT be accessed directly by application code.

Future runtime expansion SHALL occur through the Avatar Runtime rather than bypassing it.

---

# Current Status

Build 000044 Part 2 is Engineering Complete.

`avatar_relationships` and active membership are authoritative. Legacy
`linked_to` / `link_mode` fields are compatibility projections only and are
never a multi-member authority. Ambiguous legacy graphs fail closed for
operator review.

AvatarRelationshipService owns immutable persisted payload normalization,
viewer relationship/chat-access state, current membership queries, and stale
or terminal reconciliation. AvatarCoordinator reconciles versioned relationship
and compatibility events without allowing a lossy pair projection to replace a
current group.

The PHP/API layer owns database migration, transactions, requests,
permissions, lifecycle, history, repair, group-chat authorization, and exact
membership boundaries. ChatRuntime owns the stable relationship channel and
tab. `room.js` remains host composition and presentation wiring.

Build 000044 Part 3 may consume stable relationship identity and deterministic
normal-member order for multi-member presentation and movement. It must not
redesign Part 2 persistence, permissions, lifecycle, or chat.

---

# Future Development

Future builds will progressively extract ownership from the upstream ChatSpace application.

The long-term goal is for application code to coordinate runtime behavior rather than implement avatar behavior directly.

This migration will occur incrementally while preserving compatibility with upstream ChatSpace.
