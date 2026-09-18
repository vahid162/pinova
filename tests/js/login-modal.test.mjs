import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const loginScript = (
    await readFile(new URL('../../assets/js/pages/login-modal.js', import.meta.url), 'utf8')
)
    .replace(/^import pinovaAlpine[^\n]*\r?\n/m, '')
    .replace('export function pinovaOpenModal', 'function pinovaOpenModal');

function harness(
    apiResponse = { success: false, message: 'این شناسه مسدود شده است.', data: {} },
    options = {},
) {
    let factory = null;
    let nextTimer = 100;
    const apiCalls = [];
    const clearedIntervals = [];
    const focused = [];
    const listeners = {};
    const navigator = {};
    const location = {
        href: 'https://example.test/checkout/',
        reloadCalls: 0,
        reload() {
            this.reloadCalls += 1;
        },
    };

    const pageContent = {
        inert: false,
        attributes: new Map(),
        getAttribute(name) {
            return this.attributes.has(name) ? this.attributes.get(name) : null;
        },
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
        removeAttribute(name) {
            this.attributes.delete(name);
        },
    };
    const body = {
        children: [],
        style: { overflow: '' },
    };
    const modalRoot = {
        parentElement: body,
    };
    body.children = [pageContent, modalRoot];

    const document = {
        activeElement: null,
        body,
        getElementById(id) {
            if (id === 'pinovaLoginModal') {
                return modalRoot;
            }

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
        querySelector(selector) {
            if (selector === '#pinovaLoginModal [role="dialog"]') {
                return {
                    focus(focusOptions) {
                        focused.push({ id: 'dialog', selector, options: focusOptions });
                    },
                    querySelectorAll() {
                        return [];
                    },
                };
            }

            return null;
        },
    };
    const windowObject = {
        addEventListener(name, callback) {
            listeners[name] = callback;
        },
        location,
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
        AbortController,
        console: { error() {}, log() {} },
        document,
        location,
        navigator,
        pinova: { code_length: options.codeLength || 4, logo: '/logo.svg' },
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
        window: windowObject,
    });

    vm.runInContext(loginScript, context, { filename: 'assets/js/pages/login-modal.js' });
    assert.equal(typeof factory, 'function');

    return {
        apiCalls,
        clearedIntervals,
        focused,
        listeners,
        location,
        pageContent,
        state: factory(),
    };
}

test('modal password routing follows login_method without a password length restriction', async () => {
    const { state } = harness({
        success: true,
        message: null,
        data: { login_method: 'password', ttl: 120 },
    });
    state.modalIsOpen = true;
    state.forms.authenticate.inputs.identifier.value = 'customer@example.test';

    await state.authenticate();

    assert.equal(state.stepName, 'loginByPassword');
    assert.equal(state.forms.loginByPassword.inputs.identifier.value, 'customer@example.test');
    assert.equal(state.forms.loginByPassword.inputs.password.rules.minLength, undefined);
});

test('opening focuses a non-input heading and makes the checkout background inert', () => {
    const { focused, pageContent, state } = harness();
    const opener = { focus() {} };

    state.init();
    assert.deepEqual(focused, []);

    state.openModal('', opener);

    assert.equal(state.modalIsOpen, true);
    assert.equal(pageContent.inert, true);
    assert.equal(pageContent.getAttribute('aria-hidden'), 'true');
    assert.equal(focused.length, 1);
    assert.equal(focused[0].id, 'pinova-modal-authenticate');
    assert.equal(focused[0].selector, '[data-pinova-step-heading]');
});

test('closing clears secrets, restores the page, and returns focus to the opener', () => {
    const { pageContent, state } = harness();
    let returnedFocus = false;
    const opener = {
        focus(options) {
            returnedFocus = options?.preventScroll === true;
        },
    };

    state.openModal('', opener);
    state.stepName = 'loginByOtp';
    state.forms.loginByOtp.inputs.jwt.value = 'otp-jwt';
    state.forms.loginByOtp.inputs.code.value = '1234';
    state.forms.loginByPassword.inputs.password.value = 'secret';
    state.closeModal();

    assert.equal(state.modalIsOpen, false);
    assert.equal(state.stepName, 'authenticate');
    assert.equal(state.forms.authenticate.inputs.identifier.value, '');
    assert.equal(state.forms.loginByOtp.inputs.jwt.value, '');
    assert.equal(state.forms.loginByOtp.inputs.code.value, '');
    assert.equal(state.forms.loginByPassword.inputs.password.value, '');
    assert.equal(pageContent.inert, false);
    assert.equal(pageContent.getAttribute('aria-hidden'), null);
    assert.equal(returnedFocus, true);
});

