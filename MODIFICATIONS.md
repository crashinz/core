# Modification Notice

This distribution of ChatSpace Community Edition has been modified by **exe**.

The original authorship, copyright, license, and attribution notices remain in
place.

See [AUTHORS.md](AUTHORS.md) for the original project credits and
[LICENSE.md](LICENSE.md) for the governing license.

# Modification History

## 2026-09-14 — Backgammon narrow-screen bar entry

Fixed the built-in Backgammon dice panel covering the bar and intercepting checker clicks on narrow screens. The panel now fits its board area, and only the enabled Roll button captures clicks. Verified actual bar entry, bot pacing, failed-download Retry, pause/resume, focus and recording replay locally. Testing is WIP.


## 2026-09-14 — Backgammon Practice bots

Added Easy, Normal and Expert Backgammon bots using a bundled GNU Backgammon browser evaluator. Hosts can fill the second empty seat before starting; bot games automatically use Practice Mode and never affect rankings. Both starter methods and Standard/Legacy OCX move-use options remain supported. Added bounded thinking, retry, server-validated moves and dice, saved continuation, automatic recording metadata, and centralized engine licenses/source/build instructions. Local compatibility, complete-game, save/resume and recording checks passed. Testing is WIP; difficulty labels are relative, not calibrated ratings.


## 2026-09-14 — Checkers bot optional rules

Checkers Practice bots now support optional backward movement, backward captures and flying kings in every combination. Preserved Easy, Normal and Expert choices and bounded thinking time. Updated engine version checks, movement-rule recording details and the existing centralized Marcher notices/source patch. Expanded local lobby, saved-game, rematch and recording verification. Testing is WIP.


## 2026-09-14 — Practice bot completion results

Corrected Checkers and Chess Practice bot winner names in game results and the result sound when the bot wins. Expanded complete-game, saved-game restoration and cross-game verification. Testing is WIP.


## 2026-09-14 — Checkers Practice bots

Added Marcher Checkers bots with Easy, Normal and Expert levels, default-None lobby controls, automatic Practice conversion, server-validated moves and shared game recording. Corrected draw outcome scoring and preserved draw history across reloads and rematches. Added centralized Marcher attribution and source/build access. Testing is WIP.


## 2026-09-14 — Chess Practice bots

Added a separate Stockfish.js browser engine with approximate rating choices and readable level names, default-None lobby controls, automatic Practice conversion, server-validated moves and shared game recording. Includes engine license and corresponding source access. Testing is WIP.


## 2026-09-14 — Add bots from waiting lobbies

Spades and Battleship bot slots now default to None. Hosts can add Normal or Expert bots to empty seats after people join, automatically switching to Practice, then explicitly start. Occupied human seats and all ranking safeguards are preserved.


## 2026-09-14 — Spades and Hearts seat-map orientation

Seat 1 now appears below the table, followed by seats 2 left, 3 above and 4 right. Two-player Hearts puts its first occupied seat below the opponent. Fixed seat IDs, partnerships and UNO positions are preserved.


## 2026-09-14 — Clickable card-table seats

- Replaced Hearts/Spades seat dropdowns with a compact clickable table showing
  current players, open seats and Spades partners. Two-player Hearts displays
  opposing players; full relationship labels remain available below the table.
- Added the same pregame seat and approved-swap controls to UNO, retaining its
  existing two-to-ten-player capacity and chosen clockwise order. The host starts
  once seating is arranged; seats lock during play. UNO bots remain future work.
- Shared controls support keyboard focus, narrow screens and long player names.
  Existing Practice-only bot enforcement and protected game recordings remain.


## 2026-09-14 — Practice bots and card-game seating

- Added Normal and Expert Battleship bots through the existing empty-seat Game
  Options controls. Bots follow the accepted touching rule, finish hit ships and
  prioritize the largest unsunk ship using public information. Automatic moves
  are captured individually in protected game recordings.
- All games containing bots are Practice only. Shared server checks prevent
  ranked/Recorded bot results, including invalid restored or modified state.
- Added pregame Hearts and Spades seat choices and consent-based seat swaps,
  with partner labels for Spades and opposite-player labels for individual Hearts.
  These games now wait for the host to start after seating is arranged. Changed
  seating requires fresh acceptance; seats stay fixed during play.
- No database migration or deployment is part of this local update.


## 2026-09-14 - Shared game recordings and verified Spades bots

- Added automatic protected game recordings through the Multiplayer Game Framework
  for Spades, Hearts, Checkers, Chess, Backgammon, Acey Deucy, Battleship, Chinese
  Checkers and UNO, including player names/IDs, accepted moves, scores and results.
- Added compressed split files, bounded recoverable queue/storage, truthful partial
  status, and administrator controls, closed-game export and confirmed deletion.
  Chat messages, IP addresses and authentication data are excluded.
- Integrated the verified Normal Spades endgame Nil helper and Expert's approved
  1000 ms decision / 3000 ms automatic-chain allowance, retaining tested Nil
  protection and other existing bot improvements. No automatic learning is enabled.
- Added the forward recording migration; existing installation data and earlier
  migration signatures are preserved. No hosting deployment is part of this change.


## 2026-09-12 - Spades bot decision quality

- Normal evaluates the next trick when choosing cover for its partner's live Nil.
- Both difficulties compare Nil risk and score value with an ordinary bid, using
  visible partner support and the accepted exchange rules.
- Expert retains its established card-play search after further endgame
  experiments failed to demonstrate a full-game improvement.
- Opponents' unpublished bidding hints are excluded from bot observations.
- Expanded private decision diagnostics and replay verification; retain the
  existing exchange strategy after further scoring experiments were inconclusive.

## 2026-09-12 - Spades bot Nil partnership play

- Normal and Expert now offer safer cards to a Nil partner and return dangerous
  cards when they are the Nil bidder, including exchanges involving virtual seats.
