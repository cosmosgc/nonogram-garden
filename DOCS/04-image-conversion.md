# 04 — Image-to-nonogram conversion

**Goal:** Convert user-selected images into editable version-1 grids using the same draft format as manual authoring.

**Depends on:** [01-foundation.md](01-foundation.md) and [02-editor-and-publishing.md]. Implement only after the manual editor accepts and saves the canonical grid format.

## A. Browser conversion pipeline

- [~] Build guided import → crop/fit → pixelate/tune → edit/validate flow; support PNG, JPEG, and WebP only for MVP. Import, crop positioning, quarter-turn rotation, fit tuning, and editor handoff work; manual real-device flow validation remains.
- [x] Validate actual decoded type, file byte limit, and image dimensions; reject malformed/unsupported files and handle decode errors without crashing. The browser utility sniffs PNG/JPEG/WebP signatures, decodes with EXIF orientation, caps files at 10 MiB and decoded sources at 4 megapixels, closes rejected bitmaps, and surfaces friendly import errors.
- [x] Process in-browser by default; do not upload or persist original image unless the user explicitly chooses an upload/save feature. The original is decoded locally and only the resulting grid is passed to the editor through user-scoped session storage.
- [x] Implement crop, rotate, contain/cover choice, target grid size (each dimension 1–30), and nearest-neighbor / area-average resizing. The importer offers adjustable cover crop positioning, 90-degree rotation increments, contain/cover/stretch fit, target dimensions, and both resize algorithms.
- [x] Convert to grayscale using documented luminance coefficients, threshold to binary cells, and provide threshold/invert controls. Dithering is off by default. The UI and tested core use Rec. 709 coefficients (0.2126/0.7152/0.0722), composite transparency on white, and expose threshold/invert controls.
- [x] Implement optional brightness/contrast and isolated-cell cleanup as explicit controls, each covered by deterministic tests. Brightness adds 2.55 luminance units per setting point; contrast scales around midpoint 128 by `1 + contrast/100`; results clamp to 0–255. Cleanup is opt-in and removes filled cells with no orthogonal filled neighbor in one deterministic pass.
- [~] Show source, pixelated result, clues, threshold, and filled-cell ratio; ensure layout remains usable on mobile. The view shows the source, generated grid, clues, threshold, and filled-cell count; real-device layout testing remains.
- [~] Add worker-based processing for expensive conversion/analysis so controls remain responsive; provide cancellation and progress/error states. Image decoding/pixel extraction, grid conversion, and uniqueness analysis use Web Workers when supported, with main-thread fallback; decode shows an indeterminate live status and can be cancelled, and stale worker results are ignored. Percentage progress is unavailable from the browser decode API; manual real-device responsiveness checks remain.

**Acceptance:** Same input and settings produce the same grid; conversion yields the exact canonical editor grid object, and source images remain client-side by default.

## B. Validation, solver, and editor handoff

- [x] Run client-side uniqueness analysis in a Web Worker using the solver contract from task 02; distinguish unique, ambiguous, inconsistent, and timeout/unknown. A bounded worker analyzes generated clues with the same result states; the result is advisory and server publication remains authoritative.
- [x] Clearly explain that image similarity does not guarantee logical uniqueness; never label an unverified/timeout result unique. The importer explicitly states that a generated grid still requires server-side uniqueness validation before publication.
- [x] Show warnings for dense/sparse grids and ambiguous puzzles as guidance, not arbitrary rejection rules. The importer warns at ≤10% or ≥90% filled and explains that these heuristics do not block saving/publishing; ambiguous/inconsistent/unknown analysis also includes guidance.
- [x] Provide an explicit “Edit puzzle” action that transfers generated cells to a new or selected draft without a second, incompatible format. The importer passes the canonical grid through user-scoped session storage to a new editor draft.
- [x] Ensure server revalidates dimensions, cells, clues, hash, and uniqueness when user saves/publishes; client output is untrusted. The imported grid uses the ordinary draft and publish APIs, which perform server validation and uniqueness checks.
- [x] Add tests for transparent pixels, EXIF orientation policy, edge crop, max dimensions, threshold boundaries, inversion, nearest-neighbor, averaging, cancellation, and malformed files. Tests cover transparency, EXIF decode options, source limits, threshold equality, inversion, quarter-turn rotation, contain/cover crop alignment, brightness/contrast, isolated-cell cleanup, both resize methods, malformed signatures, editor handoff, solver classifications, and ignoring late worker results after cancellation.

**Acceptance / milestone:** A user can import an image, tune a grid, edit it manually, save as a draft, and publish only after normal server validation. No original image is retained without explicit consent.
