# Modification Notice

This distribution of ChatSpace Community Edition has been modified by **exe**.

The original authorship, copyright, license, and attribution notices remain in
place.

See [AUTHORS.md](AUTHORS.md) for the original project credits and
[LICENSE.md](LICENSE.md) for the governing license.

# Modification History

## Pool turn timers and compact chat menus - 2026-09-19

Post-Build 000064 follow-up to public application commit `db6f862`.

- Add a violet countdown around the active Pool player's avatar, with 30 seconds by default and 45/60-second options. Server deadlines handle timeouts, pauses and reconnects; solo practice remains untimed.
- Group avatar settings, visibility controls and block/mute actions into smaller menus. Support hover opening, nested keyboard navigation and administrator-specific tool labels.
- Keep long direct-message names inside their tabs, with full names on hover, and center the Community Chat label.
- Show required Pool break and push-out choices on the cloth. Use clear labels such as **Put the 8 ball back** and **Rerack**, while preserving rule outcomes, player permissions and table layout.
- Preserve Pool physics and the frozen v4 reference examples.

## Pool tight-contact loop correction - 2026-09-18

- Resolve leftover inward velocities after simultaneous group impacts so touching balls do not repeatedly collide without advancing simulation time.
- Match browser and server handling; preserve momentum, passive energy behavior, power/spin tuning, table geometry and the existing iteration guards.
- Retain repeatable advanced-shot paths and test complete 8/9-ball games through the server.

## Pool Expert cluster-shot search - 2026-09-18

- Build approaches to close groups and shared contact points from the current ball positions, then refine aim, power and both spin axes together.
- Evaluate every attempt with the current physics and existing legality, called-pocket, next-position and safety scoring. No saved example answers or physics changes.
- Keep Expert within 30 seconds / 5,000 simulations, with unchanged Easy/Normal and existing cancellation/pacing.

## Pool Expert joint rail-shot search - 2026-09-18

- Refine aim, power and combined spin together on difficult cushion-first shots, including clear paths around blocking balls and near-rail contacts.
- Preserve the existing direct-shot, combination and safety searches; evaluate new candidates with the same physics, called-pocket rules, next-shot position and opponent opportunities.
- Keep the 30-second / 5,000-simulation Expert ceiling, earlier completion for straightforward positions, and unchanged Easy/Normal policies. No saved-layout answers or physics changes.

## Pool Expert thinking allowance - 2026-09-18

- Allow Expert up to 30 seconds and 5,000 simulations, with more outcome exploration on difficult positions and earlier completion when its search finishes. Easy and Normal retain their budgets.
- Keep chat and standalone workers alive for the longer search; preserve cancellation, retry and motion pacing.
- Clarify that the saved Z example demonstrates a two-cushion kick. The later original-layout reconstruction and joint rail refinement verify the mechanism with documented path differences; the earlier extra search used no spin.

## Pool advanced practice shots and Expert search - 2026-09-18

- Add 23 repeatable practice examples with saved aim, power and spin, including cuts, multi-cushion banks/kicks, combinations, repeated contacts and position play. Frozen-rail examples are planar analogues; the Z example demonstrates two cushions, not exact simultaneous third-cushion contact.
- Expand Expert candidate search and compare actual outcomes, next-shot position, opponent opportunities and small-input robustness. Search is bounded; no claim of every possible shot or a globally optimal choice. Easy and Normal policies remain unchanged.
- Preserve personal/shared library edits and deletions, the original bank examples, physics, rules and frozen references.

## Pool recorded sounds and controls - 2026-09-18

- Replace generated Pool effects with the approved recorded cue strike, ball collision, cushion hit and two alternating pocket drops, with source attribution and existing mute/volume behavior.
- Clarify Shift-arrow fine aiming, validate cue-ball placement immediately, and prevent actions while shots or replays are still animating.
- Retain the double-click placement caption and remove the large yellow canvas focus outline without disabling keyboard input.
- Preserve physics, rules, bot search and frozen v3 examples.

## Pool Practice bots - 2026-09-18

- Add Easy, Normal and Expert opponents to both 8 Ball and 9 Ball Practice matches.
- Use the existing authoritative physics and rules for every bot shot. Expert searches banks, kicks, combinations, spin, next-shot position and defensive safeties; Easy uses imperfect aim without position planning.
- Show bot aiming before each shot, preserve full rolling animations and place thinking/retry status below the controls.
- Preserve Solo free shooting, human matches, saved games, immediate rematches, rankings isolation and the frozen v3 references.

