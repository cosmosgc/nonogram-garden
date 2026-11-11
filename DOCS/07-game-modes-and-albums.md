# 07 — Expanded Picross modes and album puzzles

**Goal:** Extend the Classic Nonogram foundation with versioned game rules and album-style mosaics, without breaking Classic puzzles, published revisions, or player saves.

**Dependencies:** Complete the Classic domain/editor/publishing and player foundation in [01-foundation.md](01-foundation.md), [02-editor-and-publishing.md](02-editor-and-publishing.md), and [03-playing-and-progress.md](03-playing-and-progress.md). Treat these as expansion work after the Classic vertical slice unless a task explicitly builds a reusable interface without changing the MVP format.

**Status:** In progress. Classic and Color Picross v1 are supported. Color has palette-aware authoring, publishing, typed clues, uniqueness checks, player controls, authenticated progress, and exact server-verified completion. Mega and Clip remain unsupported; albums are not started. Do not mark another mode supported until clue generation, validation, solver, editor, player, storage, and completion tests agree for that mode.

## A. Ruleset contracts and compatibility

- [x] Write a short rules specification for each mode before implementation. Record source/reference or product decision, clue syntax, cell semantics, line constraints, completion semantics, edge cases, and version number. Classic and Color v1 rules are documented in `DOCS/rulesets/`; Mega remains undecided and Clip remains unsupported.
- [x] Define a `PuzzleMode`/ruleset contract with stable mode ID and rules version; provide clue generation, input validation, solver/uniqueness analysis, and solution comparison behind the same interface. `PuzzleRuleset` is implemented by `ClassicRuleset` and `ColorRuleset` and resolved through `RulesetRegistry`; publishing, start hints, progress saves, and completion use the resolved ruleset.
- [x] Preserve `classic` as the default for existing data and API requests. Add a compatibility migration/backfill and tests proving existing binary puzzles load/play unchanged. `puzzle_revisions.mode`/`rules_version` and `puzzles.draft_mode`/`draft_rules_version` default to `classic`/1; existing rows were backfilled and Classic publication, play, and completion tests pass.
- [x] Version mode-aware clue JSON and cell encoding. Never assume all clue entries are integers once colors/Mega rules are introduced. The ruleset contract owns cell validation, clue generation, hashing, and comparison, and the player payload now exposes `mode` and `rules_version`; Classic clue JSON stays `list<int>` under its v1 contract.
- [x] Reject unsupported mode/rules-version pairs explicitly; clients may not claim a mode to bypass the server validator. `RulesetRegistry` rejects unknown pairs; publishing an unsupported draft mode returns 422 without creating a revision, and completion refuses unsupported pinned revisions.
- [x] Keep mode contracts shared across server/client test fixtures so PHP and TypeScript implementations must match exact generated clues. Shared Classic and Color v1 fixtures are consumed by PHP and TypeScript clue tests.

**Acceptance:** Classic fixtures stay byte-for-byte/semantically compatible; a new mode cannot be enabled until all required server and client rule tests pass.

## B. Color Picross

- [x] Specify palette format: stable color IDs, display color, accessible name/pattern, maximum palette entries, reserved empty value, and deterministic serialization. `DOCS/rulesets/color-v1.md` defines 1-based colour IDs, 1–8 entries, unique uppercase `#RRGGBB` values, a 1–40 character accessible name, `0` reserved for empty, and deterministic palette order.
- [x] Extend solution cells from binary to palette indexes for Color mode; validate every non-empty index refers to the immutable revision palette. `puzzle_revisions.palette` and `puzzles.draft_palette` persist the ordered palette; draft create/update/load accepts and validates Color grids, `App\Services\Nonogram\ColorGrid` and `resources/js/lib/nonogram/color-grid.ts` validate digit cells against the palette, and the player payload exposes the revision palette while Classic revisions store `null`.
- [x] Generate clues as ordered `(run_length, color_id)` values. Implement the rule that adjacent same-color runs need an empty cell, while adjacent different-color runs may touch. Both engines emit `[run_length, color_id]` pairs, encode an empty line as `[]`, and share `tests/Fixtures/rulesets/color-v1.json` cases for touching colours, separated same colours, empty lines, and top-to-bottom column order.
- [x] Update editor palette selection, cell painting, clue previews, player color input, crossed/unknown marks, and solution preview; provide color names/patterns so color is not the only cue. The editor supports Color drafts, up to 8 named palette entries, painting, resize, clue previews, autosave, and recovery. The player renders palette controls and typed clues, supports independent colour-ID/cross marks, Color start hints, authenticated/local progress, and clue candidates. Names, numeric IDs, and cell patterns distinguish colours beyond hue.
- [x] Update solver candidate generation and uniqueness checks for palette-aware clues and color-specific adjacency. `App\Services\Nonogram\ColorUniquenessSolver` and `resources/js/lib/nonogram/color-uniqueness-solver.ts` generate candidates where same-colour runs need a separator and different colours may touch, and both report unique/ambiguous/inconsistent/unknown under the same node, time, and candidate budgets.
- [x] Update server completion comparison to compare exact color IDs per cell; prevent malformed palette or client color substitutions. `ColorRuleset::matchesSolution` enforces exact filled IDs and permits crossed marks only on empty solution cells. Save validation checks IDs against the pinned palette; automated progress saves complete exact Color solutions server-side without returning solution data.
- [x] Add cross-language fixtures/tests for touching different colors, separated repeated colors, empty lines, invalid palette indexes, monochrome-only palette, ambiguous and timeout cases. `tests/Fixtures/rulesets/color-v1.json` now includes solver cases consumed by both `ColorUniquenessSolverTest` and `color-uniqueness-solver.test.ts`, covering unique, ambiguous, inconsistent, and zero-budget timeout results.

