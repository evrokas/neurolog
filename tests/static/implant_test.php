<?php
// web/implants.php (M4): implant rows, parameters per category, the @υλ reader. No DB. Exit 0 = pass.
$root = dirname(__DIR__, 2);
if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
require "$root/web/nl_id.php"; require "$root/web/vocab.php"; require "$root/web/operations.php"; require "$root/web/quickline.php";
$lists = nl_lists(null, nl_vocab_rows($root));
$spec = nl_implant_spec($root);
$ctx = ['lists' => $lists, 'companies' => [3 => true, 4 => true]];
$fail = 0; $n = 0;
function t(string $l, bool $ok, $got = null): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $l" . ($got !== null ? '  ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; } }

// every category of the list has a parameter set, and every code parameter names a real list
t('every implant category has parameters', !array_diff(array_keys($lists['field.implant.category']), array_keys($spec)), array_diff(array_keys($lists['field.implant.category']), array_keys($spec)));
foreach ($spec as $cat => $ps) foreach ($ps as $name => $p) {
    if (in_array($p['type'], ['code', 'grades'], true)) t("$cat.$name list exists", isset($lists['field.implant.' . $p['list']]), $p['list']);
    t("$cat.$name has a known type", in_array($p['type'], ['num', 'int', 'bool', 'code', 'grades', 'text'], true));
}

[$rows, $err, $miss] = nl_implants_validate(['0' => ['implant_category' => 'pedicle_screw', 'company_ref' => '3', 'product' => 'System Y', 'implant_count' => '4',
    'levels' => 'Ο4, Ο5', 'side' => 'bilateral', 'p' => ['diameter_mm' => '6,5', 'length_mm' => '45', 'cannulated' => '1', 'breach' => 'aab'], 'for' => '1']], $ctx, $spec);
$p = json_decode($rows[0]['params'] ?? '{}', true);
t('a screw group is kept with its sizes (decimal comma read)', !$err && $rows[0]['implant_count'] === 4 && $p['diameter_mm'] === 6.5 && $p['length_mm'] == 45 && $p['cannulated'] === 1, [$err, $p]);
t('breach grades one per screw, uppercased', $p['breach'] === ['A', 'A', 'B'], $p['breach'] ?? null);
t('levels parsed', $rows[0]['levels_list'] === '["L4","L5"]' && $rows[0]['region'] === 'lumbar', $rows[0]['levels_list']);
t('link to the procedure row kept', $rows[0]['_for'] === '1');

[, $err] = nl_implants_validate(['0' => ['implant_category' => 'pedicle_screw', 'implant_count' => '2', 'p' => ['diameter_mm' => '25', 'breach' => 'A A F B']]], $ctx, $spec);
t('a screw diameter of 25 mm is refused', isset($err['im.0.p.diameter_mm']), $err);
t('a grade F is refused', isset($err['im.0.p.breach']), $err);
[, $err] = nl_implants_validate(['0' => ['implant_category' => 'pedicle_screw', 'implant_count' => '2', 'p' => ['breach' => 'A A B']]], $ctx, $spec);
t('more grades than screws is refused', ($err['im.0.p.breach'] ?? '') === 'more grades than implants', $err);
[, $err] = nl_implants_validate(['0' => ['implant_category' => 'cage_PLIF_TLIF', 'company_ref' => '99']], $ctx, $spec);
t('an unknown company is refused', isset($err['im.0.company_ref']), $err);
[$rows, $err, $miss] = nl_implants_validate(['0' => ['implant_category' => 'cage_PLIF_TLIF', 'company_ref' => '4', 'p' => ['height_mm' => '10', 'width_mm' => '9']]], $ctx, $spec);
t('a cage with no material: saved, material listed as missing', !$err && in_array('implant: material', $miss, true), [$err, $miss]);
t('count defaults to 1', $rows[0]['implant_count'] === 1);
[$rows] = nl_implants_validate(['0' => ['implant_category' => 'cage_ACDF', 'p' => ['height_mm' => '6', 'diameter_mm' => '6.5']]], $ctx, $spec);
t('a parameter of another category is dropped', !isset(json_decode($rows[0]['params'], true)['diameter_mm']), $rows[0]['params']);
[, $err] = nl_implants_validate(['0' => ['implant_category' => 'other']], $ctx, $spec);
t('«other» needs a description', isset($err['im.0.p.description']), $err);
[$rows, $err] = nl_implants_validate(['0' => ['implant_category' => '', 'company_ref' => '', 'p' => []], '1' => []], $ctx, $spec);
t('empty rows are skipped', !$rows && !$err, [$rows, $err]);
[, $err] = nl_implants_validate(['0' => ['implant_category' => 'shunt_valve', 'p' => ['valve_type' => 'magic']]], $ctx, $spec);
t('a value not in the list is refused', isset($err['im.0.p.valve_type']), $err);
t('parameter text', nl_implant_params_text('{"diameter_mm":6.5,"length_mm":45,"cannulated":1}', 'pedicle_screw', $spec) === 'diameter 6.5 mm, length 45 mm, cannulated',
  nl_implant_params_text('{"diameter_mm":6.5,"length_mm":45,"cannulated":1}', 'pedicle_screw', $spec));

// the @υλ tag
$co = [3 => 'Demo Spine Co', 4 => 'Example Neuro'];
$i = nl_ql_implant('κοχλίες Demo Spine Co 6,5x45 x4', $co);
t('@υλ screws: company, sizes, count', $i['implant_category'] === 'pedicle_screw' && $i['company_ref'] === 3 && $i['params'] == ['diameter_mm' => 6.5, 'length_mm' => 45] && $i['implant_count'] === 4, $i);
$i = nl_ql_implant('cage Example Neuro 10mm 8°', $co, 'TLIF Ο4/5');
t('@υλ cage: height and lordosis, TLIF cage', $i['implant_category'] === 'cage_PLIF_TLIF' && $i['company_ref'] === 4 && $i['params'] == ['height_mm' => 10, 'lordosis_deg' => 8], $i);
t('@υλ cage in an ACDF is an ACDF cage', nl_ql_implant('cage 6x14x12', $co, 'ACDF Α5/6')['implant_category'] === 'cage_ACDF');
t('@υλ without a known category is not an implant', nl_ql_implant('DiscoGel', $co) === null);
$q = nl_quickline_parse('Διαδερμική σπονδυλοδεσία Ο4/5 @υλ κοχλίες Demo Spine Co 6,5x45 x4, TLIF', nl_aliases($root), yaml_parse_file("$root/vocab/facets.yaml")['diadermiki'], $co);
t('a decimal comma does not end the tag; the comma after it does', count($q['implants']) === 1 && $q['implants'][0]['params']['diameter_mm'] === 6.5
  && in_array('interbody_fusion', array_column($q['procedures'], 'procedure_code'), true), $q);

echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
