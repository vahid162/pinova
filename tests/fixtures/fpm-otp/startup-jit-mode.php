<?php
declare(strict_types=1);

if ('creator-prefix-v1' === getenv('PINOVA_FPM_ACTIVE_JIT_PROOF')) {
    // Standalone post-primary proof endpoint; never include/autoload/prewarm application code.
    try {
        if ('fpm-fcgi' !== PHP_SAPI || '1' !== getenv('PINOVA_FPM_FULL_STACK_FIXTURE')) {
            throw new RuntimeException();
        }
        $root = getenv('PINOVA_FPM_FULL_STACK_ROOT');
        if (!is_string($root) || !preg_match('~^/tmp/pinova-fpm-full-[0-9]+-[0-9]+$~D', $root) || realpath($root) !== $root) {
            throw new RuntimeException();
        }
        $creator = $root . '/runtime/vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';
        $blacklist = $root . '/startup-opcache-blacklist.txt';
        if (realpath($creator) !== $creator || !is_file($creator) || filesize($creator) > 131072 ||
            realpath($blacklist) !== $blacklist || !is_file($blacklist) || filesize($blacklist) > 1024 ||
            ini_get('opcache.blacklist_filename') !== $blacklist) {
            throw new RuntimeException();
        }
        $contents = file_get_contents($blacklist);
        if ($creator . "\n" !== $contents) {
            throw new RuntimeException();
        }
        $variant = '' === $contents ? 'A' : 'B';
        $inventory = $root . '/runtime/vendor/composer/installed.json';
        $lock = $root . '/runtime/composer.lock';
        foreach ([$inventory, $lock] as $file) {
            if (realpath($file) !== $file || !is_file($file) || filesize($file) > 4194304) {
                throw new RuntimeException();
            }
        }
        $status = opcache_get_status(false);
        $jit = is_array($status) ? ($status['jit'] ?? null) : null;
        if (!is_array($jit)) {
            throw new RuntimeException();
        }
        echo json_encode([
            'schema' => 'pinova.postwork-active-jit.v1', 'state' => 'observed',
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
                'file_cache_empty' => '' === ini_get('opcache.file_cache'),
                'preload_empty' => '' === ini_get('opcache.preload'),
            ],
            'jit' => [
                'enabled' => $jit['enabled'] ?? null, 'on' => $jit['on'] ?? null, 'kind' => $jit['kind'] ?? null,
                'capacity_bytes' => $jit['buffer_size'] ?? null, 'free_bytes' => $jit['buffer_free'] ?? null,
            ],
            'blacklist' => [
                'variant' => $variant,
                'file_sha256' => hash('sha256', $contents),
                'file_bytes' => strlen($contents),
                'configured_path_sha256' => hash('sha256', (string) ini_get('opcache.blacklist_filename')),
                'creator_path_sha256' => hash('sha256', $creator),
                'creator_source_sha256' => hash_file('sha256', $creator),
                'configured_path_matches_owned_file' => true,
                'entry_count' => 'B' === $variant ? 1 : 0,
                'entry_semantics' => 'absolute_resolved_creator_prefix',
            ],
            // This checks cache membership without loading the file or resolving a class.
            'inventory_sha256' => hash_file('sha256', $inventory),
            'composer_lock_sha256' => hash_file('sha256', $lock),
            'cache' => [ 'creator_cached' => opcache_is_script_cached($creator) ],
        ], JSON_THROW_ON_ERROR);
    } catch (Throwable $ignored) {
        echo '{"schema":"pinova.postwork-active-jit.v1","state":"unknown","reason":"native_mode_unavailable"}';
    }
    return;
}

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