- Both difficulties proactively cover a partner's live Nil, even after meeting
  their own contract, and distinguish partner protection from opposing Nil play.
- Improved safe Nil shedding, trump conservation and suitable-hand Nil bidding
  while retaining separate Normal and Expert policies and existing search budgets.

## 2026-09-12 - Framework arcade games

- Added Tetris Versus to the shared game framework with independent boards,
  server-owned seven-bag pieces, simultaneous play, and authoritative results.
- Added single-player Space Invasion with server-checked movement, shots,
  collisions, and Practice run history.
- Added shared pause, save, reconnect, and exit support, game sizing from 50%
  to 150%, optional height fitting, and Tetris level-speed help.
- Preserved existing legacy arcade sessions and their original assets.

## 2026-09-12 - Spades settlement timing and score readability

- Corrected completed-trick timing so a difference between the web server and
  the player's computer clock cannot turn the intended one-to-two-second card
  display into a minute-long wait.
- Gave both teams' top score values a protected line box and clear tabular
  numerals so totals, last-hand scores, and bag counts remain fully readable.

## 2026-09-12 - Voice-session cleanup and diagnostics reconciliation

- Stopped room voice polling after the server reports that its session no
  longer exists, preventing a departed or reloaded room from repeatedly
  requesting a stale voice session.
- Made room exit and document teardown fully stop the active voice runtime.
- Reconciled older game catalog, media signaling, direct transfer, room
  mutation, heartbeat, and room-poll diagnostics against their current guarded
  request paths.

## 2026-09-12 - Live Website room and connection-status corrections

- Corrected temporary Live Website rooms so they expire after five minutes
  without active participants, including rooms that were already empty before
  cleanup ran.
- Made a selected game replace the Live Website surface instead of remaining
  hidden behind it, and restored the website and room avatars after leaving the
  game.
- Removed the competing room scrollbar from Live Website presentation so the
  embedded page owns scrolling while no game is selected.
- Preserved a player's game-board scroll position while a Live Website room
  refreshes, so tall games no longer jump back to the top every two seconds.
- Corrected the room divider so dragging across an embedded page can enlarge
  Game Chat again after it has been made shorter.
- Changed the displayed connection check to a lightweight static probe so it
  reports connection and web-server delay without adding database work to every
  sample.
- Removed redundant account-authentication wording from member profiles and
  simplified this history to public, user-facing change information.
- Placed the primary Join Voice and Start Camera controls side by side in the
  room sidebar so both actions use the available width consistently.
- Corrected Make Live Website Room Official so the permanent successor keeps
  the same live page instead of opening as an empty room. The database updater
  also repairs successors created by the earlier behavior.

## 2026-08-31 - Built-in game sound mappings

- Replaced Built-in Chess's generic movement and capture base cues with the
  CC0 Piece Slide and Piece Capture recordings while preserving
  the existing visual-motion and Check/Checkmate sequences.
- Replaced Chinese Checkers's capture-like jump and chained-success mappings
  with its approved step cue and one approved non-capturing jump cue per
  authoritative route segment.
- Replaced UNO's borrowed Hearts celebration for a declaration with the
  approved plain “UNO!” voice cue and routed it through the viewer Voice option.
- Replaced Built-in Spades's globally positive Nil-failure cue with the approved
  viewer-relative team voice lines and neutral spectator line.

## First-Party Nested Four

CoreChat now includes an independently authored two-player Nested Four game.

<details>
<summary>More about this addition</summary>

- Adds a server-authoritative four-by-four strategy game with three nested
  S/M/L/XL reserve stacks per player, committed selection, size covering,
  visible four-in-a-row wins, repetition draws, and resignation.
- Keeps covered board pieces and hidden reserve contents out of the client
  projection so remembering them remains part of play.
- Adds an original walnut, teal, and brass board with coral and ocean-blue
  labeled pieces, real member avatars, legal-move cues, responsive 100%-200%
  sizing, and existing local CC0 effects.
- Uses the official public rulebook only as a structural reference and copies
  no product artwork, logo, name, rulebook wording, trade dress, or audio.
- Requires no database migration and does not change Tetris Versus, Space
  Invasion, or any existing game's rules.

</details>

## 2026-08-31 - Embedded game controls and Five Dice header cleanup

- Kept shared Pause beside Room Chat and moved SFX, GFX, and Music into their
  own shared row, with conditional pause/reconnect and terminal/replay rows.
- Preserved Blackjack `Start next round` and UNO `Start next hand` as required
  controls on their game boards.
- Removed the redundant visible Five Dice `Built-in appearance` badge while
  preserving appearance selection through Game Options.

## 2026-08-30 - Optional Acey Deucy European rules and point-result audio

- Kept `Current Acey Deucy — Default` as the existing default behavior.
- Added a separate `European Double-Double — Authentic` shared option with complementary doubles, full 1–2/chosen-double sequencing, blocked-sequence loss of the bonus roll, exact-only bearing off, and one-point scoring.
- Connected real Built-in Gammon and Backgammon bear-off classifications to their distinct spoken result cues shortly after the ordinary win cue.
- Added focused server and browser fixtures plus durable verification without changing Classic/OCX paths or adding a database migration.

## 2026-08-30 - Mute, point stacks, no-timer labels, and Sculpted point checkers

- Added participant-menu Mute/Unmute synchronized with User Profile and Account Safety, immediate protected placeholders, live timed expiry, and per-message Reveal.
- Removed successful Mute/Unmute warning popups while retaining error warnings.
- Capped large Built-in Backgammon and Acey Deucy stacks inside each point lane and preserved count badges, selected checkers, legal markers, bar, home, and reserve zones.
- Added transparent high-resolution `Sculpted checkers` as the default viewer-local style and retained `CSS-rendered checkers` as the fallback option.
- Kept Chess/Checkers move counts and omitted timer wording only when no clock/inactivity timer exists.
- Focused automated and authenticated in-app Browser verification passed; the updated presentation remains available across supported layouts.

