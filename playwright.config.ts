import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    reporter: 'list',
    use: {
        baseURL: 'http://127.0.0.1:5173',
        headless: true,
    },
    projects: [
        { name: 'chromium', testIgnore: '**/*.integration.spec.ts', use: { browserName: 'chromium' } },
        { name: 'firefox', testIgnore: '**/*.integration.spec.ts', use: { browserName: 'firefox' } },
        { name: 'webkit', testIgnore: '**/*.integration.spec.ts', use: { browserName: 'webkit' } },
        {
            name: 'laravel-chromium',
            testMatch: '**/*.integration.spec.ts',
            use: { browserName: 'chromium', baseURL: 'http://127.0.0.1:8000' },
        },
    ],
    webServer: [
        {
            command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort',
            env: { LARAVEL_BYPASS_ENV_CHECK: '1' },
            url: 'http://127.0.0.1:5173/tests/browser/player.html',
            reuseExistingServer: !process.env.CI,
            timeout: 120_000,
        },
        {
            command: 'php artisan migrate:fresh --seed --force --no-interaction && php artisan nonogram:admin test@example.com --no-interaction && php artisan serve --host 127.0.0.1 --port 8000',
            env: {
                APP_ENV: 'testing',
                APP_URL: 'http://127.0.0.1:8000',
                APP_DEBUG: 'false',
                DB_CONNECTION: 'sqlite',
                DB_DATABASE: 'database/e2e.sqlite',
                SESSION_DRIVER: 'file',
                CACHE_STORE: 'array',
                QUEUE_CONNECTION: 'sync',
                MAIL_MAILER: 'array',
            },
            url: 'http://127.0.0.1:8000/login',
            reuseExistingServer: false,
            timeout: 120_000,
        },
    ],
});
