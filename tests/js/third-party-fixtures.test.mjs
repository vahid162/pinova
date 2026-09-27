import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const configure = fileURLToPath(new URL('../../tools/configure-wp-env.mjs', import.meta.url));
const prepare = fileURLToPath(new URL('../../tools/prepare-third-party-fixtures.mjs', import.meta.url));
const baseline = JSON.parse(readFileSync(new URL('../fixtures/third-party-baseline.json', import.meta.url), 'utf8'));

function fixture(action) {
    const directory = mkdtempSync(path.join(os.tmpdir(), 'pinova-third-party-test-'));
    const configPath = path.join(directory, '.wp-env.json');
    writeFileSync(configPath, JSON.stringify({ config: { WP_DEBUG: true }, plugins: ['.'] }));
    const execute = (script, overrides = {}) => spawnSync(process.execPath, [script], {
        cwd: directory,
        env: { ...process.env, PINOVA_TEST_PROFILE: '', WP_VERSION: '', WC_VERSION: '', ...overrides },
        encoding: 'utf8', timeout: 10_000,
    });
    const installFixtures = () => {
        for (const plugin of baseline.plugins) {
            const target = path.join(directory, '.build', 'third-party', plugin.slug);
            mkdirSync(target, { recursive: true });
            writeFileSync(path.join(target, plugin.main), '<?php // Synthetic fixture; never loaded.');
        }
        writeFileSync(path.join(directory, '.build', 'third-party', 'verified.json'), JSON.stringify(baseline));
    };
    try { action({ directory, configPath, execute, installFixtures, config: () => JSON.parse(readFileSync(configPath, 'utf8')) }); }
    finally { rmSync(directory, { recursive: true, force: true }); }
}

test('default configuration preserves the existing matrix and latest semantics', () => {
    fixture(({ execute, config }) => {
        assert.equal(execute(configure).status, 0);
        assert.equal(config().core, 'WordPress/WordPress#6.8');
        assert.deepEqual(config().plugins, ['.', 'https://downloads.wordpress.org/plugin/woocommerce.10.9.4.zip']);
        assert.deepEqual(config().config, { WP_DEBUG: true });
        assert.equal(execute(configure, { WP_VERSION: 'latest', WC_VERSION: 'latest' }).status, 0);
        assert.equal(config().core, undefined);
        assert.deepEqual(config().plugins, ['.', 'https://downloads.wordpress.org/plugin/woocommerce.zip']);
    });
});

test('unknown profile fails without rewriting environment configuration', () => {
    fixture(({ execute, configPath }) => {
        const before = readFileSync(configPath, 'utf8');
        assert.notEqual(execute(configure, { PINOVA_TEST_PROFILE: 'thirdparty' }).status, 0);
        assert.equal(readFileSync(configPath, 'utf8'), before);
    });
});

test('default configuration preserves constants not owned by the third-party profile', () => {
    fixture(({ execute, configPath, config }) => {
        const original = { WP_DEBUG: true, DISABLE_WP_CRON: true, WP_ENVIRONMENT_TYPE: 'development' };
        writeFileSync(configPath, JSON.stringify({ config: original }));
        assert.equal(execute(configure).status, 0);
        assert.deepEqual(config().config, original);
    });
});

test('third-party profile requires exact baseline and prepared fixtures', () => {
    fixture(({ execute, installFixtures, config, directory }) => {
        const env = { PINOVA_TEST_PROFILE: 'third-party' };
        assert.notEqual(execute(configure, env).status, 0);
        installFixtures();
        for (const override of [{ WP_VERSION: 'latest' }, { WC_VERSION: 'latest' }]) {
            assert.notEqual(execute(configure, { ...env, ...override }).status, 0);
        }
        assert.equal(execute(configure, env).status, 0);
        assert.equal(config().core, 'WordPress/WordPress#7.1.2');
        assert.deepEqual(config().plugins, [
            'https://downloads.wordpress.org/plugin/woocommerce.11.1.2.zip',
            './.build/third-party/wpforo', './.build/third-party/dokan-lite', '.',
        ]);
        assert.deepEqual(config().config, {
            WP_DEBUG: true, PINOVA_THIRD_PARTY_BASELINE: true, DISABLE_WP_CRON: true, WP_ENVIRONMENT_TYPE: 'local',
        });
        rmSync(path.join(directory, '.build/third-party/dokan-lite/dokan.php'));
        assert.notEqual(execute(configure, env).status, 0);
    });
});

test('switching to default removes third-party fixtures and isolation markers from config', () => {
    fixture(({ execute, installFixtures, config }) => {
        installFixtures();
        assert.equal(execute(configure, { PINOVA_TEST_PROFILE: 'third-party' }).status, 0);
        assert.equal(execute(configure).status, 0);
        assert.deepEqual(config().config, { WP_DEBUG: true });
        assert.deepEqual(config().plugins, ['.', 'https://downloads.wordpress.org/plugin/woocommerce.10.9.4.zip']);
    });
});