## 2026-08-30 - Built-in point hit, Chess event audio, and Sculpted Chess pieces

- Added project-owned Backgammon/Acey Deucy bar-hit audio and authoritative Chess Check/Checkmate cues.
- Added high-resolution transparent ivory/navy Sculpted Chess pieces as the default viewer-local Built-in style while preserving Unicode, Original OCX, and CoreChat alternatives.
- Kept every piece inside its square, scaled pawns below major pieces, preserved the existing king-fall Checkmate animation, and prevented a blank/reverted frame while a moved piece awaits slow server confirmation.
- Preserved server gameplay, rules, randomness, databases, and existing release boundaries.

## First-Party Canvas

CoreChat now includes an optional repository-owned Canvas extension for one
community Canvas and one Canvas per room.

<details>
<summary>More about this addition</summary>

- Adds room-preserving community and room Canvas overlays without replacing
  chat, voice, games, membership, or room navigation.
- Supports structured text, rules, checklists, links, and safe media
  references without accepting stored raw HTML.
- Adds stable section comments, per-user view/comment/edit/manage/publish
  permissions, explicit draft saves and publishing, stale-write rejection,
  idempotent commands, and bounded revision history.
- Keeps authentication, identity, moderation, Tool Logging, extension
  lifecycle, database backup, migration, and recovery under Core ownership.

</details>

This plain-language history groups related work into meaningful milestones.
It is based on the public release history and current implementation. Small
follow-up fixes are included with the feature or safety change they support.

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

</details>

## Reusable multiplayer-game framework

CoreChat now provides one shared, server-authoritative foundation for future
first-party one-player and multiplayer game extensions while preserving the
existing game launch experience.

<details>
<summary>More about this change</summary>

- Adds registry-backed game definitions, authenticated player and spectator
  sessions, pregame acceptance, versioned state, reconnect and lifecycle
  handling, and fail-closed stale, duplicate, and conflicting action checks.
- Separates Practice randomness from server-recorded play and keeps immutable
  result and record ownership with the shared framework rather than individual
  presentation code.
- Integrates protected game chat with the established Message Protection,
  moderation, retention, account-deletion, and cleanup owners.
- Adds viewer-local presentation-pack and responsive game-shell contracts that
  cannot alter rules, moves, timers, randomness, scoring, results, records, or
  another participant's view.
- Automatically fits a visible game beside usable room chat when space permits,
  restores the ordinary room layout whenever the game surface is hidden, and
  uses a room-preserving responsive fallback on narrow displays.
- Keeps Space Invasion and Tetris Versus unchanged as playable compatibility
  surfaces under the current compatibility decision. Their planned
  extension migrations are deferred unless a later compatibility decision changes
  that disposition. Chess, Checkers, and Backgammon now use their first-party
  extensions; superseded browser implementations remain repository evidence
  and are excluded from deployable release output.

### Older arcade-game compatibility

- Preserves Tetris Versus and Space Invasion byte-for-byte in their existing
  routes and keeps their authenticated compatibility API in deployable output.
- Binds compatibility requests to the registered compatibility game and exact
  same-origin game entry, preventing superseded or cross-game mutation paths.
- Retains superseded Chess, Checkers, and Backgammon source as repository
  evidence while excluding those old pages from deployable release output.
- Records authorized inclusion under the existing project license and
  project-level credits without inventing a narrower file-level attribution.

</details>

## Classic multiplayer games


<details>
<summary>More about this addition</summary>

- Adds Backgammon as a separate first-party extension and expands the shared
  server-authoritative game lifecycle used by Checkers, Chess, Acey Deucy,
  Battleship, Spades, and Five Dice.
- Adds source-backed Classic presentation behavior, viewer-local game options,
  accessible legal-move cues, compact Score & Records and player status
  surfaces, and responsive game controls while keeping private reference media
  outside the tracked distribution.
- Adds server-owned Chess and Checkers clocks and move counts, game-specific
  Ranked/Recorded inactivity protection, all-game pause/resume and cumulative
  reconnect adjudication, and visible player countdowns above every board.

</details>

## Private gesture delivery and shared P2P connections

CoreChat now offers exact local matching for personal gesture media, complete
viewer-private sender-media hiding, and one shared P2P connection policy.

<details>
<summary>More about this change</summary>

- Keeps server-stored personal and community gesture delivery as the default
  while adding **Local Match Only for Personal Gestures** as a deliberate
  administrator choice.
- Matches media only from the viewer's installed valid gesture package with
  the exact message-time content hash. Missing media keeps canonical gesture
  text and shows an accessible local-unavailable notice without sender-media
  or hosted fallback.
- Applies each viewer's per-sender gesture-media choice before projection in
  supported live and history channels without affecting the same gesture from
  other senders or notifying the hidden sender.
- Adds one **P2P Connections** owner for the existing STUN/TURN keys. Cloudflare
  STUN is the fresh and restorable default; custom STUN servers remain
  supported.
- Keeps TURN Disabled with blank defaults, preserves configured values while
  disabled, requires an explicit relay warning and complete provider
  credentials, and offers no provider-login or anonymous TURN mode.
- Adds registry-derived P2P Avatar and P2P Gesture Local Match status and
  navigation without duplicate setting controls.

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

</details>

## Room stability, visibility, and media preferences

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
  attribution while keeping editable private-branding controls in their
  dedicated administration workflow.

</details>

## Member profiles and identity history

Signed-in community members can view a clear modern profile and manage their
approved public profile information while private account and recovery details
remain separate.

<details>
<summary>More about this change</summary>

- Added a responsive member profile with the member's current full-size avatar,
  Username, Display name, optional Name, public details, registration date, and
  previous display names.
- Added Account editing for approved public fields without exposing or
  replacing the private login and recovery email.
