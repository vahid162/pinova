import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const blockScript = (
    await readFile(new URL('../../assets/js/pages/blocks.js', import.meta.url), 'utf8')
).replace(/^import pinovaAlpine[^\n]*\r?\n/m, '');
const blockTemplate = await readFile(
    new URL('../../templates/admin/blocks.php', import.meta.url),
    'utf8'
);

function harness(apiRequest) {
    let factory = null;
    const scheduled = [];
    const notifications = [];
    const context = vm.createContext({
        clearTimeout() {},
        console: {
            error() {},
        },
        persianDate: class PersianDate {},
        pinovaAlpine: {
            data(name, callback) {
                assert.equal(name, 'blocks');
                factory = callback;
            },
            prefix() {},
            start() {},
        },
        pinovaApiRequest: apiRequest,
        pinovaGenerateFiltersObject(value) {
            return value;
        },
        pinovaGetVisiblePages(value) {
            return [value.totalPage];
        },
        pinovaNotyf: {
            error(message) {
                notifications.push({ type: 'error', message });
            },
            success(message) {
                notifications.push({ type: 'success', message });
            },
        },
        pinovaSetUrlQueryParams() {},
        setTimeout(callback) {
            scheduled.push(callback);
            return scheduled.length;
        },
    });

    vm.runInContext(blockScript, context, { filename: 'assets/js/pages/blocks.js' });
    assert.equal(typeof factory, 'function');

    return {
        notifications,
        scheduled,
        state: factory(),
    };
}

test('the add modal always opens with a permanent block selected', () => {
    const { state } = harness(async () => ({}));

    assert.equal(state.modals.add.data.blocked_until.always, true);
    state.modals.add.data.blocked_until.always = false;
    state.openAddModal();

    assert.equal(state.modals.add.data.blocked_until.always, true);
    assert.equal(state.modals.add.data.blocked_until.value, null);
});

test('page navigation refreshes blocks and never calls an unrelated user loader', async () => {
    const { state } = harness(async () => ({}));
    let refreshes = 0;
    state.getBlocks = async () => {
        refreshes += 1;
    };
    state.pagination.totalPage = 3;

    await state.changePage(2);

    assert.equal(state.tableFilters.page, 2);
    assert.equal(refreshes, 1);
});

test('an exact page multiple uses ceiling pagination without an extra page', async () => {
    const { state } = harness(async () => ({
        success: true,
        data: {
            blocks: [],
            current_page: 1,
            total_items: 20,
            total_pages: 1,
        },
    }));

    await state.getBlocks();

    assert.equal(state.pagination.currentPage, 1);
    assert.equal(state.pagination.totalPage, 1);
    assert.deepEqual(state.pagination.items, [1]);
});

test('a server-clamped empty page synchronizes filter state and keeps one UI page', async () => {
    const { state } = harness(async () => ({
        success: true,
        data: {
            blocks: [],
            current_page: 1,
            total_items: 0,
            total_pages: 1,
        },
    }));
    state.tableFilters.page = 9;

    await state.getBlocks();

    assert.equal(state.tableFilters.page, 1);
    assert.equal(state.pagination.currentPage, 1);
    assert.equal(state.pagination.totalPage, 1);
});

test('blocked-by search consumes the stable id/name response shape', async () => {
    const { scheduled, state } = harness(async () => ({
        success: true,
        data: {
            users: [{ id: 17, name: 'مدیر تست' }],
        },
    }));

    state.searchBlockedBy('مدیر');
    await scheduled.shift()();

    assert.equal(state.blockedBy.users.length, 1);
    assert.equal(state.blockedBy.users[0].id, 17);
    assert.equal(state.blockedBy.users[0].name, 'مدیر تست');
});

test('blocked-by selection stores an exact user id and can be cleared', () => {
    const { state } = harness(async () => ({}));

    state.selectBlockedBy({ id: 17, name: 'مدیر تست' });

    assert.equal(state.tableFilters.blocked_by, 17);
    assert.equal(state.blockedBy.query, 'مدیر تست');

    state.selectBlockedBy(null);

    assert.equal(state.tableFilters.blocked_by, null);
    assert.equal(state.blockedBy.query, '');
});

test('blocked-by filter UI uses the searchable selector instead of posting a name as an id', () => {
    assert.match(blockTemplate, /pinova-model="blockedBy\.query"/);
    assert.doesNotMatch(blockTemplate, /pinova-model="tableFilters\.blocked_by"/);
});

test('the add modal renders server validation errors for the selected block type', () => {
    assert.match(blockTemplate, /pinova-text="modals\.add\.data\.blocked_type\.errorMsg"/);
});

test('adding a block sends the selected type and refreshes the current page', async () => {
    const requests = [];
    const { state } = harness(async (route, options) => {
        requests.push({ route, options });
        return {
            success: true,
            message: 'افزوده شد.',
            data: { block_id: 31 },
        };
    });
    let refreshes = 0;
    state.getBlocks = async () => {
        refreshes += 1;
    };
    state.modals.add.data.identifier.value = 'person@example.test';
    state.modals.add.data.blocked_type.value = { key: 'ایمیل', value: 'email' };

    await state.addBlock();

    assert.equal(requests[0].options.data.blocked_type, 'email');
    assert.equal(requests[0].options.data.blocked_until, null);
    assert.equal(refreshes, 1);
    assert.equal(state.modals.add.active, false);
});

test('removing a block refreshes the current filtered page', async () => {
    const { state } = harness(async () => ({
        success: true,
        message: 'حذف شد.',
        data: {},
    }));
    let refreshes = 0;
    state.getBlocks = async () => {
        refreshes += 1;
    };
    state.modals.delete.block = { id: 12, identifier: 'blocked_user' };

    await state.deleteBlock();

    assert.equal(refreshes, 1);
    assert.equal(state.modals.delete.active, false);
});