test('a mismatched verification receipt cannot configure the third-party profile', () => {
    fixture(({ execute, installFixtures, directory }) => {
        installFixtures();
        const changed = structuredClone(baseline);
        changed.plugins[0].sha256 = '0'.repeat(64);
        writeFileSync(path.join(directory, '.build/third-party/verified.json'), JSON.stringify(changed));
        assert.notEqual(execute(configure, { PINOVA_TEST_PROFILE: 'third-party' }).status, 0);
    });
});

test('fixture checksum failure occurs before extraction and leaves no reusable receipt', () => {
    fixture(({ directory, execute }) => {
        const bin = path.join(directory, 'bin');
        mkdirSync(bin);
        // Fake transport and extractor: this test performs no network or ZIP extraction.
        writeFileSync(path.join(bin, 'curl'), '#!/bin/sh\nwhile [ "$1" != "--output" ]; do shift; done\nprintf corrupted > "$2"\n', { mode: 0o700 });
        writeFileSync(path.join(bin, 'unzip'), '#!/bin/sh\ntouch extraction-was-attempted\n', { mode: 0o700 });
        const result = execute(prepare, { PINOVA_TEST_PROFILE: 'third-party', PATH: `${bin}:${process.env.PATH}` });
        assert.notEqual(result.status, 0);
        assert.match(result.stderr, /Checksum mismatch for wpforo/);
        assert.equal(existsSync(path.join(directory, 'extraction-was-attempted')), false);
        assert.deepEqual(readdirSync(path.join(directory, '.build')), []);
    });
});

test('preparation refuses non-profile runs and preserves an existing fixture directory', () => {
    fixture(({ directory, execute, installFixtures }) => {
        assert.notEqual(execute(prepare).status, 0);
        assert.equal(existsSync(path.join(directory, '.build')), false);
        installFixtures();
        const receipt = path.join(directory, '.build/third-party/verified.json');
        const before = readFileSync(receipt, 'utf8');
        assert.notEqual(execute(prepare, { PINOVA_TEST_PROFILE: 'third-party' }).status, 0);
        assert.equal(readFileSync(receipt, 'utf8'), before);
    });
});

test('preparation verifies every archive before extraction and cleans later failures', () => {
    for (const mode of ['corrupt-second', 'extract-fails', 'missing-entrypoint', 'success']) {
        fixture(({ directory, execute }) => {
            // Use the unchanged preparer with a synthetic manifest and transports.
            // No network or real plugin code is needed to exercise failure ordering.
            const helperRoot = path.join(directory, 'synthetic');
            mkdirSync(path.join(helperRoot, 'tools'), { recursive: true });
            mkdirSync(path.join(helperRoot, 'tests/fixtures'), { recursive: true });
            const helper = path.join(helperRoot, 'tools/prepare-third-party-fixtures.mjs');
            writeFileSync(helper, readFileSync(prepare));
            const synthetic = structuredClone(baseline);
            const digest = createHash('sha256').update('verified fixture').digest('hex');
            for (const plugin of synthetic.plugins) plugin.sha256 = digest;
            writeFileSync(path.join(helperRoot, 'tests/fixtures/third-party-baseline.json'), JSON.stringify(synthetic));
            const bin = path.join(directory, 'bin');
            mkdirSync(bin);
            writeFileSync(path.join(bin, 'curl'), [
                '#!/bin/sh',
                'while [ "$1" != "--output" ]; do shift; done',
                'case "$FAKE_MODE:$2" in',
                '    corrupt-second:*/dokan-lite.zip) printf corrupted > "$2" ;;',
                '    *) printf "verified fixture" > "$2" ;;',
                'esac',
                '',
            ].join('\n'), { mode: 0o700 });
            const extracted = path.join(directory, 'extraction-attempted');
            writeFileSync(path.join(bin, 'unzip'), [
                '#!/bin/sh',
                'touch "$FAKE_EXTRACTED"',
                '[ "$FAKE_MODE" != extract-fails ] || exit 5',
                'slug=$(basename "$2" .zip)',
                'mkdir -p "$4/$slug"',
                '[ "$FAKE_MODE:$slug" != missing-entrypoint:dokan-lite ] || exit 0',
                'case "$slug" in wpforo) main=wpforo.php ;; dokan-lite) main=dokan.php ;; esac',
                'printf synthetic > "$4/$slug/$main"',
                '',
            ].join('\n'), { mode: 0o700 });
            const result = execute(helper, {
                PINOVA_TEST_PROFILE: 'third-party', PATH: `${bin}:${process.env.PATH}`,
                FAKE_MODE: mode, FAKE_EXTRACTED: extracted,
            });
            if (mode === 'success') {
                assert.equal(result.status, 0, result.stderr);
                assert.deepEqual(JSON.parse(readFileSync(path.join(directory, '.build/third-party/verified.json'), 'utf8')), synthetic);
                assert.deepEqual(readdirSync(path.join(directory, '.build')), ['third-party']);
            } else {
                assert.notEqual(result.status, 0, mode);
                assert.deepEqual(readdirSync(path.join(directory, '.build')), [], mode);
                if (mode === 'corrupt-second') {
                    assert.match(result.stderr, /Checksum mismatch for dokan-lite/);
                    assert.equal(existsSync(extracted), false);
                }
                if (mode === 'missing-entrypoint') assert.match(result.stderr, /Missing entry point for dokan-lite/);
            }
        });
    }
});
