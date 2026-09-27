import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const profile = process.env.PINOVA_TEST_PROFILE || 'default';
if (!['default', 'third-party'].includes(profile)) {
    throw new Error(`Unknown PINOVA_TEST_PROFILE: ${profile}`);
}

const config = JSON.parse(readFileSync('.wp-env.json', 'utf8'));
const baseline = profile === 'third-party'
    ? JSON.parse(readFileSync(new URL('../tests/fixtures/third-party-baseline.json', import.meta.url), 'utf8'))
    : null;
const wordpress = process.env.WP_VERSION || baseline?.wordpress || '6.8';
const woocommerce = process.env.WC_VERSION || baseline?.woocommerce || '10.9.4';

if (baseline) {
    if (wordpress !== baseline.wordpress || woocommerce !== baseline.woocommerce) {
        throw new Error('The third-party characterization profile requires its exact baseline WP/WC versions.');
    }
    const receipt = '.build/third-party/verified.json';
    if (!existsSync(receipt) || JSON.stringify(JSON.parse(readFileSync(receipt, 'utf8'))) !== JSON.stringify(baseline)) {
        throw new Error('Prepare checksum-verified third-party fixtures before configuring this profile.');
    }
    for (const plugin of baseline.plugins) {
        if (!existsSync(`.build/third-party/${plugin.slug}/${plugin.main}`)) {
            throw new Error(`Missing required third-party fixture: ${plugin.slug}`);
        }
    }
}

if (wordpress === 'latest') {
    delete config.core;
} else {
    config.core = `WordPress/WordPress#${wordpress}`;
}

const wooPackage = woocommerce === 'latest'
    ? 'https://downloads.wordpress.org/plugin/woocommerce.zip'
    : `https://downloads.wordpress.org/plugin/woocommerce.${woocommerce}.zip`;

config.plugins = baseline
    ? [wooPackage, ...baseline.plugins.map(plugin => `./.build/third-party/${plugin.slug}`), '.']
    : ['.', wooPackage];

config.config ??= {};
if (baseline) {
    config.config.PINOVA_THIRD_PARTY_BASELINE = true;
    config.config.DISABLE_WP_CRON = true;
    config.config.WP_ENVIRONMENT_TYPE = 'local';
} else if (config.config.PINOVA_THIRD_PARTY_BASELINE === true) {
    delete config.config.PINOVA_THIRD_PARTY_BASELINE;
    delete config.config.DISABLE_WP_CRON;
    delete config.config.WP_ENVIRONMENT_TYPE;
}

writeFileSync('.wp-env.json', `${JSON.stringify(config, null, 2)}\n`);
