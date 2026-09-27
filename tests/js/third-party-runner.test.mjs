import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const runner = fileURLToPath(new URL('../../tools/run-third-party-baseline.sh', import.meta.url));
const root = fileURLToPath(new URL('../../', import.meta.url));

function fixture(action) {
    const temporary = mkdtempSync(path.join(os.tmpdir(), 'pinova-third-party-runner-'));
    const calls = path.join(temporary, 'called');
    writeFileSync(path.join(temporary, 'npx'), [
        '#!/bin/bash',
        'touch "$FAKE_CALLS"',
        'if [[ "${FAKE_NO_MARKER:-}" != 1 ]]; then',
        '    printf "PINOVA_BASELINE_COMPLETE %s\\n" "${@: -1}"',
        'fi',
        'exit "${FAKE_EXIT:-0}"',
        '',
    ].join('\n'), { mode: 0o700 });
    const env = {
        ...process.env, CI: 'true', GITHUB_ACTIONS: 'true', GITHUB_RUN_ID: '123', GITHUB_RUN_ATTEMPT: '1',
        COMPOSE_PROJECT_NAME: 'pinova-browser-123-1', WP_ENV_HOME: '/tmp/pinova-browser-123-1',
        PINOVA_TEST_PROFILE: 'third-party', PATH: `${temporary}:${process.env.PATH}`, FAKE_CALLS: calls,
        FAKE_NO_MARKER: '', FAKE_EXIT: '',
    };
    const execute = changes => spawnSync('bash', [runner], {
        cwd: root, env: { ...env, ...changes }, encoding: 'utf8', timeout: 10_000,
    });
    try { action({ execute, called: () => existsSync(calls) }); }
    finally { rmSync(temporary, { recursive: true, force: true }); }
}

test('third-party runner refuses foreign environments before invoking wp-env', () => {
    for (const changes of [
        { CI: 'false' }, { GITHUB_ACTIONS: 'false' }, { GITHUB_RUN_ID: '' },
        { GITHUB_RUN_ATTEMPT: 'other' }, { COMPOSE_PROJECT_NAME: 'another-task' },
        { WP_ENV_HOME: '/tmp/another-task' }, { PINOVA_TEST_PROFILE: '' },
    ]) {
        fixture(({ execute, called }) => {
            assert.notEqual(execute(changes).status, 0);
            assert.equal(called(), false);
        });
    }
});

test('third-party runner requires process success and explicit scenario completion', () => {
    fixture(({ execute }) => {
        const result = execute();
        assert.equal(result.status, 0, result.stderr);
        assert.match(result.stdout, /PINOVA_BASELINE_COMPLETE normal/);
        assert.match(result.stdout, /PINOVA_BASELINE_COMPLETE manual-approval/);
        assert.notEqual(execute({ FAKE_NO_MARKER: '1' }).status, 0);
        assert.notEqual(execute({ FAKE_EXIT: '7' }).status, 0);
    });
});
