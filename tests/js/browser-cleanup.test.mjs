import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, mkdir, writeFile, readFile, rename, rm, symlink, access } from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const script = fileURLToPath(new URL('../../tools/cleanup-browser-env.sh', import.meta.url));
let sequence = 0;
async function fixture(action) {
    const sandbox = await mkdtemp(path.join(os.tmpdir(), 'pinova-cleanup-test-'));
    const run = `${Date.now()}${process.pid}${++sequence}`;
    const project = `pinova-browser-${run}-1`;
    const home = `/tmp/${project}`;
    const config = path.join(home, 'a'.repeat(32), 'docker-compose.yml');
    const log = path.join(sandbox, 'calls.jsonl');
    const state = path.join(sandbox, 'down');
    await mkdir(path.dirname(config), { recursive: true });
    await writeFile(config, 'services: {}\n');
    await writeFile(path.join(sandbox, 'docker'), `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
fs.appendFileSync(process.env.FAKE_LOG, JSON.stringify(args)+'\\n');
if (args[0] === 'compose') {
    if (process.env.FAKE_FAIL_DOWN === '1') process.exit(9);
    fs.writeFileSync(process.env.FAKE_STATE, 'done');
} else {
    if (process.env.FAKE_FAIL_QUERY === '1') process.exit(8);
    if (process.env.FAKE_LEFTOVERS === '1') console.log('remaining-resource');
}
`, { mode: 0o700 });
    const env = {
        ...process.env, CI: 'true', GITHUB_ACTIONS: 'true', GITHUB_RUN_ID: run, GITHUB_RUN_ATTEMPT: '1',
        COMPOSE_PROJECT_NAME: project, WP_ENV_HOME: home, PATH: `${sandbox}:${process.env.PATH}`,
        FAKE_LOG: log, FAKE_STATE: state, FAKE_FAIL_DOWN: '', FAKE_FAIL_QUERY: '', FAKE_LEFTOVERS: '',
    };
    const execute = changes => spawnSync('bash', [script], { env: { ...env, ...changes }, encoding: 'utf8', timeout: 10_000 });
    const calls = async () => {
        try { return (await readFile(log, 'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse); }
        catch (error) { if (error.code === 'ENOENT') return []; throw error; }
    };
    try { await action({ home, config, project, sandbox, execute, calls }); }
    finally { await rm(home, { recursive: true, force: true }); await rm(sandbox, { recursive: true, force: true }); }
}

test('browser cleanup removes only the exact run project and verifies all resource kinds', async () => {
    await fixture(async ({ home, config, project, execute, calls }) => {
        const result = execute();
        assert.equal(result.status, 0, result.stderr);
        assert.match(result.stdout, /cleanup verified/);
        assert.deepEqual(await calls(), [
            ['compose', '--project-name', project, '--file', config, 'down', '--volumes', '--remove-orphans'],
            ['container', 'ls', '--all', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
            ['volume', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
            ['network', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
        ]);
        await assert.rejects(access(home));
    });
});

test('browser cleanup accepts the exact descriptive wp-env cache directory', async () => {
    await fixture(async ({ home, config, project, sandbox, execute, calls }) => {
        const workspace = path.join(sandbox, 'pinova');
        const configPath = path.join(workspace, '.wp-env.json');
        const shortHash = createHash('md5').update(configPath).digest('hex').slice(0, 8);
        const descriptiveConfig = path.join(home, `wp-env-pinova-${shortHash}`, 'docker-compose.yml');
        await mkdir(workspace);
        await writeFile(configPath, '{}\n');
        await rename(path.dirname(config), path.dirname(descriptiveConfig));

        const result = execute({ GITHUB_WORKSPACE: workspace });
        assert.equal(result.status, 0, result.stderr);
        assert.deepEqual(await calls(), [
            ['compose', '--project-name', project, '--file', descriptiveConfig, 'down', '--volumes', '--remove-orphans'],
            ['container', 'ls', '--all', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
            ['volume', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
            ['network', 'ls', '--quiet', '--filter', `label=com.docker.compose.project=${project}`],
        ]);
        await assert.rejects(access(home));
    });
});

test('browser cleanup rejects a descriptive directory not derived from this workspace', async () => {
    await fixture(async ({ home, config, sandbox, execute, calls }) => {
        const workspace = path.join(sandbox, 'pinova');
        const foreignConfig = path.join(home, 'wp-env-pinova-deadbeef', 'docker-compose.yml');
        await mkdir(workspace);
        await rename(path.dirname(config), path.dirname(foreignConfig));

        assert.notEqual(execute({ GITHUB_WORKSPACE: workspace }).status, 0);
        assert.deepEqual(await calls(), []);
        await access(foreignConfig);
    });
});

for (const [name, changes] of Object.entries({
    'non-CI process': { GITHUB_ACTIONS: 'false' },
    'missing run': { GITHUB_RUN_ID: '' },
    'foreign project': { COMPOSE_PROJECT_NAME: 'production' },
    'foreign directory': { WP_ENV_HOME: '/tmp' },
})) {
    test(`browser cleanup rejects ${name} before any Docker action`, async () => {
        await fixture(async ({ config, execute, calls }) => {
            assert.notEqual(execute(changes).status, 0);
            assert.deepEqual(await calls(), []);
            await access(config);
        });
    });
}

for (const flag of ['FAKE_FAIL_DOWN', 'FAKE_FAIL_QUERY', 'FAKE_LEFTOVERS']) {
    test(`browser cleanup does not mask ${flag}`, async () => {
        await fixture(async ({ config, execute }) => {
            assert.notEqual(execute({ [flag]: '1' }).status, 0);
            await access(config); // Failed cleanup retains evidence and cannot report success.
        });
    });
}

test('browser cleanup rejects symlinked or ambiguous configuration', async () => {
    await fixture(async ({ config, sandbox, execute, calls }) => {
        const outside = path.join(sandbox, 'outside.yml');
        await writeFile(outside, 'services: {}');
        await rm(config);
        await symlink(outside, config);
        assert.notEqual(execute().status, 0);
        assert.deepEqual(await calls(), []);
        await access(outside);
    });
    await fixture(async ({ home, execute, calls }) => {
        const other = path.join(home, 'b'.repeat(32));
        await mkdir(other);
        await writeFile(path.join(other, 'docker-compose.yml'), 'services: {}');
        assert.notEqual(execute().status, 0);
        assert.deepEqual(await calls(), []);
    });
});

test('missing configuration succeeds only when the run owns no Docker resources', async () => {
    await fixture(async ({ config, execute, calls }) => {
        await rm(config);
        assert.notEqual(execute({ FAKE_LEFTOVERS: '1' }).status, 0);
        assert.equal(execute().status, 0);
        assert.ok((await calls()).every(args => args[0] !== 'compose'));
    });
});