- Added identity-history safeguards that warn when a Username was previously
  used by a deleted account without revealing or inheriting that former
  account's profile.
- Added one shared member-action identity header for avatar and displayed
  chat-name entry points, showing the current Display name and stable Username
  without exposing the profile-only Name or changing immutable action targets.
- Added shared Setup/Admin profile-field limits with bounded impact review,
  one-unlock/no-second-confirmation behavior, stale protection, and retention
  of unchanged existing values above a newly lowered limit.
- Preserved existing member actions, avatar privacy, moderation boundaries,
  original attribution, and SQLite/MariaDB compatibility.

</details>

## Focused server ownership

Core server responsibilities now have smaller, explicit owners while existing
public behavior and compatibility remain unchanged.

<details>
<summary>More about this change</summary>

- Moved authentication and outside-content rate limiting, room-background
  upload storage, room/community event persistence, and Tool Log persistence
  out of the shared server composition file.
- Preserved stable function APIs, caller-owned transactions, database schema,
  authorization, CSRF, upload validation, event ordering, Tool Log facts, and
  SQLite/MariaDB behavior.
- Kept migration and recovery, session security, secure remote fetching,
  relationship lifecycles, diagnostics, client policy, media/WebRTC, and the
  future extension framework with their existing owners.

</details>

## Capability-safe first-party extensions and private site branding

ChatSpace now has a narrow framework for trusted, repository-owned first-party
extensions, with Private Site Branding as the sole initial pilot.

<details>
<summary>More about this change</summary>

- Added versioned manifests, a core executable allowlist, deny-by-default
  capabilities, compatibility and integrity checks, deterministic dependency
  handling, isolated namespaced storage, lifecycle controls, safe mode, and
  bounded failure reporting.
- Added one shared Setup/Admin Branding editor with ten destination-organized
  sections, inherited and per-page names, validated private logo uploads,
  explicit previews and fallbacks, stale-write protection, and deliberate
  reminder editing.
- Kept the original license action ahead of editable branding controls,
  preserved required ChatSpace authorship, source, license, version, and
  `Modified by exe` attribution, and rendered this canonical modification
  history through the optional public `exe's Changelog` utility.
- Kept security, authentication, authorization, moderation, recovery,
  canonical history, transport, concurrency, and every deferred candidate in
  core or at its approved future checkpoint.

</details>

## Upgrade release and recovery hardening

Existing installations now receive stricter release certification, clearer
update prerequisites, and source-proven upgrade recognition.

<details>
<summary>More about this change</summary>

- Made the release manifest a deterministic derivative of the exact deployable
  file inventory and current runtime schema authority.
- Added exact, source-proven predecessor identities for the initial ChatSpace
  CE release, the portable source baseline, an earlier hardened release, and Gesture Part 5.
  Missing, extra, partial, mixed, inconsistent, newer, or integrity-invalid
  layouts still fail closed.
- Preserved ordered migrations, verified private SQLite and MariaDB backups,
  durable recovery, idempotent request replay, and installation-specific
  configuration and content.
- Added actual disabled semantics, visible disabled styling, and nearby
  prerequisite explanations to unavailable update controls.
- Included the public README, authors, installation, license, modification,
  and version documents in the deterministic release inventory.

</details>

## Gesture Maker extension and optional compatibility enforcement

Gesture Maker presentation now runs as a trusted first-party extension, and
site owners can choose whether CoreChat proactively blocks ordinary runtime
when application-release and database compatibility have not been proven.

<details>
<summary>More about this change</summary>

- Moved Create/Edit Gesture presentation, editor previews and tools, package
  import/export presentation, and Catie attribution into one integrity-checked
  repository-owned extension while core retains identity, authorization,
  canonical history, archive validation, protected media, persistence,
  concurrency, privacy, moderation, and security.
- Added the Optional Core **Enforce database/release compatibility before
  runtime** control under shared **Setup/Admin -> System -> Database & Backup**.
  Its installation-private default is Disabled; it is excluded from ordinary
  optional bulk controls and remains available from the protected update and
  recovery surface.
- Disabled mode bypasses only the proactive compatibility blocker and never
  auto-migrates or claims compatibility. Active recovery maintenance and every
  unrelated security control remain enforced. Enabled mode preserves the
  protected release, backup, migration, and recovery workflow.
- Kept manual database export/import tools, public attribution, existing
  gesture behavior, SQLite/MariaDB portability, and all deferred game,
  voice-quality, and later-build scope unchanged.

</details>

## Fresh SQLite installation completeness and recoverable Setup

The deployable Community Edition release now includes the exact audited clean
SQLite seed required by the publicly presented SQLite Setup path.

<details>
<summary>More about this change</summary>

- Classified only the exact reviewed `db/chatspace.sqlite` baseline as a
  deployable Setup source while continuing to exclude generated databases,
  configuration, backups, recovery state, dumps, WAL/SHM files, uploads, and
  installation data.
- Made SQLite Setup validate the immutable source and randomized destination,
  complete the ordered current migration, verify the final clean schema and
  ledger, and only then atomically commit durable configuration.
- Added bounded installation-private attempt ownership so pre-configuration
  failures clean only their exact disposable destination, while an
  interruption after configuration preserves the validated current database
  and deterministically resumes at first-administrator Setup.
- Kept MariaDB Setup, existing-install upgrades and recovery, configuration
  isolation, and the Disabled Optional-Core runtime compatibility default
  unchanged.

</details>

## Setup backup import compatibility and transactional recovery

Full SQLite restores and portable bundles now use version-aware, fail-closed
preflight, migration, activation, and recovery owners.

<details>
<summary>More about this change</summary>

- Migrates only the four source-proven predecessor SQLite layouts or accepts
  an already-current database, while rejecting corrupt, foreign-key-invalid,
  unknown, partial, mixed, and newer layouts before activation.
