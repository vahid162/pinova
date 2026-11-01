<?php
// Source-only validation for future isolated CI; no application autoload or native workload.
declare(strict_types=1);

function requireSource(bool $value): void
{
    if (!$value) {
        throw new RuntimeException('source_guard_failed');
    }
}

function sourceBytes(string $root, string $file): string
{
    $path = $root . '/' . $file;
    requireSource(!is_link($path) && is_file($path) && filesize($path) <= 131072);
    $value = file_get_contents($path);
    requireSource(is_string($value) && strlen($value) <= 131072);
    return $value;
}

function constructorTokens(string $source, int $kind, string $expected): array
{
    $tokens = token_get_all($source, TOKEN_PARSE);
    $depth = 0;
    $ownerDepth = null;
    $ownerPending = false;
    $ownerSeen = 0;
    $methods = [];
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_DECLARE) {
            throw new RuntimeException('unexpected_declare');
        }
        if (is_array($token) && $token[0] === $kind) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous])
                && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous--;
            }
            if ($kind === T_CLASS && $previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_DOUBLE_COLON) {
                continue;
            }
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            requireSource(isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && $tokens[$j][1] === $expected && $ownerSeen === 0);
            $ownerPending = true;
            $ownerSeen++;
        }
        if ($token === '{') {
            $depth++;
            if ($ownerPending) {
                $ownerDepth = $depth;
                $ownerPending = false;
            }
        } elseif ($token === '}') {
            $depth--;
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION || $depth !== $ownerDepth) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][1] !== '__construct') {
            continue;
        }
        $normalized = [];
        $body = 0;
        $started = false;
        for ($k = $i; $k < $count; $k++) {
            $part = $tokens[$k];
            if ($part === '{') {
                $started = true;
                $body++;
            } elseif ($part === '}') {
                $body--;
            }
            if (!is_array($part) || !in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $normalized[] = is_array($part) ? [$part[0], $part[1]] : $part;
            }
            if ($started && $body === 0) {
                break;
            }
        }
        requireSource($started && $body === 0);
        $methods[] = $normalized;
    }
    requireSource($ownerSeen === 1 && count($methods) === 1);
    return $methods[0];
}

try {
    requireSource(PHP_SAPI === 'cli' && $argc === 3);
    $contract = json_decode(file_get_contents(__DIR__ . '/candidate-contract.json'), true, 16, JSON_THROW_ON_ERROR);
    $upstream = realpath($argv[1]);
    $candidate = realpath($argv[2]);
    requireSource(is_string($upstream) && is_string($candidate) && $upstream !== $candidate);
    $source = [];
    foreach (['upstream' => $upstream, 'candidate' => $candidate] as $variant => $root) {
        foreach ($contract[$variant . '_sha256'] as $file => $expected) {
            $bytes = sourceBytes($root, $file);
            requireSource(hash('sha256', $bytes) === $expected);
            $source[$variant][$file] = $bytes;
        }
    }
    requireSource($source['upstream']['src/Carbon/Traits/Creator.php'] === $source['candidate']['src/Carbon/Traits/Creator.php']);
    requireSource($source['upstream']['LICENSE'] === $source['candidate']['LICENSE']);
    $trait = constructorTokens($source['upstream']['src/Carbon/Traits/Creator.php'], T_TRAIT, 'Creator');
    foreach (['Carbon', 'CarbonImmutable'] as $name) {
        $bytes = $source['candidate']['src/Carbon/' . $name . '.php'];
        requireSource(constructorTokens($bytes, T_CLASS, $name) === $trait);
        requireSource(substr_count($bytes, "use Exception;\n") === 1
            && substr_count($bytes, "use Carbon\\Exceptions\\InvalidFormatException;\n") === 1
            && substr_count($bytes, "use DateTimeInterface;\n") === 1);
    }
    echo json_encode(['schema' => 'pinova.carbon-constructor-token-check.v1', 'state' => 'passed',
        'constructor_tokens_equal' => true, 'Creator_unchanged' => true, 'license_unchanged' => true,
        'native_workload_executed' => false, 'shipping_fix' => false], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo '{"schema":"pinova.carbon-constructor-token-check.v1","state":"failed","reason":"source_guard_failed","shipping_fix":false}' . "\n";
    exit(1);
}
