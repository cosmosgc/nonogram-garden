# Production deployment runbook

This guide is an application checklist, not a substitute for host-specific PHP-FPM, web-server, firewall, TLS, and database documentation. Supported runtime/browser targets and the dependency update policy are recorded in [SUPPORTED-PLATFORMS.md](SUPPORTED-PLATFORMS.md). Do not use production credentials in local development, CI, or tests.

## Runtime and secrets

- Use PHP 8.3+ with the extensions listed in the root README, Composer 2, and MySQL 8.0+ (or a tested compatible server). Node.js 22 is used for the Vite build; Node is not needed to serve the compiled app.
- Provision a production database and a dedicated application user. Restrict network access and grant only the database privileges needed to run the app and its migrations.
- Inject environment variables through the host's secret/configuration manager. At minimum set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain`, a persistent secret `APP_KEY`, and production database credentials. Generate an application key once (for example, with `php artisan key:generate --show` in a secure environment) and keep it stable across releases; changing it can invalidate encrypted data and sessions.
- Set `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE=lax` when serving over HTTPS. Leave `SESSION_DOMAIN` unset unless cross-subdomain sessions are intentionally required. Configure trusted-proxy handling to match the hosting topology so Laravel sees the correct HTTPS scheme and client address.
- Configure a real mail transport if account password-reset email is enabled. Keep mail, database, and monitoring credentials out of source control and logs.

## Build and release

Build each release from a clean checkout or immutable commit:

```sh
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist
npm ci
npm run build
```

Install PHP extensions and inject the environment before running Artisan commands that bootstrap the application. Run migrations once per release, from a controlled deployment job:

```sh
php artisan migrate --force
php artisan optimize
```

The optimized caches include configuration, routes, events, and views. Rebuild them after configuration or route changes; do not cache configuration containing unresolved secrets. Keep `public/` as the web-server document root, deploy the compiled `public/build/` assets with the matching application release, and never expose the repository root, `.env`, `vendor/`, or `storage/` directly. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP runtime, but not world-writable.

Use HTTPS end to end where possible. Redirect HTTP to HTTPS at the edge, set secure response headers at the trusted web-server/proxy layer, and configure proxy trust narrowly. The application currently has no server-side image uploads, scheduled work, or dispatched queue jobs; browser image import stays local. A queue worker or scheduler is therefore not required for the current feature set. Revisit this if asynchronous jobs or scheduled cleanup are introduced.

## Database, backups, and privacy

The app uses the database for puzzles (including private draft and immutable solution data), progress, collections, sessions, and the configured cache/queue backends. Apply migrations with `--force`; never run `migrate:fresh`, `db:wipe`, or destructive seeders against production. Prefer forward-fix migrations for published data. Take a protected pre-release backup before migrations that alter data.

Backups contain private user content and puzzle solutions. Encrypt backups in transit and at rest, restrict access, document a retention period, and test restoration of both the database and matching application release. A complete retention and backup-erasure policy is still a release decision; do not claim that deleting an account immediately erases copies in backups until that policy is approved and implemented.

## Smoke checks and operations

- Check `GET /up` after deployment. This is an application liveness check, not proof that every dependency or write path is healthy.
- Verify login, password-reset mail (if configured), draft save, public browse, and play/progress on a non-production test account. Confirm pending moderation content remains undiscoverable.
- Grant moderator access only to a verified, trusted account from a protected server shell: `php artisan nonogram:moderator user@example.com`. Grant site administrator access, which controls the global pending-public preview setting, with `php artisan nonogram:admin user@example.com`. Revoke either role with `--revoke` when no longer needed. Keep the pending-preview toggle off unless deliberately accepting the public exposure of unreviewed submissions.
- Monitor PHP/web-server and Laravel logs, database capacity, backup jobs, and dependency/security advisories. Never enable `APP_DEBUG` in production.
- Unhandled server exceptions are written by `App\Support\Logging\StructuredErrorReporter` as one JSON object per line to the `errors` channel: `storage/logs/errors-YYYY-MM-DD.log`, kept for `LOG_ERROR_DAILY_DAYS` (30 by default) and written at `LOG_ERROR_LEVEL` and above. Set `LOG_ERROR_CHANNEL` to redirect the channel; keep the JSON formatter and dedicated file separation when doing so. Each record contains `event`, `severity`, `exception`, redacted `message`, `status`, `request_id`, `method`, `route`, `path`, `user_id`, environment, a relative file/line, an argument-free trace summary, and — for database failures — the connection name plus the unbound SQL statement.
- Request bodies, query values, session data, passwords, e-mail addresses, puzzle solutions, clues, player marks, and image data are not logged. Do not add them to report context; extend `SensitiveDataRedactor` instead when new payload fields are introduced. Unit and feature tests enforce the redaction rules.
- `X-Request-Id` is accepted from a well-formed inbound header (8–64 characters of `A-Z a-z 0-9 . _ : -`) or generated per request, returned on responses, and copied into matching error records. Provide it to users in support flows or capture it at the reverse proxy so a report can be traced end to end. Persist `storage/logs` on the host and ship the `errors` channel to your alerting stack; do not forward the default `laravel.log` without the same review, since framework/console output is not processed by the redactor.
- For incidents, preserve relevant logs without exposing user content, rotate compromised secrets, assess database and backup access, and follow the approved retention/deletion and notification policies.

## Rollback and release discipline

Do not assume every migration can safely be rolled back after users have written data. Prefer deploying a forward fix; if an application rollback is required, first confirm schema compatibility with the previous release and restore data only under an approved recovery plan. Keep the prior asset bundle and release available until smoke checks pass. Require the CI checks in [`.github/workflows/ci.yml`](../.github/workflows/ci.yml), review migration changes, and confirm a verified moderator and tested backup/restore process before public launch.
