/** Finite diagnostic tags only; never serializes error messages or response data. */
export function fixtureHTTPError(reason) {
    if (!['timeout', 'body_bound'].includes(reason)) throw new Error('Invalid fixture HTTP error tag.');
    const error = new Error('Fixture HTTP transport bound reached.');
    error.code = reason === 'timeout' ? 'PINOVA_FIXTURE_TIMEOUT' : 'PINOVA_FIXTURE_BODY_BOUND';
    return error;
}

export function classifyHTTPFailure(error, stage = 'request') {
    switch (error?.code) {
        case 'PINOVA_FIXTURE_TIMEOUT': case 'ETIMEDOUT': return 'timeout';
        case 'PINOVA_FIXTURE_BODY_BOUND': return 'body_bound';
        case 'ECONNREFUSED': return 'connection_refused';
        case 'ECONNRESET': return 'connection_reset';
        case 'EPIPE': return 'broken_pipe';
        case 'ERR_STREAM_PREMATURE_CLOSE': return 'premature_close';
        case 'HPE_HEADER_OVERFLOW': return 'header_limit';
        case 'HPE_INVALID_HEADER_TOKEN': return 'invalid_header';
        default: return stage === 'response' ? 'response_error' : 'transport_failure';
    }
}

/** Actual request/response event wiring shared by the controller and pure event controls. */
export function observeHTTPFailures(outgoing, { phase, category, record, reject }) {
    let recorded = false;
    const fail = (error, reason) => {
        if (recorded) return;
        recorded = true;
        record({ phase, category, status: null, failure: reason });
        reject(error);
    };
    const bound = reason => {
        const error = fixtureHTTPError(reason);
        // A response abort may fire synchronously from destroy; retain our known cause first.
        fail(error, reason);
        outgoing.destroy(error);
    };
    outgoing.on('timeout', () => bound('timeout'));
    outgoing.on('error', error => fail(error, classifyHTTPFailure(error)));
    return { bound, watchIncoming: incoming => {
        incoming.on('aborted', () => fail(new Error('Fixture HTTP response aborted.'), 'response_aborted'));
        incoming.on('error', error => fail(error, classifyHTTPFailure(error, 'response')));
    } };
}
