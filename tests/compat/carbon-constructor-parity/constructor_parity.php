<?php
// Future isolated semantic parity only. Run baseline and candidate in separate CLI processes.
// This fixture does not exercise or establish the active-JIT crash fix.
declare(strict_types=1);

function parityRequire(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('parity_guard_failed');
    }
}

function parityDate(DateTimeInterface $date): array
{
    return ['class' => get_class($date), 'local' => $date->format('Y-m-d H:i:s.u'),
        'unix_microseconds' => $date->format('U.u'), 'offset_seconds' => $date->getOffset(),
        'timezone' => $date->getTimezone()->getName()];
}

function parityLastErrors(string $class): array
{
    $value = $class::getLastErrors();
    parityRequire(is_array($value));
    $result = [];
    foreach (['warning_count', 'error_count'] as $key) {
        parityRequire(isset($value[$key]) && is_int($value[$key]) && $value[$key] >= 0 && $value[$key] <= 32);
        $result[$key] = $value[$key];
    }
    foreach (['warnings', 'errors'] as $key) {
        parityRequire(isset($value[$key]) && is_array($value[$key]) && count($value[$key]) <= 32);
        $result[$key] = [];
        foreach ($value[$key] as $offset => $message) {
            parityRequire(is_int($offset) && is_string($message) && strlen($message) <= 1024);
            $result[$key][] = ['offset' => $offset, 'message' => $message];
        }
    }
    return $result;
}

function parityException(Throwable $error, string $runRoot): array
{
    $chain = [];
    do {
        parityRequire(count($chain) < 4 && strlen($error->getMessage()) <= 4096);
        // All tested inputs are fixed synthetic fixture values. Paths/traces are never fields.
        $message = str_replace($runRoot, '<fixture>', $error->getMessage());
        parityRequire(strpos($message, "\0") === false);
        $chain[] = ['class' => get_class($error), 'message' => $message, 'code' => $error->getCode()];
        $error = $error->getPrevious();
    } while ($error !== null);
    return $chain;
}

function paritySubclasses(): void
{
    // Conditional declarations execute only after the selected vendor autoloader is verified.
    class PinovaParityMutableChild extends \Carbon\Carbon
    {
        public $parentCalls = 0;
        public function __construct($time = null, $tz = null)
        {
            $this->parentCalls++;
            parent::__construct($time, $tz);
        }
        public function initializedObjectIdMatches(): bool
        {
            return $this->constructedObjectId === spl_object_hash($this);
        }
    }
    class PinovaParityImmutableChild extends \Carbon\CarbonImmutable
    {
        public $parentCalls = 0;
        public function __construct($time = null, $tz = null)
        {
            $this->parentCalls++;
            parent::__construct($time, $tz);
        }
        public function initializedObjectIdMatches(): bool
        {
            return $this->constructedObjectId === spl_object_hash($this);
        }
    }
}

function parityCases(): array
{
    return [
        'default_now', 'null_now', 'empty_now', 'literal_now', 'relative_tomorrow',
        'explicit_utc', 'iran_historical_dst', 'iran_current', 'paris_spring_before', 'paris_spring_gap',
        'paris_autumn_ambiguous', 'timezone_object', 'timezone_offset', 'timezone_integer', 'microseconds',
        'native_mutable_input', 'native_immutable_input', 'native_timezone_override',
        'timestamp_zero', 'timestamp_negative', 'timestamp_positive', 'timestamp_fraction',
        'compact_date', 'compact_datetime', 'warning_date', 'invalid_date', 'invalid_timezone',
        'invalid_native_timezone', 'subclass_parent_constructor', 'subclass_numeric_late_static',
        'clone_and_mutability', 'serialization_roundtrip',
    ];
}

