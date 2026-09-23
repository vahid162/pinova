import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const script = await readFile(new URL('../../assets/js/admin.js', import.meta.url), 'utf8');
const globalScript = await readFile(new URL('../../assets/js/global.js', import.meta.url), 'utf8');

function harness(apiRequest) {
    const nodes = new Map();
    function node(key, length = 1) {
        if (!nodes.has(key)) {
            nodes.set(key, {
                length, handlers: {}, properties: {}, value: '', message: '',
                val() { return this.value; },
                closest() { return this; },
                find() { return this; },
                after() { return this; },
                css() { return this; },
                text(value) { this.message = value; return this; },
                prop(name, value) { this.properties[name] = value; return this; },
                on(name, callback) { this.handlers[name] = callback; return this; },
                ready(callback) { callback(); return this; },
            });
        }
        return nodes.get(key);
    }
    function jquery(selector, attributes) {
        return node(attributes?.id || selector, selector === '#pinova-test-sms-btn' ? 0 : 1);
    }
    node('[id="pinova_sms[test_mobile]"]').value = '09120000000';
    const calls = [];
    vm.runInNewContext(script, {
        document: { body: {} },
        jQuery: jquery,
        pinovaApiRequest: async (...args) => {
            calls.push(args);
            return apiRequest(...args);
        },
    });
    return {
        calls,
        button: nodes.get('pinova-test-sms-btn'),
        result: nodes.get('pinova-test-sms-result'),
    };
}

test('SMS test shows the shared client error reference as text and restores the button', async () => {
    const message = 'خطا کد پیگیری: exact-server-reference';
    const { button, calls, result } = harness(() => ({ success: false, message }));
    await button.handlers.click();
    assert.equal(result.message, message);
    assert.equal(button.properties.disabled, false);
    assert.equal(calls[0][0], 'pinova/admin/test/sms');
    assert.equal(calls[0][1].notifyOnError, false);
    assert.equal(calls[0][1].data.identifier, '09120000000');
});

test('SMS test preserves a received reference on malformed responses without leaking parser details', async () => {
    const error = new Error('private response body');
    error.pinovaMessage = 'خطا کد پیگیری: exact-server-reference';
    const { button, result } = harness(() => { throw error; });
    await button.handlers.click();
    assert.equal(result.message, error.pinovaMessage);
    assert.equal(button.properties.disabled, false);
});

test('SMS test never fabricates a reference when the network fails', async () => {
    const { button, result } = harness(() => { throw new TypeError('Failed to fetch'); });
    await button.handlers.click();
    assert.equal(result.message, 'خطای اتصال به وبسرویس.');
    assert.doesNotMatch(result.message, /کد پیگیری/);
    assert.equal(button.properties.disabled, false);
});

async function gatewayBeforeWindowLoad(fetchImpl) {
    const notifications = [];
    const documentHandlers = {};
    const gateway = {
        length: 1,
        val() { return 'selected-gateway'; },
        closest() { return this; },
        on() { return this; },
    };
    const context = vm.createContext({
        AbortController,
        clearTimeout,
        console: { error() {} },
        document: {
            body: {},
            readyState: 'loading',
            addEventListener(name, callback) { documentHandlers[name] = callback; },
            createElement() { return { innerHTML: '' }; },
            head: { appendChild() {} },
        },
        fetch: fetchImpl,
        FormData,
        jQuery(selector) {
            if (selector === '[id="pinova_sms[gateway]"]') return gateway;
            if (selector === 'tr.pinova-gateway-dynamic-row') return { remove() {} };
            if (selector === '[id="pinova_sms[test_mobile]"]') return { length: 0 };
            if (selector === context.document.body) return { ready(callback) { documentHandlers.jqueryReady = callback; } };
            throw new Error(`Unexpected selector: ${selector}`);
        },
        Notyf: class { error(message) { notifications.push(message); } },
        pinova: { adminPage: true, nonce: 'test-nonce', root: 'https://example.test/wp-json/' },
        setTimeout,
        URLSearchParams,
        window: {
            addEventListener() {},
            location: { search: '' },
        },
    });

    vm.runInContext(globalScript, context, { filename: 'assets/js/global.js' });
    vm.runInContext(script, context, { filename: 'assets/js/admin.js' });
    context.document.readyState = 'interactive';
    documentHandlers.DOMContentLoaded?.();
    documentHandlers.jqueryReady();
    await new Promise(resolve => setImmediate(resolve));

    return notifications;
}

test('gateway JSON failure before window load shows exactly one real server reference', async () => {
    const notices = await gatewayBeforeWindowLoad(async () => ({
        ok: false,
        status: 403,
        headers: { get(name) {
            return {
                'content-type': 'application/json',
                'X-Pinova-Correlation-ID': 'real-server-ref',
            }[name] ?? null;
        } },
        async json() { return { success: false, message: 'Safe failure', data: {} }; },
    }));
    assert.deepEqual(notices, ['Safe failure کد پیگیری: real-server-ref']);
});

test('gateway malformed response before window load shows one real server reference', async () => {
    const notices = await gatewayBeforeWindowLoad(async () => ({
        ok: false,
        status: 502,
        headers: { get(name) {
            return {
                'content-type': 'text/html',
                'X-Pinova-Correlation-ID': 'real-server-ref',
            }[name] ?? null;
        } },
        async text() { return '<html>bad gateway</html>'; },
    }));
    assert.deepEqual(notices, ['در پردازش درخواست خطایی رخ داده است! کد پیگیری: real-server-ref']);
});

test('gateway network failure before window load shows one error without a made-up reference', async () => {
    const notices = await gatewayBeforeWindowLoad(async () => { throw new TypeError('Failed to fetch'); });
    assert.deepEqual(notices, ['در پردازش درخواست خطایی رخ داده است!']);
});
