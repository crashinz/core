# Five Dice Expert strategy values

This is a first-party, independently implemented full-scorecard solver. It uses
the general Bellman dynamic-programming method described in public Yahtzee
research. No YahtzeeTrainer code or table is redistributed. See the repository's
`LICENSE.md` and consolidated `THIRD_PARTY_NOTICES.md`.

## Rules and objective

The generator implements the game's thirteen categories, three rolls per turn,
upper bonus of35 at63, Yahtzee50, repeated Yahtzee100 only after a50 in that box,
and mandatory Joker placement after either a zero or50 in the Yahtzee box:
matching open upper first; otherwise an open lower category; otherwise another
upper for zero. Ordinary full house requires three plus two.

The objective is maximum expected final score. It does not optimize multiplayer
win probability against particular standings, and it never sees future dice.
Reroll choices still use the live PHP rules owner and probability graph.

## Generate offline

From this directory on a machine with a C compiler:

```sh
cc -O3 generate.c -o five-dice-generate
./five-dice-generate
```

Alternatively, with Emscripten installed:

```sh
emcc generate.c -O3 -sNODERAWFS=1 -sENVIRONMENT=node -o five-dice-generate.cjs
node five-dice-generate.cjs
```

Both commands write `values.bin` in the working directory. Compile/run in a
scratch directory if preserving an existing table. The format is little-endian;
use a little-endian native machine or the WebAssembly build. Build executables,
JS/Wasm build output, logs, and diagnostic tables are not production assets.
The hosted PHP application needs no compiler, Node, third-party engine or network
service. Ordinary deployment includes the generated `values.bin`.

`unrestricted` generates a separate comparison table using unrestricted Joker
placement; **do not install that table as `values.bin`**. `verify` writes scoring
transitions for independent tests. Neither diagnostic output is shipped.

## Format and validation

`values.bin` is8,388,616bytes: ASCII header `FDV1LE64` then1,048,576IEEE754
little-endian doubles. The index is `openMask*128 + min(upperSum,63)*2 + positive`.
Category bits follow `five_dice_categories()` (Chance bit11, Yahtzee bit12).
`positive` is1 only when the already-filled Yahtzee box scored50. Slots for a
simultaneously open and positive Yahtzee are unused. There are786,432meaningful
states. Values include the terminal upper bonus, even if the threshold has
already been reached; past ordinary scores and repeat bonuses are omitted.

The exact-rule initial expected value is approximately254.587728734496. An
unrestricted-rule control reproduces YahtzeeTrainer's254.589609481963 and matches
all786,432states of its published original table within1e-12 after accounting for
when the upper bonus is booked. This establishes matching expected-score values
under matching rules; it is not a multiplayer win-rate claim.

Production loading is bounded, checks format and terminal values, and validates
finite value ranges when used. Missing/invalid data or insufficient PHP memory
headroom retains the previous bounded Expert policy. The recorded decision
identifies `full-scorecard-v1` or `bounded-expert-fallback`. Central release hashes
remain optional and editable files are not rejected solely for differing hashes.

Easy and Normal never load this table. Existing roughly two-second action pacing
and below-board status are unchanged.
