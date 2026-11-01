import assert from 'node:assert/strict';
import test from 'node:test';
import { revisionWorkload } from '../Integration/otp-fpm-revision-workload.mjs';

/** Coordination only: native queue/provider behavior has separate FPM coverage. */
async function exercise({ deferWarmup = false, deferMeasurement = false } = {}) {
    const previousProfile = process.env.PINOVA_FPM_LOGGING_PROFILE;
    process.env.PINOVA_FPM_LOGGING_PROFILE = 'normal';
    const records = [];
    const pending = new Map();
    const invocations = [];
    const verified = [];
    const receipt = { limits: { fpm_children: 10 } };
    let ordinaryCount = 0;
    const accept = flow => {
        assert.equal(records.filter(record => record.case === flow.label).length, 0,
            'A controlled worker opportunity must not resend an accepted flow.');
        records.push({ case: flow.label, accepted: 1 });
    };
    const context = {
        root: '/synthetic-fixture', records, receipt,
        ensure: (value, message) => assert.ok(value, message),
        writeFileSync: (file, value) => { assert.equal(file, '/synthetic-fixture/mode'); assert.equal(value, 'on\n'); },
        headroom: async () => {}, quiescent: async () => {}, setPhase: () => {},
        issue: async (index, label) => {
            const flow = { index, label };
            const deferred = label.startsWith('revision-warm-') ? deferWarmup : deferMeasurement;
            if (deferred) pending.set(label, flow); else accept(flow);
            return flow;
        },
        ordinary: async (_index, label) => {
            if (label === 'revision-normal-page') ordinaryCount++;
            return { status: 200, ms: 1 };
        },
        until: async (predicate, message) => {
            assert.ok(await predicate(),
                `${message} A valid fallback must receive its registered worker opportunity before acceptance is awaited.`);
        },
        runWorker: async label => {
            invocations.push(label);
            // Exactly one predetermined opportunity handles the currently
            // acknowledged flow; immediate success leaves that opportunity empty.
            for (const flow of pending.values()) accept(flow);
            pending.clear();
        },
        verifyFlow: async flow => {
            assert.ok(records.some(record => record.case === flow.label && record.accepted !== null));
            verified.push(flow);
        },
        processSample: () => Array.from({ length: 10 }, (_, index) => ({ pid: index + 100 })),
        events: () => verified.filter(flow => flow.label.startsWith('revision-warm-')).map(flow => ({
            event: 'request_start', case: `${flow.label}-verify`, pid: flow.index - 41 + 100,
        })),
        site: async () => ({ logging: { minimum_level: 'warning', warning_enabled: true,
            diagnostic_window_active: false, diagnostic_mode: 'off' }, log_storage: { rows: 0, context_bytes: 0 } }),
        http: async () => ({ text: '{"active processes":0}' }), base: 'http://127.0.0.1:18080',
        beginMeasure: () => {},
        endMeasure: async () => ({ measured_runner_invocations: invocations.filter(label => label.startsWith('revision-normal-worker-')).length }),
        inspect: async () => ({ rows: 1, purpose: 'login', otp_expiry: 180, expiry: 180, accepted_channels: ['sms'] }),
    };
    try {
        await revisionWorkload(context);
        assert.equal(invocations.length, 20);
        assert.equal(invocations.filter(label => label.startsWith('revision-normal-worker-')).length, 10);
        assert.equal(ordinaryCount, 100);
        assert.equal(records.length, 20);
        assert.equal(new Set(records.map(record => record.case)).size, 20);
        assert.equal(verified.length, 20);
        assert.equal(pending.size, 0);
        assert.equal(receipt.revision_arm.completed_stub_flows, 10);
    } finally {
        if (previousProfile === undefined) delete process.env.PINOVA_FPM_LOGGING_PROFILE;
        else process.env.PINOVA_FPM_LOGGING_PROFILE = previousProfile;
    }
}

test('revision warmup gives legitimate queued fallback its predetermined runner opportunity', async () => {
    await exercise({ deferWarmup: true });
});
test('revision measurement completes queued fallback with the same ten runner opportunities', async () => {
    await exercise({ deferMeasurement: true });
});
test('already accepted fast flows retain exactly one empty runner opportunity without resend', async () => {
    await exercise();
});
