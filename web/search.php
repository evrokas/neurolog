<?php
/**
 * Finding operations (M3; docs/plans/tags-and-chips.md C). Filters are GET parameters, each one value:
 *   procedure facets (one procedure row must match all of them together): proc, level, region, side, approach, route,
 *     technology, branch, rf_mode, coding
 *   diagnosis facets (one diagnosis row): dx, dxlevel
 *   operation: from, to (dates), surgeon, hospital, construct, patient (P- ID)
 *   implant facets (one implant row): implant (category), company, iparam + imin/imax (a parameter of
 *     vocab/implants.yaml within a range, e.g. cage height_mm 10–12)
 *   complications (M4): comp (type), cd (Clavien-Dindo grade or worse)
 * A level filter also finds ranges that cover it (L4-L5 finds L3-L5) and, for a vertebra, the segments around it.
 * The search line (`s`) is read with the quick-line parser (same words and notation) plus a year, and becomes the
 * filters above. Pure functions; the SQL uses bound parameters only.
 */

require_once __DIR__ . '/levels.php';
require_once __DIR__ . '/quickline.php';
require_once __DIR__ . '/implants.php';

/** key => [label, list (for labels and validation) or type] */
function nl_filter_spec(): array {
    return [
        'proc' => ['procedure', 'procedure'], 'level' => ['level', ':level'], 'region' => ['region', ':region'],
        'side' => ['side', 'procedure.side'], 'approach' => ['approach', 'procedure.approach_type'],
        'route' => ['route', ':route'], 'technology' => ['technology', 'procedure.technology'],
        'branch' => ['branches', ':branch'], 'rf_mode' => ['RF', 'procedure.rf_protocol.rf_mode'],
        'coding' => ['codes', ':coding'],
        'dx' => ['diagnosis', 'diagnosis'], 'dxlevel' => ['diagnosis level', ':level'],
        'from' => ['from', ':date'], 'to' => ['to', ':date'], 'surgeon' => ['surgeon', ':member'], 'hospital' => ['hospital', ':hospital'],
        'construct' => ['construct', 'operation.construct'], 'patient' => ['patient', ':patient'],
        'implant' => ['implant', 'field.implant.category'], 'company' => ['company', ':company'],
        'iparam' => ['implant parameter', ':iparam'], 'imin' => ['from', ':num'], 'imax' => ['to', ':num'],
        'comp' => ['complication', 'complication'], 'cd' => ['Clavien-Dindo ≥', 'field.complication.clavien_dindo'],
    ];
}

/** Valid filters from GET values; unknown keys and bad values are dropped (never an error page). */
function nl_filters_from_get(array $get, array $lists, array $members = [], array $hospitals = [], array $companies = []): array {
    $f = [];
    foreach (nl_filter_spec() as $k => [$label, $type]) {
        $v = trim((string)($get[$k] ?? ''));
        if ($v === '' || mb_strlen($v) > 80) continue;
        switch ($type) {
            case ':level':
                $r = nl_levels_parse($v);
                if ($r['ok'] && count($r['items']) === 1) $f[$k] = $r['text'];
                break;
            case ':region':
                if (preg_match('/^[a-z_]{2,40}$/i', $v)) $f[$k] = $v;
                break;
            case ':route':
                if (isset($lists['procedure.approach_route.spinal'][$v]) || isset($lists['procedure.approach_route.cranial'][$v])) $f[$k] = $v;
                break;
            case ':branch':
                if (preg_match('/^(V[123](\+V[123]){0,2}|non_selective)$/', $v)) $f[$k] = $v;
                break;
            case ':coding':
                if (in_array($v, ['confirmed', 'suggested'], true)) $f[$k] = $v;
                break;
            case ':date':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) $f[$k] = $v;
                break;
            case ':member':
                if (ctype_digit($v) && isset($members[(int)$v])) $f[$k] = (int)$v;
                break;
            case ':hospital':
                if (ctype_digit($v) && isset($hospitals[(int)$v])) $f[$k] = (int)$v;
                break;
            case ':company':
                if (ctype_digit($v) && isset($companies[(int)$v])) $f[$k] = (int)$v;
                break;
            case ':iparam':
                foreach (nl_implant_spec() as $params) if (isset($params[$v]) && in_array($params[$v]['type'], ['num', 'int'], true)) { $f[$k] = $v; break; }
                break;
            case ':num':
                if (($n = nl_num($v)) !== null) $f[$k] = $n;
                break;
            case ':patient':
                if (($id = nl_id_normalize($v, 'P')) !== null) $f[$k] = $id;
                break;
            default:
                if (isset($lists[$type][$v])) $f[$k] = $v;
        }
    }
    if (!isset($f['iparam'])) unset($f['imin'], $f['imax']);   // a range needs its parameter
    return $f;
}

