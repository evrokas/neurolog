<?php
/**
 * Operations: validation, derived fields and saving (docs/data-dictionary.md "Operation", "Procedure",
 * "Diagnosis and baseline status", "Cage and screws", "Trigeminal procedures", docs/rf-protocol.md).
 *
 * nl_op_validate() is pure (lists in, clean record + errors out) and unit-tested; nl_op_save() writes it.
 * Rules:
 *  - errors block the save: patient, date, primary surgeon, at least one procedure, and on every procedure row
 *    the procedure, side, approach and technology (required at entry, decisions 2026-11-14), levels for a
 *    procedure that has levels, plus the conditional fields (product, trigeminal technique/branches, RF protocol);
 *  - "missing" lists what a complete record still needs (blank = not yet entered; it never blocks a save);
 *  - codes are confirmed when the operation's own primary surgeon saves them, otherwise suggested
 *    (decisions 2026-11-18); a row saved unchanged keeps its status; a row proposed by the quick line or a tag
 *    (`proposed` = 1) and not accepted is saved as suggested, whoever saves it.
 */

require_once __DIR__ . '/levels.php';
require_once __DIR__ . '/implants.php';
require_once __DIR__ . '/supplies.php';
require_once __DIR__ . '/../tools/rf_protocol/rf_protocol.php';

// procedures.csv uses short domain names; the facet list (vocab/facets.yaml procedure.domain) the long ones
const NL_DOMAIN_MAP = ['spinal' => 'spinal', 'cranial' => 'cranial', 'CSF' => 'CSF', 'pain' => 'functional_pain',
                       'peripheral' => 'peripheral_nerve', 'other' => 'other'];
// facets that also accept n/a (does not apply)
const NL_NA = 'n/a';

/** Operation-level fields: name => [type, list]. Types: code, multi, date, time, int, bool, text, member, hospital. */
function nl_op_fields(): array {
    return [
        'op_date' => ['date', null], 'hospital_ref' => ['hospital', null],
        'primary_surgeon_ref' => ['member', null], 'assistant_1_ref' => ['member', null], 'assistant_2_ref' => ['member', null],
        'anaesthetist_ref' => ['member', null],
        'indication_urgency' => ['code', 'field.operation.indication_urgency'], 'reoperation' => ['code', 'field.operation.reoperation'],
        'asa_class' => ['code', 'field.operation.asa_class'], 'anaesthesia' => ['code', 'field.operation.anaesthesia'],
        'position' => ['code', 'field.operation.position'], 'trainee_role' => ['code', 'field.operation.trainee_role'],
        'skin_incision_time' => ['time', null], 'skin_closure_time' => ['time', null],
        'blood_loss_ml' => ['int', [0, 20000]], 'transfusion_units' => ['int', [0, 100]],
        'neuromonitoring' => ['multi', 'field.operation.neuromonitoring'], 'antibiotic_prophylaxis' => ['code', 'field.operation.antibiotic_prophylaxis'],
        'drain' => ['bool', null], 'durotomy_incidental' => ['bool', null],
        'admission_code' => ['text', 64], 'op_note' => ['text', 20000], 'quick_line' => ['text', 2000],
        'fluoroscopy_time_s' => ['int', [0, 36000]], 'adjuncts' => ['multi', 'field.operation.adjuncts'],
    ];
}
// required for a complete record (the rest is extended data)
const NL_OP_COMPLETE = ['hospital_ref', 'indication_urgency', 'reoperation', 'asa_class', 'anaesthesia', 'position',
                        'skin_incision_time', 'skin_closure_time', 'blood_loss_ml', 'transfusion_units', 'neuromonitoring',
                        'drain', 'anaesthetist_ref'];

function nl_proc_fields(): array {
    return [
        'lesion_side' => ['code', 'procedure.side', true], 'approach_type' => ['code', 'procedure.approach_type', false],
        'approach_side' => ['code', 'procedure.side', true], 'spinal_column' => ['code', 'procedure.spinal_column', true],
        'extent' => ['code', 'procedure.extent', true], 'technology' => ['multi', 'procedure.technology', false],
        'episode' => ['code', 'procedure.episode', false], 'intent' => ['code', 'procedure.intent', false],
    ];
}

function nl_dx_fields(): array {
    return [
        'lesion_side' => ['code', 'procedure.side', true], 'clinical_syndrome' => ['multi', 'diagnosis.clinical_syndrome', false],
        'acuity' => ['code', 'diagnosis.acuity', true], 'recurrence' => ['code', 'diagnosis.recurrence', false],
        'symptom_duration' => ['code', 'field.diagnosis.symptom_duration', false], 'neuro_deficit' => ['code', 'field.diagnosis.neuro_deficit', false],
        'molecular_markers' => ['multi', 'field.diagnosis.molecular_markers', false],
    ];
}

/** Qualifier lists of a diagnosis: [name => list]. Required ones per the data dictionary are in NL_DX_QUAL_REQUIRED. */
function nl_dx_qualifiers(array $lists, string $dx): array {
    $out = [];
    $prefix = "diagnosis.qualifiers.$dx.";
    foreach ($lists as $list => $_) if (str_starts_with($list, $prefix)) $out[substr($list, strlen($prefix))] = $list;
    return $out;
}
const NL_DX_QUAL_REQUIRED = ['vertebral_fracture' => ['cause'], 'trigeminal_neuralgia' => ['branches_affected', 'tn_type', 'concomitant_continuous_pain']];
const NL_DX_QUAL_MULTI = ['branches_affected', 'prior_treatments'];

