<?php
// nl_op_validate() against the data dictionary's worked examples and the entry rules. Lists come from vocab/ (no DB).
$root = dirname(__DIR__, 2);
require "$root/web/nl_id.php";
require "$root/web/vocab.php";
require "$root/web/operations.php";
$fail = 0; $n = 0;
function t(string $label, bool $ok, $got = null): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $label" . ($got !== null ? '  ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; } }
$ctx = ['lists' => nl_lists(null, nl_vocab_rows($root)), 'members' => [1 => 'surgeon', 2 => 'anaesthetist'], 'hospitals' => [1 => true],
        'ops' => ['O0000000000' => 9], 'patients' => [5 => true]];
$base = fn(array $pr, array $dx = []) => ['patient_ref' => '5', 'op' => ['op_date' => '2026-03-02', 'primary_surgeon_ref' => '1'],
                                           'pr' => $pr, 'dx' => $dx, 'main_pr' => '0', 'main_dx' => '0'];

// 1. Διαδερμική δισκεκτομή Ο4/5 αρ
$v = nl_op_validate($base(
    [['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'tubular_MIS', 'approach_route' => 'interlaminar', 'technology' => '["microscope"]', 'treats' => '0']],
    [['diagnosis_code' => 'disc_herniation', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'clinical_syndrome' => '["radiculopathy"]', 'q' => ['zone' => 'paracentral_subarticular', 'type' => 'extrusion']]]), $ctx);
t('example 1 valid', $v['ok'], $v['errors']);
$p = $v['pr'][0] ?? [];
t('example 1: L4-L5, lumbar, segment, spinal, discectomy action', ($p['levels_list'] ?? '') === '["L4-L5"]' && $p['region'] === 'lumbar' && $p['level_kind'] === 'segment' && $p['domain'] === 'spinal' && $p['action'] === 'discectomy', $p);
t('example 1: treats diagnosis 0, construct none', $p['_treats'] === 0 && $v['construct'] === 'none');
t('example 1: qualifiers stored', $v['dx'][0]['qualifiers'] === '{"zone":"paracentral_subarticular","type":"extrusion"}', $v['dx'][0]['qualifiers'] ?? null);

// 2. L3-L5 decompression with L4-L5 TLIF
$v = nl_op_validate($base([
    ['procedure_code' => 'laminectomy_decompression', 'levels' => 'Ο3-5', 'lesion_side' => 'bilateral', 'approach_type' => 'open', 'approach_route' => 'posterior_midline', 'technology' => 'microscope'],
    ['procedure_code' => 'interbody_fusion', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'tubular_MIS', 'approach_route' => 'transforaminal', 'technology' => 'fluoroscopy,navigation']],
    [['diagnosis_code' => 'spondylolisthesis', 'levels' => 'Ο4/5', 'q' => ['type' => 'degenerative', 'grade' => 'I']], ['diagnosis_code' => 'spinal_stenosis', 'levels' => 'Ο3-5', 'q' => ['type' => 'central']]]), $ctx);
t('example 2 valid', $v['ok'], $v['errors']);
t('example 2: range L3-L5, cage only', ($v['pr'][0]['levels_list'] ?? '') === '["L3-L5"]' && $v['construct'] === 'cage_only', [$v['pr'][0]['levels_list'] ?? null, $v['construct']]);

// 3. Right pterional clipping of MCA aneurysm
$v = nl_op_validate($base([['procedure_code' => 'aneurysm_clipping', 'location' => '["vascular_territory.MCA"]', 'lesion_side' => 'right', 'approach_type' => 'open', 'approach_route' => 'pterional', 'technology' => '["microscope","ICG"]']],
    [['diagnosis_code' => 'aneurysm', 'location' => '["vascular_territory.MCA"]', 'lesion_side' => 'right']]), $ctx);
t('example 3 valid (cranial route list, location)', $v['ok'], $v['errors']);
t('example 3: region from location', ($v['pr'][0]['region'] ?? '') === 'vascular_territory' && $v['pr'][0]['level_from'] === null);

// 4. Left carpal tunnel release
$v = nl_op_validate($base([['procedure_code' => 'nerve_decompression', 'location' => 'median.wrist_carpal_tunnel', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']],
    [['diagnosis_code' => 'nerve_entrapment', 'location' => 'median.wrist_carpal_tunnel', 'lesion_side' => 'left']]), $ctx);
t('example 4 valid', $v['ok'], $v['errors']);
t('example 4: peripheral domain, neurolysis', ($v['pr'][0]['domain'] ?? '') === 'peripheral_nerve' && $v['pr'][0]['action'] === 'neurolysis_release');

// cage + percutaneous screws
$v = nl_op_validate($base([
    ['procedure_code' => 'pedicle_screw_fixation', 'levels' => 'Ο4/5/Ι1', 'lesion_side' => 'bilateral', 'approach_type' => 'percutaneous', 'technology' => 'navigation'],
    ['procedure_code' => 'interbody_fusion', 'levels' => 'Ο5/Ι1', 'lesion_side' => 'left', 'approach_type' => 'tubular_MIS', 'technology' => 'microscope']]), $ctx);
t('cage and screws construct, screw levels kept apart', $v['ok'] && $v['construct'] === 'cage_and_screws' && $v['pr'][0]['levels_list'] === '["L4-L5","L5-S1"]' && $v['pr'][0]['region'] === 'lumbosacral', [$v['errors'], $v['construct'], $v['pr'][0]['levels_list'] ?? null]);

// trigeminal RF
$v = nl_op_validate($base([['procedure_code' => 'trigeminal_percutaneous', 'trigeminal_technique' => 'radiofrequency', 'branches_treated' => '["V3","V2"]', 'lesion_side' => 'right', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy', 'location' => 'target.trigeminal_ganglion',
                             'rf_protocol' => 'V2 S 50Hz 0.2V ok; V2 T 75C 90s x2; V3 T 75C 90s']]), $ctx);
t('trigeminal RF valid', $v['ok'], $v['errors']);
t('branch pattern ordered V2+V3, rf thermal, 4 cycles', ($v['pr'][0]['branch_pattern'] ?? '') === 'V2+V3' && $v['pr'][0]['rf_mode'] === 'thermal' && count($v['pr'][0]['_cycles']) === 4, [$v['pr'][0]['branch_pattern'] ?? null, $v['pr'][0]['rf_mode'] ?? null]);

// refusals
$v = nl_op_validate($base([['procedure_code' => 'discectomy', 'levels' => 'Ο4/5']]), $ctx);
t('side, approach and technology required at entry', !$v['ok'] && isset($v['errors']['pr.0.lesion_side'], $v['errors']['pr.0.approach_type'], $v['errors']['pr.0.technology']), $v['errors']);
$v = nl_op_validate($base([['procedure_code' => 'discectomy', 'levels' => 'Ο7', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']]), $ctx);
t('impossible level refused', isset($v['errors']['pr.0.levels']));
$v = nl_op_validate($base([['procedure_code' => 'discectomy', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']]), $ctx);
t('spine procedure without levels refused', isset($v['errors']['pr.0.levels']));
$v = nl_op_validate($base([['procedure_code' => 'intradiscal_hydrogel', 'levels' => 'Ο4/5', 'lesion_side' => 'midline', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy']]), $ctx);
t('hydrogel needs its product', isset($v['errors']['pr.0.product']));
$v = nl_op_validate($base([['procedure_code' => 'trigeminal_percutaneous', 'trigeminal_technique' => 'radiofrequency', 'branches_treated' => 'V2', 'lesion_side' => 'right', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy', 'rf_protocol' => 'V2 T 120C 90s']]), $ctx);
t('RF protocol out of range refused', isset($v['errors']['pr.0.rf_protocol']), $v['errors']);
$v = nl_op_validate($base([['procedure_code' => 'trigeminal_percutaneous', 'trigeminal_technique' => 'radiofrequency', 'branches_treated' => 'V2', 'lesion_side' => 'right', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy']]), $ctx);
t('RF technique needs a protocol', isset($v['errors']['pr.0.rf_protocol']));
$v = nl_op_validate($base([['procedure_code' => 'trigeminal_percutaneous', 'trigeminal_technique' => 'balloon_compression', 'branches_treated' => 'non_selective,V2', 'lesion_side' => 'right', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy']]), $ctx);
t('non_selective mixed with branches refused', isset($v['errors']['pr.0.branches_treated']));
$v = nl_op_validate($base([['procedure_code' => 'not_a_code', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']]), $ctx);
t('unknown procedure refused', isset($v['errors']['pr.0.procedure_code']));
$v = nl_op_validate($base([['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'up', 'approach_type' => 'open', 'technology' => 'laser']]), $ctx);
t('values outside the lists refused', isset($v['errors']['pr.0.lesion_side'], $v['errors']['pr.0.technology']));
$v = nl_op_validate($base([['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none,microscope']]), $ctx);
t('technology none with others refused', isset($v['errors']['pr.0.technology']));
$v = nl_op_validate(['op' => ['op_date' => '2999-01-01'], 'pr' => []], $ctx);
t('future date, no surgeon, no patient, no procedure refused', isset($v['errors']['op.op_date'], $v['errors']['op.primary_surgeon_ref'], $v['errors']['patient'], $v['errors']['pr']), $v['errors']);
$in = $base([['procedure_code' => 'vertebral_augmentation', 'levels' => 'Ο1', 'lesion_side' => 'midline', 'approach_type' => 'percutaneous', 'technology' => 'fluoroscopy']], [['diagnosis_code' => 'vertebral_fracture', 'levels' => 'Ο1']]);
$v = nl_op_validate($in, $ctx);
t('fracture needs its cause', isset($v['errors']['dx.0.q.cause']));
$in['op']['reoperation'] = 'revision_same_level'; $in['op']['reoperation_of'] = 'O-0000-0000-X';
$v = nl_op_validate($in, $ctx);
t('unknown index operation refused', isset($v['errors']['op.reoperation_of']));
$v = nl_op_validate(['new_patient' => ['surname' => 'Test', 'birth_date' => '2030-01-01'], 'op' => ['op_date' => '2026-01-01', 'primary_surgeon_ref' => '1'],
                     'pr' => [['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']]], $ctx);
t('new patient with a birth date in the future refused', isset($v['errors']['patient.birth_date']));
t('missing list names what a complete record needs', in_array('diagnosis', nl_op_validate($base([['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'open', 'technology' => 'none']]), $ctx)['missing'], true));
t('name match: uppercase, no accents', nl_name_match('Παπαδόπουλος  Νίκος') === 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΝΙΚΟΣ');

echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
