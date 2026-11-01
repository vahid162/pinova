/** Finite failure evidence for the disposable integration cleanup; import performs no I/O. */
const steps = new Set([
    'cleanup_start', 'primary_user_lookup', 'outbound_filters', 'primary_pending_erase',
    'primary_otp_delete', 'user_api_load', 'primary_user_delete', 'signup_user_lookup',
    'signup_user_delete', 'signup_queue_delete', 'saved_options_read', 'integrations_restore',
    'forum_authorization_restore', 'saved_options_delete', 'delivery_hook_unschedule',
    'vendor_page_delete', 'vendor_option_delete', 'widget_page_delete', 'widget_option_delete',
    'cleanup_complete',
]);
const prefix = 'PINOVA_BROWSER_CLEANUP_STEP ';
const signals = new Set(['SIGHUP', 'SIGINT', 'SIGQUIT', 'SIGILL', 'SIGTRAP', 'SIGABRT', 'SIGBUS',
    'SIGFPE', 'SIGKILL', 'SIGSEGV', 'SIGPIPE', 'SIGALRM', 'SIGTERM', 'SIGCHLD', 'SIGCONT',
    'SIGSTOP', 'SIGTSTP', 'SIGSYS']);
const codes = new Set(['ETIMEDOUT', 'ENOBUFS', 'ENOENT', 'EACCES', 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER']);

export function cleanupMarker(step) {
    if (!steps.has(step)) throw new Error('Unknown integration cleanup step');
    return 'fwrite(STDOUT, "\\n' + prefix + step + '\\n"); fflush(STDOUT);';
}

function output(value) {
    if (typeof value !== 'string' && !Buffer.isBuffer(value)) return { bytes: null, tail: '', truncated: false };
    const bytes = Buffer.isBuffer(value) ? value : Buffer.from(value);
    const truncated = bytes.length > 65536;
    let tail = bytes.subarray(Math.max(0, bytes.length - 65536)).toString('utf8');
    if (truncated) tail = tail.includes('\n') ? tail.slice(tail.indexOf('\n') + 1) : '';
    return { bytes: bytes.length <= 16777216 ? bytes.length : null, tail, truncated };
}

export function cleanupFailureEvidence(error) {
    const status = Number.isSafeInteger(error?.status) && error.status >= 0 && error.status <= 255 ? error.status : null;
    const signal = error?.signal == null ? null : signals.has(error.signal) ? error.signal : 'unknown';
    const code = error?.code == null ? null : codes.has(error.code) ? error.code : 'unknown';
    const stdout = output(error?.stdout);
    const stderr = output(error?.stderr);
    let step = 'unknown';
    for (const line of stdout.tail.split(/\r?\n/)) {
        if (!line.startsWith(prefix)) continue;
        const candidate = line.slice(prefix.length);
        step = steps.has(candidate) ? candidate : 'unknown';
    }
    const classification = code === 'ETIMEDOUT' ? 'timeout' :
        ['ENOBUFS', 'ERR_CHILD_PROCESS_STDIO_MAXBUFFER'].includes(code) ? 'output_limit' :
            ['ENOENT', 'EACCES'].includes(code) ? 'spawn_failure' :
                status !== null && status !== 0 ? 'nonzero_exit' :
                    signal !== null && signal !== 'unknown' ? 'signal_exit' : 'unknown';
    return { classification, exit_status: status, signal, error_code: code,
        stdout_bytes: stdout.bytes, stderr_bytes: stderr.bytes,
        stdout_tail_truncated: stdout.truncated, last_step: step };
}
