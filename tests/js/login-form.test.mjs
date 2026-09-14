import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const loginScript = (
    await readFile(new URL('../../assets/js/pages/login-form.js', import.meta.url), 'utf8')
).replace(/^import pinovaAlpine[^\n]*\r?\n/m, '');

function harness(apiResponse = { success: false, message: 'این شناسه مسدود شده است.', data: {} }) {
    let factory = null;
    let nextTimer = 100;
    const clearedIntervals = [];
    const pushedStates = [];
    const replacedStates = [];
    const listeners = {};
    const focused = [];

    const history = {
        state: null,
        back() {},
        pushState(state) {
            this.state = state;
            pushedStates.push(state);
        },
        replaceState(state) {
            this.state = state;
            replacedStates.push(state);
        },
    };
    const context = vm.createContext({
        clearInterval(id) {
            clearedIntervals.push(id);
        },
        clearTimeout() {},
        console: { error() {}, log() {} },
        document: {
            body: { classList: { add() {} } },
            getElementById(id) {
                return {
                    querySelector() {
                        return { focus: () => focused.push(id) };
                    },
                };
            },
        },
        history,
        location: { origin: 'https://example.test', href: 'https://example.test/my-account/' },
        navigator: {},
        pinova: { code_length: 4, logo: '/logo.svg' },
        pinovaAlpine: {
            data(name, callback) {
                assert.equal(name, 'pinovaLoginForm');
                factory = callback;
            },
            prefix() {},
            start() {},
        },
        pinovaApiRequest: async () => apiResponse,
        pinovaCleanNumericInput(value) {
            return String(value);
        },
        pinovaGetQueryParam() {
            return null;
        },
        pinovaNotyf: { error() {}, success() {} },
        pinovaValidateField(field) {
            return field;
        },
        setInterval() {
            nextTimer += 1;
            return nextTimer;
        },
        setTimeout(callback) {
            callback();
            return 1;
        },
        URL,
        window: {
            addEventListener(name, callback) {
                listeners[name] = callback;
            },
            frameElement: null,
            history,
            location: { origin: 'https://example.test', href: 'https://example.test/my-account/' },
            parent: { document: { querySelector: () => null } },
        },
    });

    vm.runInContext(loginScript, context, { filename: 'assets/js/pages/login-form.js' });
    assert.equal(typeof factory, 'function');

    return {
        clearedIntervals,
        focused,
        history,
        listeners,
        pushedStates,
        replacedStates,
        state: factory(),
    };
}

test('existing WordPress passwords have no client-side minimum length', () => {
    const { state } = harness();

    assert.equal(state.forms.loginByPassword.inputs.password.rules.required, true);
    assert.equal(state.forms.loginByPassword.inputs.password.rules.minLength, undefined);
});

test('starting a timer clears the prior interval before replacing it', () => {
    const { clearedIntervals, state } = harness();
    state.time.timerInterval = 73;

    state.startTime();

    assert.equal(clearedIntervals[0], 73);
    assert.notEqual(state.time.timerInterval, 73);
});

test('step changes are represented in browser history', () => {
    const { pushedStates, state } = harness();

    state.changeStep('loginByPassword');

    assert.equal(pushedStates.at(-1).pinovaStep, 'loginByPassword');
});

test('returning to the first step clears OTP, reset, and password secrets', () => {
    const { clearedIntervals, state } = harness();
    state.time.timerInterval = 81;
    state.forms.loginByOtp.inputs.jwt.value = 'otp-jwt';
    state.forms.loginByOtp.inputs.code.value = '1234';
    state.forms.forgotPassword.inputs.jwt.value = 'forgot-jwt';
    state.forms.forgotPassword.inputs.code.value = '5678';
    state.forms.changePassword.inputs.reset_key.value = 'reset-key';
    state.forms.changePassword.inputs.password_1.value = 'secret-one';
    state.forms.changePassword.inputs.password_2.value = 'secret-two';
    state.forms.loginByPassword.inputs.password.value = 'legacy-secret';

    state.changeStep('authenticate');

    assert.equal(state.forms.loginByOtp.inputs.jwt.value, '');
    assert.equal(state.forms.loginByOtp.inputs.code.value, '');
    assert.equal(state.forms.forgotPassword.inputs.jwt.value, '');
    assert.equal(state.forms.forgotPassword.inputs.code.value, '');
    assert.equal(state.forms.changePassword.inputs.reset_key.value, '');
    assert.equal(state.forms.changePassword.inputs.password_1.value, '');
    assert.equal(state.forms.changePassword.inputs.password_2.value, '');
    assert.equal(state.forms.loginByPassword.inputs.password.value, '');
    assert.ok(clearedIntervals.includes(81));
});

test('leaving an OTP step clears its token, code, and countdown', () => {
    const { clearedIntervals, state } = harness();
    state.stepName = 'loginByOtp';
    state.time.timerInterval = 91;
    state.forms.loginByOtp.inputs.jwt.value = 'short-lived-jwt';
    state.forms.loginByOtp.inputs.code.value = '1234';

    state.changeStep('loginByPassword');

    assert.equal(state.forms.loginByOtp.inputs.jwt.value, '');
    assert.equal(state.forms.loginByOtp.inputs.code.value, '');
    assert.ok(clearedIntervals.includes(91));
});

test('safe REST failures are exposed through the inline live status', async () => {
    const { state } = harness();
    state.forms.authenticate.inputs.identifier.value = 'blocked@example.test';

    await state.authenticate();

    assert.equal(state.status.message, 'این شناسه مسدود شده است.');
    assert.equal(state.status.tone, 'error');
    assert.equal(state.pageLoaderIsActive, false);
});
