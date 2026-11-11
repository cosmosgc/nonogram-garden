# 03 — Playing and progress

**Goal:** Let players solve published revisions, save progress, resume, and have completion verified against the canonical solution on the server.

**Depends on:** [01-foundation.md](01-foundation.md) and [02-editor-and-publishing.md](02-editor-and-publishing.md).

## A. Player UI

- [x] Add play page for a published puzzle revision; never include the full canonical answer in page props, HTML, or client requests. The server intentionally reveals one random row and column as a start hint, only for boards where their union is not the full solution; controller tests assert the rest of the solution is absent.
- [x] Implement player mark states `unknown`, `filled`, and `crossed-out`; support toggle/clear behavior consistently and document keyboard controls. Fill, cross-out, and erase tools are provided; arrow keys navigate and Enter/Space applies the selected mark.
- [x] Keep board cells square, size clue columns from clue content, and render column clues vertically above their fixed-width columns. The board now uses fixed equal-width columns and square cells; clue header height adapts to the deepest column clue.
- [x] Automatically cross cells proved empty in clue-complete rows/columns, with a player toggle to keep manual marking. Only server-confirmed filled cells and clues are used; incorrect fills cannot trigger auto-crossing, and the solution is never sent.
- [x] Reveal one randomly selected row and one column at game start, autofilling filled and crossed-out marks; keep the selected lines stable within the session. Only those selected line values are sent, and the hint is suppressed for single-row/column boards to avoid disclosing the entire answer.
- [x] Implement size-based lives and Hardcore mode. Normal mode grants 3 lives for ≤100 cells, 5 for 101–400, and 7 for >400. The server checks every newly filled cell; an incorrect fill costs one life, correct fills do not, and request UUIDs make retries idempotent. At zero lives the attempt locks; **Try again** resets it. Hardcore locks after the first fill, disables lives and automatic crosses, and leaves clues/solution validation unchanged. Game mode and lives persist in the Laravel session and reset with a new session; progress marks retain their existing account/device storage. Tests cover correct/wrong fills, life tiers, retry/idempotency, and hidden-puzzle access.
- [x] Define the optional random row/column hint precisely: on the first start in a session, choose one row and column, autofill their marks, and reuse that choice for the session. Do not reveal the rest of the canonical solution.
- [~] Support mouse, touch, keyboard navigation, active row/column focus, zoom, high contrast, and accessible status announcements. Semantic cell buttons, keyboard navigation, active lines, zoom, high-contrast marks, and live status are implemented; real-device touch and assistive-technology checks remain.
- [x] Add timer with pause/resume and define whether elapsed time counts paused/background time; test the chosen behavior. Time counts while unpaused, including background-tab time; pause/resume tests cover elapsed timing.
- [x] Detect local candidate completion in UI only as a prompt; final success must be server-verified. Matching clues triggers automatic persistence; the progress-save transaction compares saved marks with the canonical solution and returns completion only after an exact server-side match. There is no separate Verify completion button, and the browser never receives the solution.

**Acceptance:** Playable on desktop and narrow mobile viewport; controls are operable without a pointer. The full solution is never shipped; only the explicitly requested starting row/column hint may be revealed.

## B. Progress API and storage

- [x] Create progress migration/model with user, immutable revision, serialized marks, elapsed time, mistake count, completion timestamp, last-played time; unique save slot per user and revision. Puzzle identity is reached through the immutable revision foreign key.
- [x] Validate marks and exact cell count server-side; enforce maximum dimensions and ownership-independent permission to play public/unlisted puzzles according to visibility rules. User/revision IDs, mistake counts, and completion flags cannot be supplied in save payloads; each save is checked for exact completion inside the transaction, and incomplete saves do not incur a completion penalty. The legacy explicit completion endpoint still increments mistakes for a failed verification. Life checks are a separate session-scoped endpoint that returns only remaining lives/mode state, never a correctness flag or solution.
- [x] Add authenticated save/load routes protected by session/CSRF and rate limits. For guests, keep progress local only; never accept client-supplied user IDs. The player autosaves account progress and keeps guest progress in revision-scoped local storage.
- [x] Tie progress to revision ID so new publications do not corrupt old saves. The dashboard resumes the exact saved revision. Unpublished/private puzzles return 404 and retain their rows for a possible republish; soft-deleted puzzles are not route-bound, and hard deletion cascades progress through revisions.
- [x] Allow replaying a verified revision locally without modifying or unlocking the original server-verified completion; replay marks and timer use separate device-local storage.
- [x] Add conflict/version handling for concurrent saves and tests for invalid marks, unauthorized access, revision mismatch, and cross-user isolation. A 409 preserves a user-scoped local recovery copy and requires explicit restore/reload choice.

**Acceptance:** A signed-in player can leave and resume a save tied to the exact revision; guests can resume locally without server persistence.

## C. Completion verification

- [x] Implement server completion endpoint that loads the stored immutable revision and compares the saved filled-cell set exactly with its canonical solution.
- [x] Do not trust client completion flags, clue satisfaction, elapsed time, or answer hash. Prevent duplicate completion counting and repeated request abuse. Completion reads marks from server-stored progress, is idempotent, and is rate-limited.
- [x] Store completion atomically with progress; return success/failure without returning the solution on failure.
- [x] Add tests for exact solution success, mismatched marks, altered revision ID, forged completed flag, replayed requests, and hidden answer response.

**Acceptance / milestone:** Only an exact match to the selected canonical revision can complete a puzzle; saved progress survives sessions and revision changes.
