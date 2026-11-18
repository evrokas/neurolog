<?php
// web/search.php: filter validation, SQL shape, level coverage, the search line. No DB. Exit 0 = pass.
$root = dirname(__DIR__, 2);
require "$root/web/nl_id.php"; require "$root/web/vocab.php"; require "$root/web/search.php";
if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
$lists = nl_lists(null, nl_vocab_rows($root));
$dia = yaml_parse_file("$root/vocab/facets.yaml")['diadermiki'];
$fail = 0; $n = 0;
function t(string $l, bool $ok, $got = null): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $l" . ($got !== null ? '  ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; } }

$f = nl_filters_from_get(['proc' => 'discectomy', 'level' => 'Ο4/5', 'side' => 'left', 'approach' => 'tubular_MIS', 'from' => '2026-01-01',
    'to' => '2026-02-30', 'surgeon' => '7', 'hospital' => '99', 'bogus' => 'x', 'technology' => 'laser', 'patient' => 'P-0000-0000-0', 'branch' => 'V2+V3'], $lists, [7 => 'surgeon'], [1 => true]);
t('valid filters kept, level normalised', ($f['proc'] ?? '') === 'discectomy' && ($f['level'] ?? '') === 'L4-L5' && ($f['surgeon'] ?? 0) === 7 && ($f['branch'] ?? '') === 'V2+V3', $f);
t('bad values dropped (impossible date, unknown hospital, value not in list, unknown key, bad ID)', !isset($f['to'], $f['hospital'], $f['technology'], $f['bogus'], $f['patient']), $f);
t('a level filter must be one level', nl_filters_from_get(['level' => 'Ο4/5, Ο5/Ι1'], $lists) === []);

[$w, $p] = nl_filters_sql(['proc' => 'discectomy', 'side' => 'left', 'level' => 'L4-L5', 'from' => '2026-01-01']);
t('procedure filters share one EXISTS (same row)', substr_count($w, 'EXISTS') === 1 && str_contains($w, 'p.procedure_code = ? AND p.lesion_side = ?'), $w);
t('parameters bound, not inlined', !str_contains($w, 'discectomy') && $p[0] === 'discectomy' && end($p) === '2026-01-01', $p);
[$w2] = nl_filters_sql(['dx' => 'disc_herniation', 'proc' => 'discectomy']);
t('diagnosis filters in their own EXISTS', substr_count($w2, 'EXISTS') === 2);

$c = nl_level_candidates('L4-L5');
t('L4-L5 covered by itself and ranges (L3-L5, L1-S1), not by L5-S1', in_array('L4-L5', $c) && in_array('L3-L5', $c) && in_array('L1-S1', $c) && !in_array('L5-S1', $c) && !in_array('L4', $c), count($c));
$c = nl_level_candidates('L1');
t('vertebra L1: itself, segments around it, ranges over it', in_array('L1', $c) && in_array('T12-L1', $c) && in_array('L1-L2', $c) && in_array('T11-L3', $c) && !in_array('L2-L3', $c));

$al = nl_aliases($root);
foreach ([
    ['left L4-L5 tubular discectomies in 2026', ['from' => '2026-01-01', 'to' => '2026-12-31', 'proc' => 'discectomy', 'level' => 'L4-L5', 'side' => 'left', 'approach' => 'tubular_MIS']],
    ['αριστερές διαδερμικές δισκεκτομές Ο4/5 2026', ['from' => '2026-01-01', 'to' => '2026-12-31', 'proc' => 'discectomy', 'level' => 'L4-L5', 'side' => 'left', 'approach' => 'tubular_MIS']],
    ['διαδερμική σπονδυλοδεσία', ['proc' => 'pedicle_screw_fixation', 'approach' => 'percutaneous']],
    ['ΚΜΔ Ο5/Ι1 2024-2025', ['from' => '2024-01-01', 'to' => '2025-12-31', 'dx' => 'disc_herniation', 'dxlevel' => 'L5-S1']],
    ['TLIF', ['proc' => 'interbody_fusion', 'route' => 'transforaminal']],
] as [$s, $want]) {
    $got = nl_search_line($s, $al, $dia)['filters'];
    ksort($got); ksort($want);
    t("search line «{$s}»", $got === $want, $got);
}
echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
