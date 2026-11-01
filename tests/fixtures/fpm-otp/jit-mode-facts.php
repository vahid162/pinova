<?php
/** Finite ordinary OPcache facts only; never returns script inventories or addresses. */
function pinova_fixture_jit_facts(array $expected, array $actual, $status, bool $zts, bool $debug): array {
    $ini = [];
    foreach ($expected as $key => $literal) {
        $value = $actual[$key] ?? false;
        $ini[$key] = ['type' => gettype($value), 'value' => $value === $literal ? $value : null];
    }
    $raw = is_array($status) && is_array($status['jit'] ?? null) ? $status['jit'] : [];
    $jit = [];
    foreach (['enabled', 'on'] as $key) $jit[$key] = is_bool($raw[$key] ?? null) ? $raw[$key] : null;
    foreach (['kind', 'opt_level', 'opt_flags', 'buffer_size', 'buffer_free'] as $key)
        $jit[$key] = is_int($raw[$key] ?? null) && $raw[$key] >= 0 && $raw[$key] <= 1073741824 ? $raw[$key] : null;
    return ['nts' => !$zts, 'debug' => $debug, 'ini' => $ini, 'jit' => $jit];
}
