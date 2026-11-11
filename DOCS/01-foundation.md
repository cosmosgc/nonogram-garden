# 01 — Foundation and private puzzle drafts

**Goal:** Establish the Laravel baseline and a tested, versioned puzzle domain that can create and retrieve private drafts securely.

## A. Baseline

- [x] Confirm the Laravel project is complete at repository root: `composer install`, `php artisan --version`, `php artisan test`, and `npm install` must succeed. Removed the temporary nested scaffold; the project specification remains intact.
- [x] Configure local environment and database using `.env.example`; document required PHP extensions and database settings in the root README. Never commit `.env` or credentials.
- [x] Choose and record the supported database for development and CI (MySQL locally/production; in-memory SQLite for automated tests). All current migrations have been applied on MySQL.
- [x] Add project scripts or documented commands for tests, formatting, and frontend build; confirm a clean production asset build. Root BAT scripts are provided for migrations and Vite/Tailwind build.

**Acceptance:** A new checkout can install dependencies, configure a local database, migrate, run tests, and build assets using documented commands.

## B. Canonical grid domain

- [x] Define a version-1 monochrome grid contract: width/height integers 1–30, row-major cells, `0` empty and `1` filled, exactly `width * height` cells, maximum 900 cells. Transport/storage shape is `{version: 1, width: integer, height: integer, cells: binary-string}`.
- [x] Implement framework-independent PHP grid validation/codec under `app/Services/Nonogram/`. Reject malformed dimensions, wrong cell count, non-binary values, unknown encoding versions, and oversized payloads with clear validation errors.
- [x] Implement clue generation for rows and columns from the canonical grid. Adopt `[0]` for an all-empty line; never trust supplied clues as canonical.
- [x] Add unit tests for empty, full, alternating, single-cell, rectangular, and mixed grids; include dimension boundaries and malformed inputs.
- [x] Define a stable checksum/hash algorithm and canonical input bytes; test that equivalent valid grids produce the same hash and changed grids do not.

**Acceptance:** Domain tests prove deterministic encoding, decoding, row/column clues, and strict validation without depending on HTTP or Eloquent.

## C. Persistence and ownership

- [x] Add nullable, unique, case-normalized `username` to users; add profile fields only if required for the first slice. Supply a migration safe for existing user records.
- [x] Create `puzzles` migration/model: owner FK, title, description, dimensions, lifecycle status, visibility, nullable published revision pointer/time, timestamps, soft deletes, and owner/status plus discovery indexes. Numeric primary key is the stable draft route key; public slug routing remains a later feature.
- [x] Create `puzzle_revisions` migration/model: puzzle FK, unique per-puzzle revision number, dimensions, format version, encoded solution, regenerated row/column clues, solution hash, uniqueness/validation state, creator FK, timestamps. Add constraints/indexes where portable.
- [x] Keep mutable draft content separate from immutable published revisions. Draft cells are stored on `puzzles`; published snapshots use `puzzle_revisions`.
- [x] Add Eloquent relationships, factories, and a `PuzzlePolicy`. Deny all writes unless the authenticated user owns the puzzle; deny access to private puzzles for other users. Puzzle/User relations, Puzzle factory, and policy checks are implemented and tested.
- [x] Add feature tests for migration/model relations, create/read own draft, reject unauthenticated writes, reject cross-user reads/writes, and private visibility.

**Acceptance:** Migrations run from a clean database; policy-backed tests prove ownership and privacy.

## D. Draft HTTP vertical slice

- [x] Add authenticated web routes and Form Requests to create and fetch a user's private draft. Routes use session auth/CSRF middleware; create, fetch, and update are covered by feature tests.
- [x] Generate Vue API and navigation URLs from named Laravel routes instead of root-relative literals, so editor, importer, and player work when Laravel is mounted below a URL path. Feature/component tests cover a `/Nonogram%20laravel/public` base path.
- [x] Validate title, dimensions, and grid format on the server; generate clues and hash server-side. Ignore/reject client-submitted clues and identity/ownership fields.
- [x] Return only fields needed by the owner editor; ensure public/player responses are not introduced in this task.
- [x] Add draft version integer and enforce optimistic concurrency on updates (stale version returns HTTP 409 and does not overwrite newer data). Update locks/reloads the row within a transaction; stale-write preservation is feature-tested.
- [x] Add request tests for valid create/read/update, invalid data, forged owner, guest and cross-user authorization failure, and stale update conflict.

**Acceptance / milestone:** An authenticated owner can create, save, and retrieve a private draft with server-generated clues; another user cannot access it; stale writes cannot silently replace newer edits.