test('closing while busy aborts the request, ignores a late response, and restores checkout', async () => {
    let resolveRequest;
    let requestSignal;
    const pendingResponse = new Promise(resolve => {
        resolveRequest = resolve;
    });
    const { pageContent, state } = harness((url, options) => {
        requestSignal = options.signal;
        return pendingResponse;
    });
    let returnedFocus = false;
    const opener = {
        focus() {
            returnedFocus = true;
        },
    };

    state.openModal('', opener);
    state.forms.authenticate.inputs.identifier.value = 'buyer@example.test';
    const request = state.authenticate();

    assert.equal(state.pageLoaderIsActive, true);
    assert.equal(requestSignal.aborted, false);

    state.closeModal();

    assert.equal(state.modalIsOpen, false);
    assert.equal(state.pageLoaderIsActive, false);
    assert.equal(requestSignal.aborted, true);
    assert.equal(pageContent.inert, false);
    assert.equal(returnedFocus, true);

    resolveRequest({
        success: true,
        message: null,
        data: { login_method: 'password', ttl: 120 },
    });
    await request;

    assert.equal(state.modalIsOpen, false);
    assert.equal(state.stepName, 'authenticate');
    assert.equal(state.status.message, '');
});

test('modal OTP input normalizes digits and autosubmits each complete code exactly once', () => {
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
            assert.equal(submissions, 1);
        }
    }
});

test('modal WebOTP follows the normalized exact-length autosubmit path', async () => {
    const { listeners, state } = harness(undefined, {
        codeLength: 4,
        webOtpCode: '۱۲٣٤',
    });
    let submissions = 0;
    state.modalIsOpen = true;
    state.stepName = 'signIn';
    state.submit = () => {
        submissions += 1;
    };

    state.init();
    listeners.DOMContentLoaded();
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(state.forms.signIn.inputs.code.value, '1234');
    assert.equal(submissions, 1);
});

test('the modal request lock rejects concurrent submit, resend, and alternate actions', async () => {
    let resolveRequest;
    const pendingResponse = new Promise(resolve => {
        resolveRequest = resolve;
    });
    const { apiCalls, state } = harness(() => pendingResponse);
    state.modalIsOpen = true;
    state.forms.authenticate.inputs.identifier.value = 'buyer@example.test';

    const firstRequest = state.authenticate();
    const duplicates = [
        state.authenticate({ force_otp: '1' }),
        state.authenticate({ forget: '1' }, 'forgotPassword'),
        state.loginByPassword(),
    ];

    assert.equal(state.pageLoaderIsActive, true);
    assert.equal(apiCalls.length, 1);

    resolveRequest({ success: false, message: 'دوباره تلاش کنید.', data: {} });
    await Promise.all([firstRequest, ...duplicates]);

    assert.equal(state.pageLoaderIsActive, false);
    assert.equal(apiCalls.length, 1);
});

test('modal OTP routing stores the token and starts the server TTL countdown', async () => {
    const { state } = harness({
        success: true,
        message: 'کد ارسال شد.',
        data: { login_method: 'otp', jwt: 'otp-jwt', ttl: 75 },
    });
    state.modalIsOpen = true;

    await state.authenticate();

    assert.equal(state.stepName, 'loginByOtp');
    assert.equal(state.forms.loginByOtp.inputs.jwt.value, 'otp-jwt');
    assert.equal(state.time.duration, 75000);
    assert.notEqual(state.time.timerInterval, null);
});

test('modal countdown clears the prior interval before replacing it', () => {
    const { clearedIntervals, state } = harness();
    state.time.timerInterval = 73;

    state.startTime();

    assert.equal(clearedIntervals[0], 73);
    assert.notEqual(state.time.timerInterval, 73);
});

test('safe REST failures appear in the modal live status', async () => {
    const { state } = harness();
    state.modalIsOpen = true;
    state.forms.authenticate.inputs.identifier.value = 'blocked@example.test';

    await state.authenticate();

    assert.equal(state.status.message, 'این شناسه مسدود شده است.');
    assert.equal(state.status.tone, 'error');
    assert.equal(state.pageLoaderIsActive, false);
});