## Pool permanent review and reference pack v3 - 2026-09-18

- Add 37 permanent administrator Pool examples for 8 Ball, 9 Ball, banks, spin, fouls, wins, push-out, ball-in-hand and solo editing/rewind.
- Preserve all 316 previous frozen sequences and resources; isolate the new Pool renderer and rebase replay clocks without changing saved trajectories.
- Fit Pool within the review page, keep account setup libraries outside isolated examples, and install v3 alongside private v2 with verified Classic-media reuse.
- Complete a 17-shot uninterrupted browser 9-ball match, with banks, fouls, re-spotting and a legal win.
- Testing is WIP.

## 8 Ball Pool - 2026-09-17

Pool ball numbers are larger and sharper, with the gloss highlight moved away from the number patch. Both 8 Ball and 9 Ball retain their ball size, natural rolling orientation and existing physics.

Pool now offers 8 Ball and 9 Ball matches plus both solo practice racks, with lowest-ball guidance, combinations, push-outs, foul warnings and variant-aware saved layouts. The ten verified bank setups install into the shared library without a test database; existing edits/deletions are preserved. Accepted 8-ball physics is unchanged.

Pool saved practice setups now show an administrator-only testing readout with stored power, directional spin, and aim values. Loading a setup continues to restore its exact saved inputs automatically; ordinary players retain the existing library view.

Pool now starts with the owner-selected Carbon fiber table, Burgundy red cloth and Crimson eclipse cue; saved appearance and valid rematch cue choices remain available. Room connections stay active when a reload/leave warning is cancelled, with cleanup delayed until the document actually exits. Verified ten repeatable bank setups in local practice and two-player finish/reconnect/rematch behavior.

Pool simultaneous multi-ball impacts now preserve contact geometry before resolving the group. Numerical contact rounding no longer changes the impulse calculation at different simulation time steps. Dense racks and symmetric split hits are stable across ball ordering and time steps; browser/server agreement, banks and spin remain checked. Power, spin controls and cushion settings are unchanged.

Pool pocket calls can now be selected directly on the table or with the keyboard-accessible list. A red marker is shared with the opponent and spectators. Required calls must be confirmed before shooting; the server rejects shots that contradict the displayed selection. Calls clear after each shot, with the marker retained during shot playback. New games still default to No calls; physics is unchanged.

Pool now defaults new games to No calls, as requested. Every shot and 8 ball only remain optional; existing game states retain their selected calling rule and older states without the setting keep their original every-shot rules. This supersedes the earlier default; physics is unchanged.

Pool matches now offer pocket-calling choices: Every shot, 8 ball only, or No calls. Existing games and the default retain Every shot. Non-called modes assign groups from the first legal post-break pot and retain the turn for legal own-group pots; early eight, scratch, wrong-contact and no-rail fouls remain enforced. Solo practice is unchanged. The new Android match has been inventoried separately; no new speed or spin tuning is adopted.

Pool rolling slowdown now follows the two clear center-hit mobile practice flights more closely. Rolling friction is reduced by 12.5%; launch power, sliding/spin loss, cushion and collision coefficients are unchanged. Bank pots, spin controls and browser/server agreement remain verified; exact Miniclip trajectory parity is not claimed.

Reloading a game result restores the game and Play Again controls in the same browser tab. Explicit exit and switching games clear or replace the remembered view; server membership remains authoritative.

Pool adds distinct strength-sensitive cue, ball, cushion and pocket sounds; saved ball-surface orientations across reconnects; and read-only normal or slow last-shot replay in solo practice. Full rack-to-finish match and rematch checks pass.

Pool corner openings and angled cushions now match the approved larger-ball proportions. Visible cushions and aiming boundaries use the collision geometry. Table size, ball size, shot power, spin and pocket capture radius stay unchanged. Added Crimson eclipse, a full-shaft red-and-black cosmetic cue, visible to other players and retained for rematches.

Pool practice setups now follow the signed-in account, with automatic idempotent import of prior browser saves. Personal and administrator shared setups support updating, visual previews, and recoverable deletion. Shared editing remains administrator-only, and stale edits are rejected. Pool aiming guides now shorten the target line on thin cuts while retaining the angle-dependent cue departure, matching the Android reference behavior.

