// Verification-branch-only configuration; canonical browser wiring belongs to U6.
import base from './playwright.config.mjs';
export default {
    ...base,
    testMatch: ['theme-login-errors.spec.mjs'],
    projects: [
        { name: 'chromium', use: { browserName: 'chromium', channel: 'chromium' } },
        { name: 'webkit', use: { browserName: 'webkit' } },
    ],
};
