import { basename } from 'node:path';
import { spawnSync } from 'node:child_process';

const pluginDirectory = basename(process.cwd());
const hpos = process.env.PINOVA_TEST_HPOS === 'yes' ? 'yes' : 'no';
const result = spawnSync(
    'wp-env',
    [
        'run',
        'tests-cli',
        `--env-cwd=wp-content/plugins/${pluginDirectory}`,
		'env',
		`PINOVA_TEST_HPOS=${hpos}`,
		'WC_INTEGRATION_PLUGIN_FILE=/var/www/html/wp-content/plugins/woocommerce/woocommerce.php',
		'vendor/bin/phpunit',
        '-c',
        'phpunit.integration.xml.dist',
    ],
    { stdio: 'inherit' },
);

if (result.error) {
    throw result.error;
}

process.exit(result.status ?? 1);
