import { defineConfig, devices } from '@playwright/test';
import { existsSync } from 'node:fs';

const defaultBaseURL = existsSync('/.dockerenv')
    ? 'http://host.docker.internal:8000'
    : 'http://127.0.0.1:8000';
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? defaultBaseURL;

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30 * 1000,
    expect: {
        timeout: 5000,
    },
    fullyParallel: true,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [['html', { open: 'never' }], ['list']],
    use: {
        baseURL,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
