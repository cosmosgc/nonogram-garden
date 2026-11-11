# Supported platforms and dependency updates

This page records the project's declared runtime targets, CI baseline, and dependency-maintenance policy. A target is not a claim of manual browser certification; see [the quality backlog](../DOCS/06-quality-and-operations.md) for remaining real-browser/device checks.

## Runtime and database

| Component | Supported/target version | Validation status |
|---|---|---|
| PHP | 8.4.1 or newer, with extensions listed in the root README | Lockfile requires at least PHP 8.4.1; CI runs PHP 8.5 |
| Laravel | `^13.17` (Laravel 13) | CI resolves the committed Composer lockfile; local test run used Laravel 13.40 |
| Composer | Composer 2 | CI installs Composer 2; lockfile is committed |
| Node.js | 22 LTS for builds and frontend tests | CI runs Node 22; current local Node is newer |
| npm | Version bundled with the chosen Node 22 release; use `npm ci` for reproducible installs | `package-lock.json` is committed |
| Production database | MySQL 8.0 or newer | MySQL-compatible SQL; automated tests use in-memory SQLite. MariaDB compatibility is best-effort and should be tested on the target host |

Production must use a persistent supported SQL database; SQLite is the automated-test backend, not the recommended production datastore.

## Browser targets

The target is the latest stable and previous major release of desktop Chrome, Edge, Firefox, and Safari, plus the current iOS Safari and Android Chrome releases. Browsers without modern ES modules, Web Workers, `File`/Blob APIs, or the required canvas/image APIs are unsupported. The image importer depends on browser-local image decoding; its source image is not uploaded.

Component tests run in JSDOM. Playwright runs the browser suite in Chromium, Firefox, and WebKit, covering editor/player interaction and selected accessibility behaviors. WebKit is an engine proxy rather than certification of Safari releases, and these CI runs do not certify Edge or physical mobile devices. Manual compatibility, assistive-technology, touch, orientation, and virtual-keyboard testing remains a release task.

## Dependency update policy

- Keep `composer.lock` and `package-lock.json` committed and use `composer install` / `npm ci` for reproducible environments.
- Review dependency updates at least monthly and promptly when an upstream security advisory affects a direct or transitive dependency.
- Update dependencies in a focused branch/PR. Review release notes and breaking-change guides, especially for Laravel, PHP, Node/Vite, Vue, and database drivers.
- Run `composer audit`, `npm audit`, `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run test:unit`, and `npm run build` before merging.
- Separate major framework/runtime upgrades from routine patch/minor updates. Verify migrations and production deployment/rollback compatibility before release.
- Do not commit local `.env` files, credentials, generated secrets, or host-specific configuration while updating dependencies.
