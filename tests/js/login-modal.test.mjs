import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const loginScript = (
    await readFile(new URL('../../assets/js/pages/login-modal.js', import.meta.url), 'utf8')
)
    .replace(/^import pinovaAlpine[^\n]*\r?\n/m, '')
    .replace('export function pinovaOpenModal', 'function pinovaOpenModal');

function harness(apiResponse) {
    let factory = null;
    let nextTimer = 100;
    const clearedIntervals = [];
    const notifications = [];

    const context = vm.createContext({
        clearInterval(id) {
            clearedIntervals.push(id);
        },
        console: { error() {}, log() {} },
        document: {
            getElementById() {
                return { querySelector: () => ({ focus() {} }) };
            },
            querySelector() {
                return null;
            },
        },
        location: { reload() {} },
        navigator: {},
        pinova: { code_length: 4, logo: '/logo.svg' },
        pinovaAlpine: {
            $data() {
                return factory();
            },
            data(name, callback) {
                assert.equal(name, 'pinovaLoginModal');
                factory = callback;
            },
            prefix() {},
            start() {},
        },
        pinovaApiRequest: async () => apiResponse,
        pinovaGetQueryParam() {
            return null;
        },
        pinovaNotyf: {
            error(message) {
                notifications.push({ type: 'error', message });
            },
            success(message) {
                notifications.push({ type: 'success', message });
            },
        },
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
        window: {
            addEventListener() {},
        },
    });

    vm.runInContext(loginScript, context, { filename: 'assets/js/pages/login-modal.js' });
    assert.equal(typeof factory, 'function');

    return {
        clearedIntervals,
        notifications,
        state: factory(),
    };
}

test('modal password routing follows login_method without the removed has_account field', async () => {
    const { state } = harness({
        success: true,
        message: null,
        data: { login_method: 'password', ttl: 120 },
    });
    state.forms.authenticate.inputs.identifier.value = 'customer@example.test';

    await state.authenticate();

    assert.equal(state.stepName, 'loginByPassword');
    assert.equal(state.forms.loginByPassword.inputs.identifier.value, 'customer@example.test');
    assert.equal(state.forms.loginByPassword.inputs.password.rules.minLength, undefined);
});

test('modal OTP routing stores the token and starts the server TTL countdown', async () => {
    const { state } = harness({
        success: true,
        message: 'کد ارسال شد.',
        data: { login_method: 'otp', jwt: 'otp-jwt', ttl: 75 },
    });

    await state.authenticate();

    assert.equal(state.stepName, 'loginByOtp');
    assert.equal(state.forms.loginByOtp.inputs.jwt.value, 'otp-jwt');
    assert.equal(state.time.duration, 75000);
    assert.notEqual(state.time.timerInterval, null);
});

test('modal countdown clears the prior interval before replacing it', () => {
    const { clearedIntervals, state } = harness({ success: false, data: {} });
    state.time.timerInterval = 73;

    state.startTime();

    assert.equal(clearedIntervals[0], 73);
    assert.notEqual(state.time.timerInterval, 73);
});

test('modal authenticate does not mutate resend options supplied by the caller', async () => {
    const options = { force_otp: '1' };
    const { state } = harness({
        success: true,
        message: 'کد ارسال شد.',
        data: { login_method: 'otp', jwt: 'otp-jwt', ttl: 60 },
    });

    await state.authenticate(options, 'loginByOtp');

    assert.deepEqual(options, { force_otp: '1' });
});