- Preserves the uploaded source, stages and validates migration privately,
  keeps a verified pre-restore recovery copy, and completes the Windows-safe
  active-file transition in the next pre-bootstrap request before a database
  connection is opened.
- Imports source-proven portable version 1 bundles and current version 2
  bundles through complete envelope, identity, reference, file, MIME,
  signature, size, checksum, and destination validation.
- Validates immutable built-in link icons against the installed release,
  stages only bounded custom media, commits database and file effects as one
  recoverable attempt, and removes exact attempt-owned residue after failure
  or retry.
- Uses one matched transaction owner for SQLite immediate transactions and
  PDO transactions, certified on PHP 8.2.32 and PHP 8.4.23 without changing
  MariaDB transaction semantics.

</details>

## Moderation, trust, privacy, and message-protection foundations

CoreChat now provides one Optional-Core moderation and trust system with
versioned policy, explicit authority, privacy-safe moderation workflows,
message protection, and bounded retention controls.

<details>
<summary>More about this change</summary>

- Added one shared Setup/Admin master with preserved subordinate settings and
  mandatory security, legal-consent, blocking, active restriction, evidence,
  migration, and recovery boundaries that remain enforced when optional
  behavior is disabled.
- Added versioned Terms and Rules, registration and invitation policy,
  Installation Owner identity and atomic owner-only transfer, roles, trust
  states, capability grants, requests, appeals, notices, moderation cases,
  evidence, Personal Mute, and stronger Block reconciliation.
- Added mandatory HTTPS policy, trusted-proxy handling, and private opaque
  network identities. The earlier reversible exact-address/reveal-lease design
  is permanently superseded by the later opaque-network moderation correction.
- Added versioned Standard, server-encrypted, and private-chat E2EE protection
  with trusted devices, a separate recovery phrase, truthful transition
  coverage, and no staff decryption backdoor.
- Added configurable message and resolved-evidence retention, safety holds,
  batched idempotent expiry, session revocation, opaque lifecycle identity,
  and room/Installation Owner safeguards.
- Retired the unsafe direct Admin user-deletion action. Download Personal Data,
  voluntary deactivation, grace/cancellation, and irreversible Delete Account
  remain available only through the dedicated account-deletion workflow.

</details>

## Legacy import, Gesture/Admin usability, public documents, and profile identity

CoreChat now preserves source-backed legacy account identity during supported
imports, makes Gesture and Settings administration fully deliberate and
reachable, presents its original license and changelog safely in the browser,
and gives members a privacy-controlled Discord field with an opaque shareable
profile identity.

<details>
<summary>More about this change</summary>

- Migrates supported historical portable and full-SQLite identities without
  generated placeholders, credential loss, ambiguous collisions, or unsafe
  activation, while retaining fail-closed recovery and cross-database rules.
- Keeps public gestures in Server and owner-private gestures in Personal,
  preserves pagination and picker state, and adds explicit Admin Gesture dirty,
  validation, save, conflict, provenance, and navigation-warning behavior.
- Makes the complete Setup/Admin Settings surface vertically reachable through
  one accessible button-based unlock owner, including explicit review before
  disabling active optional Moderation and Trust workflows.
- Adds safe browser presentations for the canonical original license and this
  modification history without exposing their denied Markdown source paths.
- Adds optional Discord profile data with hidden-by-default authenticated
  visibility and stable opaque profile links that do not expose login
  usernames or internal numeric identifiers.
- Establishes condition-based bounded recovery and populated visual
  certification while preserving SQLite/MariaDB, security, configuration,
  accessibility, private-completion, and public-release boundaries.

</details>

## Measured capacity, diagnostics, and System Health

CoreChat now uses one measured operational-capacity authority, one finite
production diagnostic selector, and one sanitized Admin System Health
projection without weakening mandatory security or changing trust policy.

<details>
<summary>More about this change</summary>

- Adds Shared/Conservative, Standard, Dedicated/High-Capacity, and Custom
  capacity states. Fresh installations begin with Shared/Conservative;
  upgrades preserve their existing behavior as Custom until an administrator
  deliberately reviews and applies a measured profile.
- Keeps every profile value inside a certified hard envelope and presents
  exact current/proposed values, utilization, provenance, and impact before
  one atomic, revisioned, idempotent profile action.
- Adds Off, Errors only, Errors and warnings, and temporary 60-minute Verbose
  client diagnostic collection. Errors only is the default; Verbose returns
  to its immediately preceding finite mode and repeated requests do not extend
  its lease.
- Keeps diagnostic screenshots off with zero-day retention by default,
  proposes 30 days when deliberately enabled, preserves holds and active
  investigations, and performs evidence cleanup only through an explicit,
  bounded, leased, retry-safe maintenance owner.
- Adds a capability-restricted System Health workspace containing only
  sanitized aggregate capacity, migration, storage, host/runtime, transport,
  extension, diagnostic, and maintenance facts. It excludes evidence content,
  member content, credentials, network addresses, connection details, and
  filesystem paths.
- Preserves Polling as the default and permanent fallback. Unknown or
  unproven SSE/WSS capability remains unsupported.

</details>

## Capability-safe room event transports

CoreChat now delivers room and community events through one authoritative
cursor, authorization, filtering, and replay contract while retaining Polling
as the mandatory default and permanent fallback.

<details>
<summary>More about this change</summary>

- Adds one shared Setup/Admin choice between **Polling only — Default** and
  **Automatic best available**. Automatic tries only capability-proven WSS,
  then capability-proven HTTPS Server-Sent Events, then Polling.
- Keeps the existing room/community event ledgers as the sole persistence,
  ordering, and history authority. Transport adapters perform framing and
  lifecycle work only.
- Adds bounded client cursor and deduplication ownership shared across
  transport changes, reconnect, and fallback without duplicating, reordering,
  or inventing event truth.
