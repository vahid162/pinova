<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(2); }
require getenv('CARBON_VENDOR') . '/autoload.php';
$cases = ['now', 'string', 'immutable_input', 'timestamp', 'mixed_immutable', 'mixed_illuminate'];
$case = $argv[1] ?? '';
if (!in_array($case, $cases, true)) { exit(2); }
$checks = ['class_exact' => true, 'instant_expected' => true, 'utc_offset_zero' => true];
for ($i = 0; $i < 1000; $i++) {
    $class = $case === 'mixed_immutable' && $i % 2 ? Carbon\CarbonImmutable::class
        : ($case === 'mixed_illuminate' && $i % 2 ? Illuminate\Support\Carbon::class : Carbon\Carbon::class);
    if ($case === 'now') {
        $before = time(); $value = $class::now('UTC'); $after = time();
        $instant = $value->getTimestamp() >= $before && $value->getTimestamp() <= $after;
    } elseif ($case === 'immutable_input') {
        $value = new $class(new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC')));
        $instant = $value->getTimestamp() === 1704067200;
    } elseif ($case === 'timestamp') {
        $value = $class::createFromTimestampUTC(1704067200);
        $instant = $value->getTimestamp() === 1704067200;
    } else {
        $value = new $class('2024-01-01 00:00:00', 'UTC');
        $instant = $value->getTimestamp() === 1704067200;
    }
    foreach (['class_exact' => get_class($value) === $class, 'instant_expected' => $instant,
        'utc_offset_zero' => $value->getOffset() === 0] as $key => $value_ok) {
        $checks[$key] = $value_ok && $checks[$key];
    }
}
$keys = ['opcache.enable', 'opcache.enable_cli', 'opcache.optimization_level', 'opcache.jit', 'opcache.jit_buffer_size',
    'opcache.protect_memory', 'opcache.validate_timestamps', 'opcache.file_update_protection', 'opcache.revalidate_freq'];
$settings = [];
foreach ($keys as $key) { $settings[$key] = (string) ini_get($key); }
$status = opcache_get_status(false);
$jit = is_array($status) && isset($status['jit']) ? $status['jit'] : [];
echo json_encode(['case' => $case, 'ok' => !in_array(false, $checks, true), 'checks' => $checks, 'iterations' => 1000, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
    'settings' => $settings, 'jit_enabled' => ($jit['enabled'] ?? false) === true,
    'jit_on' => ($jit['on'] ?? false) === true, 'buffer_size' => $jit['buffer_size'] ?? null], JSON_THROW_ON_ERROR);
