# 02 — Manual editor and publishing

**Goal:** Enable authors to create and publish monochrome puzzles in the browser, preserving immutable published revisions.

**Depends on:** [01-foundation.md](01-foundation.md), especially the canonical grid contract and draft endpoints.

## A. Frontend foundation

- [x] Select Vue 3 + TypeScript + Vite integration appropriate to the installed Laravel scaffold. Record whether pages use Inertia or Blade + Vue; do not add both approaches without a need. Using Blade + Vue SFCs; Breeze Blade auth is installed; no Inertia integration.
- [x] Add TypeScript checking and Vitest; configure scripts for type-check, unit tests, and production build.
- [x] Implement typed grid, clue, and draft-version types matching the PHP format; add runtime validation at server-response boundaries. Draft response IDs, title, version, timestamp, and grid format are runtime-validated.
- [x] Implement browser clue generation and tests matching PHP fixtures, including `[0]` empty-line convention and rectangular grids.

**Acceptance:** Type check/build succeeds, and JS/PHP implementations produce identical clues for shared test fixtures.

## B. Editor core

- [x] Add editor page for a new 10×10 draft and for loading an owned draft; display actionable errors for failed load/save.
- [~] Render grid and row/column clues with Canvas 2D or equivalent accessible rendering. Keep buttons, labels, clue text, and status messages as semantic HTML rather than canvas-only UI. Canvas has keyboard editing; an optional semantic table alternative exposes row/column clues, per-cell state, roving focus, and arrow-key navigation, with component tests for editing and hidden-answer test play. Manual screen-reader/AT validation remains.
- [~] Implement pointer drawing: start stroke on pointer down, paint cells while dragging, stop on pointer up/cancel/leave; prevent accidental page scrolling only while a touch stroke is active. Pointer capture and cleanup handle up/cancel/leave; component tests cover drag, cancel, and leave, and a Chromium test paints a run across the canvas and stops after pointer up. Real-device touch/scroll behavior and limiting `touch-action` to active touch strokes remain.
- [~] Add fill, erase, and clear tools; use large usable touch targets and keyboard-accessible tool selection. Explicit fill/erase/clear and test-mode X-mark controls exist; component tests cover keyboard fill, erase, clear, undo, and redo, and a Chromium test covers erase/clear with undo/redo. Touch/pointer cancel and mobile target testing remains.
- [x] Implement zoom and hit testing so pointer-to-cell mapping remains correct at non-default zoom and on high-DPI displays. Zoom controls, device-pixel-ratio rendering, and scale-aware pointer mapping are implemented and unit/component tested; a Chromium test paints the expected cell at 120% zoom.
- [~] Add bounded undo/redo command history; new edits after undo clear redo history. Pure history supports bounded snapshots, dimension changes, undo/redo, and branch-after-undo with unit tests. Editor wiring is implemented; Chromium tests cover drag drawing, erase/clear, and a new edit after undo. Manual touch/erase/clear checks remain.
- [x] Add grid presets (5, 10, 15, 20, 25) and custom dimensions 1–30. Define resize behavior explicitly (preserve top-left intersection and pad new cells empty); test expand/shrink and rectangular grids.
- [x] Add title/description editing and a solution preview/test mode that is clearly marked as author-only and does not publish solution data. Draft descriptions are persisted server-side; local test play hides the solution in the canvas and never submits player marks as puzzle edits.
- [x] Support optional validated puzzle tags and preserve them in autosave and local recovery. Authors can add up to 8 lowercase slug tags; public detail and browse expose/filter tags only for approved public puzzles.

**Acceptance:** Author can draw and edit a grid using mouse, keyboard, and touch; clues update immediately; history and resizing pass unit/component tests.

## C. Autosave and recovery

- [x] Debounce autosave and send draft version with every update; handle 409 by preserving local work and offering reload/compare instead of retrying destructively. Component tests cover debounce, queued in-flight edits, network failure/retry, and 409 local-cache preservation; stale-tab HTTP concurrency is also tested. Loading an owned draft no longer queues a rewrite of unchanged content, and a Chromium test asserts that opening a draft sends no update request.
- [x] Display save state (saving, saved, offline/error) and last successful save time. Shows current save state and last successful save time.
- [x] Preserve recoverable unsaved work across reloads using a scoped local draft cache; clear it only after confirmed server save or explicit discard. Versioned localStorage cache is user-scoped, restores matching-version drafts, and requires explicit confirmation to restore over a newer server draft. Chromium reload tests cover automatic same-version recovery and the confirmation flow for a newer server draft.
- [~] Test rapid edits, network failure/retry, stale tabs, and navigating away with pending edits. Component tests now cover rapid queued edits and network retry; stale-tab API behavior, save conflicts, and reload recovery are covered in Chromium. Browser-level navigation with a pending request remains.

**Acceptance:** No edit is silently discarded by network failures or stale concurrent writes.

## D. Publish workflow and revisions

- [~] Add publish action behind owner policy and server-side validation; regenerate clues/hash from canonical solution. Owner-confirmed publish endpoint and editor action exist; final manual UX/error polish remains.
- [x] Implement uniqueness solver or a bounded server-side analysis service that reports `unique`, `ambiguous`, `inconsistent`, or `unknown/timeout`. Never treat timeout as verified unique. The bounded, mode-neutral Classic solver is integrated into publishing; candidate, node, and time limits are tested.
- [x] Require verified unique status for public publishing per MVP; show actionable validation errors and preserve draft on failure. Ambiguous/inconsistent/timeout outcomes block publication; the server returns the exact uniqueness state and leaves the draft unchanged.
- [x] Publish transactionally: create immutable numbered revision and update puzzle's published revision pointer/status/time atomically. Prevent updates to published revision data.
- [x] Add unpublish/republish behavior without deleting revisions; ensure editing an already-published puzzle creates a new draft and publication creates a new revision. Unpublish restores the immutable snapshot to a private draft, increments its version, and hides it publicly; republish creates the next revision number while preserving old revisions and progress.
- [x] Add policy and feature tests for publishing, failed validation, immutable revisions, transaction behavior, and ownership.
- [x] Add public puzzle detail route that returns clues and metadata but never canonical solution before completion. Test serialized HTML and JSON responses for solution leakage.

**Acceptance / milestone:** An author can create, test, autosave, and publish a unique puzzle. Published revisions are immutable; player-facing responses omit the answer.
