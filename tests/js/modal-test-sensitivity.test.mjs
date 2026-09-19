import assert from 'node:assert/strict';
import test from 'node:test';
import { checkSensitivityReport, modalMutations, requireLocalTestOrigin } from '../../tools/check-modal-test-sensitivity.mjs';

function reportFor(mutation, repeats = 1) {
    const failed = Boolean(mutation);
    return {
        errors: [],
        stats: { skipped: 0, flaky: 0, expected: failed ? 0 : repeats, unexpected: failed ? repeats : 0 },
        suites: [{ specs: [{
            title: 'checkout corner controls resist theme CSS after-account in every modal step',
            tests: Array.from({ length: repeats }, () => ({
                expectedStatus: 'passed',
                results: [{
                    retry: 0, status: failed ? 'failed' : 'passed',
                    error: failed ? { message: `${mutation.marker}\n\nexpect(locator).toBeFocused() failed` } : undefined,
                    errors: failed ? [{ message: `Error: ${mutation.marker}\n\nexpect(locator).toBeFocused() failed` }] : [],
                }],
            })),
        }] }],
    };
}

function firstResult(report) { return report.suites[0].specs[0].tests[0].results[0]; }

test('sensitivity audit requires clean controls before and after intentional faults', () => {
    checkSensitivityReport(reportFor(null, 3), 0, null, 3);
    for (const mutation of modalMutations) checkSensitivityReport(reportFor(mutation), 1, mutation);
    checkSensitivityReport(reportFor(null), 0, null);
});

const invalidReports = {
    'healthy code failing': r => { firstResult(r).status = 'failed'; },
    'missing test': r => { r.suites = []; },
    'runner error': r => { r.errors = [{ message: 'browser launch failed' }]; },
    'wrong test selection': r => { r.suites[0].specs[0].title = 'unrelated test'; },
    'skipped case': r => { r.stats.skipped = 1; },
    'pass on retry': r => { r.stats.flaky = 1; firstResult(r).retry = 1; },
    'declared expected failure': r => { r.suites[0].specs[0].tests[0].expectedStatus = 'failed'; },
};
for (const [name, damage] of Object.entries(invalidReports)) {
    test(`sensitivity audit rejects ${name}`, () => {
        const report = reportFor(null);
        damage(report);
        assert.throws(() => checkSensitivityReport(report, 0, null));
    });
}

test('a mutant must fail the intended product assertion, not infrastructure or another contract', () => {
    const mutation = modalMutations[0];
    assert.throws(() => checkSensitivityReport(reportFor(null), 0, mutation));
    for (const message of ['browser launch failed', 'Error: [modal:reverse-trap]\nexpect(locator) failed', `${mutation.marker} setup error`]) {
        const report = reportFor(mutation);
        firstResult(report).error.message = message;
        assert.throws(() => checkSensitivityReport(report, 1, mutation));
    }
    const timeout = reportFor(mutation);
    firstResult(timeout).status = 'timedOut';
    assert.throws(() => checkSensitivityReport(timeout, 1, mutation));
    assert.throws(() => checkSensitivityReport(reportFor(mutation), 2, mutation));
});

test('mutation runs cannot target a public or production origin', () => {
    assert.equal(requireLocalTestOrigin('http://localhost:8890'), 'http://localhost:8890');
    assert.equal(requireLocalTestOrigin('http://127.0.0.1:8890'), 'http://127.0.0.1:8890');
    for (const origin of ['https://example.com', 'http://localhost.example.com', 'file:///tmp/test']) {
        assert.throws(() => requireLocalTestOrigin(origin));
    }
});


test('a marker in an adjacent source snippet cannot certify a different failure', () => {
    const mutation = modalMutations[0];
    const report = reportFor(mutation);
    firstResult(report).error.message = '[modal:other-contract] unexpected failure\n\nexpect(locator) failed';
    firstResult(report).errors[0].message += `\nSource snippet: expect(heading, '${mutation.marker}').toBeFocused()`;
    assert.throws(() => checkSensitivityReport(report, 1, mutation));
    delete firstResult(report).error;
    assert.throws(() => checkSensitivityReport(report, 1, mutation));
});
