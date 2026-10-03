import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const CachePolicy = require('../../tools/vendor/http-cache-semantics/index.js');

function policy(headers, shared = true) {
    const value = new CachePolicy(
        { url: 'https://fixture.example.test/resource', method: 'GET', headers: {} },
        { status: 200, headers },
        { shared },
    );
    let clock = 0;
    value.now = () => clock;
    value._responseTime = 0;
    clock = 2000;
    return value;
}

const request = {
    url: 'https://fixture.example.test/resource',
    method: 'GET',
    headers: { 'cache-control': 'max-stale=99999' },
};

test('max-stale cannot reuse security-restricted shared responses', () => {
    for (const headers of [
        { 'set-cookie': 'fixture-session=private', 'cache-control': 'max-age=1' },
        { 'cache-control': 'max-age=1, proxy-revalidate' },
        { 'cache-control': 'max-age=1, no-cache' },
        { 'cache-control': 'max-age=1, no-store' },
        { 'cache-control': 'max-age=1, private' },
        { 'cache-control': 'max-age=1, must-revalidate' },
    ]) {
        assert.equal(policy(headers).satisfiesWithoutRevalidation(request), false);
    }
});

test('ordinary stale reuse and explicit cookie-sharing opt-ins still work', () => {
    for (const headers of [
        { 'cache-control': 'max-age=1' },
        { 'set-cookie': 'fixture-session=public', 'cache-control': 'public, max-age=1' },
        { 'set-cookie': 'fixture-session=public', 'cache-control': 'immutable, max-age=1' },
    ]) {
        assert.equal(policy(headers).satisfiesWithoutRevalidation(request), true);
    }
    assert.equal(policy({ 'set-cookie': 'fixture-session=private', 'cache-control': 'max-age=1' }, false).satisfiesWithoutRevalidation(request), true);
});

test('the private patch remains a pinned override excluded from runtime dependencies', () => {
    const packageJson = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url)));
    assert.equal(packageJson.overrides['http-cache-semantics'], '$http-cache-semantics');
    assert.equal(packageJson.devDependencies['http-cache-semantics'], 'file:tools/vendor/http-cache-semantics');
    assert.equal(packageJson.dependencies, undefined);
    const metadata = JSON.parse(readFileSync(new URL('../../tools/vendor/http-cache-semantics/package.json', import.meta.url)));
    assert.equal(metadata.version, '4.2.1-pinova.1');
    assert.equal(metadata.scripts, undefined);
    const bytes = readFileSync(new URL('../../tools/vendor/http-cache-semantics/index.js', import.meta.url));
    const digest = createHash('sha256').update(bytes).digest('hex');
    const provenance = readFileSync(new URL('../../tools/vendor/http-cache-semantics/README.md', import.meta.url), 'utf8');
    assert.ok(provenance.includes(digest));
});
