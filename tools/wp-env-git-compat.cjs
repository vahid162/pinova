// Development-only: preserve wp-env's callable CommonJS import with patched Git.
// The factory and every unsafe-operation guard remain the upstream implementations.
const resolved = require.resolve('simple-git');
const upstream = require(resolved);
if (typeof upstream !== 'function' && typeof upstream.simpleGit === 'function') {
    require.cache[resolved].exports = Object.assign(upstream.simpleGit, upstream);
}
