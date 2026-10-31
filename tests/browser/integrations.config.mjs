import { defineConfig } from '@playwright/test';
import accountConfig from './playwright.config.mjs';

export default defineConfig({
    ...accountConfig,
    testMatch: 'integration-auth.spec.mjs',
    projects: accountConfig.projects.filter(project => project.name === 'chromium'),
});