/** Locations a non-spinal procedure or diagnosis can name, as one list: code "group.value" => label. */
function nl_locations(array $lists): array {
    $out = [];
    foreach ($lists as $list => $vals) {
        if (!preg_match('/^anatomy\.(cranial|peripheral_nerve)\.(\w+)$/', $list, $m) && $list !== 'anatomy.functional_target') continue;
        $group = $m[2] ?? 'target';
        foreach ($vals as $code => $v) if (!$v['retired']) $out["$group.$code"] = str_replace('_', ' ', $group) . ' › ' . str_replace('_', ' ', $code);
    }
    return $out;
}

/** Decode a multi value: JSON array or comma list -> unique non-empty strings. */
function nl_multi($v): array {
    if (is_array($v)) $a = $v;
    else { $v = trim((string)$v); $a = $v === '' ? [] : (str_starts_with($v, '[') ? (json_decode($v, true) ?: []) : explode(',', $v)); }
    return array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $a), fn($x) => $x !== '')));
}

/** A code is acceptable if it is active in the list, or retired but already stored on this record ($keep). */
function nl_code_ok(array $lists, ?string $list, string $code, bool $na, array $keep = []): bool {
    if ($na && $code === NL_NA) return true;
    if ($list === null || !isset($lists[$list][$code])) return false;
    return !$lists[$list][$code]['retired'] || in_array($code, $keep, true);
}

/**
 * Validate a posted operation. $in: ['op' => [...], 'dx' => [i => [...]], 'pr' => [i => [...]], 'main_dx', 'main_pr'].
 * $ctx: ['lists' => nl_lists(), 'members' => [id => kind], 'hospitals' => [id => true], 'ops' => [nl_id => id], 'keep' => stored codes].
 * Returns ['ok', 'op' => clean operation fields, 'dx' => rows, 'pr' => rows, 'construct', 'errors' => [field => msg], 'missing' => [..]].
 */
