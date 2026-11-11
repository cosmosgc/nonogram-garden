# Development backlog

This directory turns `../LARAVEL_NONOGRAM_PROJECT.md` into small, verifiable implementation tasks. The specification remains the source of product intent; these files define the execution order and completion checks.

## Status convention

- `[ ]` Not started
- `[~]` In progress
- `[x]` Complete and verified

Do not mark a task complete until its acceptance criteria pass. Keep implementation decisions and changes reflected in the relevant backlog file.

## Backlog files

| File | Scope | Prerequisite |
|---|---|---|
| [01-foundation.md](01-foundation.md) | App baseline, domain format, schema, validation, private drafts | None |
| [02-editor-and-publishing.md](02-editor-and-publishing.md) | Manual editor, draft editing, revisions, publishing | 01 |
| [03-playing-and-progress.md](03-playing-and-progress.md) | Puzzle play, saved progress, completion | 02 |
| [04-image-conversion.md](04-image-conversion.md) | Image import, conversion, editor handoff | 02 |
| [05-community.md](05-community.md) | Profiles, browse, collections, visibility | 01–03 |
| [06-quality-and-operations.md](06-quality-and-operations.md) | Security, accessibility, reliability, deployment | Cross-cutting; finish before release |
| [07-game-modes-and-albums.md](07-game-modes-and-albums.md) | Color Picross, Mega Picross, Clip Picross rules gate, album/mosaic puzzles | Classic editor, publishing, and player foundations |

## Current project state

- The Laravel 13 app is rooted here; MySQL is used locally and automated tests use in-memory SQLite.
- The Classic and Color Picross v1 editors, immutable publishing, moderation, clue-only play, server-verified progress, profiles, collections, and local image conversion are implemented with automated coverage.
- Optional author tags and public tag filtering are implemented. Difficulty remains deferred until a shared rating definition exists.
- Structured error reporting is implemented: a dedicated JSON-lines `errors` log with request correlation ids, an argument-free trace summary, and redaction of bodies, credentials, e-mail addresses, solutions, clues, marks, and image data.
- Release work remains: manual real-device/accessibility checks, retention and backup-erasure policy, legal/security review, production-scale query plans, and deployment-specific recovery validation.
- Mega Picross remains gated on a documented rules convention; do not infer Clip Picross mechanics.

## Working rules

1. Work top-to-bottom within a file unless an explicit dependency allows otherwise.
2. Keep each pull request/task focused; add tests alongside behavior.
3. Treat all client-provided grids, clues, ownership IDs, and completion claims as untrusted.
4. Do not expose canonical solutions to players before completion.
5. Run the relevant automated tests and record any environment blockers before marking tasks complete.
