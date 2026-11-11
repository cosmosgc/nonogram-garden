# Laravel Nonogram / Picross — Project Specification

## 1. Vision

Build a responsive, community-driven Nonogram (Picross) application using **Laravel**. The primary differentiators are a powerful **nonogram editor/creator** and an **image-to-nonogram conversion studio**. Every published puzzle belongs to a user; users have public profiles, and puzzles can be organized into collections.

This specification assumes an existing Laravel application with Breeze authentication, but the game module should be separable from the rest of the application. Target desktop and mobile browsers. Start with monochrome puzzles and design the schema and engine for optional colored puzzles later.

### Main user journeys

1. Sign in, create a puzzle manually, preview clues, validate, save a draft, and publish it.
2. Upload an image, crop and convert it to a grid, adjust the result, validate, and publish it.
3. Browse, search, play, pause, resume, and complete puzzles.
4. Visit a creator profile and browse their puzzles and collections.
5. Create collections, add/reorder puzzles, and share collections.

## 2. Functional scope

### 2.1 Puzzle editor — top priority

- Grid sizes: presets (5×5, 10×10, 15×15, 20×20, 25×25) and custom width/height within configurable limits.
- Draw filled and empty cells by mouse, touch, or stylus; click/drag painting; erase and fill tools; zoom/pan; optional symmetry and rectangle tools.
- Undo/redo with command history; clear grid; invert; flip horizontally/vertically; rotate when dimensions permit; resize with explicit crop/pad behavior.
- Generate row/column run-length clues immediately as cells change.
- Display clue previews, solution preview, and playable test mode.
- Autosave drafts and recover unsaved edits; manual save, publish, unpublish, duplicate, and fork (with attribution and permission checks).
- Keyboard accessibility and shortcuts; mobile-friendly large touch targets and prevention of accidental scrolling while painting.
- Validation before publishing: dimensions, content rules, and **solution uniqueness** where required. Warn if a puzzle is ambiguous, rather than pretending the authored bitmap uniquely follows from its clues.
- Draft and published revision management; published puzzles should have immutable solution revisions to avoid breaking saved player progress.

### 2.2 Image-to-nonogram converter — top priority

Conversion workflow:

1. Upload PNG/JPEG/WebP (optionally GIF first frame); validate file type, byte size, dimensions, and decode safety.
2. Present crop, rotate, fit/contain, aspect ratio, and target grid size controls.
3. Resize to a low-resolution grid using selectable methods: area averaging for photographic images, nearest-neighbor for pixel art.
4. Convert to grayscale (luminance) and apply threshold; allow inversion, contrast, brightness, optional dithering, and simple cleanup of isolated pixels.
5. Show **side-by-side original, pixelated grid, and generated clues**. Allow direct cell edits after conversion.
6. Calculate difficulty indicators and run a uniqueness check. Offer suggestions if the grid produces an ambiguous puzzle.
7. Save the generated grid as an editable draft; only publish after explicit confirmation.

**Important:** converting an image to a binary grid is not the same as generating a logically unique puzzle. A bitmap always produces clues, but those clues may permit multiple solutions. Use a solver to test uniqueness; optionally offer a separate clue-preserving repair workflow in a later phase.

For privacy, use client-side processing where practical and only upload the original image if the user explicitly chooses to save it. The authoritative puzzle solution must still be validated server-side.

### 2.3 Playing

- Render row/column clues; fill, mark empty (X), and clear cells.
- Highlight active row/column; optionally highlight completed clue groups.
- Support keyboard, mouse, and touch; zoom, pan, high contrast, and colorblind-safe indicators. Classic cells remain square; auto-cross only cells proved empty by the clues and server-confirmed fills, unless disabled.
- Save progress automatically per authenticated player; optional local progress for guests. A previously verified revision can be replayed locally without altering its original completion record.
- Timer, mistakes, size-based lives, pause/resume, and completion detection. Normal mode grants 3 lives for boards up to 100 cells, 5 for 101–400 cells, and 7 above 400; each newly filled incorrect cell costs one life. At zero, the attempt locks and the player may restart. Hardcore mode disables both auto-cross and lives; mode locks on the first fill. Life/mode state is session-scoped and correctness is checked server-side without returning a per-cell correctness flag.
- At a fresh game start, autofill one server-selected random row and column as a hint. Only those lines are revealed; suppress the hint when they would disclose the whole board.
- Compare player's completed grid with the stored canonical solution (not merely clue totals) unless a separately defined multi-solution mode is introduced.
- Puzzle pages show author, difficulty, dimensions, tags, plays, completions, and related collections.

