<?php
declare(strict_types=1);

// Standalone fixture endpoint; never include WordPress or read request payloads.
try {
    if ('fpm-fcgi' !== PHP_SAPI || '1' !== getenv('PINOVA_FPM_FULL_STACK_FIXTURE')) {
        throw new RuntimeException();
    }
    $status = opcache_get_status(false);
    $jit = is_array($status) ? ($status['jit'] ?? null) : null;
    if (!is_array($jit)) {
        throw new RuntimeException();
    }
    echo json_encode([
        'schema' => 'pinova.postwork-jit-mode.v1', 'state' => 'observed',
        'pid' => getmypid(), 'sapi' => PHP_SAPI, 'php_version' => PHP_VERSION,
        'opcache_enabled' => $status['opcache_enabled'] ?? null,
        'ini' => [
            'mode' => ini_get('opcache.jit'),
            'buffer_bytes' => ini_parse_quantity((string) ini_get('opcache.jit_buffer_size')),
            'enable_cli' => (bool) ini_get('opcache.enable_cli'),
            'optimization_level' => intval((string) ini_get('opcache.optimization_level'), 0),
            'hot_loop' => (int) ini_get('opcache.jit_hot_loop'),
            'hot_func' => (int) ini_get('opcache.jit_hot_func'),
            'hot_return' => (int) ini_get('opcache.jit_hot_return'),
            'hot_side_exit' => (int) ini_get('opcache.jit_hot_side_exit'),
        ],
        'jit' => [
            'enabled' => $jit['enabled'] ?? null, 'on' => $jit['on'] ?? null, 'kind' => $jit['kind'] ?? null,
            'capacity_bytes' => $jit['buffer_size'] ?? null, 'free_bytes' => $jit['buffer_free'] ?? null,
        ],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $ignored) {
    echo '{"schema":"pinova.postwork-jit-mode.v1","state":"unknown","reason":"native_mode_unavailable"}';
}