/** Every levels_list item that covers a level: the item itself, ranges around it, and for a vertebra the segments. */
function nl_level_candidates(string $level): array {
    $parts = explode('-', $level);
    $a = nl_vertebra_index($parts[0], true); $b = nl_vertebra_index($parts[1] ?? $parts[0], true);
    if ($a === null || $b === null) return [$level];
    $V = NL_VERTEBRAE; $out = [$level]; $n = count($V);
    for ($i = 0; $i < $n; $i++) for ($j = $i; $j < $n; $j++) {
        if ($i > $a || $j < $b) continue;
        $out[] = $i === $j ? $V[$i] : $V[$i] . '-' . $V[$j];
    }
    if ($a === $b) {   // a vertebra: also the segments that touch it
        if ($a > 0) $out[] = $V[$a - 1] . '-' . $V[$a];
        if ($a < $n - 1) $out[] = $V[$a] . '-' . $V[$a + 1];
    }
    return array_values(array_unique($out));
}

/** WHERE clause (without "WHERE") and parameters for the filters; the operations table is aliased `o`. */
function nl_filters_sql(array $f): array {
    $w = ['o.deleted IS NULL']; $p = [];
    // one procedure row must satisfy all procedure filters together
    $pw = [];
    if (isset($f['proc'])) { $pw[] = 'p.procedure_code = ?'; $p[] = $f['proc']; }
    if (isset($f['side'])) { $pw[] = 'p.lesion_side = ?'; $p[] = $f['side']; }
    if (isset($f['approach'])) { $pw[] = 'p.approach_type = ?'; $p[] = $f['approach']; }
    if (isset($f['route'])) { $pw[] = 'p.approach_route = ?'; $p[] = $f['route']; }
    if (isset($f['region'])) { $pw[] = 'p.region = ?'; $p[] = $f['region']; }
    if (isset($f['branch'])) { $pw[] = 'p.branch_pattern = ?'; $p[] = $f['branch']; }
    if (isset($f['rf_mode'])) { $pw[] = 'p.rf_mode = ?'; $p[] = $f['rf_mode']; }
    if (isset($f['coding'])) { $pw[] = $f['coding'] === 'confirmed' ? "p.coding_status = 'confirmed'" : "p.coding_status <> 'confirmed'"; }
    if (isset($f['technology'])) { $pw[] = 'JSON_CONTAINS(p.technology, ?)'; $p[] = json_encode($f['technology']); }
    if (isset($f['level'])) { $pw[] = 'JSON_OVERLAPS(p.levels_list, ?)'; $p[] = json_encode(nl_level_candidates($f['level'])); }
    if ($pw) $w[] = 'EXISTS (SELECT 1 FROM procedures p WHERE p.op_ref = o.id AND ' . implode(' AND ', $pw) . ')';
    $dw = [];
    if (isset($f['dx'])) { $dw[] = 'd.diagnosis_code = ?'; $p[] = $f['dx']; }
    if (isset($f['dxlevel'])) { $dw[] = 'JSON_OVERLAPS(d.levels_list, ?)'; $p[] = json_encode(nl_level_candidates($f['dxlevel'])); }
    if ($dw) $w[] = 'EXISTS (SELECT 1 FROM diagnoses d WHERE d.op_ref = o.id AND ' . implode(' AND ', $dw) . ')';
    // one implant row must satisfy all implant filters together
    $iw = [];
    if (isset($f['implant'])) { $iw[] = 'i.implant_category = ?'; $p[] = $f['implant']; }
    if (isset($f['company'])) { $iw[] = 'i.company_ref = ?'; $p[] = $f['company']; }
    if (isset($f['iparam'])) {
        $path = '$.' . $f['iparam'];   // a known parameter name (checked in nl_filters_from_get)
        $iw[] = "JSON_EXTRACT(i.params, '$path') IS NOT NULL";
        if (isset($f['imin'])) { $iw[] = "CAST(JSON_EXTRACT(i.params, '$path') AS DECIMAL(10,2)) >= ?"; $p[] = $f['imin']; }
        if (isset($f['imax'])) { $iw[] = "CAST(JSON_EXTRACT(i.params, '$path') AS DECIMAL(10,2)) <= ?"; $p[] = $f['imax']; }
    }
    if ($iw) $w[] = 'EXISTS (SELECT 1 FROM implants i WHERE i.op_ref = o.id AND ' . implode(' AND ', $iw) . ')';
    $cw = [];
    if (isset($f['comp'])) { $cw[] = 'c.complication_type = ?'; $p[] = $f['comp']; }
    if (isset($f['cd'])) { $cw[] = "FIELD(c.clavien_dindo, 'I','II','IIIa','IIIb','IVa','IVb','V') >= FIELD(?, 'I','II','IIIa','IIIb','IVa','IVb','V')"; $p[] = $f['cd']; }
    if ($cw) $w[] = 'EXISTS (SELECT 1 FROM complications c WHERE c.op_ref = o.id AND c.deleted IS NULL AND ' . implode(' AND ', $cw) . ')';
    if (isset($f['from'])) { $w[] = 'o.op_date >= ?'; $p[] = $f['from']; }
    if (isset($f['to'])) { $w[] = 'o.op_date <= ?'; $p[] = $f['to']; }
    if (isset($f['surgeon'])) { $w[] = 'o.primary_surgeon_ref = ?'; $p[] = $f['surgeon']; }
    if (isset($f['hospital'])) { $w[] = 'o.hospital_ref = ?'; $p[] = $f['hospital']; }
    if (isset($f['construct'])) { $w[] = 'o.construct = ?'; $p[] = $f['construct']; }
    if (isset($f['patient'])) { $w[] = 'o.patient_ref = (SELECT id FROM patients WHERE nl_id = ?)'; $p[] = $f['patient']; }
    return [implode(' AND ', $w), $p];
}

