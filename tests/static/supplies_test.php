<?php
// web/supplies.php (docs/plans/suppliers.md): supplier rows, kinds a technology implies, the @προμ reader. No DB. Exit 0 = pass.
$root = dirname(__DIR__, 2);
if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
require "$root/web/nl_id.php"; require "$root/web/vocab.php"; require "$root/web/operations.php"; require "$root/web/quickline.php";
$lists = nl_lists(null, nl_vocab_rows($root));
$ctx = ['lists' => $lists, 'companies' => [3 => true, 4 => true]];
$fail = 0; $n = 0;
function t(string $l, bool $ok, $got = null): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $l" . ($got !== null ? '  ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; } }

// lists: every kind can be ticked on a company; every technology that implies a kind names a real kind
t('every supply kind can be ticked on a company', !array_diff(array_keys($lists['field.supply.kind']), array_keys($lists['field.company.supplies'])));
t('companies can be tagged as implant suppliers', isset($lists['field.company.supplies']['implants']));
foreach (NL_SUPPLY_FROM_TECHNOLOGY as $tech => $kind) {
    t("technology $tech is a technology", isset($lists['procedure.technology'][$tech]), $tech);
    t("technology $tech -> kind $kind exists", isset($lists['field.supply.kind'][$kind]), $kind);
}
t('drains are one supply kind; the implant category external_drain is retired',
  isset($lists['field.supply.kind']['drain']) && (!isset($lists['field.implant.category']['external_drain']) || !empty($lists['field.implant.category']['external_drain']['retired'])));
t('AnsoluteGel is an intradiscal hydrogel product', isset($lists['procedure.product.intradiscal_hydrogel']['AnsoluteGel']));

// validation
[$rows, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'neuromonitoring', 'company_ref' => '3', 'product' => 'System N', 'for' => '1'],
                                      '1' => ['supply_kind' => 'navigation', 'company_ref' => '4']], $ctx);
t('two supplier rows kept', !$err && count($rows) === 2 && $rows[0]['company_ref'] === 3 && $rows[0]['product'] === 'System N' && $rows[1]['product'] === null, [$rows, $err]);
t('link to the procedure row kept', $rows[0]['_for'] === '1');
[$rows, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'endoscope'], '1' => [], '2' => ['supply_kind' => '', 'company_ref' => '']], $ctx);
t('a row with only its kind (offered, left blank) is skipped', !$rows && !$err, [$rows, $err]);
[, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'endoscope', 'product' => 'Scope X']], $ctx);
t('a product without a company is refused', ($err['su.0.company_ref'] ?? '') === 'required', $err);
[, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'endoscope', 'company_ref' => '99']], $ctx);
t('an unknown company is refused', isset($err['su.0.company_ref']), $err);
[, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'teleporter', 'company_ref' => '3']], $ctx);
t('a kind not in the list is refused', isset($err['su.0.supply_kind']), $err);
[, $err] = nl_supplies_validate(['0' => ['company_ref' => '3', 'product' => 'P']], $ctx);
t('a row with a company needs its kind', ($err['su.0.supply_kind'] ?? '') === 'required', $err);
[, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'other', 'company_ref' => '3']], $ctx);
t('«other» needs a description', isset($err['su.0.note']), $err);
[, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'neuromonitoring', 'company_ref' => '3', 'product' => 'A'],
                                 '1' => ['supply_kind' => 'neuromonitoring', 'company_ref' => '3', 'product' => 'a']], $ctx);
t('the same kind, company and product twice is refused', ($err['su.1.product'] ?? '') === 'listed twice', $err);
[$rows, $err] = nl_supplies_validate(['0' => ['supply_kind' => 'neuromonitoring', 'company_ref' => '3'], '1' => ['supply_kind' => 'neuromonitoring', 'company_ref' => '4']], $ctx);
t('one technology from two companies is allowed', !$err && count($rows) === 2, $err);