### 2.4 Game modes and multi-puzzle albums

Model a puzzle's rules as a versioned mode plus mode-specific settings; do not infer rules from its title. Classic remains the default; Color Picross v1 is also implemented and supported. Add other modes incrementally. Lives and Hardcore are player/session settings orthogonal to puzzle rules; expose the same behavior in every implemented mode.

- **Classic Picross / Nonogram:** monochrome binary grid, standard run-length clues, existing MVP rules.
- **Color Picross:** cells use a palette (empty plus one or more colors). Clues identify both run length and color. Adjacent runs of the same color require at least one empty cell between them; adjacent runs of different colors may touch. Solver, clue generator, rendering, and completion checks must all use the same palette-aware rules. Add accessible color labels/patterns so color is not the only signal.
- **Mega Picross:** paired adjacent rows/columns have special extended clues whose run spans the pair of lines (commonly displayed with a plus marker). Treat Mega clues as a distinct clue/rule representation, not as ordinary run numbers with decorative UI. Confirm and document the exact line pairing, clue interpretation, and edge behavior against the selected ruleset before implementation; generate/validate/solve with that same ruleset.
- **Clip Picross:** a named mode is requested, but its mechanics are not sufficiently specified here. Before implementation, record the intended source game's rules or a product decision: how a clip is defined, whether regions are hidden/overlapped, what clues are shown, and how solving unlocks or reveals content. Do not ship a guessed mechanic. Implement it as a separately versioned ruleset once approved.
- **Album / mosaic puzzles:** a creator can publish a themed album/series containing ordered component puzzles that are pieces of one larger image. Solving one component unlocks its image tile; completing all required components reveals the assembled full image and album completion. Components remain individually playable and retain their own dimensions, clues, progress, and immutable puzzle revisions. Albums specify the composite image/grid dimensions, tile arrangement, required component set, and optional cover/artwork. Validate that component placements do not overlap or leave unintended gaps; support non-square arrangements. Do not require the component solutions to use identical grid dimensions if each placement metadata maps them unambiguously into the composite.
- Albums are a distinct relationship/feature, not a synonym for ordinary collections: collections are user-curated lists; albums have a completion objective and component-to-composite mapping. An album may also be listed in a collection.
- Publishing an album pins each component to a specific published puzzle revision. Later component edits must not change the album's answer/artwork; changing a component requires an explicit album update/new album revision.
- Player progress and completion are tracked per component revision, plus derived album completion. Do not count the album complete until every required component in the pinned album revision is server-verified complete.

### 2.5 User profiles and community

- Public `/@{username}` profile with avatar, bio, puzzle list, public collections, counts, and optionally achievements.
- Puzzle ownership via `user_id`, with author attribution and authorization checks.
- Visibility: draft/private, unlisted, public; moderation state tracked separately.
- Collections have an owner, description, visibility, cover, and ordered puzzle entries; one puzzle can appear in many collections.
- Browse/filter by grid size, difficulty, creator, tags, popularity, and recency.
- Favorites/bookmarks, ratings, comments, follows, and reporting are optional later phases.
- Provide account export/deletion and clear privacy settings.

## 3. Suggested technical architecture

