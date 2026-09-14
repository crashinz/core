# CoreChat Marcher browser build

Upstream: Stermere/Checkers-Engine, commit
`1fa785edbe8003445163a63f00f04a4c0b77254b`. License and attribution are in
CoreChat's central `THIRD_PARTY_NOTICES.md` (Marcher section).

Use Emscripten 6.0.6. Download the pinned upstream source, then apply
`corechat.patch` from the source directory with `git apply corechat.patch`.
Build `src/engine/board_search.c` with these common arguments:

```
-O3 -flto --no-entry -DNDEBUG -DWASM_API=1 -DPRINT_OUTPUT=0
-DTT_ENTRIES_LOG2=21 -DENDGAME_DB_MEM_LOAD=1 -DENDGAME_DB_NO_FILES=1
-sMODULARIZE=1 -sEXPORT_NAME=createMarcher -sENVIRONMENT=worker,node
-sWASM_BIGINT=1 -sFILESYSTEM=0 -sALLOW_MEMORY_GROWTH=1
-sINITIAL_MEMORY=32MB -sMAXIMUM_MEMORY=512MB -sSTACK_SIZE=4MB
-sASSERTIONS=0
-sINCOMING_MODULE_JS_API=wasmBinary,print,printErr,locateFile
-sEXPORTED_RUNTIME_METHODS=HEAPU8,HEAP32
-sEXPORTED_FUNCTIONS=_wasm_search,_wasm_result_ptr,_wasm_result_fields,_wasm_perft,_wasm_nnue_eval,_wasm_nnue_simd,_wasm_db_max_pieces,_endgame_db_alloc_slice,_malloc,_free,_wasm_context_reset,_wasm_context_add,_wasm_perft_forced,_wasm_variant_configure,_wasm_variant_moves,_wasm_variant_step,_wasm_variant_perft,_wasm_variant_result_ptr
```

For `marcher.js`, add `-msimd128 -msse2 -o marcher.js`.
For `marcher-scalar.js`, add `-DNNUE_NO_SIMD=1 -o marcher-scalar.js`.
For a diagnostic build, replace `-sASSERTIONS=0` with
`-sASSERTIONS=1 -sSTACK_OVERFLOW_CHECK=2 -sSAFE_HEAP=1`.

Example invocation after activating the SDK:
`emcc src/engine/board_search.c <common arguments above> -msimd128 -msse2 -o marcher.js`.

No opening books or endgame databases are included. Each browser search imports
the current authoritative board, saved repetition counts and no-progress counter.
CoreChat adds a separate exact optional-rule generator and full-width
alpha-beta path inside the same Marcher WASM module. The default rule mask uses
the adopted standard search unchanged. Mask bit 1 enables backward man movement,
bit 2 backward man capture, and bit 4 flying kings. Configure the mask after each
authoritative context reset/import and before search. The reset clears the mask.
Variant search uses a transparent material/promotion/centralization evaluation;
it does not use the standard-trained NNUE, opening book or tablebase as evidence
of variant strength. Captures extend past nominal depth until a quiet position,
subject to the same time budget and an absolute 96-ply guard. No calibrated Elo.

Engine identity: `marcher-1fa785ed-corechat-2`. Worker, context and engine are
versioned together. All eight movement combinations are supported for Practice
bots. Diagnostic move, step and perft exports support PHP differential checks.
License and attribution remain in the existing central THIRD_PARTY_NOTICES.md.
The Easy, Normal and Expert labels describe configured search levels, not measured
Elo ratings; relative variant strength is not calibrated.
