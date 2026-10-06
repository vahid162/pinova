import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
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
const lock = JSON.parse(readFileSync(new URL('../package-lock.json', import.meta.url), 'utf8'));
assert.equal(Object.keys(lock.packages).some(name => /(?:^|\/)node_modules\/sprintf-js$/.test(name)), false);
console.log('Development dependencies reject unsafe VISUAL editors and preserve wp-env YAML configuration without sprintf-js.');
