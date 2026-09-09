import { readFileSync, writeFileSync } from 'node:fs';

const config = JSON.parse(readFileSync('.wp-env.json', 'utf8'));
const wordpress = process.env.WP_VERSION || '6.8';
const woocommerce = process.env.WC_VERSION || '10.9.4';

if (wordpress === 'latest') {
    delete config.core;
} else {
    config.core = `WordPress/WordPress#${wordpress}`;
}

const wooPackage = woocommerce === 'latest'
    ? 'https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip'
    : `https://downloads.wordpress.org/plugin/woocommerce.${woocommerce}.zip`;

config.plugins = ['.', wooPackage];

writeFileSync('.wp-env.json', `${JSON.stringify(config, null, 2)}\n`);