function nl_op_validate(array $in, array $ctx): array {
    $L = $ctx['lists']; $keep = $ctx['keep'] ?? [];
    $err = []; $missing = [];
    $op = [];
    $src = $in['op'] ?? [];
    foreach (nl_op_fields() as $f => [$type, $arg]) {
        $raw = $src[$f] ?? '';
        $v = is_string($raw) ? trim($raw) : $raw;
        $val = null;
        switch ($type) {
            case 'date':
                if ($v !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)))) $err["op.$f"] = 'not a valid date';
                else $val = $v ?: null;
                break;
            case 'time':
                if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $err["op.$f"] = 'use HH:MM';
                else $val = $v ?: null;
                break;
            case 'int':
                if ($v !== '' && (!preg_match('/^\d+$/', $v) || (int)$v < $arg[0] || (int)$v > $arg[1])) $err["op.$f"] = "a whole number {$arg[0]}–{$arg[1]}";
                else $val = $v === '' ? null : (int)$v;
                break;
            case 'bool':
                if (!in_array($v, ['', '0', '1'], true)) $err["op.$f"] = 'yes or no';
                else $val = $v === '' ? null : (int)$v;
                break;
            case 'text':
                if (mb_strlen($v) > $arg) $err["op.$f"] = "at most $arg characters";
                else $val = $v === '' ? null : $v;
                break;
            case 'code':
                if ($v !== '' && !nl_code_ok($L, $arg, $v, false, $keep)) $err["op.$f"] = 'not in the list';
                else $val = $v ?: null;
                break;
            case 'multi':
                $vals = nl_multi($v);
                foreach ($vals as $c) if (!nl_code_ok($L, $arg, $c, false, $keep)) $err["op.$f"] = "«{$c}» is not in the list";
                $val = $vals ? json_encode($vals) : null;
                break;
            case 'member':
                if ($v !== '' && !isset($ctx['members'][(int)$v])) $err["op.$f"] = 'unknown member';
                else $val = $v === '' ? null : (int)$v;
                break;
            case 'hospital':
                if ($v !== '' && !isset($ctx['hospitals'][(int)$v])) $err["op.$f"] = 'unknown hospital';
                else $val = $v === '' ? null : (int)$v;
                break;
        }
        $op[$f] = $val;
    }
    if (!$op['op_date']) $err['op.op_date'] = $err['op.op_date'] ?? 'required';
    elseif ($op['op_date'] > date('Y-m-d', strtotime('+1 day'))) $err['op.op_date'] = 'in the future';
    if (!$op['primary_surgeon_ref']) $err['op.primary_surgeon_ref'] = $err['op.primary_surgeon_ref'] ?? 'required';
    if ($op['reoperation'] === null) $op['reoperation'] = 'primary';
    // reoperation_of: the index operation's NeuroLog ID, required unless primary
    $ro = trim((string)($src['reoperation_of'] ?? ''));
    $op['reoperation_of_ref'] = null;
    if ($ro !== '') {
        $id = nl_id_normalize($ro, 'O');
        if ($id === null || !isset($ctx['ops'][$id])) $err['op.reoperation_of'] = 'not a known operation ID';
        else $op['reoperation_of_ref'] = $ctx['ops'][$id];
    } elseif ($op['reoperation'] !== 'primary') $missing[] = 'index operation (reoperation of)';
    if ($op['skin_incision_time'] && $op['skin_closure_time'] && $op['skin_closure_time'] < $op['skin_incision_time']) {
        // past midnight is possible; only flag it
        $missing[] = 'check times (closure before incision)';
    }
    foreach (NL_OP_COMPLETE as $f) if ($op[$f] === null) $missing[] = str_replace(['_ref', '_'], ['', ' '], $f);

    // ---------- diagnoses ----------
    $dx = [];
    $mainDx = (string)($in['main_dx'] ?? '0');
    $dxIndex = [];   // posted index -> position in $dx
    foreach (($in['dx'] ?? []) as $i => $d) {
        $code = trim((string)($d['diagnosis_code'] ?? ''));
        if ($code === '' && trim(implode('', array_map(fn($x) => is_array($x) ? implode('', $x) : (string)$x, $d))) === '') continue;  // empty row
        $p = "dx.$i";
        $row = ['diagnosis_code' => null, 'is_main' => (string)$i === $mainDx ? 1 : 0];
        if (!nl_code_ok($L, 'diagnosis', $code, false, $keep)) $err["$p.diagnosis_code"] = $code === '' ? 'required' : 'not in the list';
        else { $row['diagnosis_code'] = $code; $row['pathology_class'] = $L['diagnosis'][$code]['parent']; }
        foreach (nl_dx_fields() as $f => [$type, $list, $na]) {
            $v = $d[$f] ?? '';
            if ($type === 'multi') {
                $vals = nl_multi($v);
                foreach ($vals as $c) if (!nl_code_ok($L, $list, $c, $na, $keep)) $err["$p.$f"] = "«{$c}» is not in the list";
                $row[$f] = $vals ? json_encode($vals) : null;
            } else {
                $v = trim((string)$v);
                if ($v !== '' && !nl_code_ok($L, $list, $v, $na, $keep)) $err["$p.$f"] = 'not in the list';
                $row[$f] = $v ?: null;
            }
        }
        nl_levels_into($row, $d, $p, false, $err);
        $loc = nl_multi($d['location'] ?? '');
        $locs = nl_locations($L);
        foreach ($loc as $c) if (!isset($locs[$c])) $err["$p.location"] = "«{$c}» is not a known location";
        $row['location'] = $loc ? json_encode($loc) : null;
        // qualifiers
        $q = [];
        if ($row['diagnosis_code']) {
            foreach (nl_dx_qualifiers($L, $row['diagnosis_code']) as $name => $list) {
                $raw = $d['q'][$name] ?? '';
                if (in_array($name, NL_DX_QUAL_MULTI, true)) {
                    $vals = nl_multi($raw);
                    foreach ($vals as $c) if (!nl_code_ok($L, $list, $c, false, $keep)) $err["$p.q.$name"] = "«{$c}» is not in the list";
                    if ($vals) $q[$name] = $vals;
                } else {
                    $raw = trim((string)$raw);
                    if ($raw !== '' && !nl_code_ok($L, $list, $raw, false, $keep)) $err["$p.q.$name"] = 'not in the list';
                    if ($raw !== '') $q[$name] = $raw;
                }
            }
            foreach (NL_DX_QUAL_REQUIRED[$row['diagnosis_code']] ?? [] as $name) {
                if (!isset($q[$name])) {
                    if ($name === 'cause') $err["$p.q.$name"] = 'required';
                    else $missing[] = "diagnosis: " . str_replace('_', ' ', $name);
                }
            }
        }
        $row['qualifiers'] = $q ? json_encode($q, JSON_UNESCAPED_UNICODE) : null;
        $row['histology'] = mb_substr(trim((string)($d['histology'] ?? '')), 0, 128) ?: null;
        $row['note'] = trim((string)($d['note'] ?? '')) ?: null;
        $row['prior_surgery_same_region'] = in_array($d['prior_surgery_same_region'] ?? '', ['0', '1'], true) ? (int)$d['prior_surgery_same_region'] : null;
        $row['index_op_ref'] = null;
        $io = trim((string)($d['index_op'] ?? ''));
        if ($io !== '') {
            $id = nl_id_normalize($io, 'O');
            if ($id === null || !isset($ctx['ops'][$id])) $err["$p.index_op"] = 'not a known operation ID';
            else $row['index_op_ref'] = $ctx['ops'][$id];
        }
        if ($row['symptom_duration'] === null) $missing[] = 'diagnosis: symptom duration';
        if ($row['neuro_deficit'] === null) $missing[] = 'diagnosis: neurological deficit';
        $row['_proposed'] = !empty($d['proposed']);
        $dxIndex[(string)$i] = count($dx);
        $dx[] = $row;
    }
    if (!$dx) $missing[] = 'diagnosis';
    elseif (!array_sum(array_column($dx, 'is_main'))) $dx[0]['is_main'] = 1;

    // ---------- procedures ----------
    $pr = []; $prIndex = [];
    $mainPr = (string)($in['main_pr'] ?? '0');
    foreach (($in['pr'] ?? []) as $i => $d) {
        $code = trim((string)($d['procedure_code'] ?? ''));
        if ($code === '' && trim(implode('', array_map(fn($x) => is_array($x) ? implode('', $x) : (string)$x, $d))) === '') continue;
        $p = "pr.$i";
        $row = ['procedure_code' => null, 'is_main' => (string)$i === $mainPr ? 1 : 0];
        $meta = null;
        if (!nl_code_ok($L, 'procedure', $code, false, $keep)) $err["$p.procedure_code"] = $code === '' ? 'required' : 'not in the list';
        else {
            $row['procedure_code'] = $code; $meta = $L['procedure'][$code];
            $row['domain'] = NL_DOMAIN_MAP[$meta['parent']] ?? $meta['parent'];
        }
        $kind = $meta['extra']['level_kind'] ?? '';
        $row['level_kind'] = $kind ?: null;
        // action: default from the procedure, overridable
        $act = trim((string)($d['action'] ?? ''));
        if ($act !== '' && !nl_code_ok($L, 'procedure.action', $act, false, $keep) && $act !== 'other') $err["$p.action"] = 'not in the list';
        $row['action'] = $act !== '' ? $act : ($meta['extra']['action'] ?? null);
        foreach (nl_proc_fields() as $f => [$type, $list, $na]) {
            $v = $d[$f] ?? '';
            if ($type === 'multi') {
                $vals = nl_multi($v);
                foreach ($vals as $c) if (!nl_code_ok($L, $list, $c, $na, $keep)) $err["$p.$f"] = "«{$c}» is not in the list";
                if (in_array('none', $vals, true) && count($vals) > 1) $err["$p.$f"] = '«none» with other values';
                $row[$f] = $vals ? json_encode($vals) : null;
            } else {
                $v = trim((string)$v);
                if ($v !== '' && !nl_code_ok($L, $list, $v, $na, $keep)) $err["$p.$f"] = 'not in the list';
                $row[$f] = $v ?: null;
            }
        }
        if ($row['episode'] === null) $row['episode'] = 'primary';
        // required at entry
        foreach (['lesion_side', 'approach_type', 'technology'] as $f) if ($row[$f] === null && !isset($err["$p.$f"])) $err["$p.$f"] = 'required';
        // route and compartment depend on the domain
        $route = trim((string)($d['approach_route'] ?? ''));
        $routeList = ($row['domain'] ?? null) === 'cranial' ? 'procedure.approach_route.cranial' : 'procedure.approach_route.spinal';
        if ($route !== '' && !nl_code_ok($L, $routeList, $route, true, $keep)) $err["$p.approach_route"] = 'not in the list';
        $row['approach_route'] = $route ?: null;
        $comp = trim((string)($d['compartment'] ?? ''));
        $compList = ($row['domain'] ?? null) === 'cranial' ? 'procedure.compartment.cranial' : 'procedure.compartment.spinal';
        if ($comp !== '' && !nl_code_ok($L, $compList, $comp, true, $keep)) $err["$p.compartment"] = 'not in the list';
        $row['compartment'] = $comp ?: null;
        if ($meta && $row['approach_route'] === null && ($row['domain'] === 'cranial' || ($row['domain'] === 'spinal' && $row['action'] === 'fusion'))) $missing[] = "procedure: approach route";
        if ($meta && $row['action'] === 'excision_resection' && $row['extent'] === null) $missing[] = "procedure: extent of resection";
        // levels or location
        if ($kind !== '') {
            nl_levels_into($row, $d, $p, true, $err);
        } else {
            $row['level_from'] = $row['level_to'] = $row['levels_list'] = $row['region'] = null;
            $loc = nl_multi($d['location'] ?? '');
            $locs = nl_locations($L);
            foreach ($loc as $c) if (!isset($locs[$c])) $err["$p.location"] = "«{$c}» is not a known location";
            $row['location'] = $loc ? json_encode($loc) : null;
            $row['region'] = $loc ? explode('.', $loc[0])[0] : null;
            if ($meta && !$loc && in_array($row['domain'], ['cranial', 'peripheral_nerve', 'functional_pain'], true)) $missing[] = 'procedure: location';
        }
        if ($kind !== '') $row['location'] = null;
        // product
        $prod = trim((string)($d['product'] ?? ''));
        if ($code === 'intradiscal_hydrogel') {
            if ($prod === '') $err["$p.product"] = 'required';
            elseif (!nl_code_ok($L, 'procedure.product.intradiscal_hydrogel', $prod, false, $keep)) $err["$p.product"] = 'not in the list';
        }
        $row['product'] = $prod !== '' ? mb_substr($prod, 0, 64) : null;
        // trigeminal
        $row['trigeminal_technique'] = $row['branches_treated'] = $row['branch_pattern'] = null;
        $isRf = $code === 'rf_neurotomy';
        if ($code === 'trigeminal_percutaneous') {
            $tech = trim((string)($d['trigeminal_technique'] ?? ''));
            if ($tech === '') $err["$p.trigeminal_technique"] = 'required';
            elseif (!nl_code_ok($L, 'procedure.trigeminal.technique', $tech, false, $keep)) $err["$p.trigeminal_technique"] = 'not in the list';
            else $row['trigeminal_technique'] = $tech;
            $br = nl_multi($d['branches_treated'] ?? '');
            foreach ($br as $c) if (!nl_code_ok($L, 'procedure.trigeminal.branches_treated', $c, false, $keep)) $err["$p.branches_treated"] = "«{$c}» is not in the list";
            if (!$br) $err["$p.branches_treated"] = 'required';
            elseif (in_array('non_selective', $br, true) && count($br) > 1) $err["$p.branches_treated"] = '«non_selective» with branches';
            elseif (in_array('non_selective', $br, true) && $tech === 'radiofrequency') $err["$p.branches_treated"] = 'radiofrequency treats named branches';
            $order = ['V1' => 1, 'V2' => 2, 'V3' => 3, 'non_selective' => 4];
            usort($br, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
            $row['branches_treated'] = $br ? json_encode($br) : null;
            $row['branch_pattern'] = $br ? implode('+', $br) : null;
            $isRf = $tech === 'radiofrequency';
        }
        // RF protocol
        $rf = trim((string)($d['rf_protocol'] ?? ''));
        $row['rf_protocol'] = $row['rf_mode'] = null; $row['_cycles'] = [];
        if ($rf !== '') {
            $r = rf_protocol_parse($rf);
            if (!$r['ok']) $err["$p.rf_protocol"] = implode('; ', $r['errors']);
            else { $row['rf_protocol'] = $r['canonical']; $row['rf_mode'] = $r['summary']['rf_mode']; $row['_cycles'] = $r['cycles']; }
        } elseif ($isRf) $err["$p.rf_protocol"] = 'required for radiofrequency';
        if (!$isRf && $rf !== '' && $code !== 'trigeminal_percutaneous') $err["$p.rf_protocol"] = 'only for RF procedures';
        // which diagnosis this row treats
        $t = (string)($d['treats'] ?? '');
        $row['_treats'] = $t !== '' && isset($dxIndex[$t]) ? $dxIndex[$t] : null;
        $row['_proposed'] = !empty($d['proposed']);
        $prIndex[(string)$i] = count($pr);
        $pr[] = $row;
    }
    if (!$pr) $err['pr'] = 'at least one procedure';
    elseif (!array_sum(array_column($pr, 'is_main'))) $pr[0]['is_main'] = 1;
    foreach ($pr as $row) if ($row['_treats'] === null && $dx) { $missing[] = 'procedure: diagnosis it treats'; break; }

    // ---------- implants (M4) ----------
    [$im, $imErr, $imMissing] = nl_implants_validate((array)($in['im'] ?? []), $ctx);
    $err += $imErr; $missing = array_merge($missing, $imMissing);
    foreach ($im as &$row) $row['_for'] = $row['_for'] !== null && isset($prIndex[$row['_for']]) ? $prIndex[$row['_for']] : null;
    unset($row);
    $codes = array_column($pr, 'procedure_code'); $cats = array_column($im, 'implant_category');
    if (array_intersect($cats, ['pedicle_screw']) && !in_array('pedicle_screw_fixation', $codes, true)) $missing[] = 'check: pedicle screws recorded but no screw fixation procedure';
    if (array_intersect($cats, ['cage_PLIF_TLIF', 'cage_ALIF_LLIF']) && !in_array('interbody_fusion', $codes, true)) $missing[] = 'check: a cage recorded but no interbody fusion procedure';

    // ---------- suppliers (docs/plans/suppliers.md): optional; a technology without its supplier is listed as missing ----------
    [$su, $suErr] = nl_supplies_validate((array)($in['su'] ?? []), $ctx);
    $err += $suErr;
    foreach ($su as &$row) $row['_for'] = $row['_for'] !== null && isset($prIndex[$row['_for']]) ? $prIndex[$row['_for']] : null;
    unset($row);
    foreach (nl_supplies_expected(array_column($pr, 'technology'), $op['neuromonitoring'], $op['adjuncts'], $su) as $k) $missing[] = 'supplier: ' . str_replace('_', ' ', $k);

    // patient
    $patient = null;
    $pref = trim((string)($in['patient_ref'] ?? ''));
    if ($pref !== '') { if (!isset($ctx['patients'][(int)$pref])) $err['patient'] = 'unknown patient'; else $patient = ['ref' => (int)$pref]; }
    else {
        $np = $in['new_patient'] ?? [];
        $sn = trim((string)($np['surname'] ?? ''));
        if ($sn === '') $err['patient'] = 'choose a patient or enter a new one';
        else {
            $bd = trim((string)($np['birth_date'] ?? ''));
            $sex = trim((string)($np['sex'] ?? ''));
            if ($bd !== '' && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) && checkdate((int)substr($bd, 5, 2), (int)substr($bd, 8, 2), (int)substr($bd, 0, 4)) && $bd <= date('Y-m-d'))) $err['patient.birth_date'] = 'not a valid date';
            if ($sex !== '' && !nl_code_ok($L, 'field.patient.sex', $sex, false)) $err['patient.sex'] = 'not in the list';
            $patient = ['new' => ['surname' => mb_substr($sn, 0, 128), 'given_name' => mb_substr(trim((string)($np['given_name'] ?? '')), 0, 128) ?: null,
                                  'birth_date' => $bd ?: null, 'sex' => $sex ?: null]];
            if (!$bd) $missing[] = 'patient birth date';
        }
    }

    return ['ok' => !$err, 'op' => $op, 'dx' => $dx, 'pr' => $pr, 'im' => $im, 'su' => $su, 'patient' => $patient, 'construct' => nl_construct($pr),
            'errors' => $err, 'missing' => array_values(array_unique($missing))];
}

