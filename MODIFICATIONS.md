# Modification Notice

This distribution of ChatSpace Community Edition has been modified by **exe**.

The original authorship, copyright, license, and attribution notices remain in
place.

See [AUTHORS.md](AUTHORS.md) for the original project credits and
[LICENSE.md](LICENSE.md) for the governing license.

# Modification History

This plain-language history groups related work into meaningful milestones.
It is based on the complete reachable Git history, milestone records,
engineering reports, source contracts, and the current implementation. Small
follow-up fixes and certification-only commits are included with the feature
or safety change they support.

## Original ChatSpace Community Edition baseline

The root release established ChatSpace Community Edition, its original
authorship, room chat, avatars, moderation, games, voice, webcams, Setup, and
the public license and project notices. This original work is not attributed
to **exe**.

<details>
<summary>More about the original baseline</summary>

- The root commit contains the original source, public credits, license,
  installation flow, room and lobby surfaces, database, media, and games.
- Later entries below describe changes made to the modified distribution; they
  do not reassign authorship of the original release.

Technical reference: Original release, 2026-05-18, commit `2e7b10f`.

</details>

## Early room, chat, media, and administration improvements

The modified distribution expanded room presentation, chat composition,
portable administration, account recovery, moderation, voice controls, games,
and everyday media behavior.

<details>
<summary>More about these changes</summary>

- Added avatar entry and exit effects, GIF and gesture presentation, reply
  previews, pasted images, URL previews, room effects, and better scrolling.
- Added portable administration exports and imports, room-history moderation,
  Setup branding options, recovery codes, an optional age gate, and clearer
  administration controls.
- Improved games, voice-device selection, webcam transitions, WebRTC media,
  upload handling, and historical avatar snapshots.
- Added CSRF protection, authentication rate limits, safer sessions, duplicate
  message protection, and web-server hardening.

Technical reference: 2026-05-20 through 2026-05-31, commits `bd85b0f`–`308d608`.

</details>

## GIF providers, imported rooms, and avatar interaction foundations

Community owners gained another GIF provider, stronger imported-room support,
and more expressive avatar interactions while remote content handling became
stricter.

<details>
<summary>More about these changes</summary>

- Added Klipy GIF search and avatar aura selection.
- Added VP-style website-room import, safer remote asset handling, CSS-manifest
  support, and improved imported-room visual fidelity.
- Added horizontal imported avatar pairs and the first lap-link interaction
  mode.

Technical reference: 2026-06-15 through 2026-06-21, commits `95d2b46`–`1b1b9b7`.

</details>

## Shared room-runtime and relationship ownership

Large room behaviors were separated into focused runtime owners, and avatar
relationships gained stable identities, persistence, repair, and cross-database
certification.

<details>
<summary>More about these changes</summary>

- Separated chat rendering, actions, unread state, replies, composition,
  typing, media sending, private chat, games, polls, voice, room effects, and
  imported-room behavior into shared owners.
- Consolidated room event routing and reduced legacy wrappers without changing
  established room behavior.
- Added stable relationship identity, metadata-driven geometry, persistence,
  compatibility synchronization, backfill, repair, diagnostics, and
  SQLite/MariaDB parity.

Technical reference: Builds 000022–000040, 2026-07-03 through 2026-07-12,
commits `04e9ee9`–`db6b1b2`.

</details>

## Avatar groups, lap seating, formations, and dances

Avatar relationships grew from pairs into managed groups with requests,
membership rules, private group chat, configurable positioning, lap seating,
formations, orientation, sizing, and synchronized dances.

<details>
<summary>More about these changes</summary>

- Added relationship eligibility, requests, join policy, membership lifecycle,
  permission enforcement, concurrency protection, and group chat.
- Added multi-member movement, management menus, dynamic ordering, dual-side
  lap seats, static formations, transitions, image orientation, and display
  sizing.
- Added synchronized dance formations and later installation controls that
  stop active optional dances safely without disturbing relationship state.

Technical reference: Build 000044 Parts 1–9B and Dance Capability Controls,
2026-07-13 through 2026-07-20, commits `b6ae4a4`–`520b0dd`.

</details>

## Room stability, visibility, media preferences, and certification

Room polling and media presentation became more resilient, while each viewer
gained private avatar and webcam visibility controls.

<details>
<summary>More about these changes</summary>

- Stabilized avatar and polling behavior and aligned avatar/webcam display-size
  rules across clients.
- Added private local webcam receive controls, avatar fallback, exact-avatar and
  account-wide avatar hiding, and reversible hidden-avatar preferences.
- Added bounded relationship capacity and group wrapping without making
  viewport size an admission rule.
