# CoreChat GNU Backgammon engine

Engine identity: gnubg-95d0ffc-corechat-1.
Pinned source: https://github.com/hwatheod/gnubg-web/tree/95d0ffcbaa3f891571c28af5ba57ec831c6ee000
Source archive: https://github.com/hwatheod/gnubg-web/archive/95d0ffcbaa3f891571c28af5ba57ec831c6ee000.zip

This source contains GNU Backgammon 1.05.000, the web port, GLib 2.62.0,
the unmodified neural weights and one-sided bearoff database. We compile an
independent worker module with the added corechat_api.c bridge; upstream files
are unchanged. Activate Emscripten 6.0.6 and set EMSDK to its SDK root, then run:

```
python build.py /path/to/pinned-source /path/to/output
```

build.py records its complete compiler arguments. It embeds the weights and
one-sided bearoff data in gnubg.wasm; there is no network engine or dice service.
The source archive and this directory provide corresponding source and build
instructions. All engine rights remain under GPL-3.0-or-later; GLib retains
LGPL-2.1-or-later. Full license texts and authorship are in CoreChat's existing
THIRD_PARTY_NOTICES.md. Do not apply the CoreChat application license to this
independently licensed module. The GNU board representation uses row 1 on roll,
with each player's home point at index 0 and bar at 24.

CoreChat enumerates exact legal complete turns under the selected move-use
rule, including partial saved doubles. GNU supplies static cubeless evaluation.
Easy adds deterministic modest evaluation noise; Normal selects the best static
complete turn; Expert compares all 21 possible reply rolls for its top three
static candidates when the budget permits. If that comparison cannot finish,
the full static ranking remains authoritative; a partial roll subset is never
used. Selected moves remain proposals verified by the PHP reducer. The engine
receives only the current public board and rolled dice. GNU weights/bearoff data
were trained/generated for standard rules; Legacy OCX uses exact legacy legal
turn/reply generation with this evaluator, without a calibrated strength claim.
The response watchdog terminates a worker that exceeds its bound.