- Adds a one-batch Server-Sent Events adapter over the same server-side
  authorization and privacy projection. It is eligible only when HTTPS,
  response-buffering, and direct or proven proxy ownership are established.
- Includes an inert same-origin WSS client adapter boundary while truthfully
  keeping WebSocket unavailable until a repository-owned persistent process
  and its dependency, deployment, TLS/proxy, authorization, resource,
  lifecycle, and cleanup audits are complete.
- Revalidates room access on every bounded server wait iteration and returns
  truthfully to Polling if an optional adapter fails, without changing active
  room state or describing transport encryption as end-to-end encryption.

</details>

## Runtime issue reconciliation and clearer administration

CoreChat now groups recurring runtime issues without exposing private
diagnostic content, gives authorized staff a complete evidence lifecycle, and
uses a compact, human-readable Setup and Admin settings presentation.

<details>
<summary>More about this change</summary>

- Adds bounded issue grouping, recurrence tracking, sanitized environment
  context, revisioned states, retention holds, reviewed support exports,
  hosted-pending handoffs, and separately confirmed evidence deletion.
- Keeps network addresses out of issue views, exports, screenshots, handoffs,
  System Health, and ordinary Tool Logs. The later opaque-network moderation
  correction removes the superseded exact-address security-export path.
- Reorganizes Settings and Manage Users into accessible selectable sections,
  preserves one shared settings owner and one sticky save workflow, and moves
  network, retention, and moderation panels to their canonical destinations.
- Replaces large setting cards with compact grouped rows across Admin and the
  guided Setup subset, with adjacent units and ranges, accessible information
  disclosures, persistent validation, and responsive two-column or
  single-column layouts.
- Keeps expanded plain-language setting information clear of the global
  sticky Save area while preserving focus, scrolling, and the shared settings
  owner.
- Adds synchronized accessible role-color controls, plain-language retention,
  branding, Limits, and Gesture presentation, while preserving the exact
  **ChatSpace Community Edition** default branding and required attribution.
- Preserves SQLite and MariaDB behavior, migration and recovery compatibility,
  private configuration, security boundaries, and separation from the
  irreversible account-deletion workflow.

</details>

## Admin focus, opaque-network moderation, and stronger visual safeguards

CoreChat now uses one intentional Admin heading-focus treatment, never reveals
or reversibly stores a network address, and gives the Installation Owner a
privacy-bounded, source-backed Manual Network Ban workflow.

<details>
<summary>More about this change</summary>

- Replaces browser/default or detached Admin heading focus rectangles with one
  generic theme-consistent indicator that wraps its heading while preserving
  programmatic focus, announcement, scroll ownership, navigation state, and
  back/forward behavior.
- Preserves inline setting information controls as compact native buttons on
  the owned setting-label line, outside checkbox labels, with accessible
  naming, pointer/touch and keyboard activation, Escape close/focus return,
  and responsive/enlarged-zoom alignment.
- Removes every raw/exact network-address reveal, output, export, lease, and
  reversible store. Request addresses are used only ephemerally for trusted
  proxy processing, IPv4/IPv6 normalization, keyed/versioned opaque identity
  derivation, and enforcement.
- Migrates prior reversible address records and reveal leases away while
  preserving required opaque-network, moderation, integrity, and audit
  records across SQLite and MariaDB.
- Adds **Manual Network Bans** under shared Setup/Admin Network Protection.
  It defaults Disabled, is Installation-Owner-only, is excluded from broad
  bulk operations, and never bans automatically.
- Requires source-backed context selection, recent authentication, reason,
  privacy/NAT warning, affected-account identity preview, bounded duration or
  Permanent, deliberate confirmation, idempotent server enforcement,
  affected-session reconciliation, self-lockout protection, and an
  authenticated idempotent removal flow.
- Shows owner-only, privacy-bounded context and ban status without implying
  that a network identifies a person or household. Non-owner menu, URL, API,
  altered-role, stale-session, and forged-request access fails closed.
- Permanently requires populated pixel inspection of materially affected Admin
  destinations across desktop, tablet where applicable, narrow/mobile, and
  enlarged zoom, including focused headings, selected navigation, inline help,
  grouping, clipping, overlap, horizontal overflow, sticky actions, dirty/error
  states, and browser/default focus artifacts.

</details>

## Clearer Settings order, community defaults, and operational labels

CoreChat now makes moderation policy easier to find, restores original-style
fresh-install access through an explicit preset, and clarifies several
administrative labels without changing saved upgrades or runtime limits.

<details>
<summary>More about this change</summary>

- Keeps Settings Overview first, then places Moderation, Privacy & Security
  before Branding and Moderation and Trust before Network Protection through
  one shared Setup/Admin registry order.
- Adds **Open Community (Original-style)** as the fresh-install-only default:
  Open self-registration, immediately Trusted ordinary accounts, Disabled
  optional outside-content confirmation, no guests, and mandatory security
  unchanged.
- Preserves every existing installation's saved policy, trust, grants,
  requests, and history. Deliberate Approval still permits ordinary chat while
  protected features await Administrator approval.
- Renames diagnostic screenshot retention and three capacity warning
  thresholds in plain language without changing their identifiers, values,
  validation, calculations, or runtime behavior.

</details>

## Private voice and selective webcam controls

CoreChat now adds independently controlled private-call admission,
transmission preferences, and selective webcam audiences while preserving the
established room-wide voice and webcam experience.

<details>
<summary>More about this change</summary>

- Adds server-authoritative private voice membership, invitations, requests,
  blocking/moderation eligibility, idempotent decisions, and exact
  180-second expiry and retry boundaries.
- Adds Voice activation, Push to talk, Push to mute, and Always muted on join,
  with an unassigned device-local binding and fail-safe focus/lifecycle
  handling.
- Adds confirmed webcam audiences that send no live track to excluded members,
  retain local preview, show the saved avatar as fallback, and clear grants on
  every relevant lifecycle transition.
