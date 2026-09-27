import { defineConfig } from '@playwright/test';
import accountConfig from './playwright.config.mjs';

export default defineConfig({
    ...accountConfig,
    testMatch: 'integration-auth.spec.mjs',
});