/**
 * The search line -> filters: the quick-line parser reads procedure, diagnosis, levels, side and approach; a year
 * (2026) or a year range (2024-2026) becomes from/to. Words it cannot place are returned, not guessed.
 */
function nl_search_line(string $s, array $aliases, array $diadermiki): array {
    $f = []; $rest = $s;
    if (preg_match('/(?<!\d)((?:19|20)\d{2})(?:\s*[-–]\s*((?:19|20)\d{2}))?(?!\d)/u', $rest, $m)) {
        $f['from'] = "$m[1]-01-01"; $f['to'] = ($m[2] ?? $m[1]) . '-12-31';
        $rest = str_replace($m[0], ' ', $rest);
    }
    $q = nl_quickline_parse($rest, $aliases, []);   // no «Διαδερμική» defaults when searching: only what was typed
    $pr = $q['procedures'][0] ?? null; $dx = $q['diagnoses'][0] ?? null;
    $norm = nl_ql_norm($rest);
    if ($pr) {
        $f['proc'] = $pr['procedure_code'];
        if ($pr['levels'] !== '') { $r = nl_levels_parse($pr['levels']); if ($r['ok'] && count($r['items']) === 1) $f['level'] = $r['text']; }
        if ($pr['lesion_side']) $f['side'] = $pr['lesion_side'];
        $ap = $pr['approach_type'] ?? nl_ql_approach($norm);
        if (!$ap && preg_match('/ΔΙΑΔΕΡΜ|ΔΙΑΔΕΜΙΚ/u', $norm)) $ap = in_array($pr['procedure_code'], $diadermiki['percutaneous'] ?? [], true) ? 'percutaneous' : 'tubular_MIS';
        if ($ap) $f['approach'] = $ap;
        if ($pr['approach_route']) $f['route'] = $pr['approach_route'];
    } else {
        $lv = nl_ql_levels($norm);
        if ($lv !== '') { $r = nl_levels_parse($lv); if ($r['ok'] && count($r['items']) === 1) $f[$dx ? 'dxlevel' : 'level'] = $r['text']; }
        if ($s = nl_ql_side($norm)) $f['side'] = $s;
        if ($a = nl_ql_approach($norm)) $f['approach'] = $a;
    }
    if ($dx) {
        $f['dx'] = $dx['diagnosis_code'];
        if (!$pr && $dx['levels'] !== '') { $r = nl_levels_parse($dx['levels']); if ($r['ok'] && count($r['items']) === 1) $f['dxlevel'] = $r['text']; }
    }
    return ['filters' => $f, 'unplaced' => $q['unplaced'], 'more' => max(0, count($q['procedures']) - 1)];
}