// what is missing
$miss = nl_supplies_expected(['["microscope","neuromonitoring"]', '["fluoroscopy"]'], '["MEP"]', null, [['supply_kind' => 'microscope']]);
t('technologies without a supplier are listed', $miss === ['neuromonitoring'], $miss);
t('neuromonitoring "none" asks for nothing', nl_supplies_expected([], '["none"]', null, []) === []);
t('an adjunct (intra-op US) asks for its supplier', nl_supplies_expected([], null, '["intraop_US","5-ALA"]', []) === ['intraop_US']);

// operation validation carries the rows and the missing supplier
$v = nl_op_validate(['op' => ['op_date' => '2026-01-10', 'primary_surgeon_ref' => '1', 'neuromonitoring' => '["MEP"]'],
    'pr' => ['0' => ['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => '["microscope"]']],
    'su' => ['0' => ['supply_kind' => 'microscope', 'company_ref' => '4', 'for' => '0']], 'patient_ref' => '7'],
    $ctx + ['members' => [1 => 'surgeon'], 'hospitals' => [], 'ops' => [], 'patients' => [7 => true]]);
t('operation: supplier row validated and linked', $v['ok'] && count($v['su']) === 1 && $v['su'][0]['_for'] === 0, $v['errors']);
t('operation: neuromonitoring without supplier is missing', in_array('supplier: neuromonitoring', $v['missing'], true) && !in_array('supplier: microscope', $v['missing'], true), $v['missing']);

// the @προμ tag and drains under @υλ
$co = [3 => 'Demo Neuro Services', 4 => 'Example Optics'];
$q = nl_quickline_parse('μικροδισκεκτομή Ο4/5 αρ @προμ νευροπαρακολούθηση Demo Neuro Services, @προμ μικροσκόπιο Example Optics', nl_aliases($root), [], $co);
t('@προμ: two supplier rows with kind and company', count($q['supplies']) === 2 && $q['supplies'][0]['supply_kind'] === 'neuromonitoring' && $q['supplies'][0]['company_ref'] === 3
  && $q['supplies'][1]['supply_kind'] === 'microscope' && $q['supplies'][1]['company_ref'] === 4, $q['supplies']);
$q = nl_quickline_parse('κρανιοτομία @υλ EVD @υλ καθετήρας παροχέτευσης', nl_aliases($root), [], $co);
t('drains and catheters are one supply kind, not implants', !$q['implants'] && count($q['supplies']) === 2 && $q['supplies'][0]['supply_kind'] === 'drain' && $q['supplies'][1]['supply_kind'] === 'drain', $q);
$q = nl_quickline_parse('@προμ κάτι άγνωστο', nl_aliases($root), [], $co);
t('an unknown supplier tag is not guessed', !$q['supplies'] && $q['unplaced'] === ['@προμ κάτι άγνωστο'], $q);
t('FloSeal-like words: haemostat before sealant', nl_ql_supply('αιμοστατικό seal', [])['supply_kind'] === 'haemostat');

// a supplier-list entry's name -> what the company supplies (bin/import_apyweb_companies.php); general words only
foreach (['Lumbar cage system' => 'implants', 'ΣΠΟΝΔΥΛΟΔΕΣΙΑ' => 'implants', 'Cervical disc prosthesis' => 'implants', 'Kyphoplasty kit' => 'implants',
          'IONM service' => 'neuromonitoring', 'Νευροπαρακολούθηση' => 'neuromonitoring', 'Navigation' => 'navigation', 'Spine endoscopy' => 'endoscope',
          'RF' => 'RF_generator', 'Dural sealant' => 'dural_sealant', 'Dura patch' => 'dural_graft', 'Καθετήρας' => 'drain', 'EVD' => 'drain',
          'DiscoGel' => 'intradiscal_product', 'Αιμοστατικό' => 'haemostat', 'Office chairs' => null, 'CT' => 'intraop_CT'] as $name => $want)
    t("name «{$name}» → " . ($want ?? 'not read'), nl_supply_tag_for_name($name) === $want, nl_supply_tag_for_name($name));
echo "supplies: $n checks, $fail failed\n";
exit($fail ? 1 : 0);
