import { readdirSync, statSync } from 'node:fs';
import { extname, join, relative, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = resolve(import.meta.dirname, '..');
const roots = ['assets/js', 'tests/browser', 'tests/js', 'tools'];
const excluded = new Set([
	'assets/js/alpine.js',
]);

function collect(directory, files = []) {
	for (const entry of readdirSync(directory).sort()) {
		const path = join(directory, entry);
		const repositoryPath = relative(root, path).replaceAll('\\', '/');

		if (statSync(path).isDirectory()) {
			collect(path, files);
			continue;
		}

		if (!['.js', '.mjs'].includes(extname(path)) || entry.endsWith('.min.js') || excluded.has(repositoryPath)) {
			continue;
		}

		files.push(path);
	}

	return files;
}

const files = roots.flatMap((directory) => collect(join(root, directory)));
let failed = false;

for (const file of files) {
	const result = spawnSync(process.execPath, ['--check', file], { stdio: 'inherit' });
	if (result.status !== 0) {
		failed = true;
	}
}

if (failed) {
	process.exit(1);
}

console.log(`JavaScript syntax lint passed (${files.length} first-party files).`);
