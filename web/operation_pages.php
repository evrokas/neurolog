<?php
/**
 * Operation pages (M2): list (minimal; filters come in M3), new, view, edit, confirm codes, patient search.
 * Every view of an operation and every list is written to audit_log; values never go into the audit detail.
 */

/** Context for nl_op_validate(): lists, members, hospitals, patient and operation IDs. */
function nl_op_ctx(PDO $db, array $keep = []): array {
    return [
        'lists' => nl_lists($db),
        'members' => $db->query('SELECT id, kind FROM members')->fetchAll(PDO::FETCH_KEY_PAIR),
        'hospitals' => array_fill_keys($db->query('SELECT id FROM hospitals')->fetchAll(PDO::FETCH_COLUMN), true),
        'ops' => $db->query('SELECT nl_id, id FROM operations WHERE deleted IS NULL')->fetchAll(PDO::FETCH_KEY_PAIR),
        'patients' => array_fill_keys($db->query('SELECT id FROM patients WHERE deleted IS NULL')->fetchAll(PDO::FETCH_COLUMN), true),
        'keep' => $keep,
    ];
}

function nl_can_edit(): bool { return (bool)array_intersect(nl_user_roles(), ['surgeon', 'admin']); }

/** Member id(s) linked to the signed-in account. */
function nl_my_member_ids(PDO $db): array {
    global $kernel;
    $st = $db->prepare('SELECT id FROM members WHERE uname = ?'); $st->execute([$kernel->getUserName()]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Data the form page needs: lists as JSON for the chip pickers, members, hospitals, frequent values. */
function nl_form_data(PDO $db): array {
    $lists = nl_lists($db);
    $js = [];
    foreach ($lists as $list => $vals) {
        foreach ($vals as $code => $v) {
            $js[$list][] = [$code, $v['label_el'], $v['label_en'], $v['parent'], $v['extra'] ?: null, $v['retired'] ? 1 : 0];
        }
    }
    foreach (nl_locations($lists) as $code => $label) $js['location'][] = [$code, $label, null, null, null, 0];
    // the signed-in surgeon's most used values per field (confirmed rows of operations they operated), ZPMS-style
    $freq = [];
    $mine = nl_my_member_ids($db);
    if ($mine) {
        $in = implode(',', $mine);
        $map = ['procedure_code' => 'procedure', 'approach_type' => 'procedure.approach_type', 'approach_route' => 'procedure.approach_route.spinal',
                'technology' => 'procedure.technology', 'lesion_side' => 'procedure.side'];
        foreach ($map as $col => $list) {
            $rows = $db->query("SELECT p.$col AS v FROM procedures p JOIN operations o ON o.id = p.op_ref
                                 WHERE o.deleted IS NULL AND o.primary_surgeon_ref IN ($in) AND p.coding_status = 'confirmed' AND p.$col IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $v) foreach (str_starts_with($v, '[') ? json_decode($v, true) : [$v] as $c) $freq[$list][$c] = ($freq[$list][$c] ?? 0) + 1;
        }
        $rows = $db->query("SELECT d.diagnosis_code FROM diagnoses d JOIN operations o ON o.id = d.op_ref
                             WHERE o.deleted IS NULL AND o.primary_surgeon_ref IN ($in) AND d.coding_status = 'confirmed'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $c) $freq['diagnosis'][$c] = ($freq['diagnosis'][$c] ?? 0) + 1;
        $freq['procedure.approach_route.cranial'] = $freq['procedure.approach_route.spinal'] ?? [];
    }
    $members = $db->query('SELECT id, name, kind, active FROM members ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $hospitals = $db->query('SELECT id, name, active FROM hospitals ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    return ['lists_json' => json_encode($js, $flags), 'freq_json' => json_encode((object)$freq, $flags),
            'members' => $members, 'hospitals' => $hospitals, 'lists' => $lists, 'my_members' => $mine];
}

/** A stored operation as form input (the same shape as a POST). */
function nl_op_as_input(PDO $db, array $op): array {
    $in = ['patient_ref' => (string)$op['patient_ref'], 'op' => [], 'dx' => [], 'pr' => [], 'main_dx' => '0', 'main_pr' => '0'];
    foreach (nl_op_fields() as $f => [$type]) $in['op'][$f] = $op[$f] === null ? '' : (string)$op[$f];
    $in['op']['reoperation_of'] = '';
    if ($op['reoperation_of_ref']) {
        $st = $db->prepare('SELECT nl_id FROM operations WHERE id = ?'); $st->execute([$op['reoperation_of_ref']]);
        $in['op']['reoperation_of'] = nl_id_format((string)$st->fetchColumn());
    }
    $st = $db->prepare('SELECT * FROM diagnoses WHERE op_ref = ? ORDER BY sort'); $st->execute([$op['id']]);
    $dxPos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k => $d) {
        $dxPos[$d['id']] = (string)$k;
        if ($d['is_main']) $in['main_dx'] = (string)$k;
        $row = ['diagnosis_code' => $d['diagnosis_code'], 'levels' => $d['levels_list'] ? implode(', ', json_decode($d['levels_list'], true)) : '',
                'location' => $d['location'] ?? '', 'q' => $d['qualifiers'] ? json_decode($d['qualifiers'], true) : [], 'index_op' => '',
                'prior_surgery_same_region' => $d['prior_surgery_same_region'] === null ? '' : (string)$d['prior_surgery_same_region'],
                'histology' => $d['histology'] ?? '', 'note' => $d['note'] ?? '', '_status' => $d['coding_status']];
        foreach (nl_dx_fields() as $f => $_) $row[$f] = $d[$f] ?? '';
        if ($d['index_op_ref']) { $s2 = $db->prepare('SELECT nl_id FROM operations WHERE id = ?'); $s2->execute([$d['index_op_ref']]); $row['index_op'] = nl_id_format((string)$s2->fetchColumn()); }
        $in['dx'][(string)$k] = $row;
    }
    $st = $db->prepare('SELECT * FROM procedures WHERE op_ref = ? ORDER BY sort'); $st->execute([$op['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k => $p) {
        if ($p['is_main']) $in['main_pr'] = (string)$k;
        $row = ['procedure_code' => $p['procedure_code'], 'levels' => $p['levels_list'] ? implode(', ', json_decode($p['levels_list'], true)) : '',
                'transitional' => in_array('L6', [$p['level_from'], $p['level_to']], true) ? '1' : '',
                'location' => $p['location'] ?? '', 'approach_route' => $p['approach_route'] ?? '', 'compartment' => $p['compartment'] ?? '',
                'product' => $p['product'] ?? '', 'trigeminal_technique' => $p['trigeminal_technique'] ?? '', 'branches_treated' => $p['branches_treated'] ?? '',
                'rf_protocol' => $p['rf_protocol'] ?? '', 'action' => $p['action'] ?? '', 'treats' => $p['diagnosis_ref'] !== null ? ($dxPos[$p['diagnosis_ref']] ?? '') : '',
                '_status' => $p['coding_status']];
        foreach (nl_proc_fields() as $f => $_) $row[$f] = $p[$f] ?? '';
        $in['pr'][(string)$k] = $row;
    }
    return $in;
}

/** Codes already stored on an operation (a retired value may stay on it). */
function nl_op_keep(PDO $db, int $opId): array {
    $keep = [];
    foreach (['procedures', 'diagnoses'] as $t) {
        $st = $db->prepare("SELECT * FROM $t WHERE op_ref = ?"); $st->execute([$opId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) foreach ($r as $v) {
            if (!is_string($v) || $v === '') continue;
            foreach (str_starts_with($v, '[') ? (json_decode($v, true) ?: []) : (str_starts_with($v, '{') ? array_merge(...array_map(fn($x) => (array)$x, array_values(json_decode($v, true) ?: []))) : [$v]) as $c) $keep[] = (string)$c;
        }
    }
    return array_values(array_unique($keep));
}

function nl_op_load(PDO $db, string $nlId): ?array {
    $id = nl_id_normalize($nlId, 'O');
    if ($id === null) return null;
    $st = $db->prepare('SELECT * FROM operations WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nl_patient_brief(PDO $db, int $ref): ?array {
    $st = $db->prepare('SELECT id, nl_id, surname, given_name, birth_date, sex FROM patients WHERE id = ?'); $st->execute([$ref]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if ($p) $p['nl_id_display'] = nl_id_format($p['nl_id']);
    return $p ?: null;
}

function nl_render_form(PDO $db, array $in, array $errors, array $missing, ?array $op): string {
    $data = nl_form_data($db);
    $patient = ($in['patient_ref'] ?? '') !== '' ? nl_patient_brief($db, (int)$in['patient_ref']) : null;
    if (!$in['dx']) $in['dx'] = ['0' => []];
    if (!$in['pr']) $in['pr'] = ['0' => []];
    return Renderer::render('operation_form.zetem', $data + [
        'in' => $in, 'errors' => $errors, 'missing' => $missing, 'op' => $op, 'patient' => $patient,
        'action' => $op ? rel_url('/operations/' . nl_id_format($op['nl_id']) . '/edit') : rel_url('/operations/new'),
        'presets' => [],
    ]);
}

function nl_page_operation_new($params) {
    $db = dbConnection::getConnection();
    $mine = nl_my_member_ids($db);
    $in = ['patient_ref' => '', 'op' => ['op_date' => date('Y-m-d'), 'primary_surgeon_ref' => $mine ? (string)$mine[0] : '', 'reoperation' => 'primary'],
           'dx' => [], 'pr' => [], 'main_dx' => '0', 'main_pr' => '0'];
    return nl_render_form($db, $in, [], [], null);
}

function nl_page_operation_new_post($params) { return nl_operation_save_post(null); }
function nl_page_operation_edit_post($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    return nl_operation_save_post($op);
}

function nl_operation_save_post(?array $op) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $in = ['patient_ref' => (string)($_POST['patient_ref'] ?? ''), 'new_patient' => (array)($_POST['new_patient'] ?? []),
           'op' => (array)($_POST['op'] ?? []), 'dx' => (array)($_POST['dx'] ?? []), 'pr' => (array)($_POST['pr'] ?? []),
           'main_dx' => (string)($_POST['main_dx'] ?? '0'), 'main_pr' => (string)($_POST['main_pr'] ?? '0')];
    if ($op) $in['patient_ref'] = (string)$op['patient_ref'];   // the patient of an existing operation is not changed here
    $v = nl_op_validate($in, nl_op_ctx($db, $op ? nl_op_keep($db, (int)$op['id']) : []));
    if (!$v['ok']) {
        http_response_code(422);
        return nl_render_form($db, $in, $v['errors'], $v['missing'], $op);
    }
    $isSurgeon = in_array((int)$v['op']['primary_surgeon_ref'], nl_my_member_ids($db), true);
    $id = nl_op_save($db, $v, $kernel->getUserName(), $isSurgeon, $op ? (int)$op['id'] : null);
    $st = $db->prepare('SELECT nl_id FROM operations WHERE id = ?'); $st->execute([$id]);
    $kernel->addStatus('status', $isSurgeon ? 'Saved; codes confirmed' : 'Saved; codes suggested until the primary surgeon confirms them');
    header('location: ' . rel_url('/operations/' . nl_id_format((string)$st->fetchColumn())));
    exit();
}

function nl_page_operation_edit($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    global $kernel;
    nl_audit($db, $kernel->getUserName(), 'view', 'operations', (int)$op['id'], $op['nl_id'], 'edit form');
    return nl_render_form($db, nl_op_as_input($db, $op), [], [], $op);
}

function nl_page_operation($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    nl_audit($db, $kernel->getUserName(), 'view', 'operations', (int)$op['id'], $op['nl_id']);
    $lists = nl_lists($db);
    $names = $db->query('SELECT id, name FROM members')->fetchAll(PDO::FETCH_KEY_PAIR);
    $hosp = $db->query('SELECT id, name FROM hospitals')->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $db->prepare('SELECT * FROM diagnoses WHERE op_ref = ? ORDER BY sort'); $st->execute([$op['id']]); $dx = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT * FROM procedures WHERE op_ref = ? ORDER BY sort'); $st->execute([$op['id']]); $pr = $st->fetchAll(PDO::FETCH_ASSOC);
    $cyc = [];
    foreach ($pr as $p) { $s = $db->prepare('SELECT * FROM rf_cycles WHERE procedure_ref = ? ORDER BY seq'); $s->execute([$p['id']]); $cyc[$p['id']] = $s->fetchAll(PDO::FETCH_ASSOC); }
    $suggested = count(array_filter(array_merge($pr, $dx), fn($r) => $r['coding_status'] !== 'confirmed'));
    $canConfirm = $suggested && in_array((int)$op['primary_surgeon_ref'], nl_my_member_ids($db), true);
    $in = nl_op_as_input($db, $op);
    $v = nl_op_validate($in, nl_op_ctx($db, nl_op_keep($db, (int)$op['id'])));
    $st = $db->prepare('SELECT cdate, cuser, field FROM change_log WHERE entity = ? AND entity_ref = ? ORDER BY id DESC LIMIT 30');
    $st->execute(['operations', $op['id']]);
    return Renderer::render('operation_view.zetem', [
        'op' => $op, 'nl' => nl_id_format($op['nl_id']), 'patient' => nl_patient_brief($db, (int)$op['patient_ref']),
        'dx' => $dx, 'pr' => $pr, 'cycles' => $cyc, 'lists' => $lists, 'names' => $names, 'hosp' => $hosp,
        'can_edit' => nl_can_edit(), 'can_confirm' => $canConfirm, 'suggested' => $suggested,
        'missing' => $v['missing'], 'history' => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

function nl_page_operation_confirm_post($params) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    if (!in_array((int)$op['primary_surgeon_ref'], nl_my_member_ids($db), true)) return error_403('Only the primary surgeon confirms the codes of an operation.');
    $n = nl_op_confirm($db, (int)$op['id'], $op['nl_id'], $kernel->getUserName());
    $kernel->addStatus('status', "$n codes confirmed");
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id']))); exit();
}

function nl_page_operations($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $rows = $db->query("SELECT o.id, o.nl_id, o.op_date, o.record_status, o.construct, pa.surname, pa.given_name, m.name AS surgeon,
                               p.procedure_code, p.levels_list, p.lesion_side, p.approach_type, p.location,
                               (SELECT COUNT(*) FROM procedures x WHERE x.op_ref = o.id AND x.coding_status <> 'confirmed')
                             + (SELECT COUNT(*) FROM diagnoses y WHERE y.op_ref = o.id AND y.coding_status <> 'confirmed') AS suggested
                          FROM operations o
                          JOIN patients pa ON pa.id = o.patient_ref
                          LEFT JOIN members m ON m.id = o.primary_surgeon_ref
                          LEFT JOIN procedures p ON p.op_ref = o.id AND p.is_main = 1
                         WHERE o.deleted IS NULL ORDER BY o.op_date DESC, o.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    nl_audit($db, $kernel->getUserName(), 'view', 'operations', null, null, 'list: ' . count($rows) . ' rows');
    foreach ($rows as &$r) {
        $r['nl'] = nl_id_format($r['nl_id']);
        $r['where'] = $r['levels_list'] ? implode(', ', json_decode($r['levels_list'], true)) : ($r['location'] ? implode(', ', json_decode($r['location'], true)) : '');
    }
    unset($r);
    return Renderer::render('operations_list.zetem', ['rows' => $rows, 'lists' => nl_lists($db), 'can_edit' => nl_can_edit()]);
}

/** JSON: patients matching a name or NeuroLog ID (at most 15). Session-gated; the term is never logged. */
function nl_page_patient_search($params) {
    global $kernel;
    header('Content-Type: application/json; charset=utf-8');
    $db = dbConnection::getConnection();
    $q = trim((string)($_GET['q'] ?? ''));
    $out = [];
    if (mb_strlen($q) >= 2) {
        $id = nl_id_normalize($q, 'P');
        if ($id !== null) { $st = $db->prepare('SELECT id, nl_id, surname, given_name, birth_date FROM patients WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]); }
        else {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], nl_name_match($q)) . '%';
            $st = $db->prepare('SELECT id, nl_id, surname, given_name, birth_date FROM patients WHERE deleted IS NULL AND name_match LIKE ? ORDER BY surname, given_name LIMIT 15');
            $st->execute([$like]);
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out[] = ['id' => (int)$p['id'], 'nl_id' => nl_id_format($p['nl_id']),
            'name' => trim($p['surname'] . ' ' . ($p['given_name'] ?? '')), 'birth_date' => $p['birth_date']];
        nl_audit($db, $kernel->getUserName(), 'search', 'patients', null, null, 'matches: ' . count($out));
    }
    echo json_encode(['matches' => $out], JSON_UNESCAPED_UNICODE);
    exit();
}

// ---------- template helpers ----------

function nl_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Error message under a field, or ''. */
function nl_ferr(array $errors, string $key): string {
    return isset($errors[$key]) ? '<span class="field-error">' . nl_e($errors[$key]) . '</span>' : '';
}

/**
 * A chip picker (web/js/chips.js): a hidden input holding the code (or a JSON list when $multi) and its list name.
 * $na adds "n/a"; $req marks it required at entry (the server checks either way).
 */
function nl_chip(string $name, string $list, $value, bool $multi = false, bool $na = false, bool $req = false, string $ph = ''): string {
    if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    return '<span class="chipf' . ($req ? ' req' : '') . '" data-list="' . nl_e($list) . '"' . ($multi ? ' data-multi="1"' : '') . ($na ? ' data-na="1"' : '')
         . ($ph !== '' ? ' data-ph="' . nl_e($ph) . '"' : '') . '><input type="hidden" name="' . nl_e($name) . '" value="' . nl_e($value) . '"></span>';
}

/** <select> of members (optionally one kind) or hospitals; inactive ones only if already chosen. */
function nl_select(string $name, array $rows, $value, ?string $kind = null): string {
    $h = '<select name="' . nl_e($name) . '"><option value="">—</option>';
    foreach ($rows as $r) {
        if ($kind !== null && ($r['kind'] ?? '') !== $kind && (string)$r['id'] !== (string)$value) continue;
        if (!$r['active'] && (string)$r['id'] !== (string)$value) continue;
        $h .= '<option value="' . (int)$r['id'] . '"' . ((string)$r['id'] === (string)$value ? ' selected' : '') . '>' . nl_e($r['name']) . '</option>';
    }
    return $h . '</select>';
}

/** yes / no / not entered */
function nl_yesno(string $name, $value): string {
    $h = '<select name="' . nl_e($name) . '">';
    foreach (['' => '—', '1' => 'yes', '0' => 'no'] as $k => $l) $h .= '<option value="' . $k . '"' . ((string)$value === (string)$k ? ' selected' : '') . ">$l</option>";
    return $h . '</select>';
}

/** Label of a stored code or JSON list of codes, for the view page. */
function nl_show(array $lists, string $list, $v): string {
    if ($v === null || $v === '') return '—';
    $codes = str_starts_with((string)$v, '[') ? json_decode($v, true) : [$v];
    return implode(', ', array_map(fn($c) => $c === NL_NA ? 'n/a' : nl_label($lists, $list, $c), $codes));
}

/** vocab/facets.yaml `diadermiki`: how «Διαδερμική» is read per procedure. */
function nl_diadermiki(): array {
    static $d = null;
    if ($d === null) {
        if (!function_exists('yaml_parse_file')) require_once(__FWDIR__ . '/lib/yaml_compat.php');
        $d = yaml_parse_file(__APPDIR__ . '/vocab/facets.yaml')['diadermiki'] ?? [];
    }
    return $d;
}

/** JSON: the quick line (or the operative note) read into SUGGESTED rows. Saves nothing; CSRF in the header. */
function nl_page_operation_parse_post($params) {
    csrfClass::requireValidJson();
    header('Content-Type: application/json; charset=utf-8');
    $text = mb_substr((string)($_POST['text'] ?? ''), 0, 20000);
    echo json_encode(nl_quickline_parse($text, nl_aliases(__APPDIR__), nl_diadermiki()), JSON_UNESCAPED_UNICODE);
    exit();
}
