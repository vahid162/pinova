import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = (await readFile(new URL('../../assets/js/pages/login-modal.js', import.meta.url), 'utf8'))
    .replace(/^import pinovaAlpine[^\n]*\r?\n/m, '')
    .replace('export function pinovaOpenModal', 'function pinovaOpenModal');

// Deferred callbacks stay queued, so the test can exercise adverse task ordering
// without wall-clock sleeps or changing the shipped controller's implementation.
function harness() {
    const focus = [];
    const tasks = [];
    let factory;
    const background = {
        inert: false,
        getAttribute() { return null; },
        setAttribute() {},
        removeAttribute() {},
    };
    const body = { children: [], style: { overflow: '' } };
    const root = { parentElement: body };
    body.children = [background, root];
    const document = {
        body, activeElement: null,
        getElementById(id) {
            if (id === 'pinovaLoginModal') return root;
            return { querySelector: () => ({ focus: () => focus.push(id) }) };
        },
    };
    vm.runInNewContext(source, {
        AbortController,
        document,
        window: {},
        pinova: { code_length: 4, logo: '/fixture.svg' },
        pinovaGetQueryParam: () => null,
        pinovaAlpine: {
            prefix() {}, start() {},
            data(name, value) { factory = value; },
        },
        setTimeout(callback, delay) { tasks.push({ callback, delay }); return tasks.length; },
        clearInterval() {},
    });
    const state = factory();
    const opener = { focus: () => focus.push('opener') };
    return {
        state, focus, tasks, opener, background,
        run(delay) {
            const index = tasks.findIndex(task => task.delay === delay);
            assert.notEqual(index, -1, `Missing deferred ${delay}ms callback`);
            tasks.splice(index, 1)[0].callback();
        },
    };
}

test('current modal heading and ordinary close still receive focus', () => {
    const h = harness();
    h.state.openModal('', h.opener);
    assert.deepEqual(h.focus, []);
    h.run(100);
    assert.deepEqual(h.focus, ['pinova-modal-authenticate']);
    h.state.closeModal();
    h.run(0);
    assert.deepEqual(h.focus, ['pinova-modal-authenticate', 'opener']);
    assert.equal(h.background.inert, false);
});

test('a pending heading callback cannot steal restored focus after busy dismissal', () => {
    const h = harness();
    h.state.openModal('', h.opener);
    const request = h.state.beginRequest();
    assert.equal(h.state.pageLoaderIsActive, true);
    h.state.closeModal();
    h.run(0);
    assert.deepEqual(h.focus, ['opener']);
    // The closing DOM can remain visible until its reactive hide is applied.
    // Even then, this stale callback must not call heading.focus at all.
    h.run(100);
    assert.deepEqual(h.focus, ['opener']);
    assert.equal(h.state.modalIsOpen, false);
    assert.equal(h.state.pageLoaderIsActive, false);
    assert.equal(request.signal.aborted, true);
    assert.equal(h.background.inert, false);
});

test('a superseded step callback cannot focus an old heading', () => {
    const h = harness();
    h.state.openModal('', h.opener);
    h.state.changeStep('loginByPassword');
    h.run(100);
    assert.deepEqual(h.focus, []);
    h.run(100);
    assert.deepEqual(h.focus, ['pinova-modal-loginByPassword']);
});

test('only the latest request may focus a heading even when its step name repeats', () => {
    const h = harness();
    h.state.openModal('', h.opener);
    h.state.focusStepHeading('authenticate');
    h.run(100);
    assert.deepEqual(h.focus, []);
    h.run(100);
    assert.deepEqual(h.focus, ['pinova-modal-authenticate']);
});

test('rapid reopen invalidates both the old heading and old return-focus callback', () => {
    const h = harness();
    h.state.openModal('', h.opener);
    h.state.closeModal();
    h.state.openModal('', h.opener);
    h.run(0);
    assert.deepEqual(h.focus, []);
    h.run(100);
    assert.deepEqual(h.focus, []);
    h.run(100);
    assert.deepEqual(h.focus, ['pinova-modal-authenticate']);
    assert.equal(h.state.modalIsOpen, true);
    assert.equal(h.background.inert, true);
});
