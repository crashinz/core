# Five Dice

Five Dice is an independently authored first-party game extension for the
CoreChat multiplayer-game framework. Its opaque registry key is durable machine
identity only. The fresh visible name is `Five Dice`; the Installation Owner
may change the effective display name without changing sessions, saves, results,
records, statistics, or audit identity.

The supplied private OCX/browser reference bundle was not copied into this
extension and is not distributed here.

Practice games support optional Easy, Normal and Expert bots in empty lobby
seats. Seats default to None; adding a bot switches the game to Practice.
Solo play and tables of up to four players remain supported. Bots use the
same verified dice rolls and scoring rules as human players.

Normal enumerates one reroll; Expert plans both remaining rerolls. Both use
scorecard opportunity costs and an approximate future upper-bonus value.
This is first-party strategy code, not a full-game optimal solver. Bot turns
show holds, dice animations and scores separately, with feedback below the
board. Shared protected game recordings include bot decisions and dice.


### Full-scorecard Expert
Expert uses the first-party strategy table described in `games/five-dice/strategy/BUILD.md`. It optimizes expected total score under the existing rules. Easy/Normal and paced presentation are unchanged. If optional table data is unavailable or structurally invalid, Expert retains its previous bounded strategy; recordings identify the fallback. No external engine/service is required.
