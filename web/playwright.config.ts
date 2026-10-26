import { defineConfig, devices } from '@playwright/test';

const liveAudit = process.env.ZEFANYA_LIVE_AUDIT === '1';

export default defineConfig({
    testMatch: liveAudit ? '**/live-functional-audit.spec.ts' : '**/*.spec.ts',
    testIgnore: liveAudit ? [] : ['**/live-functional-audit.spec.ts'],
    testDir: './playwright',
    outputDir: './playwright/test-results',
    fullyParallel: !liveAudit,
    forbidOnly: !!process.env.CI,
    retries: liveAudit ? 0 : (process.env.CI ? 2 : 0),
    workers: liveAudit ? 1 : (process.env.CI ? 1 : undefined),
    reporter: 'list',
    use: {
        baseURL: liveAudit ? 'https://zefanya.shop' : 'http://localhost:8000',
        trace: 'on-first-retry',
        headless: true,
        viewport: { width: 1280, height: 720 },
        actionTimeout: 30000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: liveAudit ? undefined : {
        command: 'php artisan serve --env=testing',
        url: 'http://localhost:8000',
        reuseExistingServer: true,
        timeout: 120000,
    },
});