Pool now uses larger balls on new tables, with unchanged table dimensions and cosmetic decoration along each cue shaft. Solo practice includes a ball editor, clear/rack controls, shot rewind, and named personal setups with load/rename/delete. Administrators can publish named practice setups for everyone to load; shared changes require administrator permission. Existing tables retain their original ball geometry until reset. Nine-ball remains a disabled Coming soon choice.

Pool gentle shots now use a softer minimum that blends into the existing power curve at 20%; medium and full power remain unchanged. The target-ball direction guide is shorter, with a separate short cue-ball departure guide at contact.

Pool balls now keep their final rolling orientation when stopped and between consecutive shots, instead of automatically turning numbers upward. The pocketed-ball display stays readable. Shot physics are unchanged.

Pool adds personal table frames: walnut, rosewood, maple, carbon fiber, brushed steel and midnight lacquer, with an independent burgundy-red cloth option. Appearance stays in your browser and does not change other players or game physics.

Pool adopts the measured medium/high power curve in browser and server. Gentle shots through 35% are unchanged; cue finishes remain cosmetic. Spin, friction and collision rules are unchanged.

Pool vertical power increases when dragged upward and decreases downward. Keyboard power, cue pullback and held plus/minus controls remain unchanged.

Pool power now sits beside spin above the board as a vertical pull-down meter. Mouse cue pullback, keyboard power and held plus/minus controls remain available; the table size and game physics are unchanged.

Pool turn outlines now last three seconds. Table options can keep playable targets highlighted throughout your turn; default is the brief reminder. Highlights stay hidden during movement, placement and opponents turns.

Pool briefly outlines legal target balls before every playable shot, including continued turns, and places routine shot status below the controls. Reminder waits for movement/placement to finish and does not restart on duplicate updates.

Pool aiming now marks predicted illegal first contact with a red crossed circle, matching server group and 8-ball rules while preserving solo free shooting and break exemptions. Testing remains WIP pending owner acceptance.

- Refine Pool aim buttons and arrow keys to 0.05-degree taps, with delayed hold repeat and finer Shift-arrow adjustments.

- Keep Pool horizontal scrolling accessible at the viewport edge in narrow chat layouts, using the same page owner for both axes.

- Name the game Pool in the games room; retain 8 Ball as the current rules variant.

- Preserve each player's selected cue across rematches, including rotated seats.

- Refresh game script cache tags and update regression coverage for the central release inventory, current dice animation lifecycle, and native module imports.

- Add scrollable side clearance without shrinking the pool table; preserve the full cue length and consistent power pullback at corners.

- Allow picking up the cue ball directly over a pocket after scratching, and use the framework's single game scrollbar with wheel support over the table.

- Add cue space above and below the pool table while preserving its original displayed width; keep cue grabbing and ball placement aligned when resized.

- Restore the first-contact aiming guide regardless of shot power, removing the short stopping-distance marker introduced during physics integration.

- Restore mouse placement after a cue-ball scratch; dragging and double-click confirmation work while the cue ball is pocketed.

- Roll numbered and striped ball artwork around the visible sphere during travel, then ease numbers toward the viewer when stopped. Shot physics is unchanged.

- Correct tight-rack contact response for wider breaks at the existing calibrated power, and show the complete cue-ball approach on live shots. Preserve authoritative recorded trajectories and reconnect catch-up.

- Automatically rack new games and rematches; start shots with the cue close to the ball and provide a longer visible power pullback.

- Add two-human 8 Ball and permanent solo Practice with fresh racks, free cue-ball placement, and repeatable shot layouts.
- Add server-owned shots and foul rules, shared trajectory playback, six cosmetic cues, direct spin and mouse/keyboard controls, resizing, recording, save/restore and rematches that preserve game conversation.
- Adapt and attribute Pooltool collision and motion models; retain the approved approximate maximum-power calibration.
- Testing is WIP. Local checks do not replace owner acceptance; no pool bots are included yet.



## Reliability corrections - 2026-09-17

- Bind pending sends and replies to their original conversations. Keep failed text recoverable and retry ordinary, protected and game messages with the same request identity.
- Acknowledge room/community events after processing; recover failed handlers without skipping subsequent updates.
- Compare popup edits against saved values, make discard truthful, preserve other library edits when saving one row, guard pending saves, restore focus and use the shared gesture deletion confirmation.
- Verify final private/public package contents and exact identities with isolated installation and upgrade checks. End-user source edits remain permitted by default.
- Testing is WIP; local technical checks do not replace owner acceptance.


