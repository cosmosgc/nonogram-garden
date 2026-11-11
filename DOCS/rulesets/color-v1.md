# Color Picross ruleset — version 1

**Mode ID:** `color`
**Rules version:** `1`
**Status:** Supported and playable under rules version 1.

This document is the normative rules contract for Color Picross. Palette persistence, clue generation, the palette-aware solver, draft editing and publishing, palette controls, typed clues, local and authenticated progress, and exact server-verified completion are implemented and tested. Unsupported Color rules versions remain rejected.

## Palette format

A revision owns an immutable, ordered palette:

```json
[
    {"name": "Crimson", "hex": "#C0392B"},
    {"name": "Ocean", "hex": "#2471A3"}
]
```

- The palette is a non-empty list with at most **8** entries.
- A colour's **ID is its 1-based position** in the list. IDs are stable for the life of the revision.
- `name` is a trimmed, non-empty label of at most 40 characters. It is the accessible name and must not be the only distinguishing cue; the player also needs a pattern or shape cue.
- `hex` is an uppercase `#RRGGBB` value. Hex values must be unique within a palette.
- Palette order and values are serialized deterministically. Reordering or re-colouring a palette requires a new published revision.
- `0` is reserved for empty and is never a palette entry.

## Cell semantics

- The solution is a row-major string with exactly `width × height` cells.
- Each character is a digit: `0` is empty, and `1`–`8` reference the palette ID at that position.
- Every non-zero cell must reference an existing palette entry; a palette of N colours only permits digits `1`–`N`.
- Dimensions follow the Classic limits: integers from 1 to 30 and at most 900 cells.
- Player marks use the same digit alphabet: `0` unknown, `1`–`8` filled with that palette colour, and `9` crossed out. A fill only matches when the colour ID equals the solution cell's ID.
- A crossed mark (`9`) may replace an unknown mark (`0`) on an empty solution cell. Any other mismatch fails completion.

## Clue syntax

- Row and column clues are ordered lists of `[run_length, color_id]` pairs.
- A run is a maximal sequence of cells sharing the same colour.
- An **empty line is encoded as `[]`**, not `[0]`; `[0]` remains a Classic-only encoding.
- Clue values must never be interpreted as Classic run-length integers. Clients receive mode and rules version with the puzzle and must parse clues accordingly.

## Line constraints

- Adjacent runs of the **same colour must be separated by at least one empty cell**.
- Adjacent runs of **different colours may touch**; no empty separator is required between them.
- Run order matches left-to-right or top-to-bottom position.
- Supplied clues are never trusted. The server regenerates clues from the canonical solution at publish time.

## Completion semantics

- The server compares the saved player marks with the canonical solution for the pinned immutable revision, including the exact colour ID of every filled cell.
- Every filled cell must match the solution colour; empty and crossed-out marks must align with empty solution cells.
- Client-submitted colours, palette substitutions, matching clue totals, elapsed time, and client hashes are never sufficient.
- Completion is recorded atomically with the progress save and is idempotent per user and revision.

## Edge cases

- A monochrome palette is valid and behaves like Classic clues with colour IDs attached.
- `1 × 1` boards are valid and receive no start hint.
- An all-empty board is valid only if it is uniquely solvable; in practice, monochrome puzzles with no fills are rejected by the solver.
- A palette entry that no cell uses is allowed but should be discouraged by the editor.
- Revisions published under ruleset version 1 keep that version forever. Changing clue or adjacency semantics requires a new rules version.

## Compatibility

- Color is not the default. Classic remains the default mode for existing data and API requests.
- A Color puzzle may be published only after the editor, solver, player, and completion checks all pass the shared fixtures for this version.
- The solution is never sent to a player before verified completion; only the approved start-hint lines may be revealed.

## Shared fixtures

`tests/Fixtures/rulesets/color-v1.json` holds the cross-language cases. The PHP `ColorGrid` test and the TypeScript `color-grid` test both read its clue cases, and `ColorUniquenessSolverTest` plus `color-uniqueness-solver.test.ts` read its `solver_cases`, so the two engines cannot drift apart silently. Solver cases cover unique, ambiguous, inconsistent, and zero-budget `unknown` results.