function parityCase(string $case, string $class, string $child): array
{
    $input = null;
    switch ($case) {
        case 'default_now': $date = new $class(); break;
        case 'null_now': $date = new $class(null, 'UTC'); break;
        case 'empty_now': $date = new $class('', 'UTC'); break;
        case 'literal_now': $date = new $class('now', 'UTC'); break;
        case 'relative_tomorrow': $date = new $class('tomorrow', 'UTC'); break;
        case 'explicit_utc': $date = new $class('2024-06-15 12:34:56', 'UTC'); break;
        case 'iran_historical_dst': $date = new $class('2021-07-01 12:34:56', 'Asia/Tehran'); break;
        case 'iran_current': $date = new $class('2024-07-01 12:34:56', 'Asia/Tehran'); break;
        case 'paris_spring_before': $date = new $class('2024-03-31 01:59:59.999999', 'Europe/Paris'); break;
        case 'paris_spring_gap': $date = new $class('2024-03-31 02:30:00', 'Europe/Paris'); break;
        case 'paris_autumn_ambiguous': $date = new $class('2024-10-27 02:30:00', 'Europe/Paris'); break;
        case 'timezone_object': $date = new $class('2024-06-15 12:34:56', new DateTimeZone('Asia/Tehran')); break;
        case 'timezone_offset': $date = new $class('2024-06-15 12:34:56', '+03:30'); break;
        case 'timezone_integer': $date = new $class('2024-06-15 12:34:56', 3); break;
        case 'microseconds': $date = new $class('2024-06-15 12:34:56.123456', 'UTC'); break;
        case 'native_mutable_input':
            $input = new DateTime('2024-06-15 12:34:56.123456', new DateTimeZone('Asia/Tokyo'));
            $before = parityDate($input); $date = new $class($input); break;
        case 'native_immutable_input':
            $input = new DateTimeImmutable('2024-06-15 12:34:56.123456', new DateTimeZone('Asia/Tokyo'));
            $before = parityDate($input); $date = new $class($input); break;
        case 'native_timezone_override':
            $input = new DateTime('2024-06-15 12:34:56.123456', new DateTimeZone('Asia/Tokyo'));
            $before = parityDate($input); $date = new $class($input, 'Europe/Paris'); break;
        case 'timestamp_zero': $date = new $class(0, 'UTC'); break;
        case 'timestamp_negative': $date = new $class(-123456789, 'UTC'); break;
        case 'timestamp_positive': $date = new $class(1600000000, 'UTC'); break;
        case 'timestamp_fraction': $date = new $class(1600000000.125, 'UTC'); break;
        case 'compact_date': $date = new $class('20240615', 'UTC'); break;
        case 'compact_datetime': $date = new $class('20240615123456', 'UTC'); break;
        case 'warning_date': $date = new $class('2024-02-30 12:34:56', 'UTC'); break;
        case 'invalid_date': $date = new $class('PINOVA_SYNTHETIC_INVALID_DATE', 'UTC'); break;
        case 'invalid_timezone': $date = new $class('2024-06-15 12:34:56', 'Pinova/Synthetic_Invalid'); break;
        case 'invalid_native_timezone':
            $date = new $class(new DateTimeImmutable('2024-06-15', new DateTimeZone('UTC')), 'Pinova/Synthetic_Invalid'); break;
        case 'subclass_parent_constructor':
        case 'subclass_numeric_late_static':
            $date = new $child($case === 'subclass_numeric_late_static' ? 1600000000.125 : '2024-06-15 12:34:56.123456', 'UTC');
            $clone = clone $date;
            return ['date' => parityDate($date), 'clone' => parityDate($clone), 'parent_calls' => $date->parentCalls,
                'object_id_initialized' => $date->initializedObjectIdMatches(), 'clone_id_initialized' => $clone->initializedObjectIdMatches(),
                'distinct_objects' => $date !== $clone, 'last_errors' => parityLastErrors($class)];
        case 'clone_and_mutability':
            $date = new $class('2024-06-15 12:34:56.123456', 'UTC');
            $clone = clone $date; $changed = $clone->addDay();
            return ['original' => parityDate($date), 'clone' => parityDate($clone), 'changed' => parityDate($changed),
                'changed_is_clone' => $changed === $clone, 'original_is_clone' => $date === $clone,
                'last_errors' => parityLastErrors($class)];
        case 'serialization_roundtrip':
            $date = new $class('2024-06-15 12:34:56.123456', 'Asia/Tehran');
            $restored = unserialize(serialize($date), ['allowed_classes' => [$class]]);
            parityRequire($restored instanceof DateTimeInterface);
            return ['original' => parityDate($date), 'restored' => parityDate($restored),
                'distinct_objects' => $date !== $restored, 'last_errors' => parityLastErrors($class)];
        default: throw new RuntimeException('unknown_case');
    }
    $result = ['date' => parityDate($date), 'last_errors' => parityLastErrors($class)];
    if ($input !== null) {
        $result['native_input_before'] = $before;
        $result['native_input_after'] = parityDate($input);
        $result['native_input_unchanged'] = $before === $result['native_input_after'];
        parityRequire($result['native_input_unchanged']);
    }
    return $result;
}

