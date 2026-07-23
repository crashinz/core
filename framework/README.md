# Chat Runtime Framework for ChatSpace

The Chat Runtime Framework is a modern runtime architecture for ChatSpace.

Its purpose is to progressively isolate runtime behavior from the upstream ChatSpace application into independently owned framework services while preserving compatibility and minimizing modifications to the original project.

The framework is designed to remain highly mergeable with upstream ChatSpace releases while providing a stable, modular foundation for long-term development.

---

# Framework Mission

The Chat Runtime Framework exists to transform application-specific runtime behavior into framework-owned runtime systems.

The framework is guided by five core objectives:

* Preserve compatibility with upstream ChatSpace
* Minimize merge complexity
* Establish explicit ownership boundaries
* Provide stable public runtime APIs
* Support long-term maintainability through progressive extraction

The framework extends ChatSpace.

It does not replace ChatSpace.

---

# Current Status

**Framework Version**

1.0

**Current Build**

Gesture Checkpoint Part 5

**Framework Phase**

Engineering Complete; Build 000048 is next

Parts 1-5 and the standalone Gesture Program are Engineering Complete. Part 5
adds one shared master plus four subordinate server-authoritative Gesture
capabilities, atomic Setup/Admin dependency semantics, action-time channel
enforcement, immutable history snapshots with current-viewer media projection,
and account-scoped per-sender gesture-media visibility.

The picker retains exactly four tabs. `Create / Edit Gestures` opens
room-preserving owned-gesture management, and both owned Edit entry points use
one editor launcher and the existing server authorization owner. Exact-final
r2 passed all `95/95` Chrome scenarios across `14/14` workers with exact HTTP
`26/26`, no unmatched response, host-safety violation, or cleanup residue.
The final owner-review deployment package is prepared but not uploaded, and
hosting was not contacted. Build 000048 is the exact next checkpoint. Send
Gesture/P2P transfer, Build 000051, pre-Build and Build 000064 work, and hosted
deployment remain future-owned.

---

# Documentation Guide

Documentation is organized into three layers.

## 1. Framework Specification

Defines the architecture of the framework.

Read first.

```
framework/specification/

FRAMEWORK_SPECIFICATION.md
```

---

## 2. Engineering Standards

Defines how framework code is written.

```
framework/specification/

ENGINEERING_STANDARD.md
```

---

## 3. Framework Decisions

Records significant architectural decisions.

```
framework/specification/

FRAMEWORK_DECISIONS.md
```

---

## 4. Runtime Documentation

Documents individual runtime systems.

```
framework/docs/

ROOM_SUBSYSTEM_AUDIT.md

AVATAR_RUNTIME_ARCHITECTURE.md

AVATAR_RUNTIME_API.md

AVATAR_RUNTIME_MIGRATION.md
```

---

# Recommended Reading Order

New contributors should read the documentation in the following order.

1. `framework/specification/FRAMEWORK_SPECIFICATION.md`
2. `framework/specification/ENGINEERING_STANDARD.md`
3. `framework/specification/FRAMEWORK_DECISIONS.md`
4. `framework/docs/ROOM_SUBSYSTEM_AUDIT.md`
5. `framework/docs/AVATAR_RUNTIME_ARCHITECTURE.md`
6. `framework/docs/AVATAR_RUNTIME_API.md`
7. `framework/docs/AVATAR_RUNTIME_MIGRATION.md`

Following this sequence provides:

* framework philosophy
* architectural principles
* engineering standards
* design rationale
* runtime architecture
* public APIs
* implementation roadmap

---

# Repository Layout

```
assets/

└── js/

    core/
        Shared framework infrastructure.

    runtime/
        Framework runtime systems.

        avatar/
            Avatar Runtime.

        chat/
            Chat Runtime.

        room/
            Room Runtime.

        voice/
            Voice Runtime.

        game/
            Game Runtime.

        room-effects/
            Room Effects Runtime.

        imported-room/
            Imported Room Runtime.

        presence/
            Future Presence Runtime.

        media/
            Future Media Runtime.

        notifications/
            Future Notification Runtime.

        games/
            Future Game Runtime.

    integration/
        ChatSpace integration layer.

    developer/
        Diagnostics and developer tooling.

    plugins/
        Optional framework plugins.

framework/

    framework.json

    specification/

        FRAMEWORK_SPECIFICATION.md

        ENGINEERING_STANDARD.md

        FRAMEWORK_DECISIONS.md

    docs/

        ROOM_SUBSYSTEM_AUDIT.md

        AVATAR_RUNTIME_ARCHITECTURE.md

        AVATAR_RUNTIME_API.md

        AVATAR_RUNTIME_MIGRATION.md
```

---

# Framework Architecture

The Chat Runtime Framework is organized into four architectural layers.

```
Application

↓

Runtime

↓

Core

↓

Utilities
```

Application code orchestrates runtime systems.

Runtime systems own behavior.

Core provides shared infrastructure.

Utilities provide reusable implementation support.

---

# Engineering Philosophy

Framework development follows a structured engineering process.

```
Observe

↓

Audit

↓

Specify

↓

Review

↓

Implement

↓

Validate

↓

Release
```

Architecture is documented before implementation.

Ownership is defined before code is extracted.

Behavior is extracted before files are reorganized.

---

# Runtime Philosophy

Every runtime subsystem follows the same architectural principles.

* One Runtime — One Owner
* Stable Public APIs
* Explicit Ownership
* Runtime Pipeline
* Progressive Extraction
* Compatibility First

The Avatar Runtime is the first implementation of this model.

Future runtime systems will follow the same structure.

---

# Build Philosophy

A Framework Build represents a coherent, reviewable unit of architectural or engineering progress.

Every Build leaves the framework in a stable state.

Builds may represent:

* Foundation work
* Runtime extraction
* Runtime integration
* Stabilization
* Documentation

---

# Licensing

The Chat Runtime Framework is developed as part of the ChatSpace Community Edition project.

The repository and all framework components are distributed under the same license as the ChatSpace Community Edition project unless explicitly stated otherwise.

See `LICENSE.md` for licensing details.
