import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';

const browserDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(browserDirectory, '../..');

export default defineConfig({
    testDir: browserDirectory,
    testMatch: 'account-ui.spec.mjs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: Boolean(process.env.CI),
    timeout: 45_000,
    expect: {
        timeout: 7_500,
    },
    outputDir: path.join(repositoryRoot, 'test-results/browser'),
    reporter: [
        ['line'],
        ['html', {
            open: 'never',
            outputFolder: path.join(repositoryRoot, 'playwright-report/browser'),
        }],
    ],
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8890',
        actionTimeout: 10_000,
        navigationTimeout: 20_000,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
