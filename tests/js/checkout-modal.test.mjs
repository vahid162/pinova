import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const checkoutScript = await readFile(
    new URL('../../assets/js/woocommerce/checkout.js', import.meta.url),
    'utf8',
);

function harness() {
    const body = {};
    const handlers = {};
    const modalCalls = [];
    let accountConflict = null;
    let loginFormRemoved = false;
    let phoneFocused = false;
    let phoneScrolled = false;

    const phoneRow = {
        classList: {
            contains(name) {
                return name === 'woocommerce-invalid-required-field';
            },
        },
    };
    const phone = {
        closest(selector) {
            assert.equal(selector, '.form-row');
            return phoneRow;
        },
        focus(options) {
            phoneFocused = options?.preventScroll === true;
        },
        scrollIntoView(options) {
            phoneScrolled = options?.block === 'center';
        },
    };
    const document = {
        body,
        getElementById(id) {
            return id === 'billing_phone' ? phone : null;
        },
        querySelector(selector) {
            assert.equal(
                selector,
                '.woocommerce-error .pinova-open_login__modal, .woocommerce-NoticeGroup-checkout .pinova-open_login__modal',
            );
            return accountConflict;
        },
    };

    function collection(input) {
        if (input === body) {
            return {
                on(eventName, selectorOrHandler, maybeHandler) {
                    if (typeof selectorOrHandler === 'function') {
                        handlers[eventName] = selectorOrHandler;
                        return;
                    }

                    handlers[`${eventName}:${selectorOrHandler}`] = maybeHandler;
                },
            };
        }

        if (input === '.showlogin') {
            return { length: 1 };
        }

        if (input === 'form.woocommerce-form.woocommerce-form-login.login') {
            return {
                remove() {
                    loginFormRemoved = true;
                },
            };
        }

        if (typeof input === 'object' && input !== null) {
            return {
                attr(name) {
                    assert.equal(name, 'data-identifier');
                    return input.identifier;
                },
            };
        }

        return { length: 0 };
    }

    const context = vm.createContext({
        document,
        jQuery: collection,
        pinovaOpenModal(identifier, opener) {
            modalCalls.push({ identifier, opener });
        },
    });

    vm.runInContext(checkoutScript, context, {
        filename: 'assets/js/woocommerce/checkout.js',
    });

    return {
        handlers,
        modalCalls,
        setAccountConflict(element) {
            accountConflict = element;
        },
        state() {
            return {
                loginFormRemoved,
                phoneFocused,
                phoneScrolled,
            };
        },
    };
}

test('unrelated checkout errors keep Pinova closed and focus an invalid phone field', () => {
    const instance = harness();

    instance.handlers.checkout_error();

    assert.deepEqual(instance.modalCalls, []);
    assert.equal(instance.state().phoneFocused, true);
    assert.equal(instance.state().phoneScrolled, true);
});

test('a checkout account-conflict notice opens Pinova with that exact identifier', () => {
    const instance = harness();
    const conflict = { identifier: '09121234567' };
    instance.setAccountConflict(conflict);

    instance.handlers.checkout_error();

    assert.deepEqual(instance.modalCalls, [
        { identifier: '09121234567', opener: conflict },
    ]);
});

test('explicit login clicks use the clicked element instead of the first global match', () => {
    const instance = harness();
    const clicked = { identifier: 'buyer@example.test' };
    let prevented = false;

    instance.handlers['click:.pinova-open_login__modal, .showlogin']({
        currentTarget: clicked,
        preventDefault() {
            prevented = true;
        },
    });

    assert.equal(prevented, true);
    assert.deepEqual(instance.modalCalls, [
        { identifier: 'buyer@example.test', opener: clicked },
    ]);
    assert.equal(instance.state().loginFormRemoved, true);
});