/** Parse levels typed on a row into level_from/level_to/levels_list/region. */
function nl_levels_into(array &$row, array $d, string $p, bool $required, array &$err): void {
    $row['level_from'] = $row['level_to'] = $row['levels_list'] = $row['region'] = null;
    $txt = trim((string)($d['levels'] ?? ''));
    if ($txt === '') { if ($required) $err["$p.levels"] = 'required'; return; }
    $r = nl_levels_parse($txt, !empty($d['transitional']));
    if (!$r['ok']) { $err["$p.levels"] = implode('; ', $r['errors']); return; }
    $items = array_map(fn($it) => $it[0] === $it[1] ? $it[0] : "$it[0]-$it[1]", $r['items']);
    $row['level_from'] = $r['items'][0][0];
    $row['level_to'] = end($r['items'])[1];
    $row['levels_list'] = json_encode($items);
    $row['region'] = $r['region'];
}

/** cage_only | screws_only | cage_and_screws | none (data dictionary "Cage and screws"). */
function nl_construct(array $pr): string {
    $codes = array_column($pr, 'procedure_code');
    $cage = in_array('interbody_fusion', $codes, true);
    $screws = in_array('pedicle_screw_fixation', $codes, true);
    return $cage && $screws ? 'cage_and_screws' : ($cage ? 'cage_only' : ($screws ? 'screws_only' : 'none'));
}

