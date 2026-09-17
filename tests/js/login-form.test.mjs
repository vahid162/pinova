import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const loginScript = (
    await readFile(new URL('../../assets/js/pages/login-form.js', import.meta.url), 'utf8')
).replace(/^import pinovaAlpine[^\n]*\r?\n/m, '');

function harness(
    apiResponse = { success: false, message: 'این شناسه مسدود شده است.', data: {} },
    options = {},
) {
    let factory = null;
    let nextTimer = 100;
    const clearedIntervals = [];
    const apiCalls = [];
    const pushedStates = [];
    const replacedStates = [];
    const listeners = {};
    const focused = [];
    const navigator = {};

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
    const windowObject = {
        addEventListener(name, callback) {
            listeners[name] = callback;
        },
        frameElement: null,
        history,
        location: { origin: 'https://example.test', href: 'https://example.test/my-account/' },
        parent: { document: { querySelector: () => null } },
    };

    if (options.webOtpCode) {
        windowObject.OTPCredential = function OTPCredential() {};
        navigator.credentials = {
            async get() {
                return { code: options.webOtpCode };
            },
        };
    }

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
                    querySelector(selector) {
                        return {
                            focus(focusOptions) {
                                focused.push({ id, selector, options: focusOptions });
                            },
                        };
                    },
                };
            },
        },
        history,
        location: { origin: 'https://example.test', href: 'https://example.test/my-account/' },
        navigator,
        pinova: { code_length: options.codeLength || 4, logo: '/logo.svg' },
        pinovaAlpine: {
            data(name, callback) {
                assert.equal(name, 'pinovaLoginForm');
                factory = callback;
            },
            prefix() {},
            start() {},
        },
        pinovaApiRequest: async (...args) => {
            apiCalls.push(args);
            return typeof apiResponse === 'function' ? apiResponse(...args) : apiResponse;
        },
        pinovaCleanNumericInput(value) {
            const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
            const arabicDigits = '٠١٢٣٤٥٦٧٨٩';

            return String(value ?? '')
                .replace(/[۰-۹]/g, digit => persianDigits.indexOf(digit))
                .replace(/[٠-٩]/g, digit => arabicDigits.indexOf(digit))
                .replace(/[^0-9]/g, '');
        },
        pinovaGetQueryParam() {
            return null;
        },
        pinovaNotyf: { error() {}, success() {} },
        pinovaValidateField: options.validateField || (field => field),
        setInterval() {
            nextTimer += 1;
            return nextTimer;
        },
        setTimeout(callback) {
            callback();
            return 1;
        },
        URL,
        window: windowObject,
    });

    vm.runInContext(loginScript, context, { filename: 'assets/js/pages/login-form.js' });
    assert.equal(typeof factory, 'function');

    return {
        apiCalls,
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

test('initialization leaves inputs unfocused and later steps focus their heading', () => {
    const { focused, state } = harness();

    state.init();
    assert.deepEqual(focused, []);

    state.changeStep('loginByPassword');

    assert.equal(focused.length, 1);
    assert.equal(focused[0].id, 'loginByPassword');
    assert.equal(focused[0].selector, '[data-pinova-step-heading]');
    assert.equal(focused[0].options.preventScroll, true);
});

test('client validation focuses the first field marked invalid', () => {
    const { focused, state } = harness(undefined, {
        validateField(field) {
            if (field.rules?.required && !String(field.value || '').trim()) {
                return { ...field, errorMsg: 'این فیلد نمی‌تواند خالی باشد' };
            }

            return field;
        },
    });

    state.submit();

    assert.equal(focused.length, 1);
    assert.equal(focused[0].id, 'authenticate');
    assert.equal(focused[0].selector, '[aria-invalid="true"]');
});

test('OTP input normalizes digits and autosubmits each complete code exactly once', () => {
    for (const codeLength of [4, 5, 6]) {
        for (const formName of ['signIn', 'loginByOtp', 'forgotPassword']) {
            const { state } = harness(undefined, { codeLength });
            let submissions = 0;
            state.submit = () => {
                submissions += 1;
            };

            const persianCode = '۱۲۳۴۵۶'.slice(0, codeLength);
            const asciiCode = '123456'.slice(0, codeLength);
            state.forms[formName].inputs.code.value = `${persianCode}-ignored`;

            state.handleOtpInput(formName);
            state.handleOtpInput(formName);

            assert.equal(state.forms[formName].inputs.code.value, asciiCode);
            assert.equal(submissions, 1, `${formName}: ${codeLength}-digit code should autosubmit once`);

            state.forms[formName].inputs.code.value = asciiCode.slice(0, -1);
            state.handleOtpInput(formName);
            state.forms[formName].inputs.code.value = persianCode;
            state.handleOtpInput(formName);

            assert.equal(submissions, 2, `${formName}: replacement should autosubmit once`);
        }
    }
});

test('WebOTP uses the normalized exact-length autosubmit path', async () => {
    const { listeners, state } = harness(undefined, { codeLength: 4, webOtpCode: '۱۲٣٤' });
    let submissions = 0;
    state.stepName = 'signIn';
    state.submit = () => {
        submissions += 1;
    };

    state.init();
    assert.equal(typeof listeners.DOMContentLoaded, 'function');
    listeners.DOMContentLoaded();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(state.forms.signIn.inputs.code.value, '1234');
    assert.equal(submissions, 1);
});

test('the centralized request lock rejects concurrent actions', async () => {
    let resolveRequest;
    const pendingResponse = new Promise(resolve => {
        resolveRequest = resolve;
    });
    const { apiCalls, state } = harness(() => pendingResponse);
    state.forms.authenticate.inputs.identifier.value = 'buyer@example.test';

    const firstRequest = state.authenticate();
    const duplicateRequests = [
        state.authenticate({ force_otp: '1' }),
        state.authenticate({ forget: '1' }, 'forgotPassword'),
        state.loginByPassword(),
    ];

    assert.equal(state.pageLoaderIsActive, true);
    assert.equal(apiCalls.length, 1);

    resolveRequest({ success: false, message: 'دوباره تلاش کنید.', data: {} });
    await Promise.all([firstRequest, ...duplicateRequests]);

    assert.equal(state.pageLoaderIsActive, false);
    assert.equal(apiCalls.length, 1);
});

test('identifier edit labels match email, mobile, and fallback identifiers', () => {
    const { state } = harness();

    state.forms.authenticate.inputs.identifier.value = 'buyer@example.test';
    assert.equal(state.identifierEditLabel(), 'ویرایش ایمیل');

    state.forms.authenticate.inputs.identifier.value = '۰۹۱۲ ۱۲۳ ۴۵۶۷';
    assert.equal(state.identifierEditLabel(), 'ویرایش شماره');

    state.forms.authenticate.inputs.identifier.value = 'customer-name';
    assert.equal(state.identifierEditLabel(), 'ویرایش شناسه');
});

test('editing the identifier returns to the first step and clears OTP secrets', () => {
    const { state } = harness();
    state.stepName = 'loginByOtp';
    state.forms.loginByOtp.inputs.jwt.value = 'otp-jwt';
    state.forms.loginByOtp.inputs.code.value = '1234';

    state.editIdentifier();

    assert.equal(state.stepName, 'authenticate');
    assert.equal(state.forms.loginByOtp.inputs.jwt.value, '');
    assert.equal(state.forms.loginByOtp.inputs.code.value, '');
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
