import assert from 'node:assert/strict';
import * as fs from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { setTimeout } from 'node:timers/promises';
import './wp-env-git-compat.cjs';
import { vulnerabilityCheck } from '@simple-git/argv-parser';
import yaml from 'js-yaml';

const require = createRequire(import.meta.url);
assert.equal(typeof require('simple-git'), 'function');

// wp-env needs YAML dump and ordinary Git operations, never unsafe editors.
for (const key of ['VISUAL', 'visual', 'Visual']) {
    assert.ok(vulnerabilityCheck(['commit'], { [key]: '/untrusted-editor' })
        .some(finding => finding.category === 'allowUnsafeEditor'));
}
assert.deepEqual(vulnerabilityCheck(['status'], {}), []);
const config = { services: { wordpress: { image: 'wordpress:latest', ports: ['8888:80'] } } };
assert.deepEqual(yaml.load(yaml.dump(config)), config);
const lock = JSON.parse(fs.readFileSync(new URL('../package-lock.json', import.meta.url), 'utf8'));
assert.equal(Object.keys(lock.packages).some(name => /(?:^|\/)node_modules\/sprintf-js$/.test(name)), false);

// Resolve through wp-env rather than accidentally testing a different hoisted API.
const wpRequire = createRequire(require.resolve('@wordpress/env/package.json'));
const rimrafRequire = createRequire(wpRequire.resolve('rimraf'));
const globRequire = createRequire(rimrafRequire.resolve('glob'));
assert.equal(wpRequire('@wordpress/env/package.json').version, '12.0.0');
assert.equal(wpRequire('rimraf/package.json').version, '5.0.10');
assert.equal(rimrafRequire('glob/package.json').version, '14.0.2');
assert.equal(globRequire('minimatch/package.json').version, '11.0.1');
const { rimraf, rimrafSync } = wpRequire('rimraf');
const { glob, globSync } = rimrafRequire('glob');
const { Minimatch } = globRequire('minimatch');
for (const api of [rimraf, rimrafSync, glob, globSync, Minimatch]) assert.equal(typeof api, 'function');

const fixture = fs.mkdtempSync(path.join(tmpdir(), 'pinova-dev-dependencies-'));
try {
    const preserved = path.join(fixture, 'preserved');
    fs.mkdirSync(preserved);
    fs.writeFileSync(path.join(preserved, 'sentinel'), 'owned test data');
    for (const synchronous of [false, true]) {
        const target = path.join(fixture, synchronous ? 'sync-delete' : 'async-delete');
        fs.mkdirSync(target);
        fs.writeFileSync(path.join(target, 'file'), 'owned test data');
        fs.symlinkSync(preserved, path.join(target, 'link'), 'dir');
        assert.equal(synchronous ? rimrafSync(target) : await rimraf(target), true);
        assert.equal(fs.existsSync(target), false);
        assert.equal(fs.readFileSync(path.join(preserved, 'sentinel'), 'utf8'), 'owned test data');
    }

    const patterns = path.join(fixture, 'patterns');
    fs.mkdirSync(path.join(patterns, 'nested'), { recursive: true });
    for (const name of ['a.tmp', 'nested/b.tmp', 'keep.txt']) {
        fs.writeFileSync(path.join(patterns, name), 'owned test data');
    }
    const options = { cwd: patterns, absolute: true, withFileTypes: false };
    const expected = ['a.tmp', 'nested/b.tmp'].map(name => path.join(patterns, name)).sort();
    assert.deepEqual((await glob('**/*.tmp', options)).sort(), expected);
    assert.deepEqual(globSync('**/*.tmp', options).sort(), expected);
    assert.equal(await rimraf('a.tmp', { glob: options }), true);
    assert.equal(fs.existsSync(path.join(patterns, 'a.tmp')), false);
    assert.equal(fs.existsSync(path.join(patterns, 'nested/b.tmp')), true);
    assert.equal(rimrafSync('**/*.tmp', { glob: options }), true);
    assert.equal(fs.existsSync(path.join(patterns, 'nested/b.tmp')), false);
    assert.equal(fs.existsSync(path.join(patterns, 'keep.txt')), true);

    // Small upstream cancellation regression: no background traversal after rejection.
    const abortTree = path.join(fixture, 'abort');
    const populate = (directory, depth) => {
        fs.mkdirSync(directory);
        fs.writeFileSync(path.join(directory, 'file'), 'owned test data');
        if (depth > 0) for (const name of ['a', 'b']) populate(path.join(directory, name), depth - 1);
    };
    populate(abortTree, 3);
    const controller = new AbortController();
    const reason = new Error('Bounded glob cancellation check.');
    let reads = 0;
    const countedFS = {
        ...fs,
        readdir(...args) {
            if (++reads === 1) controller.abort(reason);
            return fs.readdir(...args);
        },
        readdirSync(...args) {
            if (++reads === 1) controller.abort(reason);
            return fs.readdirSync(...args);
        },
    };
    await assert.rejects(glob('**', { cwd: abortTree, fs: countedFS, signal: controller.signal }), error => error === reason);
    const readsAtRejection = reads;
    assert.equal(readsAtRejection, 1);
    await setTimeout(100);
    assert.equal(reads, readsAtRejection);

    // Explicitly small preprocessing cap; never run the upstream 100,000-pattern case.
    const capped = new Minimatch(Array(8).fill('**/../a/b').join('/'), { optimizationLevel: 2, braceExpandMax: 16 });
    assert.equal(capped.set.length, 16);
    const ordinary = new Minimatch('**/../a/b/**/../c/d', { optimizationLevel: 2, braceExpandMax: 8 });
    assert.equal(ordinary.set.length, 4);
    assert.equal(ordinary.match('../a/b/../c/d'), true);
    assert.equal(ordinary.match('x/a/b/y/c/d'), true);
} finally {
    fs.rmSync(fixture, { recursive: true, force: true });
}
console.log('Development dependency checks passed: Git/YAML safety, scoped cleanup APIs, cancellation and bounded pattern expansion.');
