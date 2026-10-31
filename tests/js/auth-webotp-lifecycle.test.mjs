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
    state.$root = {isConnected: true, querySelector: () => focus};
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

for (const kind of ['form', 'modal']) {
    test(`${kind}: accepted current WebOTP normalizes and submits exactly once`, async () => {
        const h = harness(kind);
        assert.equal(h.credentials.length, 0);
        await h.state.authenticate();
        assert.equal(h.credentials.length, 1);
        assert.equal(h.credentials[0].options.signal.aborted, false);
        h.credentials[0].resolve({ code: '۱۲٣٤' });
        await settled();
        h.state.handleOtpInput('loginByOtp');
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '1234');
        assert.equal(h.submissions.length, 1);
        assert.equal(h.submissions[0].jwt, 'new-flow');
    });

    test(`${kind}: edit, history and pagehide invalidate a pending credential even when abort races resolution`, async () => {
        for (const action of ['edit', 'history', 'pagehide']) {
            const h = harness(kind);
            await h.state.authenticate();
            const pending = h.credentials[0];
            if (action === 'edit') h.state.editIdentifier();
            if (action === 'history') {
                if (kind === 'form') h.listeners.popstate({ state: { pinovaStep: 'authenticate' } });
                else h.state.backStep();
            }
            if (action === 'pagehide') h.listeners.pagehide();
            assert.equal(pending.options.signal.aborted, true);
            pending.resolve({ code: '1234' });
            await settled();
            assert.equal(h.state.forms.loginByOtp.inputs.code.value, '');
            assert.equal(h.submissions.length, 0);
        }
    });

    test(`${kind}: initiation rejection never starts a credential wait`, async () => {
        const h = harness(kind, () => ({ success: false, message: 'Try later', data: {} }));
        await h.state.authenticate();
        assert.equal(h.credentials.length, 0);
        assert.equal(h.state.status.message, 'Try later');
    });

    test(`${kind}: same JWT resend still invalidates the earlier browser wait`, async () => {
        const h = harness(kind);
        await h.state.authenticate();
        const earlier = h.credentials[0];
        await h.state.authenticate({ force_otp: '1' });
        assert.equal(h.credentials.length, 2);
        assert.equal(earlier.options.signal.aborted, true);
        earlier.resolve({ code: '1111' });
        await settled();
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '');
        h.credentials[1].resolve({ code: '2222' });
        await settled();
        assert.equal(h.submissions.length, 1);
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '2222');
    });

    test(`${kind}: autofill while busy is submitted once after the current request ends`, async () => {
        const h = harness(kind);
        h.state.stepName = 'loginByOtp';
        h.state.forms.loginByOtp.inputs.jwt.value = 'new-flow';
        const request = h.state.beginRequest();
        h.state.startWebOtp('loginByOtp');
        h.credentials[0].resolve({ code: '1234' });
        await settled();
        assert.equal(h.state.pageLoaderIsActive, true);
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '');
        assert.equal(h.submissions.length, 0);
        h.state.endRequest(request);
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '1234');
        assert.equal(h.submissions.length, 1);
        h.state.resumeWebOtp();
        assert.equal(h.submissions.length, 1);
    });

    test(`${kind}: a detached component cannot receive credentials`, async () => {
        const h = harness(kind);
        await h.state.authenticate();
        h.state.authRoot.isConnected = false;
        h.credentials[0].resolve({ code: '1234' });
        await settled();
        assert.equal(h.submissions.length, 0);
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '');
    });

    test(`${kind}: leaving during REST initiation makes its late success inert`, async () => {
        let resolve;
        const h = harness(kind, () => new Promise(done => { resolve = done; }));
        const pending = h.state.authenticate();
        assert.equal(h.calls[0][1].signal.aborted, false);
        if (kind === 'form') h.listeners.popstate({ state: { pinovaStep: 'authenticate' } });
        else h.state.closeModal();
        assert.equal(h.calls[0][1].signal.aborted, true);
        resolve({ success: true, data: { login_method: 'otp', jwt: 'obsolete-flow', ttl: 180 } });
        await pending;
        assert.equal(h.state.stepName, 'authenticate');
        assert.equal(h.state.forms.loginByOtp.inputs.jwt.value, '');
        assert.equal(h.credentials.length, 0);
    });
}