## Dominos and shared popup improvements - 2026-09-17

- Add Dominos (All Fives) for two, three and four players or teams, with selectable seats, adjustable targets, fair starter rotation, a four-ended spinner and round scoring.
- Add responsive domino artwork, on-board avatars and scores, team glows, legal placement previews, drag-to-play, drawing and move animations, placement sounds and a victory sequence.
- Add Normal and qualified Expert Practice bots, public-information search, readable turn pacing, bottom thinking/retry messages, recordings, saves and rematches. Preserve Normal behavior.
- Add shared movable popup headings, visible close controls, outside/Escape dismissal, draft and pending-action safeguards, and a retry for canceled password confirmation.
- Testing is WIP.



## Game Review reference pack v2 - 2026-09-16

- Add the approved Five Dice blank, tumbling and smooth-landing presentation to a new frozen reference pack, preserving all other game references and sequences.
- Add v2 installation alongside the private v1 backup and verified reuse of its Classic media, with an updated optional download.
- Testing is WIP.

## Five Dice smooth roll landing - 2026-09-16

- Add a gradual landing transition from tumbling dice into the original resting artwork, completing before the rolling view clears. Keep held dice still and retain existing roll results and timing.
- Testing is WIP.

## Five Dice edge-over-edge rolling - 2026-09-16

- Add six-sided Built-in dice that tip over their edges during rolls, with fixed face numbers and the real result at rest. Preserve kept dice, Classic motion and existing timing.
- Testing is WIP.

## Five Dice rolling motion - 2026-09-16

- Add visible Built-in dice rotations and changing faces during rolls, settling on the actual result while kept dice remain still. Preserve Classic rolling artwork and existing sound/timing.
- Testing is WIP.

## Five Dice unrolled dice - 2026-09-16

- Add blank Built-in dice before the first roll so placeholder values cannot look like a Yahtzee. Preserve Classic unrolled presentation, real rolled faces and all game/bot rules.
- Testing is WIP.

## Five Dice full-scorecard Expert - 2026-09-16
Added full-scorecard planning to Five Dice Expert with generated strategy values for the existing Joker and bonus rules, preserved Easy/Normal play and readable pacing, and added a safe fallback when strategy data is unavailable. Added reproducible first-party table generation and research attribution in the consolidated notices. Testing is WIP.

## Five Dice Practice bots - 2026-09-16
Added Easy, Normal and Expert Five Dice bots in optional empty lobby seats, with Practice-only play, visible holds and rolls, and below-board thinking and retry controls. Added scorecard-aware probability search, bot-aware outcomes, protected game recordings and save/rematch coverage while preserving solo play. Testing is WIP.

## Puppy Panic Practice bots - 2026-09-16
Added Easy, Normal and Expert Practice bots for the Core deck and Mischief Pack, selectable in empty lobby seats. Added paced turns and counters, below-table thinking and last-move feedback, protected game recordings and save/rematch coverage. Added safeguards for private card views and empty-hand card requests, plus a rectangular default avatar that fits the Puppy Panic portrait frame. Testing is WIP.

## Blackjack Expert - 2026-09-16
Added a qualified Expert Practice bot with exposed-card memory, count-informed hand decisions and betting based on public standings and rounds remaining. Added shuffle/legacy-memory safeguards and recording/save/rematch coverage. Existing Easy and Normal play is preserved. Testing is WIP.

## Blackjack Practice bots - 2026-09-16
Added optional Easy and Normal Blackjack players in empty lobby seats, Practice-only play, exact-rule basic strategy, readable per-action pacing and bottom thinking/retry feedback. Added shared Blackjack move/score recordings and bot decision traces, with saves/rematches and hidden-card protection. Testing is WIP.

## Acey Deucy Practice bots - 2026-09-16
Added optional Easy, Normal and Expert Acey Deucy bots for Current and European Double-Double rules, with Practice-only lobby seats, variant-aware dice-sequence and reply search, verified rolls, paced individual checker moves, bottom thinking/retry feedback, recordings, saves and rematches. Added visible Built-in checker travel and registered bot win/hit presentation. Testing is WIP.

## Nested Four Practice bots - 2026-09-16

Added optional Easy, Normal and Expert Nested Four bots for both reserve-covering rules, with remembered public moves, committed-piece search, Practice-only lobby seats, authoritative move validation and shared game recordings. Added paced visible selection and movement for humans and bots, saved-game memory, rematch support and reserved bottom thinking/retry feedback. Testing is WIP.