| Layer | Recommendation | Rationale |
|---|---|---|
| Backend | Laravel (current supported release) + PHP | Authentication, policies, Eloquent, queues, storage |
| Existing auth | Laravel Breeze if already installed | Reuse existing user accounts and sessions |
| Frontend | Vue 3 + TypeScript + Vite | Strong interactive editor/game UI |
| Laravel integration | Inertia.js Vue adapter **or** API endpoints | Inertia for app pages; JSON endpoints for autosave and processing |
| Styling | Tailwind CSS | Responsive layout and accessible controls |
| Editor rendering | HTML Canvas 2D | Efficient grid drawing, hit testing, zoom, touch |
| Rule engines | Versioned mode strategies (`classic`, `color`, `mega`, approved `clip`) | Keeps clue generation, validation, solver, editor, and player aligned |
| Album composition | Ordered album/component records with pinned puzzle revisions | Stable tile placement and completion across puzzle edits |
| UI overlays | Vue components + HTML | Accessible buttons, dialogs, clues and toolbars |
| State | Pinia | Editor history, draft state, game progress |
| Image processing | Browser Canvas / `sharp` server-side where applicable | Client preview and server-side thumbnails |
| Server image processing | Intervention Image for PHP | Image validation, resizing, previews |
| Database | PostgreSQL (preferred) or MySQL | Relations, indexes, JSON support |
| Cache / jobs | Redis + Laravel queues | Image jobs, uniqueness analysis, statistics |
| Storage | Laravel filesystem, local or S3-compatible | Avatars, optional source images, thumbnails |
| Tests | Pest/PHPUnit + Vitest + Playwright | Backend, puzzle engine, and browser behavior |

**Design rule:** keep the puzzle engine as a framework-independent TypeScript module, with matching PHP validation of serialized puzzle data. This makes the editor and game reusable in future projects.

### Suggested module layout

```text
app/
  Actions/Puzzles/{CreatePuzzle,UpdateDraft,PublishPuzzle,ConvertImage}.php
  Http/Controllers/{PuzzleController,PuzzleDraftController,CollectionController,ProfileController}.php
  Http/Requests/{StorePuzzleRequest,UpdatePuzzleRequest,ImageUploadRequest}.php
  Jobs/{AnalyzePuzzleUniqueness,ProcessPuzzleImage}.php
  Models/{Puzzle,PuzzleRevision,Collection,CollectionItem,PuzzleProgress}.php
  Policies/{PuzzlePolicy,CollectionPolicy}.php
  Services/Nonogram/{GridCodec,ClueGenerator,UniquenessSolver,PuzzleValidator}.php
resources/js/
  components/nonogram/{GridCanvas.vue,CluePanel.vue,EditorToolbar.vue,ImageConverter.vue}.vue
  pages/{Puzzles,Editor,Play,Profiles,Collections}/
  stores/{editor,player}.ts
  lib/nonogram/{types,grid,clues,history,solver,image-conversion}.ts
  workers/{uniqueness.worker.ts,image-conversion.worker.ts}
  tests/nonogram/
routes/{web.php,api.php}
tests/{Feature,Unit}/
```

If using Inertia, follow the conventions of the installed Inertia/Vue starter version rather than copying directory naming blindly.

## 4. Composer packages

Packages below are candidates, not all mandatory. Verify compatibility with the installed Laravel/PHP versions and review maintenance status before installation.

| Package | Priority | Purpose |
|---|---|---|
| `intervention/image-laravel` | Recommended | Laravel integration for Intervention Image; thumbnails and server-side transforms |
| `intervention/image` | Recommended | PHP image processing; may be installed transitively by its Laravel integration |
| `spatie/laravel-medialibrary` | Optional | Attach avatars, puzzle covers, and uploaded source images to Eloquent models |
| `spatie/laravel-permission` | Optional | Moderator/admin roles and permissions; Laravel policies still enforce ownership |
| `spatie/laravel-query-builder` | Recommended | Safe filter/sort/include patterns for browse APIs |
| `laravel/sanctum` | Optional | Token auth if adding external clients; not necessary for same-origin session-based Inertia |
| `laravel/horizon` | Optional | Monitor Redis queue workers |
| `laravel/pulse` | Optional | Application performance and usage monitoring |
| `pestphp/pest` | Recommended | Expressive PHP tests |
| `laravel/pint` | Recommended | PHP formatting |
| `spatie/laravel-activitylog` | Optional | Audit publishing, moderation, and significant content changes |

Example installation (pick only the features you need):

```bash
composer require intervention/image-laravel spatie/laravel-query-builder
composer require --dev pestphp/pest laravel/pint
# Optional:
composer require spatie/laravel-medialibrary spatie/laravel-permission
composer require laravel/horizon
```

**Avoid duplicate image pipelines:** Intervention Image and Media Library can complement each other, but Media Library is not needed if Laravel's filesystem plus a few image transformations is enough.