$phase = 'setup';
$activeCase = null;
$activeClass = null;
try {
    parityRequire(PHP_SAPI === 'cli' && $argc === 2 && in_array($argv[1], ['baseline', 'candidate'], true));
    $variant = $argv[1];
    $run = getenv('GITHUB_RUN_ID'); $attempt = getenv('GITHUB_RUN_ATTEMPT');
    parityRequire(getenv('GITHUB_ACTIONS') === 'true' && getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
        && is_string($run) && ctype_digit($run) && $attempt === '1');
    $root = '/tmp/pinova-carbon-parity-' . $run . '-' . $attempt;
    parityRequire(getenv('PINOVA_CARBON_PARITY_ROOT') === $root && realpath($root) === $root);
    $vendor = $root . '/' . $variant . '/vendor';
    parityRequire(realpath($vendor) === $vendor && is_file($vendor . '/autoload.php'));
    $inventoryPath = $vendor . '/composer/installed.json';
    parityRequire(realpath($inventoryPath) === $inventoryPath && is_file($inventoryPath) && filesize($inventoryPath) <= 1048576);
    $inventoryBytes = file_get_contents($inventoryPath);
    $inventory = json_decode($inventoryBytes, true, 32, JSON_THROW_ON_ERROR);
    parityRequire(isset($inventory['packages']) && is_array($inventory['packages']));
    $carbonPackages = array_values(array_filter($inventory['packages'], static function (array $package): bool {
        return ($package['name'] ?? null) === 'nesbot/carbon';
    }));
    parityRequire(count($carbonPackages) === 1 && $carbonPackages[0]['version'] === '2.73.0'
        && $carbonPackages[0]['source']['reference'] === '9228ce90e1035ff2f0db84b40ec2e023ed802075');
    $contractBytes = file_get_contents(__DIR__ . '/candidate-contract.json');
    $contract = json_decode($contractBytes, true, 16, JSON_THROW_ON_ERROR);
    $parityBytes = file_get_contents(__DIR__ . '/parity-contract.json');
    $parity = json_decode($parityBytes, true, 16, JSON_THROW_ON_ERROR);
    parityRequire($parity['cases'] === parityCases() && $parity['source_reference'] === $contract['source_reference']);
    $carbonRoot = $vendor . '/nesbot/carbon';
    $pins = $contract[($variant === 'baseline' ? 'upstream' : 'candidate') . '_sha256'];
    foreach ($pins as $file => $hash) {
        $path = $carbonRoot . '/' . $file;
        parityRequire(realpath($path) === $path && is_file($path) && filesize($path) <= 131072
            && hash_file('sha256', $path) === $hash);
    }
    parityRequire(!class_exists('Carbon\\Carbon', false) && !class_exists('Carbon\\CarbonImmutable', false));
    if (!extension_loaded('Zend OPcache')) {
        $jitEvidence = 'extension_absent';
    } elseif (ini_get('opcache.enable_cli') === '0') {
        $jitEvidence = 'CLI_OPcache_disabled';
    } else {
        $jit = opcache_get_status(false);
        parityRequire(is_array($jit) && isset($jit['jit']) && is_array($jit['jit'])
            && array_key_exists('on', $jit['jit']) && $jit['jit']['on'] === false);
        $jitEvidence = 'native_status_inactive';
    }
    parityRequire(date_default_timezone_set('UTC') && setlocale(LC_NUMERIC, 'C') === 'C');
    require $vendor . '/autoload.php';
    $loaded = [];
    foreach (['Carbon', 'CarbonImmutable'] as $name) {
        $class = 'Carbon\\' . $name;
        parityRequire(class_exists($class));
        $reflection = new ReflectionClass($class);
        $method = $reflection->getConstructor();
        $expectedMethod = $variant === 'baseline' ? '/src/Carbon/Traits/Creator.php' : '/src/Carbon/' . $name . '.php';
        parityRequire(realpath($reflection->getFileName()) === $carbonRoot . '/src/Carbon/' . $name . '.php'
            && realpath($method->getFileName()) === $carbonRoot . $expectedMethod
            && $method->getDeclaringClass()->getName() === $class
            && $method->isPublic() && !$method->isStatic() && $method->getNumberOfParameters() === 2
            && $method->getNumberOfRequiredParameters() === 0);
        $loaded[$class] = ['class_source_sha256' => $pins['src/Carbon/' . $name . '.php'],
            'constructor_origin' => $variant === 'baseline' ? 'Creator_trait_source' : 'concrete_class_source',
            'exact_expected_source_loaded' => true];
    }
    paritySubclasses();
    $phase = 'cases';
    $rows = [];
    foreach (['Carbon\\Carbon' => 'PinovaParityMutableChild', 'Carbon\\CarbonImmutable' => 'PinovaParityImmutableChild'] as $class => $child) {
        foreach (parityCases() as $case) {
            $activeCase = $case;
            $activeClass = $class;
            $class::setTestNow(new DateTimeImmutable('2024-06-15 10:11:12.123456', new DateTimeZone('UTC')));
            // Normalize lastErrors before every case, including error-path observations.
            new $class('2024-06-15 00:00:00', 'UTC');
            $row = ['class' => $class, 'case' => $case];
            $expectedException = in_array($case, ['invalid_date', 'invalid_timezone', 'invalid_native_timezone'], true);
            try {
                $row['value'] = parityCase($case, $class, $child);
                $row['outcome'] = 'value';
            } catch (Throwable $error) {
                $row['exception_chain'] = parityException($error, $root);
                $row['last_errors'] = parityLastErrors($class);
                $row['outcome'] = 'exception';
            } finally {
                $class::setTestNow();
            }
            parityRequire(($row['outcome'] === 'exception') === $expectedException);
            if ($expectedException) {
                $expected = $case === 'invalid_native_timezone' ? 'Carbon\\Exceptions\\InvalidTimeZoneException' : 'Carbon\\Exceptions\\InvalidFormatException';
                parityRequire($row['exception_chain'][0]['class'] === $expected);
            }
            parityRequire(date_default_timezone_get() === 'UTC' && setlocale(LC_NUMERIC, '0') === 'C');
            $rows[] = $row;
        }
    }
    $binary = realpath('/proc/self/exe');
    $phase = 'receipt';
    parityRequire(is_string($binary) && is_file($binary) && filesize($binary) <= 67108864);
    $result = ['schema' => 'pinova.carbon-constructor-parity.v1', 'state' => 'complete', 'variant' => $variant,
        'source_contract_sha256' => hash('sha256', $contractBytes), 'parity_contract_sha256' => hash('sha256', $parityBytes),
        'pid' => getmypid(), 'php_version' => PHP_VERSION, 'php_binary_sha256' => hash_file('sha256', $binary),
        'timezone_db_version' => timezone_version_get(), 'numeric_locale' => 'C', 'default_timezone' => 'UTC',
        'JIT_active' => false, 'JIT_evidence' => $jitEvidence,
        'normalized_inventory_sha256' => hash('sha256', $inventoryBytes),
        'loaded_sources' => $loaded, 'cases' => $rows,
        'shipping_fix' => false, 'active_JIT_fix_proven' => false, 'resource_acceptance' => false];
    $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    parityRequire(strlen($json) <= 131072);
    echo $json . "\n";
} catch (Throwable $error) {
    // Setup/invariant failures never export exception messages, paths or traces.
    echo json_encode(['schema' => 'pinova.carbon-constructor-parity.v1', 'state' => 'failed',
        'reason' => 'fixture_or_semantic_guard_failed', 'phase' => $phase,
        'case' => $activeCase, 'class' => $activeClass, 'shipping_fix' => false], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
}