## Chinese Checkers guide colors - 2026-09-16

Added matching marble colors to the goal triangle, labels and direction arrow. Testing is WIP.

## 2026-09-16 - Chinese Checkers goal guide

Added a personal destination outline, Your goal label and direction arrow to Chinese Checkers, with a saved Goal guide setting that defaults to On. Added support across board appearances and viewer-relative seats. Added visible marble steps and hops along confirmed move routes, last-move markers, and bot waits for completed animations. Testing is WIP.


## 2026-09-16 - Readable Practice bot turns

Added viewing intervals for individual bot actions across Chinese Checkers, Chess, Checkers, Backgammon, Spades, Hearts, UNO and Battleship. Added separate visible server replies for Spades and Battleship, individually paced Backgammon checker moves including doubles, and a larger Expert Backgammon reply-search allowance within the viewing interval. Preserved bottom status placement, game animations and Practice-only recording. Testing is WIP.


## 2026-09-16 - Chinese Checkers Practice bots

Added optional Easy, Normal and Expert Chinese Checkers bots using an attributed JumpStar classical adaptation, empty-seat lobby controls, server-validated moves, Practice-only protection, and reserved thinking/retry feedback below the controls. Added multiplayer goal planning, bounded lookahead and decision recording. Testing is WIP.


## 2026-09-16 — Optional downloadable Game Review references

Add a versioned optional reference ZIP distributed through GitHub Releases, with administrator download, chunked installation, verification and per-game Classic media copying. Built-in assets and frozen example code/sequences are distributed separately from ordinary application updates; original OCX media and personal references remain private. Existing matching references are reused and differing files are never overwritten. Missing Classic references affect only their game, while live examples and rules comparisons remain available. Testing is WIP.


## 2026-09-16 — Immediate administrator game-review selection

Administrator Game Review now defaults to Classic where supported. Changing the example or appearance applies immediately; the Choose example button is removed. Explicit appearance choices, Built-in-only games, Start/Reset and independent frozen references remain supported. Testing is WIP.


## 2026-09-16 — Bulk selection for unused-file cleanup

Added Select this page, Select all results, Clear selection, and a selected count/size summary to administrator unused-file review. Selections persist across result pages and reset when changing scan categories. Batch confirmation includes total count/size; progress reports completed and skipped/failed files. Stop after current file lets an in-flight request finish and retains unprocessed selections for review or retry. Existing per-file reference/identity checks and recoverable trash remain in force. Nothing is automatically selected. Testing is WIP.


## 2026-09-16 — Administrator unused media cleanup

Added Admin → Storage Management → Find unused files for avatars, nameplates, gesture files, imported-room media and room backgrounds. The compact, cancellable review protects saved private/community libraries, selected media, retained gesture versions and database history/room references without exposing protected files. Files younger than 24 hours and unsupported or unverifiable files are skipped. Administrators select candidates and confirm a move into private recoverable trash; restore never overwrites another file. Permanent deletion requires a separate confirmation and another reference check. Custom emoji files remain excluded so historical messages keep working. No automatic deletion or database migration. Testing is WIP.


## 2026-09-15 - Continuous game rematches and conversation

Play Again keeps the game view open and carries the conversation into consent-linked rematches. Earlier-round messages remain available after reload for authorized returning players, while new messages and game records stay attached to their own match. Testing is WIP.


## 2026-09-15 - Editable extensions and central release checksums

Extension manifests now list required files without duplicate hashes. Release checksums are centralized in release-manifest.json, with administrator modified-file diagnostics and optional strict checking disabled by default. Required-file, capability and frozen-reference checks remain enforced. Testing is WIP.


## 2026-09-15 - Stable bot thinking feedback

Chess, Checkers, Hearts, UNO and Backgammon share a reserved status area below the board controls. Thinking, completion and retry messages no longer move the board. Frozen administrator references remain unchanged. Testing is WIP.


## 2026-09-15 - Permanent administrator game review

Adds Game review to the administrator games list with 158 repeatable examples across all 15 games and supported Built-in/Classic appearances. Live practice examples compare against preserved renderer, media and rules references, with private reference backups and revision history. Review preferences stay isolated from regular games. Testing is WIP.


## 2026-09-15 - Original game audio and optional voices