- Added isolated, risk-prioritized browser certification and stronger cleanup,
  ownership, memory-safety, and continuation safeguards.

Technical reference: 2026-07-18 through 2026-07-20, commits `cddac84`–`7b4962b`
and `e35538d`–`7f6a6ad`.

</details>

## Shared Setup and Admin settings

Setup and Admin now use one settings registry with consistent categories,
labels, defaults, search, filters, resets, presets, authorization, revisions,
and cross-tab synchronization.

<details>
<summary>More about this change</summary>

- Preserved both intentional Admin launch locations while keeping one canonical
  lobby-owned Admin menu.
- Added one deliberate unlock boundary for ordinary registry-backed settings;
  optional individual and grouped changes need no second confirmation.
- Preserved independent protections for destructive data, moderation,
  security, privacy, credential, and recent-authentication actions.
- Kept SQLite/MariaDB parity, atomic broad changes, stale-write rejection,
  Tool Logs, and safe optional-capability shutdown.

Technical reference: Setup/Admin Settings Organization, 2026-07-22, commit
`d1c23b0`.

</details>

## Gesture catalog presentation and preferences

The gesture picker gained separate GIF, Server Gesture, Personal Gesture, and
Emoji tabs with search, sorting, ordering, pagination, hiding, preferences,
and protected administration foundations.

<details>
<summary>More about this change</summary>

- Kept stable gesture identities and immutable historical message snapshots.
- Added private per-account presentation preferences and server-owned catalog
  searches and pages.
- Added accessible gesture action menus and a bounded read-only Admin catalog.

Technical reference: Gesture Checkpoint Part 3, 2026-07-22, commit `8dc496e`.

</details>

## Gesture Maker, packages, and media

Authenticated users gained a room-preserving Gesture Maker and Editor for
their own Personal Gestures, with validated AGST packages, protected downloads,
animation, audio, provenance, and authorized Admin inspection.

<details>
<summary>More about this change</summary>

- Added one shared create/edit owner, stable identity, expected-version
  rejection, private-by-default creation, and ownership enforcement.
- Added bounded package validation, safe extraction, protected media delivery,
  Catie attribution, and legacy AGST compatibility.
- Preserved existing per-gesture editing shortcuts and kept package transfer
  between users out of scope.

Technical reference: Gesture Checkpoint Part 4, 2026-07-23, commit `5cd17c8`.

</details>

## Server-authoritative gesture capability controls

Gesture use now follows one installation-wide parent capability with protected
subordinate controls for server gestures, personal gestures, editing, and
audio delivery.

<details>
<summary>More about this change</summary>

- Enforced capabilities below the browser at message, catalog, package, and
  media owners.
- Preserved stored subordinate choices when the parent is disabled, immutable
  historical text, ownership, provenance, stale-write rejection, and protected
  Admin maintenance.
- Added the Personal Gestures management entry while preserving the direct
  `Edit Gesture` shortcut and one shared editor-launch path.

Technical reference: Gesture Checkpoint Part 5, 2026-07-23, commit `7246dc3`.

</details>

## Versioned database migrations and data lifecycle

Database changes gained a manifest, immutable checksums, a durable ledger,
verified backups, fail-closed compatibility checks, and protected owner update
controls for SQLite and MariaDB.

<details>
<summary>More about this change</summary>

- Added clean-install and recognized-upgrade paths with atomic execution,
  resumable state, checksum drift rejection, Tool Logs, and cross-engine
  contracts.
- Added bounded server-side MariaDB logical backups and verified SQLite
  snapshots without treating user-facing import/export as migration backup.
- Preserved application data, stable IDs, revisions, gesture provenance,
  history, settings, and internal relationships.

Technical reference: Build 000048 Part 1, 2026-07-23, commit `befc3c1`.

</details>

## Safe upgrades, paired rollback, and recovery

Prepare for Update now creates one verified recovery set containing a private
database recovery point and a matching snapshot of the installed deployable
application release.

<details>
<summary>More about this change</summary>

- Selects application files from the authoritative deployment inventory,
  streams them to private storage, records hashes and compatibility metadata,
  and preserves installation-specific configuration and content.
- Verifies recovery before mutation and supports protected paired restoration
  where the database engine and server capabilities have been certified.
- Fails closed with exact manual recovery guidance when safe automatic recovery
  cannot be proven.
- Adds public modification notices and a separately owned room-version
  attribution while deferring editable private-branding controls to Build
  000050.

Technical reference: Build 000048 Part 2, 2026-07-24, based on `befc3c1`;
publication commit recorded in the final Engineering Report and Git history.

</details>