## 5. npm packages

| Package | Priority | Purpose |
|---|---|---|
| `vue` | Core | Editor/game UI |
| `typescript` | Core | Type-safe puzzle engine |
| `@vitejs/plugin-vue` | Core | Vue SFC integration with Vite |
| `@inertiajs/vue3` | Conditional | Vue integration if using Inertia |
| `pinia` | Recommended | Editor and game state |
| `@vueuse/core` | Recommended | Debounce, storage, pointer-related utilities |
| `cropperjs` | Recommended | Interactive image crop UI; integrate its current API carefully |
| `pica` | Optional | High-quality browser image resizing; compare output with native Canvas |
| `zod` | Recommended | Runtime validation of draft/grid data in browser |
| `lucide-vue-next` | Optional | Toolbar icons |
| `@headlessui/vue` | Optional | Accessible dialogs and menus |
| `vitest` | Recommended | Unit tests for clue generation, grid transforms, undo/redo |
| `@vue/test-utils` | Recommended | Vue component tests |
| `@playwright/test` | Recommended | End-to-end browser and touch interaction tests |

Example:

```bash
npm install vue pinia @vueuse/core cropperjs zod lucide-vue-next
npm install -D typescript @vitejs/plugin-vue vitest @vue/test-utils @playwright/test
# Only if using Inertia and not already installed:
npm install @inertiajs/vue3
# Optional resizing:
npm install pica
```

Use native Canvas APIs first. A large drawing framework such as Konva or Fabric is not essential for a regular cell grid and can increase complexity. Add one only if the editor later needs rich layered objects.

## 6. Data model

### `users` (extend existing)

- `id`, `name`, `username` (unique, case-normalized), `avatar_path` (nullable), `bio` (nullable), `is_moderator`, `is_admin`, timestamps.
- Do not expose private email addresses on profiles.

### `puzzles`

- `id` (ULID or bigint), `user_id` FK, `slug` (unique), `title`, `description`, `width`, `height`, `status` (`draft`, `published`, `archived`), `visibility` (`private`, `unlisted`, `public`), `difficulty` (nullable), `cover_path` (nullable), `published_revision_id` (nullable), `published_at` (nullable), timestamps, soft deletes.
- Index `(user_id, status, created_at)` and `(status, visibility, published_at)`.
- `status` describes lifecycle; `visibility` controls discovery/access; moderation should be independent.

### `puzzle_revisions`

- `id`, `puzzle_id`, `revision_number`, `width`, `height`, `mode` (`classic`, `color`, `mega`, or approved future mode), `rules_version`, `palette` (JSON for color mode), `solution_encoding`, `solution_data`, mode-aware `row_clues`/`column_clues` (JSON), `solution_hash`, `is_unique` (nullable boolean), `validation_status`, `created_by`, timestamps.
- Unique `(puzzle_id, revision_number)`.
- Treat published revisions as immutable. Use a separate draft revision or working document for changes.
- Server must regenerate clues from the canonical solution; do not trust client-supplied clues.

### `puzzle_progress`

- `id`, `user_id`, `puzzle_id`, `puzzle_revision_id`, `cell_marks` (binary/JSON), `elapsed_seconds`, `mistake_count`, `completed_at` (nullable), `last_played_at`, timestamps.
- Unique `(user_id, puzzle_revision_id)` for a single save slot; introduce `attempts` separately if replay history is needed.
- Mark values should distinguish unknown, filled, and crossed-out cells.

### `collections`

- `id`, `user_id`, `slug`, `title`, `description`, `visibility`, `cover_path`, timestamps, soft deletes.

### `albums` and `album_revisions` (multi-puzzle mosaic)

- `albums`: owner, slug/title/description, visibility/status, cover, current published album revision pointer, timestamps, soft deletes.
- `album_revisions`: album, revision number, composite width/height or tile layout dimensions, serialized composite artwork/grid (or private artwork path plus checksum), validation state, timestamps. Published album revisions are immutable.
- `album_revision_puzzles`: album revision, component puzzle, pinned `puzzle_revision_id`, tile position/row/column or explicit normalized rectangle, required flag, unique position/component constraints. Validate placements server-side and ensure all required components are pinned to published revisions.
- `album_progress`: player, album revision, derived completion state/time; derive/check completion from verified component progress rather than trusting client flags. If materialized, update transactionally and remain recomputable.
- Treat album progress as separate from ordinary collection membership. A collection can contain albums only through a separately defined relationship if that is desired; do not overload `collection_puzzles`.
- Unique `(user_id, slug)` or global slug with a documented route strategy.

