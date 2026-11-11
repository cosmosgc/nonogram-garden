import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    reporter: 'list',
    use: {
        baseURL: 'http://127.0.0.1:5173',
        browserName: 'chromium',
        headless: true,
    },
    webServer: {
        command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort',
        url: 'http://127.0.0.1:5173/tests/browser/player.html',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