- Adds a registry-derived **Voice, Media & Players** overview to shared Setup
  and Admin Settings and a separate member **Voice & Webcam** preferences
  destination without creating duplicate setting owners.
- Keeps all new optional controls Disabled by default, preserves stored
  subordinate settings during disablement, and retains ordinary room
  voice/webcam behavior.
- Records a privacy-safe media audit outcome that retains browser-managed
  quality and the four-participant supported private-call ceiling.
- Clarifies that initial policy acceptance applies to the first administrator
  account while preserving both required policy controls and records.

</details>

## Safe irreversible account deletion

CoreChat now provides one self-service Delete Account flow with strong
confirmation, atomic cleanup, privacy-safe retained history, and explicit
ownership safeguards.

<details>
<summary>More about this change</summary>

- Adds Delete Account to Account → Security & Privacy with recent
  authentication, current-password reauthentication, exact `DELETE`, CSRF,
  stable replay protection, and accessible status handling.
- Prevents Installation Owner deletion until ownership is transferred and
  requires an eligible successor for owned rooms so shared history remains
  intact.
- Atomically revokes sessions and credentials, clears private profile and
  recovery state, ends active relationships and media state, and removes
  unshared personal media across SQLite and MariaDB.
- Preserves required chat, moderation, safety, legal, audit, shared-asset, and
  integrity history under a privacy-safe **[Deleted User]** identity.
- Releases the old username for a wholly new immutable account without
  inheriting former history, profile, grants, ownership, or dates.
- Replaces technical Message Protection prompts with one accessible dialog,
  clear protection names, a private-note fingerprint, one E2EE confirmation,
  and content-free participant notices.

</details>

## Private peer-to-peer avatars

CoreChat now offers an optional peer-to-peer avatar delivery mode that keeps
received avatar bytes in session memory and preserves the established
server-stored and built-in avatar choices.

<details>
<summary>More about this change</summary>

- Adds a Disabled-by-default **P2P Avatar** capability to the shared
  **Voice, Media & Players** Settings overview without duplicating setting
  ownership.
- Authorizes each transfer for the exact authenticated room participants,
  current avatar identity, and short-lived session context, and revalidates
  visibility and blocking policy before delivery.
- Applies account-wide and exact-avatar hide choices before request, transfer,
  display, or retention while allowing a genuinely new avatar identity after
  an exact hide.
- Uses direct or configured STUN connectivity first, keeps TURN fallback
  explicitly Disabled by default, preserves saved subordinate settings, and
  never exposes relay credentials or network details.
- Keeps received bytes in session memory only, validates bounded GIF/WebP
  content, uses the standard avatar until retrieval succeeds, and clears
  peers, channels, buffers, object URLs, tokens, and timers across every room,
  participant, session, and identity lifecycle.
- Shows only currently available member Voice & Webcam preferences, preserves
  hidden values during policy disablement, and restores those values when the
  corresponding option becomes available again.
- Leaves ordinary room voice/webcam behavior and the default server-stored
  avatar path unchanged.

</details>

## 2026-08-25 - Crisp enlarged Backgammon dice

- Preserved installation-private Original OCX dice at the native 100% board size.
- Added project-owned exact-size Backgammon dice sprite sheets for 125%, 150%, 175%, and 200% viewer-local board sizes.
- Updated the Backgammon renderer to select the matching 25px, 30px, 35px, or 40px sprite without browser resampling when sufficient layout space is available.
- Five Dice and all other game artwork remain unchanged by this correction.

## 2026-08-25 - Crisp enlarged Five Dice artwork

- Preserved installation-private Five Dice normal and held dice at the native 100% Classic board size.
- Added project-owned exact-size normal and orange-glowing held dice sprite sheets for 125%, 150%, 175%, and 200% viewer-local board sizes.
- Enlarged Classic appearance now selects 70px, 84px, 98px, or 112px artwork without browser resampling when sufficient layout space is available.
- No game rules, scoring, randomness, multiplayer state, or database structure changed.

## 2026-08-25 - Crisp enlarged Acey Deucy dice

- Preserved Acey Deucy's installation-private Original OCX dice at the native 100% board size.
- Added Acey-specific project-owned exact-size green-edged dice sprite sheets for 125%, 150%, 175%, and 200% viewer-local board sizes.
- Updated the point-game renderer to select Acey Deucy's 25px, 30px, 35px, or 40px artwork independently from Backgammon.
- No game rules, turn authority, randomness, multiplayer state, or database structure changed.

## 2026-08-30 - Built-in point-lane and classified audio correction

- Moved Backgammon/Acey Deucy point numbers into the frame gutters and expanded responsive normally spaced checker lanes to seven before capped overlap.
- Added distinct built-in Acey Deucy boot-stomp, blocked-wall, and true hit-to-bar spoken `Booted!` sounds.
- Added distinct spoken `Gammon!` and `Backgammon!` cues driven by existing authoritative terminal classification.
- Preserved Classic/OCX media, existing server gameplay and scoring, and the separate Backgammon hit cue.
## 2026-08-25 - Public Blackjack sound-effects package

- Added a 16-file CC0-only public Blackjack sound-effects mix for cards, chips,
  player actions, outcomes, and round transitions.
- Bound gameplay playback to increasing authoritative session-state versions so
  refresh and repeated rendering do not replay consumed cues.
- Preserved viewer-local Sound FX and master-volume controls and artwork-mode
  independence.
- Added public per-file provenance and private acquisition hashes.
- Added no music, speech, gameplay-rule change, database migration, runtime
  network dependency, Tetris Versus change, or Space Invasion change.
## 2026-08-25 - First-Party Hearts

- Added an authoritative Hearts game for exactly two or four humans, with no
  bots.
- Added the source-backed 28-card two-player ruleset and standard four-player
  Hearts as separate modes.
