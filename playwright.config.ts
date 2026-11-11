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
        { name: 'chromium', use: { browserName: 'chromium' } },
        { name: 'firefox', use: { browserName: 'firefox' } },
        { name: 'webkit', use: { browserName: 'webkit' } },
    ],
    webServer: {
        command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort',
        env: { LARAVEL_BYPASS_ENV_CHECK: '1' },
        url: 'http://127.0.0.1:5173/tests/browser/player.html',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