Classic games add personal default-off controls for original turn reminders and extra voices, with sound previews. Chess move/capture and check cues, incoming draw offers, Battleship placement sounds, and Acey Deucy roll and victory reactions follow traced original events. Manual previews continue across routine game refreshes. Testing is WIP.


## 2026-09-15 - Five Dice roll effects and original reactions

Five Dice presents accepted rolls for every player, preserves held dice and prepares Classic sound with motion. Original Yahtzee, upper-bonus, repeat-Yahtzee and personal-record Cheers cues are connected. Idle voices have personal opt-in switches and audio previews, disabled by default. Optional Classic resources preserve compatibility with existing packs. Testing is WIP.


## 2026-09-15 - Restore original Battleship sequences

Classic Battleship follows original combat stages, timing and victory strip geometry. Spades retains the approved sliding card and original direct transitions. Game rules are unchanged. Testing is WIP.


## 2026-09-15 - Correct Chess castling presentation

Classic castling now places the king and rook together, following the original OCX board-update behavior. Built-in castling moves both pieces in one coordinated transition, including server confirmation and either board orientation. Game rules are unchanged. Testing is WIP.


## 2026-09-15 — Keep selected Checkers and Chess pieces visible

Classic Checkers and Chess now retain the visible piece until its selected artwork is decoded. Chess keyboard focus uses the same protection, and delayed, failed or cancelled image requests cannot replace a newer selection. Classic Checkers time expiration now receives the same colored-tile sequence as other decisive results. Existing game rules and source artwork are preserved. Testing is WIP.


## 2026-09-15 — Keep point-game artwork visible during image loading

Fixed the first selected checker briefly disappearing in Classic Backgammon and Acey Deucy, and the Acey Deucy board briefly disappearing on a winning move. Preload the replacement artwork and retain the visible checker or board until the replacement decodes, including safe fallback for failed loads and cancelled selections. Testing is WIP.


## 2026-09-15 — Original checker sequences and rotating starters

Made Rotate starter the default for new Backgammon and Acey Deucy games while preserving explicit starter choices. Added optional original Classic checker capture, bar arrival, spinning movement and landing sequences, including settling after bearing off before victory. Retained larger dice, corrected racks and fallback for older media packs. Verify now reports whether the complete checker artwork needs re-importing. Testing is WIP.


## 2026-09-15 — Original point-game rack alignment

Corrected Classic Backgammon and Acey Deucy bear-off rack positions to match the original upper/lower baselines and alternating offsets. Corrected Acey Deucy resting checker widths and kept the animation destination aligned with the resting stack. Testing is WIP.


## 2026-09-15 — Acey direction and original Chess king animation

Corrected Classic Acey Deucy’s lower-row direction and player bear-off racks. Added the original Chess king-and-flag animation with working frame playback, cleanup and optional-media fallback. Verify now checks its artwork. Corrected Backgammon and Acey Deucy bear-off to use the original concurrent slide and turn, eight complete frames at80ms, and clean handoff to the resting rack. Testing is WIP.


## 2026-09-15 — Original Chess capture and Acey Deucy victory animations

Added optional original Chess capture artwork for all piece depths and both viewing orientations, including en passant, and the original five-stage Acey Deucy victory sequence. Verify reports whether the new animation artwork is installed. Existing Classic packs retain their fallback effects. Testing is WIP.


## 2026-09-15 — Five Dice original and doubled Classic media

Added complete static Five Dice OCX import, original-size image preparation, and automatic preference for supplied doubled artwork when multiple sources are selected. Verify now identifies 2x artwork, Original OCX 1x artwork, or a mixture, alongside missing and invalid file checks. Existing complete packs and protected private installation remain supported. Testing is WIP.


## 2026-09-15 — Classic media details on demand

Kept the Classic game list compact by moving optional bear-off artwork details into the Verify result. Verification still reports progress, missing or invalid files, and failures beside the relevant game. Testing is WIP.


## 2026-09-15 — Clear Classic media verification results

Added verification progress, file counts and errors beside each game in Admin, with results remaining visible when its menu is closed. Added separate original bear-off animation status for Backgammon and Acey Deucy so missing artwork clearly requests re-import. Added immediate status refresh after installation or removal and an unverified message for older server responses. Testing is WIP.


## 2026-09-15 — Shared original bear-off animation

