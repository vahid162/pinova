import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const shared = (await readFile(new URL('../../assets/js/pages/auth-state.js', import.meta.url), 'utf8'))
    .replace('export function createAuthState', 'function createAuthState');
const scripts = Object.fromEntries(await Promise.all(['form', 'modal'].map(async name => [name,
    (await readFile(new URL(`../../assets/js/pages/login-${name}.js`, import.meta.url), 'utf8'))
        .replace(/^import [^\n]+\n/gm, '').replace('export function pinovaOpenModal', 'function pinovaOpenModal'),
])));

function harness(kind, respond = () => ({ success: true, data: { login_method: 'otp', jwt: 'new-flow', ttl: 180 } })) {
    let factory;
    const listeners = {};
    const credentials = [];
    const submissions = [];
    const calls = [];
    const focus = { focus() {}, querySelector() { return this; } };
    const document = { body: { style: {}, classList: { add() {} } }, getElementById: () => focus, querySelector: () => null };
    const history = { state: null, pushState(state) { this.state = state; }, replaceState(state) { this.state = state; }, back() {} };
    const window = { addEventListener(name, callback) { listeners[name] = callback; }, history,
        location: { origin: 'https://example.test', href: 'https://example.test/login/' }, parent: { document }, OTPCredential: function () {} };
    const context = vm.createContext({
        AbortController, URL, document, history, window, location: window.location,
        navigator: { credentials: { get(options) { return new Promise(resolve => credentials.push({ options, resolve })); } } },
        pinova: { logo: '', code_length: 4 }, pinovaGetQueryParam: () => null,
        pinovaCleanNumericInput: value => String(value ?? '').replace(/[۰-۹٠-٩]/g, digit => String(digit.charCodeAt(0) % 16)).replace(/[^0-9]/g, ''),
        pinovaValidateField: field => field, pinovaNotyf: { error() {}, success() {} },
        pinovaApiRequest: async (...args) => { calls.push(args); return respond(...args); },
        pinovaAlpine: { prefix() {}, start() {}, data(name, callback) { factory = callback; } },
        console: { log() {}, error() {} }, setTimeout(callback) { callback(); return 1; }, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
    });
    vm.runInContext(shared + scripts[kind], context);
    const state = factory();
    state.$nextTick = callback => callback();
    state.modalIsOpen = kind === 'modal' ? true : undefined;
    state.submit = () => submissions.push({ step: state.stepName, jwt: state.forms[state.stepName].inputs.jwt?.value });
    state.forms.authenticate.inputs.identifier.value = 'buyer@example.test';
    state.init();
    return { state, listeners, credentials, submissions, calls };
}
const settled = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); };

for (const kind of ['form', 'modal']) {
    test(`${kind}: WebOTP callback from an earlier accepted flow cannot fill or submit its replacement`, async () => {
        let flow = 0;
        const h = harness(kind, () => ({ success: true, data: { login_method: 'otp', jwt: `flow-${++flow}`, ttl: 180 } }));
        await h.state.authenticate();
        // Characterize the old DOM-loaded subscription too; the new implementation subscribes at acceptance.
        h.listeners.DOMContentLoaded?.();
        assert.equal(h.credentials.length, 1);
        const earlier = h.credentials[0];
        await h.state.authenticate({ force_otp: '1' });
        assert.equal(h.state.forms.loginByOtp.inputs.jwt.value, 'flow-2');
        earlier.resolve({ code: '۱۲٣٤' });
        await settled();
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '', 'stale credentials must not enter the replacement JWT');
        assert.equal(h.submissions.length, 0);
    });
}
