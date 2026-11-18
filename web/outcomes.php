<?php
/**
 * Complications, follow-up and scores (M4, docs/plans/m4.md B–D). The functions here are pure (no database) and
 * unit-tested; the pages are in outcome_pages.php. Windows and which scores are asked: vocab/followup.yaml.
 */

const NL_CD_ORDER = ['I', 'II', 'IIIa', 'IIIb', 'IVa', 'IVb', 'V'];

function nl_followup_cfg(?string $root = null): array {
    static $cache = [];
    $root ??= defined('__APPDIR__') ? __APPDIR__ : dirname(__DIR__);
    if (!isset($cache[$root])) {
        if (!function_exists('yaml_parse_file')) require_once(__FWDIR__ . '/lib/yaml_compat.php');
        $cache[$root] = yaml_parse_file("$root/vocab/followup.yaml");
    }
    return $cache[$root];
}

function nl_days_between(string $from, string $to): int {
    return (int)round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
}

function nl_valid_date(string $v): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4));
}

/** The timing class of a complication from its onset, or null if the day count alone cannot tell. */
function nl_complication_timing(int $days): ?string {
    if ($days < 0 || $days > 90) return null;
    return $days <= 1 ? 'lt_48h' : ($days <= 30 ? '48h_30d' : '31d_90d');
}

/**
 * Validate a complication. $in: posted fields; $op: ['id', 'op_date', 'patient_ref']; $ctx: lists, ops
 * [nl_id => ['id', 'op_date', 'patient_ref']]. Returns ['ok', 'row', 'errors', 'missing'].
 */
function nl_complication_validate(array $in, array $op, array $ctx, string $today): array {
    $L = $ctx['lists']; $err = []; $missing = []; $row = [];
    $code = function (string $f, string $list, bool $req) use ($in, $L, &$err, &$row) {
        $v = trim((string)($in[$f] ?? ''));
        if ($v === '') { if ($req) $err[$f] = 'required'; $row[$f] = null; return; }
        if (!isset($L[$list][$v]) || $L[$list][$v]['retired']) { $err[$f] = 'not in the list'; $row[$f] = null; return; }
        $row[$f] = $v;
    };
    $code('complication_type', 'complication', true);
    $code('clavien_dindo', 'field.complication.clavien_dindo', true);
    $code('treatment', 'field.complication.treatment', true);
    $code('timing', 'field.complication.timing', false);
    foreach (['outcome_at_90d', 'related_to_surgery', 'detected_by'] as $f) $code($f, "field.complication.$f", false);
    $d = trim((string)($in['onset_date'] ?? ''));
    $row['onset_date'] = null;
    if ($d !== '') {
        if (!nl_valid_date($d)) $err['onset_date'] = 'not a valid date';
        elseif ($d > $today) $err['onset_date'] = 'in the future';
        else {
            $days = nl_days_between($op['op_date'], $d);
            if ($days < 0) $err['onset_date'] = 'before the operation';
            elseif ($days > 90) $err['onset_date'] = 'more than 90 days after the operation: record it as a diagnosis of the later operation (iatrogenic), linked to this one';
            else {
                $row['onset_date'] = $d;
                $auto = nl_complication_timing($days);
                if ($row['timing'] === null) $row['timing'] = $auto;
                elseif ($row['timing'] === 'intraoperative' ? $days !== 0 : $row['timing'] !== $auto) $err['timing'] = 'does not fit the onset date (' . str_replace('_', ' ', (string)$auto) . ')';
            }
        }
    } else $missing[] = 'onset date';
    if ($row['timing'] === null && !isset($err['timing'])) $missing[] = 'timing';
    // Clavien-Dindo V is death
    if ($row['clavien_dindo'] === 'V' && $row['outcome_at_90d'] !== null && $row['outcome_at_90d'] !== 'died') $err['outcome_at_90d'] = 'Clavien-Dindo V means the patient died';
    if ($row['outcome_at_90d'] === 'died' && $row['clavien_dindo'] !== null && $row['clavien_dindo'] !== 'V') $err['clavien_dindo'] = 'a death from a complication is grade V';
    // the reoperation that treated it
    $row['reoperation_op_ref'] = null;
    $ro = trim((string)($in['reoperation_op'] ?? ''));
    if ($ro !== '') {
        $id = nl_id_normalize($ro, 'O');
        $t = $id !== null ? ($ctx['ops'][$id] ?? null) : null;
        if (!$t) $err['reoperation_op'] = 'not a known operation ID';
        elseif ((int)$t['id'] === (int)$op['id']) $err['reoperation_op'] = 'the reoperation is a different operation';
        elseif ((int)$t['patient_ref'] !== (int)$op['patient_ref']) $err['reoperation_op'] = 'an operation of another patient';
        elseif ($t['op_date'] < $op['op_date']) $err['reoperation_op'] = 'dated before this operation';
        else $row['reoperation_op_ref'] = (int)$t['id'];
    } elseif ($row['treatment'] === 'reoperation') $missing[] = 'the reoperation (its operation ID)';
    if ($row['reoperation_op_ref'] !== null && $row['treatment'] !== null && $row['treatment'] !== 'reoperation') $err['treatment'] = 'a reoperation is linked: treatment is reoperation';
    foreach (['outcome_at_90d', 'related_to_surgery'] as $f) if ($row[$f] === null) $missing[] = str_replace('_', ' ', $f);
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 2000) $err['note'] = 'at most 2000 characters';
    $row['note'] = $note !== '' ? $note : null;
    return ['ok' => !$err, 'row' => $row, 'errors' => $err, 'missing' => $missing];
}