Added matching rack-entry and overlapping checker-turn motion for Classic Backgammon and Acey Deucy, using the original optional bear-off frames. Added a full bear-off duration for Acey Deucy so cleanup no longer truncates the shared sequence. Added compatible optional-media import and a working fallback for existing packs or unavailable artwork, with both-seat ordinary and final-checker checks. Testing is WIP.


## 2026-09-15 — Single blocked-roll dice pair and Backgammon rack entry

Added a single displayed dice pair during Classic Backgammon rolls and blocked bar-entry turns, preventing smaller legacy dice from appearing over the new dice. Added the same dice renderer for blocked-turn fallback. Added a Backgammon bear-off path that enters the side channel before travelling along the rack and turning edge-on into the notch, with existing total duration preserved. Added both-seat blocked-roll and ordinary/final bear-off checks, plus Acey Deucy regression coverage. Testing is WIP.


## 2026-09-15 — Game FX controls and motion preferences

Added per-game Visual FX choices that override the device motion default. Added matching lit/enabled and dim/disabled Classic sound and visual controls for Backgammon, Acey Deucy, Chess and Checkers. Added protection against white empty-image boxes during Classic control loading. Added browser checks for delayed artwork, saved FX state, keyboard controls and animations under reduced motion. Testing is WIP.


## 2026-09-15 — Independent game records loading

Added records loading independent of gameplay across shared games and Five Dice. Added isolated records errors, retained cached totals, stale-response protection and retry on reopening Five Dice records. Added regression coverage for held/failed requests, accepted browser actions, session expiry and retained point-game animations. Testing is WIP.


## 2026-09-15 — Readable Classic dice and prompt point-game animation

Added larger dice and separated larger dots for Classic Backgammon and Acey Deucy. Added immediate animation rendering after accepted actions while score records load separately, preserving checker movement and Backgammon roll motion on slow record responses. Added delayed-record movement and opening/ordinary-roll browser verification. Testing is WIP.


## 2026-09-15 — Matching Classic dice and stable bot-turn layout

Added matching rounded, shaded dice for all six values in Classic Backgammon and Acey Deucy. Added stable below-board Backgammon bot feedback to prevent vertical board movement during bot turns. Added complete dice-face and bot-opening layout verification. Testing is WIP.


## 2026-09-15 — Classic legal-move cues and stable embedded controls

Added deployment-folder-aware legal-move artwork for Classic Backgammon, Acey Deucy, Chess and Checkers. Added stable embedded sound and visual control visibility during game redraws. Added subdirectory and repeated-selection regression coverage. Testing is WIP.


## 2026-09-15 — Clear Classic six-face dice

Added scalable six-face pips with clear spacing for Classic Backgammon and Acey Deucy at native and enlarged board sizes. Added populated gameplay-input and board-selection verification across all 15 first-party games. Testing is WIP.


## 2026-09-15 — Classic Backgammon controls and board interaction

Added corrected Classic Backgammon direction-arrow positioning and orientation, source-backed dice-roll motion, board artwork selection protection and stable Classic control artwork. Added shared removal of redundant idle practice-bot and engine-credit lines while retaining consolidated Third-Party Notices and active bot feedback. Testing is WIP.


## 2026-09-15 — In-message emoji expansion and bundled monkey

Added full-size custom emoji expansion inside the original chat message, proportional containment within the chat, click-away collapse and keyboard controls. Added a bundled animated monkey emoji with automatic one-time catalog installation, duplicate reuse and normal administrator rename/delete support. Testing is WIP.


## 2026-09-14 — Hearts Expert Practice bot

Added an Expert Hearts bot alongside Easy and Normal for two-player and four-player Practice games. Expert compares a small set of plays and passes against possible unseen hands using public card information, current scores and the existing Shoot the Moon rules. Added a short computation limit, Normal fallback and automatic search decision traces. Existing bot seating, late additions, human priority, explicit Start and unranked recording/lifecycle behavior are preserved. Testing is WIP; difficulty labels are relative, not calibrated ratings.


## 2026-09-14 — Hearts Practice bots

Added Easy and Normal Hearts bots for two-player and four-player games, including the existing Shoot the Moon option. Hosts can add bots to empty seats after people join, with None as the default, human seat priority and explicit Start. Adding a bot switches the game to Practice Mode; bot games never affect rankings. Normal considers passing, public played cards, safe high-card disposal and moon prevention. Added paced turns, Retry, automatic decision recordings and saved-game support through the shared game framework. Testing is WIP; difficulty labels are relative, not calibrated ratings.


