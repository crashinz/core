# Pool

Two human players can play digital 8 Ball or 9 Ball in Practice or Recorded
mode. Choose the variant in Game Options. Existing sessions and unspecified
settings keep 8 Ball. Solo free shooting is Practice only. Initial breaks use framework
randomness; rematches alternate breakers. The in-game rules describe explicit
optional pocket calls, break choices, scratches, and the winning ball.

Practice matches also offer an Easy, Normal or Expert opponent. Keep Table set
to Two-player match and choose Practice opponent; None keeps the human match.
Solo free shooting and its setup library remain available separately.

Easy has imperfect aim/power and no next-shot position planning. Normal searches
straight pots and simple banks with modest position planning. Expert uses the
same table physics to search banks, two-cushion shots, kicks, combinations,
power and spin. When no pot is found, Expert searches legal safeties that leave
the opponent blocked, farther away or with a difficult pot. It sees only the
public table and chooses inputs; it cannot change collision or pocket outcomes.
Expert is a bounded search, not an optimal or unbeatable solver.

Search runs in a cancellable worker (Easy up to 0.6 seconds, Normal 1.8,
Expert 5.5). The bot shows its aim before shooting and waits for each full
animation. Thinking and Retry bot messages stay below the board controls.
Practice bot games support saving and rematches and never enter Recorded
rankings. No downloaded model or third-party bot engine is needed.

9 Ball uses lowest-ball-first contact, legal combination wins, a diamond rack
with 9 at the marked foot spot, push-outs with an opponent take/return choice,
and three consecutive fouls with a visible two-foul warning. No calls are needed.
Breaks require a pot or four object balls reaching cushions; this casual version
does not impose the professional three-ball crossing restriction. A 9 potted
on a foul or push-out is spotted. Fouls give ball in hand anywhere.

Every shot is simulated and adjudicated by the PHP server. Clients send aim,
power, spin and a called shot; they cannot submit outcomes or ball positions.
All viewers animate the same recorded trajectory. Cosmetic cues confer no
advantage. Elevated-cue shots, jumps and masse are unavailable. Bots are deferred.

Solo practice supports both rack types, free cue-ball placement, and repeatable
center-hit, follow, draw, side-spin and pocket layouts. Right-drag locks aim and
sets power; release shoots. Left-drag aims near the cue/guide. Arrow keys aim
and adjust power; left/right are locked during right-drag. Space shoots, Enter
or double-click confirms placement, and Escape cancels a power pull.

Named personal/shared setups preserve the variant, ball positions and shot
inputs. Rewind and replay use the same authoritative shot as live play. The ten
first-party bank examples in `games/eight-ball/bank-setups.json` install once
when an authenticated player opens the library. Existing names are retained;
later edits, renames and soft deletions are never reset by a file update.
No local test database, account or private media is needed. Administrators can
manage these examples through the normal shared-library controls.

Physics attribution and license: THIRD_PARTY_NOTICES.md. Rules reference:
https://wpapool.com/rules/

The approved power calibration is approximate; no claim of identical Miniclip
physics is made. Pooltool numerical parity is separate from physical-table
validation.

Tight groups use an original simultaneous normal-contact approximation.
Isolated two-ball contacts retain the attributed friction/spin model. Group
contacts omit tangential friction and preserve existing spin; this is a bounded
game approximation, not a validated material-deformation simulation. Live
shots play from frame zero; reconnects catch up to server time.
