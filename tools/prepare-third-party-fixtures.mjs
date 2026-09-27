import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';

if (process.env.PINOVA_TEST_PROFILE !== 'third-party') {
    throw new Error('Fixture preparation requires PINOVA_TEST_PROFILE=third-party.');
}

const baseline = JSON.parse(readFileSync(new URL('../tests/fixtures/third-party-baseline.json', import.meta.url), 'utf8'));
const build = path.resolve('.build');
const destination = path.join(build, 'third-party');
if (existsSync(destination)) {
    throw new Error('Third-party fixtures already exist; use a fresh disposable checkout.');
}
mkdirSync(build, { recursive: true });
const temporary = mkdtempSync(path.join(build, 'third-party-download-'));

try {
    // Verify every archive before extracting any plugin code.
    for (const plugin of baseline.plugins) {
        const archive = path.join(temporary, `${plugin.slug}.zip`);
        execFileSync('curl', [
            '--fail', '--silent', '--show-error', '--location', '--proto', '=https',
            '--proto-redir', '=https', '--connect-timeout', '20', '--max-time', '180',
            '--output', archive, plugin.url,
        ], { stdio: 'inherit', timeout: 190_000 });
        const digest = createHash('sha256').update(readFileSync(archive)).digest('hex');
        if (digest !== plugin.sha256) {
            throw new Error(`Checksum mismatch for ${plugin.slug}; no fixtures were extracted.`);
        }
    }
    for (const plugin of baseline.plugins) {
        const archive = path.join(temporary, `${plugin.slug}.zip`);
        execFileSync('unzip', ['-q', archive, '-d', temporary], { stdio: 'inherit', timeout: 60_000 });
        if (!existsSync(path.join(temporary, plugin.slug, plugin.main))) {
            throw new Error(`Missing entry point for ${plugin.slug}.`);
        }
        rmSync(archive);
    }
    writeFileSync(path.join(temporary, 'verified.json'), `${JSON.stringify(baseline, null, 2)}\n`);
    renameSync(temporary, destination);
    process.stdout.write('Pinned third-party archives verified and fixtures prepared.\n');
} finally {
    rmSync(temporary, { recursive: true, force: true });
}
