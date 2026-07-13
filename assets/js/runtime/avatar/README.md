# Avatar Runtime

The Avatar Runtime is the first runtime implementation of the Chat Runtime Framework.

It owns all avatar-related runtime behavior while remaining isolated from application-level code.

The Avatar Runtime is responsible for:

- avatar relationships
- member ordering
- layout calculation
- avatar rendering
- avatar interaction
- aura workflow

The Avatar Runtime does not own:

- networking
- chat messages
- authentication
- moderation
- room lifecycle
- application routing

Those responsibilities remain outside the runtime.

---

# Relationship Persistence Boundary

Build 000044 Parts 1 and 2A establish the current relationship boundary:

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
- The server independently enforces current creation constraints in an atomic
  transaction and never replaces an existing relationship implicitly.

Build 000044 Part 2B will add versioned membership and permission operations;
Part 2C will add stable relationship group chat. Callers must not add
independent `linked_to` eligibility or snapshot-version rules.

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

Build:

000039

Status:

Relationship Persistence Repair Complete

Build 000038 added persisted relationship payload ingestion for the first-class
runtime relationship identity model. Build 000039 added deterministic
database-side backfill, repair, divergence diagnostics, and administrative
recovery for the additive persisted relationship tables. Build 000040
operationally certified the relationship persistence and repair system on
SQLite and on MariaDB through the repository's MySQL-compatible PDO path.

The current legacy `linked_to` / `link_mode` participant-edge model remains
valid and remains the compatibility write authority while API payloads can now
carry relationship identity, metadata, members, roles, ordering, anchors,
options, persistence flags, and reconciliation flags.

AvatarRelationshipService owns persisted payload normalization and ingestion.
AvatarCoordinator reconciles persisted payloads from remote link events.

The PHP/API layer owns database persistence, repair execution, administrative
transport, and dry-run diagnostics. `room.js` remains host composition and only
seeds the runtime from room configuration.

The runtime infrastructure has been established.

Behavior migration will occur incrementally in future builds.

---

# Future Development

Future builds will progressively extract ownership from the upstream ChatSpace application.

The long-term goal is for application code to coordinate runtime behavior rather than implement avatar behavior directly.

This migration will occur incrementally while preserving compatibility with upstream ChatSpace.