/** The comparable content of a coded row (to keep the status of a row saved unchanged). */
function nl_row_signature(array $row, array $fields): string {
    $s = [];
    foreach ($fields as $f) $s[$f] = $row[$f] ?? null;
    return md5(json_encode($s));
}
const NL_PR_COLS = ['procedure_code', 'is_main', 'domain', 'action', 'region', 'level_kind', 'level_from', 'level_to', 'levels_list',
                    'location', 'lesion_side', 'approach_type', 'approach_route', 'approach_side', 'compartment', 'spinal_column',
                    'extent', 'technology', 'episode', 'intent', 'product', 'trigeminal_technique', 'branches_treated',
                    'branch_pattern', 'rf_protocol', 'rf_mode'];
const NL_DX_COLS = ['diagnosis_code', 'is_main', 'pathology_class', 'clinical_syndrome', 'acuity', 'recurrence', 'qualifiers',
                    'level_from', 'level_to', 'levels_list', 'lesion_side', 'location', 'index_op_ref', 'histology',
                    'molecular_markers', 'symptom_duration', 'neuro_deficit', 'prior_surgery_same_region', 'note'];

/** Uppercase without accents, for patient name matching (same rule as ZPMS/APYweb). */
function nl_name_match(string $s): string {
    $s = mb_strtoupper($s, 'UTF-8');
    $s = strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ϊ' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ϋ' => 'Υ', 'Ώ' => 'Ω']);
    return preg_replace('/\s+/u', ' ', trim($s));
}