- Added private hand/pass/widow/captured-card projections, deterministic deals,
  passing, legal play, scoring, moon handling, responsive UI, actual avatars,
  and CC0 card-game cues.
- Reused public card-presentation infrastructure without copying private Spades
  OCX media or public-reference artwork.
- Added no database migration or unrelated game work.

## 2026-08-25 - First-Party Hearts corrections

- Corrected the responsive viewer hand so every card rank and suit remains readable.
- Separated opponent card backs from score, trick, and hand-point text.
- Centered left and right opponent seats on the same table centerline.
- Added project-original CC0 Hearts event cues for Hearts broken, Queen of Spades, point tricks, zero-point tricks, shoot-the-moon, win, and loss.
- Preserved generic CC0 card handling for deal, ordinary play, pass, and shuffle.
- No bots, database migration, Tetris Versus change, or Space Invasion change was introduced.

## 2026-08-25 - Hearts and Blackjack full-avatar frames r4

- Replaced circular cover-cropping on Hearts and Blackjack table avatars with 44 px rounded-rectangle contain frames.
- Square, portrait, and landscape source images retain their full composition without stretching.
- No game rules, database schema, bots, Tetris Versus, or Space Invasion behavior changed.

<details>
<summary>First-Party UNO (August 26, 2026)</summary>

- Added a repository-owned two-to-ten-player first-party UNO extension with server-authoritative hidden cards, legal actions, standard action-card behavior, Wild Draw Four challenges, UNO declaration/catch, scoring, and results.
- Added a responsive original CoreChat table with real member avatars, accessible controls, public CC0 four-color card art, and public CC0 card-game audio.
- Added explicit CC0 provenance and an off-board Mattel trademark/non-affiliation notice while excluding official logos and branded trade dress.
- No database migration was required. Tetris Versus and Space Invasion were not changed.

</details>

## 2026-09-12 - Bounded Spades Expert bot response time

- Added one shared 750 ms computation budget for each synchronous Practice bot chain and a 220 ms search slice for each Expert bot decision.
- Preserved authoritative legal-card enforcement and made incomplete Expert searches fall back to the existing deterministic legal tactical heuristic.
- Added focused source and runtime latency contracts so a minute-long bot turn cannot pass the targeted Spades audit again.
- Restored the existing authoritative per-trick card and winner records to the visible Round History panel.
- Refit only the compact top Spades seat between the score rail and played-card area so the partner avatar is not clipped or moved into play space.

## 2026-09-12 - Explicit Nil exchange options

- Renamed the existing option to Blind Nil two-card exchange so its trigger is clear before players accept the rules.
- Added a separate Regular Nil one-card exchange option that is disabled by default and requires one private card in each direction when enabled.
- Made Blind Nil take priority so regular and Blind Nil exchanges cannot stack in the same partnership hand.
- Generalized authoritative reducer validation, private projection, Practice bots, and the shared exchange dialog to use the required one-card or two-card count.
- Preserved old saved games by treating pre-existing exchange records as Blind Nil two-card exchanges.

## 2026-09-12 - Spades subdirectory presentation assets

- Replaced domain-root Spades artwork references with installation-relative paths so the table room, spade emblem, and Practice bot fallbacks load when CoreChat is hosted under `/core` or another subdirectory.
- Refreshed the Spades presentation cache identities and added a source contract that rejects future domain-root asset regressions.
- No game rules, database schema, or non-Spades artwork changed.

## 2026-09-12 - Spades current-hand history and reliable trick continuation

- Limited Round History to completed tricks from the active hand and placed its entries in an internal scrolling region.
- Kept the dialog within the visible game viewport so long trick lists no longer lengthen the surrounding website page.
- Allowed one connected seated human to submit the non-choice settlement transition after its authoritative dwell deadline, including when a Practice bot won the trick.
- Preserved spectator rejection, the 1.04-second ordinary dwell, the 2-second Nil-set dwell, and the winning player as the next trick leader.

## 2026-09-12 - Subdirectory-safe remaining game assets

- Replaced the remaining domain-root game asset URLs in Puppy Panic, Nested Four, Chinese Checkers, and shared Classic cue metadata with installation-relative paths.
- Preserved existing artwork and audio ownership; only URL resolution changed.

## 2026-09-12 - Stable optional viewport-height fitting

- Made shared height-fit style writes idempotent instead of removing and reapplying zoom during every resize observation.
- Preserved each viewer's saved option, board-size multiplier, readability floor, and page-scrolling fallback.

## 2026-09-12 Spades score metric glyph bounds

- Expanded the top score metric line box and released glyph clipping so Total, Last Hand, and Bags render completely and symmetrically for both teams under fitted and fractional board sizes.

## 2026-09-12 - Spades optional rules and card-play reliability

- Added an optional minimum team bid with None, 2, 3, 4, and 5 choices; None remains the default.
- Added an optional Joker-Joker-Ace deck with distinct Big and Little Joker cards, ordered above the Ace of Spades, while removing the 2 of Clubs and 2 of Hearts to retain 52 cards.
- Added optional 10 for 200 scoring with a matching -200 failed-contract penalty and an additive Boston bonus for taking all 13 tricks.
- Added selectable bag-penalty rules while preserving the existing ten-bag penalty as the default.
- Added the optional legacy back-door ending, disabled by default, with strict below-threshold qualification and draw handling for tied qualifying teams.
- Preserved the separate optional Regular Nil one-card and Blind Nil two-card exchanges, with Blind Nil taking priority when both could apply.
- Corrected rapid double-click card play so selecting a card no longer replaces its element before the second click can submit the play.
- Replaced the cartoon-style Joker figures with a crowned gold heraldic spade and a smaller silver-teal harlequin-spade seal; both titles remain isolated from decorative lines.
- Added no database migration and left every new gameplay variation disabled unless a game creator selects it.