## 2026-09-14 — Complete custom emoji display and preview

Added uncropped custom emoji display and an original-size animated preview, with click-anywhere dismissal and keyboard controls. Testing is WIP.


## 2026-09-14 — Private room passwords and room controls

Added optional passwords when creating regular, imported URL and Live Website rooms, with private-room entry prompts and PRIVATE ROOM overlays in the room list. Added owner/admin deletion controls for Live Website rooms and a single-line optional room-name label. Testing is WIP.


## 2026-09-14 — Admin library duplicate review

Added Find duplicates under Admin Storage Management, with compact previews and grouped exact matches across avatar, nameplate, gesture and custom emoji libraries. Administrators can choose a copy to keep and confirm individual removals, with current-match checks, private-library boundaries and existing historical-image protections. Scans show progress, cancellation and skipped entries. Testing is WIP.


## 2026-09-14 — Duplicate uploads and custom emoji renaming

Added content-based duplicate checks for avatars, nameplates, gestures and custom emojis, including folder uploads and older stored media. Existing accessible images can be selected without another upload; private libraries remain separate and distinct gesture text, sound or posters remain supported. Added administrator Rename emoji beside Delete, preserving existing image references and protecting against name conflicts and stale edits. Testing is WIP.


## 2026-09-14 — Emoji and gesture management actions

Added administrator right-click custom emoji deletion while preserving images in older messages. Restored gesture three-dot menus, added Manage gesture and Delete gesture for administrators, and added deletion to the existing admin gesture editor. Added viewport bounds for picker menus. Testing is WIP.


## 2026-09-14 — UNO Easy and Normal choices

Updated UNO bot choices to Easy and Normal, with None remaining the default. Older Expert lobby settings and rematches use Normal; existing in-progress and saved Expert matches remain compatible through completion. Bot games remain Practice only. Testing is WIP.


## 2026-09-14 — UNO Practice bots

Added Easy, Normal and Expert UNO bots for two through ten total players. Hosts can add bots to empty seats after people join, with None as the default and existing seat choices preserved. Adding a bot switches the game to Practice Mode; bot games never affect rankings. Bots use their own cards and public information, handle special cards and UNO declarations, and continue between hands. Added paced turns, Retry, automatic decision recordings and saved-game support. Corrected bot-winner scoring after a final Draw Four and allowed UNO declarations for a playable drawn card. Testing is WIP; difficulty labels are relative, not calibrated ratings.


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


## 2026-09-13 - Normal Spades endgame Nil attacks

- Normal can take a trick from its winning partner to force an opposing Nil to
  take the final trick, when public card history proves the benefit.
- The small endgame check runs only after the team has made its contract and
  neither teammate bid Nil. Uncertain positions keep the existing card choice.
- Partner Nil protection, Expert strategy and existing thinking-time budgets
  remain unchanged.

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


## 2026-09-13 - Spades Expert bounded thinking time (local, unpublished)

- Allow Expert up to one second of search per decision, with a shared three-second
  search budget for consecutive bots. Straightforward and forced moves still finish immediately.
- Preserve Normal behavior, Nil partnership safeguards, legal play and bounded
  deadlines. Local timing and paired-hand checks support this increase; hosted
  timing and owner gameplay verification remain pending deployment.

### Advanced Pool review and practice examples

Added 36 verified advanced Pool reference sequences and 13 additional shared practice setups, preserving existing names, edits and deletions. Reference pack v4 retains all 353 prior sequences and freezes the advanced renderer separately. Reviewed cut shots, banks, kicks, combinations, follow/draw, safety and multi-ball clusters use the existing physics. Exact shot inputs and reconstruction limitations are shown in the administrator review. Validated 12 complete 8/9-ball bot matches and reference installation/integrity contracts. Testing is WIP.


## 2026-09-19 — Profiles, notifications and avatar library reliability

- Add reciprocal profile relationships with recipient approval, private pending prompts, cancellation and either-side removal.
- Add recipient-controlled private poke notifications and shared notification metadata while retaining existing sound and mute preferences.
- Keep avatar/nameplate choosers open through nested confirmation dialogs, refresh saved names and sections immediately, and provide alphabetical existing-section selectors.
- Keep expanded library controls within the viewport, improve webcam-audience spacing and empty report-status presentation, and consolidate avatar menu actions.
- Provide a database-free compatibility handshake and read-only connection status for compatible clients.