/**
 * Save a validated operation (new when $opId is null). $v from nl_op_validate(). Returns the operation id.
 * Status of coded rows: confirmed if $actorIsSurgeon (the user is the primary surgeon), else suggested; a row whose
 * content is unchanged keeps its previous status. Writes change_log for the operation's fields and row summaries.
 */
function nl_op_save(PDO $pdo, array $v, string $uname, bool $actorIsSurgeon, ?int $opId = null): int {
    $pdo->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        // patient
        if (isset($v['patient']['new'])) {
            $np = $v['patient']['new'];
            $pid = nl_id_new_unique($pdo, 'patients', 'P');
            $pdo->prepare('INSERT INTO patients (nl_id, surname, given_name, name_match, birth_date, sex, match_status, research_consent, source, cuser)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$pid, $np['surname'], $np['given_name'], nl_name_match($np['surname'] . ' ' . ($np['given_name'] ?? '')),
                           $np['birth_date'], $np['sex'] ?: 'unknown', 'new', 'not_asked', 'manual', $uname]);
            $patientRef = (int)$pdo->lastInsertId();
            nl_audit($pdo, $uname, 'create', 'patients', $patientRef, $pid);
        } else $patientRef = $v['patient']['ref'];

        $op = $v['op'];
        $op['patient_ref'] = $patientRef;
        $op['construct'] = $v['construct'];
        $old = null;
        if ($opId === null) {
            $op['nl_id'] = nl_id_new_unique($pdo, 'operations', 'O');
            $op['record_status'] = 'draft'; $op['source'] = 'manual'; $op['cuser'] = $uname;
            $cols = array_keys($op);
            $pdo->prepare('INSERT INTO operations (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($op));
            $opId = (int)$pdo->lastInsertId();
            $nlId = $op['nl_id'];
            nl_audit($pdo, $uname, 'create', 'operations', $opId, $nlId);
        } else {
            $st = $pdo->prepare('SELECT * FROM operations WHERE id = ? AND deleted IS NULL FOR UPDATE');
            $st->execute([$opId]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) throw new RuntimeException('operation not found');
            $nlId = $old['nl_id'];
            $op['udate'] = $now; $op['uuser'] = $uname;
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($op)));
            $pdo->prepare("UPDATE operations SET $set WHERE id = ?")->execute([...array_values($op), $opId]);
            $changed = [];
            foreach ($op as $f => $val) {
                if (in_array($f, ['udate', 'uuser'], true)) continue;
                if ((string)($old[$f] ?? '') !== (string)($val ?? '')) {
                    nl_change($pdo, $uname, 'operations', $opId, $nlId, $f, $old[$f], $val);
                    $changed[] = $f;
                }
            }
            nl_audit($pdo, $uname, 'update', 'operations', $opId, $nlId, $changed ? 'fields: ' . implode(', ', $changed) : 'no field changed');
        }

        // rows: previous status by content
        $prevPr = $prevDx = $prevIm = $prevSu = [];
        $oldPrRows = $oldDxRows = $oldImRows = $oldSuRows = [];
        if ($old) {
            $st = $pdo->prepare('SELECT * FROM procedures WHERE op_ref = ? ORDER BY sort'); $st->execute([$opId]); $oldPrRows = $st->fetchAll(PDO::FETCH_ASSOC);
            $st = $pdo->prepare('SELECT * FROM diagnoses WHERE op_ref = ? ORDER BY sort'); $st->execute([$opId]); $oldDxRows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($oldPrRows as $r) $prevPr[nl_row_signature($r, NL_PR_COLS)] = $r;
            foreach ($oldDxRows as $r) $prevDx[nl_row_signature($r, NL_DX_COLS)] = $r;
            $st = $pdo->prepare('SELECT * FROM implants WHERE op_ref = ? ORDER BY sort'); $st->execute([$opId]); $oldImRows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($oldImRows as $r) $prevIm[nl_row_signature($r, NL_IM_COLS)] = $r;
            $pdo->prepare('DELETE FROM implants WHERE op_ref = ?')->execute([$opId]);
            $st = $pdo->prepare('SELECT * FROM supplies WHERE op_ref = ? ORDER BY sort'); $st->execute([$opId]); $oldSuRows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($oldSuRows as $r) $prevSu[nl_row_signature($r, NL_SU_COLS)] = $r;
            $pdo->prepare('DELETE FROM supplies WHERE op_ref = ?')->execute([$opId]);
            $pdo->prepare('DELETE c FROM rf_cycles c JOIN procedures p ON p.id = c.procedure_ref WHERE p.op_ref = ?')->execute([$opId]);
            $pdo->prepare('DELETE FROM procedures WHERE op_ref = ?')->execute([$opId]);
            $pdo->prepare('DELETE FROM diagnoses WHERE op_ref = ?')->execute([$opId]);
        }
        $status = function (array $row, array $prev, array $cols) use ($actorIsSurgeon, $uname, $now) {
            $sig = nl_row_signature($row, $cols);
            if (isset($prev[$sig])) return [$prev[$sig]['coding_status'], $prev[$sig]['coded_by'], $prev[$sig]['coded_at']];
            // a row proposed by the quick line or a tag and not accepted stays suggested, whoever saves it
            return $actorIsSurgeon && empty($row['_proposed']) ? ['confirmed', $uname, $now] : ['suggested', null, null];
        };
        $dxIds = [];
        foreach ($v['dx'] as $k => $row) {
            [$cs, $cb, $ca] = $status($row, $prevDx, NL_DX_COLS);
            $vals = ['op_ref' => $opId, 'sort' => $k] + array_intersect_key($row, array_flip(NL_DX_COLS))
                  + ['coding_status' => $cs, 'coded_by' => $cb, 'coded_at' => $ca, 'cuser' => $uname];
            $pdo->prepare('INSERT INTO diagnoses (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
                ->execute(array_values($vals));
            $dxIds[$k] = (int)$pdo->lastInsertId();
        }
        $prIds = [];
        $ins = $pdo->prepare('INSERT INTO rf_cycles (procedure_ref, seq, target, side, mode, temp_c, duration_s, voltage_v, current_ma, frequency_hz, pulse_width_ms, impedance_ohm, response)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($v['pr'] as $k => $row) {
            [$cs, $cb, $ca] = $status($row, $prevPr, NL_PR_COLS);
            $vals = ['op_ref' => $opId, 'sort' => $k, 'diagnosis_ref' => $row['_treats'] !== null ? $dxIds[$row['_treats']] : null]
                  + array_intersect_key($row, array_flip(NL_PR_COLS))
                  + ['coding_status' => $cs, 'coded_by' => $cb, 'coded_at' => $ca, 'cuser' => $uname];
            $pdo->prepare('INSERT INTO procedures (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
                ->execute(array_values($vals));
            $prId = (int)$pdo->lastInsertId();
            $prIds[$k] = $prId;
            foreach ($row['_cycles'] as $c) {
                $ins->execute([$prId, $c['seq'], $c['target'], $c['side'] ? substr($c['side'], 0, 1) : null, strtoupper(substr($c['mode'], 0, 1)),
                    $c['temp_c'] ?? null, isset($c['duration_s']) ? (int)round($c['duration_s']) : null, $c['voltage_v'] ?? null, $c['current_ma'] ?? null,
                    $c['frequency_hz'] ?? null, $c['pulse_width_ms'] ?? null, isset($c['impedance_ohm']) ? (int)$c['impedance_ohm'] : null,
                    isset($c['response']) ? (array_search($c['response'], RF_RESPONSES, true) ?: null) : null]);
            }
        }
        foreach ($v['im'] ?? [] as $k => $row) {
            [$cs, $cb, $ca] = $status($row, $prevIm, NL_IM_COLS);
            $vals = ['op_ref' => $opId, 'sort' => $k, 'procedure_ref' => $row['_for'] !== null ? ($prIds[$row['_for']] ?? null) : null]
                  + array_intersect_key($row, array_flip(NL_IM_COLS))
                  + ['coding_status' => $cs, 'coded_by' => $cb, 'coded_at' => $ca, 'cuser' => $uname];
            $pdo->prepare('INSERT INTO implants (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
                ->execute(array_values($vals));
        }
        foreach ($v['su'] ?? [] as $k => $row) {
            // a row saved unchanged keeps where it came from (an APYweb import keeps its source key)
            $prev = $prevSu[nl_row_signature($row, NL_SU_COLS)] ?? null;
            $vals = ['op_ref' => $opId, 'sort' => $k, 'procedure_ref' => $row['_for'] !== null ? ($prIds[$row['_for']] ?? null) : null]
                  + array_intersect_key($row, array_flip(NL_SU_COLS))
                  + ['source' => $prev['source'] ?? 'entered', 'source_key' => $prev['source_key'] ?? null, 'cuser' => $uname];
            $pdo->prepare('INSERT INTO supplies (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
                ->execute(array_values($vals));
        }
        if ($old) {
            $sum = fn(array $rows, string $col) => implode(' | ', array_map(fn($r) => ($r[$col] ?? '') . ' ' . ($r['levels_list'] ?? '') . ' ' . ($r['lesion_side'] ?? ''), $rows));
            $oPr = $sum($oldPrRows, 'procedure_code'); $nPr = $sum($v['pr'], 'procedure_code');
            if ($oPr !== $nPr) nl_change($pdo, $uname, 'operations', $opId, $nlId, 'procedures', $oPr, $nPr);
            $oDx = $sum($oldDxRows, 'diagnosis_code'); $nDx = $sum($v['dx'], 'diagnosis_code');
            if ($oDx !== $nDx) nl_change($pdo, $uname, 'operations', $opId, $nlId, 'diagnoses', $oDx, $nDx);
            $isum = fn(array $rows) => implode(' | ', array_map(fn($r) => ($r['implant_category'] ?? '') . ' x' . ($r['implant_count'] ?? '') . ' ' . ($r['company_ref'] ?? '') . ' ' . ($r['product'] ?? '') . ' ' . ($r['levels_list'] ?? '') . ' ' . ($r['params'] ?? ''), $rows));
            $oIm = $isum($oldImRows); $nIm = $isum($v['im'] ?? []);
            if ($oIm !== $nIm) nl_change($pdo, $uname, 'operations', $opId, $nlId, 'implants', $oIm, $nIm);
            $ssum = fn(array $rows) => implode(' | ', array_map(fn($r) => ($r['supply_kind'] ?? '') . ' ' . ($r['company_ref'] ?? '') . ' ' . ($r['product'] ?? '') . ' ' . ($r['note'] ?? ''), $rows));
            $oSu = $ssum($oldSuRows); $nSu = $ssum($v['su'] ?? []);
            if ($oSu !== $nSu) nl_change($pdo, $uname, 'operations', $opId, $nlId, 'supplies', $oSu, $nSu);
        }
        $pdo->commit();
        return $opId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Confirm the suggested codes of an operation (only its primary surgeon may; the caller checks). */
function nl_op_confirm(PDO $pdo, int $opId, string $nlId, string $uname): int {
    $now = date('Y-m-d H:i:s'); $n = 0;
    foreach (['procedures', 'diagnoses', 'implants'] as $t) {
        $st = $pdo->prepare("UPDATE $t SET coding_status = 'confirmed', coded_by = ?, coded_at = ? WHERE op_ref = ? AND coding_status <> 'confirmed'");
        $st->execute([$uname, $now, $opId]); $n += $st->rowCount();
    }
    nl_audit($pdo, $uname, 'confirm_code', 'operations', $opId, $nlId, "rows: $n");
    return $n;
}

/** Audit row: who did what to which record. Never patient names or values in $detail. */
function nl_audit(PDO $pdo, ?string $uname, string $action, ?string $entity = null, ?int $ref = null, ?string $nlId = null, ?string $detail = null): void {
    $pdo->prepare('INSERT INTO audit_log (uname, action, entity, entity_ref, nl_id, detail, ip) VALUES (?,?,?,?,?,?,?)')
        ->execute([$uname, $action, $entity, $ref, $nlId, $detail !== null ? mb_substr($detail, 0, 255) : null, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function nl_change(PDO $pdo, string $uname, string $entity, int $ref, ?string $nlId, string $field, $old, $new, ?string $reason = null): void {
    $pdo->prepare('INSERT INTO change_log (cuser, entity, entity_ref, nl_id, field, old_value, new_value, reason) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$uname, $entity, $ref, $nlId, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new, $reason]);
}
