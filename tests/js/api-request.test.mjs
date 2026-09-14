import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const globalScript = await readFile(
    new URL('../../assets/js/global.js', import.meta.url),
    'utf8'
);

const GENERIC_ERROR = 'در پردازش درخواست خطایی رخ داده است!';

function response(status, payload, extraHeaders = {}) {
    const headers = new Map(
        Object.entries({
            'content-type': 'application/json; charset=UTF-8',
            ...extraHeaders,
        }).map(([key, value]) => [key.toLowerCase(), value])
    );

    return {
        ok: status >= 200 && status < 300,
        status,
        headers: {
            get(name) {
                return headers.get(name.toLowerCase()) ?? null;
            },
        },
        async json() {
            return payload;
        },
        async text() {
            return JSON.stringify(payload);
        },
    };
}

function textResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: {
            get(name) {
                return name.toLowerCase() === 'content-type' ? 'text/html' : null;
            },
        },
        async json() {
            throw new Error('not json');
        },
        async text() {
            return body;
        },
    };
}

function harness(fetchImpl) {
    const notifications = [];
    const loadHandlers = [];

    class Notyf {
        error(message) {
            notifications.push({ type: 'error', message });
        }

        success(message) {
            notifications.push({ type: 'success', message });
        }
    }

    const context = vm.createContext({
        console: {
            error() {},
            log() {},
        },
        AbortController,
        clearTimeout,
        document: {
            createElement() {
                return { innerHTML: '' };
            },
            head: {
                appendChild() {},
            },
        },
        fetch: fetchImpl,
        FormData,
        Notyf,
        pinova: {
            adminPage: false,
            nonce: 'test-nonce',
            root: 'https://example.test/wp-json/',
        },
        URLSearchParams,
        setTimeout,
        window: {
            addEventListener(name, callback) {
                if (name === 'load') {
                    loadHandlers.push(callback);
                }
            },
            location: {
                search: '',
            },
        },
    });

    vm.runInContext(globalScript, context, { filename: 'assets/js/global.js' });
    loadHandlers.forEach((callback) => callback());

    return {
        context,
        notifications,
        request: context.pinovaApiRequest,
    };
}

test('returns a successful Pinova envelope with HTTP correlation metadata', async () => {
    const { notifications, request } = harness(async () => response(
        200,
        { success: true, message: null, data: { login_method: 'password' } },
        { 'X-Pinova-Correlation-ID': 'correlation-123' }
    ));

    const result = await request('pinova/user/authenticate');

    assert.equal(result.success, true);
    assert.equal(result.data.login_method, 'password');
    assert.equal(result.http.status, 200);
    assert.equal(result.http.correlationId, 'correlation-123');
    assert.equal(result.http.retryAfter, null);
    assert.equal(notifications.length, 0);
});

test('returns a plugin 403 response so the caller can show the blocked message', async () => {
    const blockedMessage = 'آدرس آی.پی شما مسدود شده است.';
    const { notifications, request } = harness(async () => response(
        403,
        { success: false, message: blockedMessage, data: {} }
    ));

    const result = await request('pinova/user/authenticate');

    assert.equal(result.success, false);
    assert.equal(result.message, blockedMessage);
    assert.equal(result.http.status, 403);
    assert.equal(notifications.length, 0);
});

test('prefers a field-specific WordPress validation message over rest_invalid_param', async () => {
    const blockedMessage = 'ایمیل یا تلفن همراه مسدود شده است.';
    const { notifications, request } = harness(async () => response(400, {
        code: 'rest_invalid_param',
        message: 'پارامتر(های) نامعتبر: identifier',
        data: {
            status: 400,
            params: {
                identifier: blockedMessage,
            },
        },
    }));

    const result = await request('pinova/user/authenticate');

    assert.equal(result.success, false);
    assert.equal(result.message, blockedMessage);
    assert.equal(result.code, 'rest_invalid_param');
    assert.equal(result.http.status, 400);
    assert.equal(notifications.length, 0);
});

