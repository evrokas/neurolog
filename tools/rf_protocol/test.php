<?php
declare(strict_types=1);
// Tests for the RF protocol parser. Run: php tools/rf_protocol/test.php  (exit code 1 on failure)
require __DIR__ . '/rf_protocol.php';

$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
    if (!$cond) $fail++;
}

// Valid protocols: input => [canonical, rf_mode, number of expanded cycles, branch_pattern]
$valid = [
    'V2 T 75C 90s x2'                                        => ['V2 T 75C 90s x2', 'thermal', 2, 'V2'],
    'V2 T 75C 90s; V3 T 80C 60s'                             => ['V2 T 75C 90s; V3 T 80C 60s', 'thermal', 2, 'V2+V3'],
    'v2 p 45v 2hz 20ms 120s 42c; V2 T 75°C 90sec'            => ['V2 P 45V 2Hz 20ms 120s 42C; V2 T 75C 90s', 'pulsed+thermal', 2, 'V2'],
    'V2 S 50Hz 0,2V ok; V2 S 2Hz 0.8V mot; V2 T 75C 90s x2'  => ['V2 S 50Hz 0.2V ok; V2 S 2Hz 0.8V mot; V2 T 75C 90s x2', 'thermal', 4, 'V2'],
    'L5 P 45V 2Hz 20ms 240s 42C'                             => ['L5 P 45V 2Hz 20ms 240s 42C', 'pulsed', 1, null],
    'Ο5-L P 45V 2Hz 20ms 240s; Ι1-R P 45V 2Hz 20ms 240s'     => ['L5-L P 45V 2Hz 20ms 240s; S1-R P 45V 2Hz 20ms 240s', 'pulsed', 2, null],
    'V S 50Hz 0.3V no'                                       => ['V S 50Hz 0.3V no', 'stimulation_only', 1, null],
    'V3 T 80C 60s 320ohm;'                                   => ['V3 T 80C 60s 320ohm', 'thermal', 1, 'V3'],
];
foreach ($valid as $in => [$canon, $mode, $n, $bp]) {
    $r = rf_protocol_parse($in);
    check("valid: $in", $r['ok'] && $r['canonical'] === $canon && $r['summary']['rf_mode'] === $mode
        && count($r['cycles']) === $n && $r['summary']['branch_pattern'] === $bp,
        json_encode(['canon' => $r['canonical'], 'mode' => $r['summary']['rf_mode'] ?? null,
                     'n' => count($r['cycles']), 'bp' => $r['summary']['branch_pattern'] ?? null, 'err' => $r['errors']],
                    JSON_UNESCAPED_UNICODE));
    // Canonical form is a fixed point: parsing it again gives the same string.
    if ($r['ok']) {
        check("  canonical re-parses identically", rf_protocol_parse($r['canonical'])['canonical'] === $r['canonical']);
    }
}

// Totals
$r = rf_protocol_parse('V2 T 75C 90s x2; V2 T 80C 60s; V3 P 45V 2Hz 20ms 120s');
check('totals: V2 thermal 240 s, max 80 C', $r['summary']['targets']['V2']['thermal_s'] == 240
    && $r['summary']['targets']['V2']['max_temp_c'] == 80 && $r['summary']['targets']['V2']['lesions'] === 3);
check('totals: V3 pulsed 120 s', $r['summary']['targets']['V3']['pulsed_s'] == 120);
check('expanded seq numbers 1..4', array_column($r['cycles'], 'seq') === [1, 2, 3, 4]);

// Invalid protocols: input => fragment expected in the first error
$invalid = [
    ''                               => 'empty',
    'V4 T 75C 90s'                   => 'unknown target',
    'L6 P 45V 2Hz 20ms 120s'         => 'unknown target',
    'V2 X 75C 90s'                   => 'unknown mode',
    'V2 T 750C 90s'                  => 'outside',
    'V2 T 75C'                       => 'needs s',
    'V2 P 45V 2Hz 120s'              => 'needs ms',
    'V2 P 45V 2Hz 20ms 120s 60C'     => 'outside',
    'V2 S 50Hz'                      => 'needs V or mA',
    'V2 T 75C 90s 2Hz'               => 'does not apply',
    'V2 T 75C 90s 80C'               => 'given twice',
    'V2 T 75C 90s ok'                => 'only to a stimulation',
    'V2 T 75C 90s x20'               => 'repeat',
    'V2 T 75 90s'                    => 'cannot read',
    'V2'                             => 'at least a target and a mode',
];
foreach ($invalid as $in => $frag) {
    $r = rf_protocol_parse($in);
    check("invalid: '$in'", !$r['ok'] && str_contains($r['errors'][0] ?? '', $frag), json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
}

echo $fail ? "\n$fail test(s) failed\n" : "\nall tests passed\n";
exit($fail ? 1 : 0);
