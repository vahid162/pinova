import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const script = await readFile(new URL('../../assets/js/mobile-verification.js', import.meta.url), 'utf8');
const reference = '4b3e97ad-6845-4d82-a381-237af90cb5f1';

function harness(fetch) {
    const listeners = {};
    const element = () => ({
        value: '', textContent: '', hidden: false,
        addEventListener() {}, focus() {}, setCustomValidity() {},
    });
    const status = element();
    const form = {
        elements: { mobile: element(), code: element() },
        reportValidity: () => true,
        setAttribute() {},
        addEventListener(name, handler) { listeners[name] = handler; },
    };
    form.elements.mobile.value = '09120000000';
    const root = {
        dataset: { length: '5', endpoint: 'https://example.test/wp-json/pinova/mobile/', nonce: 'fixture' },
        querySelector(selector) {
            if (selector === 'form') return form;
            if (selector === '.pinova-mobile-status') return status;
            return element();
        },
    };
    vm.runInNewContext(script, {
        AbortController, Error, fetch,
        document: { querySelectorAll: () => [root] },
        window: { addEventListener() {} },
        setTimeout: () => 1, clearTimeout() {}, setInterval: () => 2, clearInterval() {},
    });
    return { status, submit: () => listeners.submit({ preventDefault() {} }) };
}

function response(status, message, header = reference) {
    return {
        ok: status === 200, status,
        headers: { get: name => name === 'X-Pinova-Correlation-ID' ? header : null },
        json: async () => ({ success: false, message }),
    };
}

test('mobile proof preserves an LTR support reference even when the server body is malformed', async () => {
    const reply = response(502, 'unused');
    reply.json = async () => { throw new SyntaxError('private server response'); };
    const { status, submit } = harness(async () => reply);
    await submit();
    assert.match(status.textContent, /پاسخ معتبری از سرور دریافت نشد/);
    assert.ok(status.textContent.includes(`\nکد پیگیری برای پشتیبانی:\n\u2066${reference}\u2069`));
    assert.doesNotMatch(status.textContent, /private server response/);
});

test('mobile proof clears the previous reference on a subsequent network failure', async () => {
    let calls = 0;
    const { status, submit } = harness(async () => {
        if (++calls === 1) return response(401, 'تأیید انجام نشد.');
        throw new Error('private network detail');
    });
    await submit();
    assert.ok(status.textContent.includes(reference));
    await submit();
    assert.doesNotMatch(status.textContent, /کد پیگیری|private network detail/);
});

test('mobile proof never presents an unsafe response header as a support reference', async () => {
    const { status, submit } = harness(async () => response(403, 'درخواست پذیرفته نشد.', '<script>bad</script>'));
    await submit();
    assert.equal(status.textContent, 'درخواست پذیرفته نشد.');
});
