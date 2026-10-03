import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const resolved = require.resolve('http-cache-semantics');
const installed = readFileSync(resolved);
const expected = readFileSync(new URL('./vendor/http-cache-semantics/index.js', import.meta.url));
assert.equal(createHash('sha256').update(installed).digest('hex'), createHash('sha256').update(expected).digest('hex'));
const CachePolicy = require('http-cache-semantics');
const request = { url: 'https://fixture.example.test/resource', method: 'GET', headers: { 'cache-control': 'max-stale=99999' } };
for (const headers of [
    { 'set-cookie': 'fixture-session=private', 'cache-control': 'max-age=1' },
    { 'cache-control': 'max-age=1, proxy-revalidate' },
    { 'cache-control': 'max-age=1, no-cache' },
]) {
    const policy = new CachePolicy({ ...request, headers: {} }, { status: 200, headers });
    policy.now = () => 2000;
    policy._responseTime = 0;
    assert.equal(policy.satisfiesWithoutRevalidation(request), false);
}
console.log('Installed cache dependency matches the private security patch and rejects restricted stale reuse.');