/** Which follow-up windows an operation has: 6w, 3m, 12m and, for fusion or tumour cases, 24m. */
function nl_followup_windows(array $case, ?array $cfg = null): array {
    $cfg ??= nl_followup_cfg();
    $long = $cfg['long_windows_for'];
    $w = ['6w', '3m', '12m'];
    if (in_array($case['construct'] ?? 'none', $long['construct'], true)
        || array_intersect($case['actions'] ?? [], $long['action'])
        || array_intersect($case['classes'] ?? [], $long['pathology_class'])) $w[] = '24m';
    return $w;
}

/** The window a visit on $date falls in ('unscheduled' if none). */
function nl_window_for(string $opDate, string $date, array $windows, ?array $cfg = null): string {
    $cfg ??= nl_followup_cfg();
    $d = nl_days_between($opDate, $date);
    foreach ($windows as $w) { [$a, $b] = $cfg['windows'][$w]; if ($d >= $a && $d <= $b) return $w; }
    return 'unscheduled';
}

/**
 * The scores a case is asked for. $case: procedures [['procedure_code', 'domain', 'region', 'level_kind']],
 * diagnoses [['diagnosis_code', 'pathology_class']], urgency. Returns score codes in the order of vocab/followup.yaml.
 */
function nl_scores_asked(array $case, ?array $cfg = null): array {
    $cfg ??= nl_followup_cfg();
    $groups = [];
    $regionGroup = ['cervical' => 'cervical', 'craniocervical' => 'cervical', 'cervicothoracic' => 'cervical', 'thoracic' => 'thoracic',
                    'thoracolumbar' => 'thoracic', 'lumbar' => 'lumbar', 'lumbosacral' => 'lumbar', 'sacral' => 'lumbar'];
    foreach ($case['procedures'] ?? [] as $p) {
        $dom = $p['domain'] ?? '';
        if ($dom === 'spinal' && isset($regionGroup[$p['region'] ?? ''])) $groups[] = $regionGroup[$p['region']];
        if ($dom === 'cranial' || $dom === 'CSF') $groups[] = 'cranial';
        if (($p['procedure_code'] ?? '') === 'trigeminal_percutaneous') $groups[] = 'trigeminal';
        if ($dom === 'functional_pain' && empty($p['level_kind'])) $groups[] = 'pain';
    }
    foreach ($case['diagnoses'] ?? [] as $d) {
        $c = $d['diagnosis_code'] ?? '';
        if ($c === 'myelopathy') $groups[] = 'myelopathy';
        if ($c === 'epilepsy') $groups[] = 'epilepsy';
        if ($c === 'trigeminal_neuralgia') $groups[] = 'trigeminal';
        if (str_starts_with((string)($d['pathology_class'] ?? ''), 'traumatic')) $groups[] = 'trauma';
    }
    if (($case['urgency'] ?? '') === 'emergency') $groups[] = 'trauma';
    $out = [];
    foreach ($cfg['asked'] as $g => $codes) if (in_array($g, $groups, true)) $out = array_merge($out, $codes);
    $out = array_values(array_unique($out));
    if (!$out) $out = ['eq5d'];
    $order = array_keys($cfg['scores']);
    usort($out, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
    return $out;
}

/**
 * Validate posted scores: $in [code => ['value', 'reason', 'source']]. $asked: codes a complete record needs.
 * Returns [rows [code => ['score', 'value', 'value_code', 'not_done_reason', 'score_source']], errors, missing].
 */
function nl_scores_validate(array $in, array $asked, array $lists, ?array $cfg = null): array {
    $cfg ??= nl_followup_cfg();
    $rows = []; $err = []; $missing = [];
    foreach ($cfg['scores'] as $code => $def) {
        $d = (array)($in[$code] ?? []);
        $v = trim((string)($d['value'] ?? '')); $why = trim((string)($d['reason'] ?? '')); $src = trim((string)($d['source'] ?? ''));
        $p = "sc.$code";
        if ($v === '' && $why === '') { if (in_array($code, $asked, true)) $missing[] = $def[0]; continue; }
        if ($v !== '' && $why !== '') { $err["$p.reason"] = 'a value or a reason it was not done, not both'; continue; }
        if ($src !== '' && !isset($lists['field.score.source'][$src])) $err["$p.source"] = 'not in the list';
        $row = ['score' => $code, 'value' => null, 'value_code' => null, 'not_done_reason' => null, 'score_source' => $src ?: null];
        if ($why !== '') {
            if (mb_strlen($why) > 128) { $err["$p.reason"] = 'at most 128 characters'; continue; }
            $row['not_done_reason'] = $why;
        } elseif ($def[1] === 'code') {
            if (!isset($lists[$def[2]][$v])) { $err["$p.value"] = 'not in the list'; continue; }
            $row['value_code'] = $v;
        } else {
            $n = nl_num($v);
            if ($n === null || ($def[1] === 'int' && floor($n) != $n) || $n < $def[2] || $n > $def[3]) { $err["$p.value"] = ($def[1] === 'int' ? 'a whole number ' : 'a number ') . "{$def[2]}–{$def[3]}"; continue; }
            if ($code === 'kps' && (int)$n % 10 !== 0) { $err["$p.value"] = 'KPS goes in steps of 10'; continue; }
            $row['value'] = $n;
        }
        $rows[$code] = $row;
    }
    return [$rows, $err, $missing];
}

/**
 * Validate a follow-up visit. $in: posted fields; $case: ['op_date', 'windows', 'taken' => windows already recorded
 * (other visits), 'fusion', 'tumour', 'epilepsy', 'trigeminal' bools]. Returns ['ok', 'row', 'errors', 'missing'].
 */
function nl_followup_validate(array $in, array $case, array $lists, string $today, ?array $cfg = null): array {
    $cfg ??= nl_followup_cfg();
    $err = []; $missing = []; $row = [];
    $code = function (string $f, bool $req = false) use ($in, $lists, &$err, &$row) {
        $v = trim((string)($in[$f] ?? ''));
        if ($v === '') { if ($req) $err[$f] = 'required'; $row[$f] = null; return; }
        if (!isset($lists["field.followup.$f"][$v])) { $err[$f] = 'not in the list'; $row[$f] = null; return; }
        $row[$f] = $v;
    };
    $code('status', true);
    foreach (['neuro_status', 'patient_satisfaction', 'return_to_work', 'fusion_status', 'tumour_status', 'seizure_outcome'] as $f) $code($f);
    $date = trim((string)($in['visit_date'] ?? ''));
    $row['visit_date'] = null;
    if ($date !== '') {
        if (!nl_valid_date($date)) $err['visit_date'] = 'not a valid date';
        elseif ($date > $today) $err['visit_date'] = 'in the future';
        elseif ($date < $case['op_date']) $err['visit_date'] = 'before the operation';
        else $row['visit_date'] = $date;
    }
    // the window comes from the date when there is one (outside every window: unscheduled)
    $w = trim((string)($in['fu_window'] ?? ''));
    if ($row['visit_date'] !== null) $w = nl_window_for($case['op_date'], $row['visit_date'], $case['windows'], $cfg);
    if ($w === '') $err['fu_window'] = 'required (or give the visit date)';
    elseif ($w !== 'unscheduled' && !in_array($w, $case['windows'], true)) $err['fu_window'] = 'not a window of this operation';
    elseif ($w !== 'unscheduled' && in_array($w, $case['taken'] ?? [], true)) $err['fu_window'] = "this operation already has a $w follow-up";
    $row['fu_window'] = $w !== '' ? $w : null;
    $seen = in_array($row['status'], ['seen', 'phone'], true);
    if ($seen && $row['visit_date'] === null && !isset($err['visit_date'])) $missing[] = 'visit date';
    // death
    $dd = trim((string)($in['date_of_death'] ?? ''));
    $row['date_of_death'] = null;
    if ($dd !== '') {
        if (!nl_valid_date($dd) || $dd > $today || $dd < $case['op_date']) $err['date_of_death'] = 'a date between the operation and today';
        else $row['date_of_death'] = $dd;
    }
    if ($row['status'] === 'died' && $row['date_of_death'] === null && !isset($err['date_of_death'])) $missing[] = 'date of death';
    if ($row['date_of_death'] !== null && $row['status'] !== 'died') $err['status'] = 'a date of death is given: status is died';
    // no complication: stated explicitly at a visit
    $nc = (string)($in['no_complications'] ?? '');
    $row['no_complications'] = in_array($nc, ['0', '1'], true) ? (int)$nc : null;
    if ($seen && $row['no_complications'] === null) $missing[] = 'complications since the operation (none, or recorded)';
    // case-specific outcomes
    if ($seen) {
        if ($row['neuro_status'] === null) $missing[] = 'neurological status';
        if (!empty($case['fusion']) && $row['fu_window'] === '12m' && $row['fusion_status'] === null) $missing[] = 'fusion status';
        if (!empty($case['tumour']) && $row['tumour_status'] === null) $missing[] = 'tumour status';
        if (!empty($case['epilepsy']) && $row['seizure_outcome'] === null) $missing[] = 'seizure outcome (Engel)';
    }
    $br = nl_multi($in['numbness_branches'] ?? '');
    foreach ($br as $b) if (!isset($lists['field.followup.branches'][$b])) $err['numbness_branches'] = "«{$b}» is not a branch";
    $row['numbness_branches'] = $br ? json_encode(array_values($br)) : null;
    $rd = trim((string)($in['recurrence_date'] ?? ''));
    $row['recurrence_date'] = null;
    if ($rd !== '') {
        if (!nl_valid_date($rd) || $rd > $today || $rd < $case['op_date']) $err['recurrence_date'] = 'a date between the operation and today';
        else $row['recurrence_date'] = $rd;
    }
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 2000) $err['note'] = 'at most 2000 characters';
    $row['note'] = $note !== '' ? $note : null;
    return ['ok' => !$err, 'row' => $row, 'errors' => $err, 'missing' => $missing];
}

/**
 * Follow-up windows still to be done for one operation on $today: [['window', 'from', 'to', 'state' => upcoming|due|overdue]].
 * $done: windows already recorded; $dead: the patient died (no more visits).
 */
function nl_followups_due(string $opDate, array $windows, array $done, bool $dead, string $today, ?array $cfg = null): array {
    $cfg ??= nl_followup_cfg();
    if ($dead) return [];
    $out = [];
    foreach ($windows as $w) {
        if (in_array($w, $done, true)) continue;
        [$a, $b] = $cfg['windows'][$w];
        $from = date('Y-m-d', strtotime("$opDate +$a days")); $to = date('Y-m-d', strtotime("$opDate +$b days"));
        $soon = date('Y-m-d', strtotime("$from -" . (int)$cfg['due_soon_days'] . ' days'));
        if ($today < $soon) continue;
        $out[] = ['window' => $w, 'from' => $from, 'to' => $to, 'state' => $today < $from ? 'upcoming' : ($today <= $to ? 'due' : 'overdue')];
    }
    return $out;
}
