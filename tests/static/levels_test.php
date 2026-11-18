<?php
// web/levels.php against tests/cases/levels.json (the same file tests web/js/levels.js). Exit 0 = pass.
require __DIR__ . '/../../web/levels.php';
$cases = json_decode(file_get_contents(__DIR__ . '/../cases/levels.json'), true)['cases'];
$fail = 0;
foreach ($cases as $c) {
    $r = nl_levels_parse($c['in'], $c['transitional'] ?? false);
    $wantOk = $c['ok'] ?? true;
    $good = $r['ok'] === $wantOk && (!$wantOk || ($r['text'] === $c['text'] && $r['region'] === $c['region']));
    if (!$good) { $fail++; echo "FAIL «{$c['in']}»: got ", json_encode([$r['ok'], $r['text'], $r['region'], $r['errors']], JSON_UNESCAPED_UNICODE), "\n"; }
}
echo (count($cases) - $fail) . '/' . count($cases) . " passed\n";
exit($fail ? 1 : 0);