test('returns a 401 OTP response so the caller can show the verification error', async () => {
    const otpMessage = 'کد تأیید معتبر نمی‌باشد.';
    const { notifications, request } = harness(async () => response(
        401,
        { success: false, message: otpMessage, data: {} }
    ));

    const result = await request('pinova/user/login/otp');

    assert.equal(result.success, false);
    assert.equal(result.message, otpMessage);
    assert.equal(result.http.status, 401);
    assert.equal(notifications.length, 0);
});

test('preserves a 429 message and makes Retry-After actionable', async () => {
    const rateLimitMessage = 'تعداد درخواست‌ها بیش از حد مجاز است.';
    const { notifications, request } = harness(async () => response(
        429,
        { success: false, message: rateLimitMessage, data: {} },
        { 'Retry-After': '37' }
    ));

    const result = await request('pinova/user/authenticate');

    assert.equal(result.success, false);
    assert.equal(
        result.message,
        `${rateLimitMessage} امکان تلاش مجدد تا 37 ثانیهٔ دیگر وجود دارد.`
    );
    assert.equal(result.http.status, 429);
    assert.equal(result.http.retryAfter, '37');
    assert.equal(result.http.retryAfterSeconds, 37);
    assert.equal(notifications.length, 0);
});

test('returns a 503 delivery message without replacing it with a generic error', async () => {
    const deliveryMessage = 'در حال حاضر ارسال کد تأیید امکان‌پذیر نیست.';
    const { notifications, request } = harness(async () => response(
        503,
        { success: false, message: deliveryMessage, data: {} }
    ));

    const result = await request('pinova/user/authenticate');

    assert.equal(result.success, false);
    assert.equal(result.message, deliveryMessage);
    assert.equal(result.http.status, 503);
    assert.equal(notifications.length, 0);
});

test('uses one generic notification for a malformed non-JSON response', async () => {
    const { notifications, request } = harness(async () => textResponse(502, '<html>bad gateway</html>'));

    await assert.rejects(() => request('pinova/user/authenticate'), {
        message: GENERIC_ERROR,
    });

    assert.deepEqual(notifications, [{ type: 'error', message: GENERIC_ERROR }]);
});

test('uses one generic notification for a network failure', async () => {
    const { notifications, request } = harness(async () => {
        throw new TypeError('Failed to fetch');
    });

    await assert.rejects(() => request('pinova/user/authenticate'), TypeError);
    assert.deepEqual(notifications, [{ type: 'error', message: GENERIC_ERROR }]);
});

test('allows an inline-status caller to suppress the duplicate generic notification', async () => {
    const { notifications, request } = harness(async () => {
        throw new TypeError('Failed to fetch');
    });

    await assert.rejects(
        () => request('pinova/user/authenticate', { notifyOnError: false }),
        TypeError,
    );
    assert.deepEqual(notifications, []);
});

test('uses the WordPress nonce only for authenticated requests without mutating caller headers', async () => {
    const observed = [];
    const { request } = harness(async (url, options) => {
        observed.push({ url, options });
        return response(200, { success: true, message: null, data: {} });
    });

    const privateHeaders = { Accept: 'application/json' };
    await request('pinova/admin/blocks/index', { headers: privateHeaders });
    await request('pinova/user/authenticate', { headers: { nonce: null } });

    assert.equal(observed[0].options.headers['X-WP-Nonce'], 'test-nonce');
    assert.equal(privateHeaders['X-WP-Nonce'], undefined);
    assert.equal(observed[1].options.headers['X-WP-Nonce'], undefined);
    assert.equal(observed[1].options.headers.nonce, undefined);
});

test('numeric input accepts Persian, Arabic, and ASCII digits', () => {
    const { context } = harness(async () => response(200, { success: true, data: {} }));

    assert.equal(context.pinovaCleanNumericInput('۱۲٣4-۵۶'), '123456');
});

test('aborts a request after its bounded timeout', async () => {
    const { request } = harness(async (url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => {
            const error = new Error('aborted');
            error.name = 'AbortError';
            reject(error);
        });
    }));

    await assert.rejects(
        () => request('pinova/user/authenticate', { timeout: 5 }),
        { name: 'AbortError' }
    );
});
