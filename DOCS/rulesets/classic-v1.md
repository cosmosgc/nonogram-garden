# Classic monochrome ruleset — version 1

**Mode ID:** `classic`
**Rules version:** `1`
**Status:** Supported

This is the normative rules contract for existing Classic Nonogram puzzles. It records the behaviour implemented by the Classic clue generator, uniqueness solver, editor, player, progress saves, and completion endpoint. Any change to these rules requires a new rules version; published revisions keep the version they were created with.

## Source

Product decision from `LARAVEL_NONOGRAM_PROJECT.md` §2.1–2.3, implemented by the original Classic vertical slice. There is no external ruleset dependency.

## Cell semantics

- The solution is a row-major binary string with exactly `width × height` cells.
- `0` means empty; `1` means filled.
- Dimensions are integers from 1 to 30, and the total number of cells may not exceed 900.
- Transportation and storage shape: `{version: 1, width, height, cells}`.
- Player marks use three values: unknown (`0`), filled (`1`), crossed out (`2`). Crossed-out marks are never part of solution comparison.

## Clue syntax

- Row and column clues are lists of positive integers representing consecutive filled runs, read from the first row/column position to the last.
- An all-empty line is encoded as `[0]`.
- Clue values never carry colour, pairing, or plus-marker semantics in Classic. Clients must not infer other modes from clue values.

## Line constraints

- Runs within a line must be separated by at least one empty cell.
- The order of runs matches the order listed in the clue.
- Supplied clues are never trusted. The server regenerates clues from the canonical solution at publish time.
- Clue completeness determines only a local candidate. Completed runs do not prove that the whole board is solved.

## Completion semantics

- The server compares the saved player marks with the canonical solution for the pinned immutable revision.
- Every cell must match exactly: filled cells where the solution has `1`, and no filled cells where it has `0`.
- Crossed-out marks may replace empty marks.
- A client claim, matching clue totals, elapsed time, or a client-computed hash is never sufficient.
- Completion is recorded atomically with the progress save and is idempotent per user and revision.

## Auto-cross and hints

- Automatic crosses may be offered only for cells that cannot be filled in a line whose clue is already satisfied by server-confirmed fills.
- Incorrect fills never cause automatic crosses.
- The optional start hint reveals exactly one row and one column selected by the server for the session. It is suppressed when a single-row or single-column board would expose the complete solution.
- The remainder of the canonical solution is never sent to a player before verified completion.

## Edge cases

- `1 × 1` boards are valid. They receive no start hint because a row or column hint would reveal the answer.
- Puzzles that do not have exactly one solution may not be published.
- Withdrawn, unpublished, private, rejected, or removed puzzles do not expose their canonical solutions.

## Compatibility

- Revisions published before the ruleset contract existed are Classic rules version 1.
- A client requesting another mode or rules version must be rejected unless the server has a matching ruleset implementation.
- Mode and rules version are immutable properties of a published revision.

## Shared fixtures

`tests/Fixtures/rulesets/classic-v1.json` holds the cross-language clue cases. The PHP ruleset test and the TypeScript grid test both read it and assert identical row/column clues, so the two implementations cannot drift apart silently. Add a case there before changing clue generation.