test('modal close/reopen leaves the earlier credential unable to enter a new accepted flow', async () => {
    const h = harness('modal');
    await h.state.authenticate();
    const earlier = h.credentials[0];
    h.state.closeModal();
    h.state.openModal();
    await h.state.authenticate();
    earlier.resolve({ code: '1111' });
    await settled();
    assert.equal(h.state.modalIsOpen, true);
    assert.equal(h.state.forms.loginByOtp.inputs.code.value, '');
    assert.equal(h.submissions.length, 0);
    h.credentials[1].resolve({ code: '2222' });
    await settled();
    assert.equal(h.submissions.length, 1);
});


for (const kind of ['form', 'modal']) {
    test(`${kind}: invoking a detached old button does not replace the captured component root`, async () => {
        const h = harness(kind);
        const captured = h.state.authRoot;
        h.state.$el = {isConnected: false};
        await h.state.authenticate();
        assert.equal(h.state.authRoot, captured);
        assert.equal(h.credentials.length, 1);
        h.credentials[0].resolve({code: '1234'});
        await settled();
        assert.equal(h.submissions.length, 1);
    });

    test(`${kind}: a rejected manual code preserves one wait for a different current SMS`, async () => {
        const h = harness(kind, route => route.endsWith('/authenticate')
            ? {success: true, data: {login_method: 'otp', jwt: 'same-flow', ttl: 180}}
            : {success: false, message: 'Wrong code', data: {}, http: {status: 401}});
        await h.state.authenticate();
        h.state.forms.loginByOtp.inputs.code.value = '1111';
        await h.state.loginByOtp();
        assert.equal(h.credentials.length, 1, 'failed verification must not start another subscription');
        assert.equal(h.credentials[0].options.signal.aborted, false);
        h.credentials[0].resolve({code: '2222'});
        await settled();
        assert.equal(h.submissions.length, 1);
        assert.equal(h.state.forms.loginByOtp.inputs.code.value, '2222');
    });

    test(`${kind}: SMS during failed verification survives field clearing, without retrying the rejected code`, async () => {
        for (const incoming of ['1111', '2222']) {
            let finish;
            const h = harness(kind, route => route.endsWith('/authenticate')
                ? {success: true, data: {login_method: 'otp', jwt: 'same-flow', ttl: 180}}
                : new Promise(resolve => { finish = resolve; }));
            await h.state.authenticate();
            h.state.forms.loginByOtp.inputs.code.value = '1111';
            const verifying = h.state.loginByOtp();
            h.credentials[0].resolve({code: incoming});
            await settled();
            assert.equal(h.submissions.length, 0);
            assert.equal(h.state.forms.loginByOtp.inputs.code.value, '1111');
            finish({success: false, message: 'Wrong code', data: {}, http: {status: 401}});
            await verifying;
            assert.equal(h.state.forms.loginByOtp.inputs.code.value, incoming);
            assert.equal(h.submissions.length, incoming === '1111' ? 0 : 1);
            assert.equal(h.credentials.length, 1);
        }
    });

    test(`${kind}: throttling ends the existing browser wait without rearming`, async () => {
        const h = harness(kind, route => route.endsWith('/authenticate')
            ? {success: true, data: {login_method: 'otp', jwt: 'same-flow', ttl: 180}}
            : {success: false, message: 'Try later', data: {}, http: {status: 429}});
        await h.state.authenticate();
        h.state.forms.loginByOtp.inputs.code.value = '1111';
        await h.state.loginByOtp();
        assert.equal(h.credentials[0].options.signal.aborted, true);
        h.credentials[0].resolve({code: '2222'});
        await settled();
        assert.equal(h.submissions.length, 0);
        assert.equal(h.credentials.length, 1);
    });
}
