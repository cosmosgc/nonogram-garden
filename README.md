# Nonogram Garden

Community Nonogram puzzle editor and game built with Laravel 13, Vue, and TypeScript. Classic and Color puzzle rules are versioned; canonical solutions stay server-side until completion is verified.

- Product specification: [`LARAVEL_NONOGRAM_PROJECT.md`](LARAVEL_NONOGRAM_PROJECT.md)
- Development backlog: [`DOCS/`](DOCS/README.md)
- Deployment notes: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- Supported platforms: [`docs/SUPPORTED-PLATFORMS.md`](docs/SUPPORTED-PLATFORMS.md)

## Features

- Create, edit, publish, and moderate immutable puzzle revisions.
- Play Classic and Color puzzles with keyboard, pointer, and gamepad controls.
- Save and resume progress; the server verifies exact solutions without exposing them to the client.
- Import images into editable puzzle grids.
- Add an optional profile avatar; uploads are validated and re-encoded before public display.

## Requirements

PHP 8.4.1+ with `ctype`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, and `xml`; Composer 2; Node.js 22/npm; and MySQL 8. Tests use SQLite and require `pdo_sqlite`.

## Setup

1. Install dependencies with `composer install` and `npm install`.
2. Copy `.env.example` to `.env`, configure the database, then run `php artisan key:generate`.
3. Run `php artisan migrate`.
4. Create the public storage symlink with `php artisan storage:link` (used for sanitized profile avatars).
5. Build assets with `npm run build`.
6. Start the app with `php artisan serve`; use `npm run dev` for frontend hot reload.

Run checks with `php artisan test`, `npm run typecheck`, and `npm run test:unit`.

## Security

Do not commit `.env`, credentials, local databases, or generated assets. Report suspected vulnerabilities privately to the repository owner rather than opening a public issue.