### `collection_puzzles`

- `id`, `collection_id`, `puzzle_id`, `position`, `added_at`.
- Unique `(collection_id, puzzle_id)`; index `(collection_id, position)`.
- Check visibility of both collection and puzzle when serving entries.

### Optional tables

- `tags`, `puzzle_tag` for filtering.
- `puzzle_favorites` (`user_id`, `puzzle_id`) with unique pair.
- `site_settings` for privileged global discovery settings, including the disabled-by-default pending-public preview toggle.
- `puzzle_reports` for moderation.
- `puzzle_plays` or `puzzle_completions` for analytics and repeat attempts.
- `follows` for creator subscriptions.

### Storage format

Use a versioned row-major cell document. Classic mode uses binary cells (`0` empty, `1` filled), packed bits or a compact validated string. Color mode uses palette indexes (`0` empty, nonzero values referencing the revision's palette). Mega mode can retain a binary solution but must store/derive its paired-line clue semantics under its `rules_version`. Do not conflate clue representation with solution cell encoding. Store player marks separately with explicit unknown/filled/crossed-out states (and selected color where applicable). Include mode, rules/encoding version, width, height, and checksum/hash; enforce limits on palette size, total cells, and payload. Do not send the full canonical solution to players before completion. Classic play intentionally reveals exactly one randomly selected row and one column as a server-generated start hint; return only those line marks, never the remaining solution. Suppress the hint when a single-row or single-column board would make it equivalent to the full answer. Server-side completion validation remains authoritative. Set server-side limits for total cells and payload size.

## 7. Nonogram domain algorithms

### Clue generation

For Classic mode, for each row/column scan consecutive filled cells and emit their run lengths. Color mode emits ordered run length + palette color clues using color-specific separation rules. Mega mode generates paired-line clues per its explicitly versioned ruleset; it must not reuse Classic clue generation. Clip mode requires an approved written rules definition before implementation. Example:

```text
Cells:  0 1 1 0 1 1 1 0
Clues:  [2, 3]
Cells:  0 0 0 0 0
Clues:  [0]  (choose one consistent empty-line convention)
```

Define one shared convention and test it on both frontend and backend.

### Uniqueness and solver

- Generate all legal line patterns consistent with clue runs and line length.
- Apply constraint propagation between row and column candidates.
- Backtrack on the most constrained unresolved line/cell.
- Stop after finding **two distinct solutions**; zero means inconsistent clues, one means unique, two means ambiguous.
- Set time/iteration limits and report `unknown/timeout` distinctly from `unique`.
- Run in a Web Worker for previews; confirm on server before publication if uniqueness is a publishing requirement.
- Difficulty estimates can combine propagation-only progress, search branching, and puzzle dimensions; label estimates as heuristic.

### Image conversion notes

- Use an adjustable threshold (for example luminance below 128 = filled), with optional Otsu auto-threshold.
- Allow nearest-neighbor for sprite/pixel art and area average for photographs.
- Dithering may improve resemblance but can create noisy, unpleasant puzzles; default it off.
- Show filled-cell ratio and connected-component warnings, but do not impose arbitrary artistic rules.
- Never promise an image-derived grid has a unique logical solution until the solver confirms it.

## 8. Routes and API design

### Web pages

```text
GET  /puzzles                    Browse public puzzles
GET  /puzzles/{slug}             Puzzle details / play
GET  /editor/new                 New puzzle editor
GET  /editor/{puzzle}            Edit owned puzzle draft
GET  /@{username}                Public creator profile
GET  /@{username}/collections    Creator collections
GET  /collections/{collection}   Collection details
GET  /me/puzzles                 My drafts and published puzzles
```

### Authenticated endpoints (illustrative)

```text
POST   /api/puzzles                        Create draft
PATCH  /api/puzzles/{puzzle}/draft          Autosave editable draft
POST   /api/puzzles/{puzzle}/publish        Validate and publish
POST   /api/puzzles/{puzzle}/unpublish      Unpublish
POST   /api/puzzles/{puzzle}/duplicate      Duplicate/fork if allowed
POST   /api/puzzle-images/preview           Optional server-side conversion preview
GET    /api/puzzles/{puzzle}/progress       Load player's progress
PUT    /api/puzzles/{puzzle}/progress       Save player's progress
POST   /api/puzzles/{puzzle}/complete       Validate completion on server
POST   /api/collections                    Create collection
PATCH  /api/collections/{collection}       Update owned collection
POST   /api/collections/{collection}/items  Add puzzle
PATCH  /api/collections/{collection}/order Reorder puzzles
DELETE /api/collections/{collection}/items/{puzzle} Remove puzzle
```

Use standard Laravel session authentication and CSRF protection for same-origin requests. Put API routes behind the appropriate session/auth middleware; do not assume `api.php` automatically provides session authentication. For Inertia applications, using `web.php` with JSON responses for autosave is often simpler.

Use Form Requests for validation, Policies for authorization, transactions for publishing, and idempotency/concurrency controls for autosave. Include a draft version number or `If-Match`/ETag-like check to prevent stale tabs overwriting newer edits.

## 9. Security, reliability, and moderation

- Verify puzzle ownership on every write, including draft, image, progress, and collection mutations.
- Enforce upload MIME by actual file content, size, pixel dimensions, and allowed extensions; never trust the filename. Strip image metadata and serve sanitized previews.
- Use storage URLs with appropriate visibility; avoid making original uploads public without consent.
- Rate-limit puzzle creation, uploads, progress writes, and public search.
- Validate grid dimensions, binary values, clue consistency, revision IDs, and collection ordering on the server.
- Queue expensive image transforms and uniqueness checks; set CPU/memory/time limits.
- Sanitize rich text or use plain text/Markdown rendered safely to prevent XSS.
- Prevent leaking solutions through public HTML props, serialized Inertia page data, and JSON endpoints.
- Provide report/unpublish controls and a moderation workflow for user-generated imagery, titles, descriptions, and profiles. Pending submissions stay hidden by default; a site administrator may deliberately enable a global preview of pending, public, published puzzles. This preview never exposes drafts, private or unlisted puzzles, rejected/removed content, or canonical solutions.
- Use database indexes and pagination for profile and browse listings.
- Back up database and media; test recovery of puzzle revisions and progress.

## 10. UX design

### Editor desktop layout

```text
+--------------------------------------------------------------+
| Title / Save / Publish / Undo / Redo / Test                   |
+----------------+--------------------------+------------------+
| Tools          | Grid + row/column clues  | Puzzle settings  |
| Pencil / Erase | Zoomable canvas          | Size / Metadata  |
| Symmetry       |                          | Preview / Checks |
+----------------+--------------------------+------------------+
```

On mobile, make the grid the main focus and move tools/settings to collapsible drawers or bottom toolbars. Keep controls large enough for touch, provide pinch-to-zoom and a pan mode, and preserve the visible puzzle area when the keyboard opens.

### Image converter layout

Use a guided three-stage flow: **Import & crop → Pixelate & tune → Edit & validate**. Keep source preview, resulting grid, and clue preview visible where screen size allows. Display warnings for low contrast, overly dense grids, and non-unique solutions.

## 11. Development roadmap

### Phase 1 — Foundation

- Reuse Breeze auth; create profile fields and username routing.
- Create puzzle/revision/progress/collection migrations, Eloquent relations, factories, policies, and tests.
- Implement shared grid format, clue generator, and basic server validation.

**Done when:** users can create, save, and retrieve private puzzle drafts with correct clues and ownership enforcement.

### Phase 2 — Manual editor (highest priority)

- Canvas grid, touch/mouse drawing, undo/redo, resize, clue preview, and autosave.
- Test-play mode, publish workflow, immutable revisions, and public puzzle pages.
- Unit tests for transforms, clue generation, serialization, and history.

**Done when:** a creator can author and publish a puzzle entirely in-browser.

### Phase 3 — Image conversion (highest priority)

- Upload/crop, threshold, resize strategies, invert, cleanup, and side-by-side preview.
- Convert to editable grid; optional worker for heavy processing.
- Add solver-based uniqueness check and actionable ambiguity feedback.

**Done when:** an uploaded image becomes an editable, validated puzzle draft.

### Phase 4 — Playing and progress

- Responsive clue/grid renderer, fill/X/clear tools, completion checking, timers, and save/resume.
- Revision-aware progress and server completion validation.

**Done when:** players can complete puzzles on desktop/mobile and resume later.

### Phase 5 — Community

- Profiles, creator galleries, collections, ordering, visibility, browse/search/filter.
- Optional favorites, tags, ratings, and moderation tools.

**Done when:** users can publish and discover creators' puzzles and organize them into shareable collections.

### Phase 6 — Quality and expansion

- Accessibility audits, browser/device testing, caching, queue monitoring, analytics, and backup drills.
- Classic and Color Picross v1 are supported; implement Mega Picross only after its convention is specified, and Clip Picross only after its reference mechanics are approved.
- Add album/mosaic puzzles: author a composite image, assign component puzzle tiles, pin revisions, reveal solved tiles, and verify album completion.
- Other optional work: daily challenges, achievements, leaderboards, collaboration, and puzzle import/export.

## 12. Testing checklist

- Clue generation: empty, full, alternating, single-cell, rectangular grids.
- Image conversion: transparent pixels, grayscale, large images, EXIF rotation, edge crops, different thresholds.
- Solver: uniquely solvable, ambiguous, impossible, and timeout cases for each supported mode; include color-run adjacency and Mega paired-line edge cases.
- Modes: mode/rules version round trip; reject unsupported combinations; color palette references and player mark states; Mega pair boundaries; Clip behavior only after its rules are approved.
- Albums: ordered component placement, gaps/overlap validation, pinned revision stability, partial reveal, all-components completion, and privacy of unrevealed tiles/solutions.
- Editor: drag drawing, touch input, zoom hit testing, undo/redo, resizing, autosave conflicts.
- Authorization: one user cannot edit another user's puzzle, collection, or progress.
- Revision behavior: editing a published puzzle does not corrupt active player progress.
- Completion: altered client requests cannot claim completion without matching the stored solution.
- Visibility: private/unlisted content is not leaked through search, feeds, or collections.
- Performance: large allowed grids and mobile low-memory devices.

## 13. Initial implementation decisions

Recommended MVP defaults:

- **Classic monochrome puzzles are the MVP/default; Color Picross v1 is now supported** with versioned palette rules. Mega Picross still needs an approved convention, and Clip Picross requires an approved mechanics decision. Album/mosaic puzzles are a later feature built on immutable component revisions.
- **Maximum 30×30 grid** initially; configurable after profiling.
- **Vue 3 + TypeScript + Canvas 2D** for editor and player.
- **Laravel + Inertia + Breeze** if compatible with the existing project; do not replace working authentication just to change UI stack.
- **Client-side image conversion** for fast interactive previews; server-side verification and optional thumbnail generation.
- **Uniqueness required for public publishing** (configurable later); timeouts do not count as verified unique.
- **One published immutable revision per update**; progress is tied to its revision.
- **Collections are user-owned ordered many-to-many lists**, not puzzle ownership containers.
- **Redis optional in development**, recommended when queue workloads grow.

## 14. First coding milestone

Build a vertical slice in this order:

1. Create `Puzzle`, `PuzzleRevision`, and `PuzzlePolicy` with migrations.
2. Define a versioned grid serialization format and PHP validation.
3. Implement `generateClues(grid)` in TypeScript with unit tests and matching PHP tests.
4. Build a Vue Canvas editor for 10×10 grids with draw/erase and clue display.
5. Add draft save/load with debounced requests and optimistic version checks.
6. Add a simple test-play mode and server-side publish validation.
7. Introduce image conversion only after the editor can reliably accept and save a generated bitmap.

**Architectural principle:** the image converter should produce exactly the same grid document that the manual editor produces. The editor, converter, solver, publisher, and player should not have separate incompatible puzzle formats.
