<?php
require __DIR__ . '/../fixtures/fpm-otp/jit-mode-facts.php';
$count = 0;
function check_fact(bool $ok, string $name): void { global $count; ++$count; if (!$ok) throw new RuntimeException($name); }
$contract = json_decode(file_get_contents(__DIR__ . '/../fixtures/jit-mode-comparison.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['1235' => 3, '1255' => 5] as $mode => $kind) {
    $expected = $contract['fpm_ini_expected_literals']; $expected['opcache.jit'] = (string) $mode;
    $jit = ['enabled'=>true,'on'=>true,'kind'=>$kind,'opt_level'=>5,'opt_flags'=>6,'buffer_size'=>268435440,'buffer_free'=>260000000];
    $facts = pinova_fixture_jit_facts($expected, $expected, ['jit'=>$jit,'scripts'=>['private'=>'must_not_escape']], PHP_ZTS, PHP_DEBUG);
    check_fact($facts['nts'] === !PHP_ZTS && $facts['debug'] === PHP_DEBUG, 'real_bool_constants');
    check_fact($facts['jit'] === $jit, 'exact_active_mode_fields');
    check_fact(array_keys($facts) === ['nts','debug','ini','jit'], 'only_header_schema');
    check_fact(count($facts['ini']) === 9 && $facts['ini']['opcache.jit']['value'] === (string) $mode, 'literal_mode_observed');
    check_fact(!str_contains(json_encode($facts), 'must_not_escape'), 'raw_status_inventory_excluded');
    $missing = pinova_fixture_jit_facts($expected, [], false, false, false);
    check_fact($missing['nts'] === true && $missing['debug'] === false, 'false_constants_preserved');
    check_fact(count(array_filter($missing['jit'], static fn($value) => $value !== null)) === 0, 'unavailable_status_unknown');
    foreach ($jit as $key => $value) {
        $bad = $jit; $bad[$key] = 'not_a_typed_fact';
        check_fact(pinova_fixture_jit_facts($expected,$expected,['jit'=>$bad],false,false)['jit'][$key] === null, 'typed_jit_' . $key);
    }
    $actual = $expected; $actual['opcache.jit'] = 'invalid_literal';
    check_fact(pinova_fixture_jit_facts($expected,$actual,['jit'=>$jit],false,false)['ini']['opcache.jit']['value'] === null, 'mismatched_mode_unknown');
}
echo 'JIT formatter controls: ' . $count . " assertions passed.\n";