**Acceptance:** Color puzzle can be authored, published under the same validation policy, played accessibly, saved/resumed, and server-verified without leaking solution cells.

## C. Mega Picross

- [ ] Before coding, document the exact Mega Picross convention selected (paired row/column association, plus-clue interpretation, valid runs crossing the pair, clue display, unmatched edge line behavior) and include worked examples.
- [ ] Represent Mega clues as typed mode-specific structures; do not encode a plus marker as a negative/large Classic run integer.
- [ ] Generate, validate, solve, and render paired-line clue constraints. Ensure clue candidates are consistent across both linked lines.
- [ ] Add editor visual indicators for linked row/column pairs and validation messages when a pair is inconsistent.
- [ ] Make player UI communicate active paired lines and completed cross-line runs; support keyboard/screen-reader descriptions.
- [ ] Add solver fixtures for valid cross-pair runs, pair edges, contradiction, ambiguous solution, unique solution, and timeouts.
- [ ] Verify completion is exact comparison with canonical grid and saved progress remains tied to immutable Mega rules revision.

**Acceptance:** One approved Mega rules version is implemented identically by server/client and has passing generation, solver, editor, play, and completion tests.

## D. Clip Picross — rules decision gate

- [ ] Identify the specific Clip Picross reference/desired behavior with product owner before implementation. Record URL/source, version/platform, and examples or explicitly decide original project rules.
- [ ] Resolve and write down: meaning of a “clip”, whether an area is masked/cropped/overlaid, what clues are visible, how the player selects/reveals clips, unlock progression, and how the final solution is checked.
- [ ] Add a rules decision note to this task (or separate ADR) and define at least three example boards with expected clues and solved state.
- [ ] Only after the above is approved, implement Clip as its own rules strategy and version; reuse editor/player primitives where valid, not guessed Classic assumptions.
- [ ] Add server and client tests for clip boundaries, reveal/unlock rules, solution secrecy, progress persistence, and completion.

**Acceptance:** Clip Picross remains explicitly unsupported until its rule decision is approved. No UI label or game behavior should imply compatibility before then.

## E. Album/mosaic domain and persistence

- [ ] Define album vs collection clearly in UI and API: an album has a completion objective and tile-to-composite mapping; a collection is only a curated ordered list.
- [ ] Create album, immutable album revision, album-revision component, and album-progress persistence with owner, visibility, component puzzle revision FK, arrangement/position, required flag, and timestamps.
- [ ] Choose and document placement coordinates: grid row/column arrangement or normalized rectangles. Validate all required placements lie within the composite, have valid dimensions, do not overlap, and cover intended regions; support non-square albums.
- [ ] Pin each album component to an immutable `puzzle_revision_id` when publishing the album revision. Component edits/new publications must not silently update an existing album.
- [ ] Validate that every component is published and viewable; enforce ownership/fork permissions when creators add someone else's puzzle. Respect unlisted/private visibility and moderation on every read.
- [ ] Make album publication transactional; failed placement or permission validation leaves previous published album revision intact.
- [ ] Add factories/policies and tests for ordering, missing components, overlap/gap policy, cross-owner permissions, visibility, deleted/unpublished puzzles, and pinned revision immutability.

**Acceptance:** A published album has a stable, validated tile map to exact published component revisions and cannot expose private component content.

## F. Album authoring, play, and reveal

- [ ] Add album editor flow to create a composite artwork/grid, assign each component tile, reorder/replace components, preview the whole mosaic, and validate all placements before publish.
- [ ] Store/reuse sanitized artwork according to privacy policy; do not disclose unsolved tile source pixels through HTML, client props, APIs, thumbnails, or predictable asset URLs.
- [ ] Add album detail/play page with progress per required component and clear locked/solved tile states.
- [ ] Reveal each component tile only after server-verified completion of that pinned component revision. Never accept a client “solved” flag as proof.
- [ ] Derive album completion from all required component progress records for the pinned album revision; make the operation idempotent and safe under concurrent completion requests.
- [ ] Add album progress persistence for authenticated users and local-only guest progress; tie saves to album revision and component puzzle revision IDs.
- [ ] Add tests for partial reveal, puzzle replay, optional vs required components, changing component revision after album publish, concurrent final tile completion, guest resume, and no pre-completion image leakage.

**Acceptance / milestone:** Players can solve independent component puzzles, see their corresponding tiles revealed, and receive album completion only after all required components pass server verification.

## G. Discovery and regression

- [ ] Add mode and album filters/badges to browse pages without making unsupported modes selectable.
- [ ] Add album public/private/unlisted routes and creator profile listings with pagination and visibility checks.
- [ ] Add related albums/collections only when both parent and component content are visible to the current viewer.
- [ ] Add regression tests proving Classic editor, clue generation, uniqueness solver, progress, completion, and public response solution secrecy still pass after schema/engine changes.
- [ ] Update the main project specification and user-facing docs when a mode reaches supported status; record rules version and known limitations.

**Release gate:** Classic regression suite passes; each enabled mode has a complete ruleset contract and end-to-end validation; albums pin revisions and reveal no unsolved answers/artwork.
