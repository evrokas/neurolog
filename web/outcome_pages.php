<?php
/**
 * Complication, follow-up and score pages (M4). Rules are in outcomes.php (pure, unit-tested). Every view and save
 * is audited (IDs and field names only). Surgeons and admins enter; viewers read.
 */

require_once __DIR__ . '/outcomes.php';

/** What follow-up needs to know about an operation: windows, scores asked, case flags. */
function nl_case_info(PDO $db, array $op): array {
    $st = $db->prepare('SELECT procedure_code, domain, region, level_kind, action FROM procedures WHERE op_ref = ?'); $st->execute([$op['id']]);
    $pr = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT diagnosis_code, pathology_class FROM diagnoses WHERE op_ref = ?'); $st->execute([$op['id']]);
    $dx = $st->fetchAll(PDO::FETCH_ASSOC);
    $classes = array_column($dx, 'pathology_class'); $actions = array_column($pr, 'action');
    $codes = array_merge(array_column($pr, 'procedure_code'), array_column($dx, 'diagnosis_code'));
    $windows = nl_followup_windows(['construct' => $op['construct'], 'actions' => $actions, 'classes' => $classes]);
    return [
        'op_date' => $op['op_date'], 'windows' => $windows,
        'asked' => nl_scores_asked(['procedures' => $pr, 'diagnoses' => $dx, 'urgency' => $op['indication_urgency']]),
        'fusion' => ($op['construct'] ?? 'none') !== 'none' || in_array('fusion', $actions, true),
        'tumour' => in_array('neoplastic', $classes, true),
        'epilepsy' => in_array('epilepsy', $codes, true),
        'trigeminal' => (bool)array_intersect($codes, ['trigeminal_percutaneous', 'trigeminal_neuralgia']),
    ];
}

/** Outcome data shown on the operation page. */
function nl_op_outcomes(PDO $db, array $op): array {
    $info = nl_case_info($db, $op);
    $st = $db->prepare('SELECT c.*, r.nl_id AS reop_nl FROM complications c LEFT JOIN operations r ON r.id = c.reoperation_op_ref
                         WHERE c.op_ref = ? AND c.deleted IS NULL ORDER BY c.onset_date, c.id'); $st->execute([$op['id']]);
    $comps = $st->fetchAll(PDO::FETCH_ASSOC);
    // complications of earlier operations that this operation treated
    $st = $db->prepare('SELECT c.nl_id, c.complication_type, o.nl_id AS op_nl FROM complications c JOIN operations o ON o.id = c.op_ref
                         WHERE c.reoperation_op_ref = ? AND c.deleted IS NULL'); $st->execute([$op['id']]);
    $treats = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT * FROM followups WHERE op_ref = ? AND deleted IS NULL ORDER BY visit_date IS NULL, visit_date, id'); $st->execute([$op['id']]);
    $fus = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT * FROM scores WHERE op_ref = ? ORDER BY followup_ref IS NOT NULL, id'); $st->execute([$op['id']]);
    $scores = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $scores[$s['followup_ref'] ?? 'base'][$s['score']] = $s;
    $done = array_column($fus, 'fu_window');
    $dead = (bool)array_filter($fus, fn($f) => $f['status'] === 'died');
    $due = [];
    foreach (nl_followups_due($op['op_date'], $info['windows'], $done, $dead, date('Y-m-d')) as $d) $due[$d['window']] = $d;
    return ['info' => $info, 'comps' => $comps, 'treats' => $treats, 'fus' => $fus, 'scores' => $scores, 'due' => $due];
}

/** "VAS back 3 · ODI % 22 · EQ-5D index not done" */
function nl_scores_line(array $scores): string {
    $cfg = nl_followup_cfg();
    return implode(' · ', array_map(fn($s) => ($cfg['scores'][$s['score']][0] ?? $s['score']) . ' ' . nl_score_text($s, $cfg), array_values($scores)));
}

function nl_score_text(array $s, array $cfg): string {
    if ($s['not_done_reason'] !== null) return 'not done';
    if ($s['value_code'] !== null) return $s['value_code'];
    return rtrim(rtrim(number_format((float)$s['value'], 2, '.', ''), '0'), '.');
}

// ---------- complications ----------

function nl_comp_ctx(PDO $db): array {
    $ops = [];
    foreach ($db->query('SELECT id, nl_id, op_date, patient_ref FROM operations WHERE deleted IS NULL') as $r) $ops[$r['nl_id']] = $r;
    return ['lists' => nl_lists($db), 'ops' => $ops];
}

function nl_comp_load(PDO $db, string $id): ?array {
    $id = nl_id_normalize($id, 'C');
    if ($id === null) return null;
    $st = $db->prepare('SELECT * FROM complications WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nl_op_by_id(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM operations WHERE id = ? AND deleted IS NULL'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nl_render_comp(PDO $db, array $op, ?array $comp, array $in, array $errors, array $missing) {
    return Renderer::render('complication_form.zetem', ['op' => $op, 'nl' => nl_id_format($op['nl_id']), 'comp' => $comp, 'in' => $in,
        'errors' => $errors, 'missing' => $missing, 'patient' => nl_patient_brief($db, (int)$op['patient_ref']),
        'lists_json' => nl_form_data($db)['lists_json']]);
}

function nl_page_complication_new($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    return nl_render_comp($db, $op, null, ['detected_by' => '', 'onset_date' => ''], [], []);
}

function nl_page_complication_edit($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $c = nl_comp_load($db, (string)($params['id'] ?? ''));
    if (!$c || !($op = nl_op_by_id($db, (int)$c['op_ref']))) return error_404();
    nl_audit($db, $kernel->getUserName(), 'view', 'complications', (int)$c['id'], $c['nl_id'], 'edit form');
    $in = $c;
    if ($c['reoperation_op_ref']) { $r = nl_op_by_id($db, (int)$c['reoperation_op_ref']); $in['reoperation_op'] = $r ? nl_id_format($r['nl_id']) : ''; }
    return nl_render_comp($db, $op, $c, $in, [], []);
}

function nl_page_complication_new_post($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    return nl_complication_save($db, $op, null);
}

function nl_page_complication_edit_post($params) {
    $db = dbConnection::getConnection();
    $c = nl_comp_load($db, (string)($params['id'] ?? ''));
    if (!$c || !($op = nl_op_by_id($db, (int)$c['op_ref']))) return error_404();
    return nl_complication_save($db, $op, $c);
}

function nl_complication_save(PDO $db, array $op, ?array $c) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $in = (array)($_POST['c'] ?? []);
    $v = nl_complication_validate($in, $op, nl_comp_ctx($db), date('Y-m-d'));
    if (!$v['ok']) { http_response_code(422); return nl_render_comp($db, $op, $c, $in, $v['errors'], $v['missing']); }
    $u = $kernel->getUserName(); $row = $v['row'];
    if ($c) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        $db->prepare("UPDATE complications SET $set, udate = NOW(), uuser = ? WHERE id = ?")->execute([...array_values($row), $u, $c['id']]);
        $changed = [];
        foreach ($row as $k => $val) if ((string)($c[$k] ?? '') !== (string)($val ?? '')) { nl_change($db, $u, 'complications', (int)$c['id'], $c['nl_id'], $k, $c[$k], $val); $changed[] = $k; }
        nl_audit($db, $u, 'update', 'complications', (int)$c['id'], $c['nl_id'], $changed ? 'fields: ' . implode(', ', $changed) : 'no field changed');
    } else {
        $row += ['nl_id' => nl_id_new_unique($db, 'complications', 'C'), 'op_ref' => $op['id'], 'cuser' => $u];
        $db->prepare('INSERT INTO complications (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
           ->execute(array_values($row));
        nl_audit($db, $u, 'create', 'complications', (int)$db->lastInsertId(), $row['nl_id'], 'of ' . $op['nl_id']);
    }
    $kernel->addStatus('notice', $v['missing'] ? 'Saved; still missing: ' . htmlspecialchars(implode(', ', $v['missing']), ENT_QUOTES, 'UTF-8') : 'Saved');
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit();
}

function nl_page_complication_delete_post($params) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $c = nl_comp_load($db, (string)($params['id'] ?? ''));
    if (!$c || !($op = nl_op_by_id($db, (int)$c['op_ref']))) return error_404();
    $db->prepare('UPDATE complications SET deleted = NOW(), udate = NOW(), uuser = ? WHERE id = ?')->execute([$kernel->getUserName(), $c['id']]);
    nl_audit($db, $kernel->getUserName(), 'delete', 'complications', (int)$c['id'], $c['nl_id']);
    $kernel->addStatus('notice', 'Complication removed');
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit();
}

// ---------- follow-up and scores ----------

function nl_fu_load(PDO $db, string $id): ?array {
    $id = nl_id_normalize($id, 'F');
    if ($id === null) return null;
    $st = $db->prepare('SELECT * FROM followups WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Stored scores as form input. */
function nl_scores_as_input(PDO $db, int $opId, ?int $fuId): array {
    $st = $fuId === null ? $db->prepare('SELECT * FROM scores WHERE op_ref = ? AND followup_ref IS NULL') : $db->prepare('SELECT * FROM scores WHERE op_ref = ? AND followup_ref = ?');
    $st->execute($fuId === null ? [$opId] : [$opId, $fuId]);
    $in = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $in[$s['score']] = ['value' => $s['value_code'] ?? ($s['value'] !== null ? rtrim(rtrim((string)$s['value'], '0'), '.') : ''),
                            'reason' => $s['not_done_reason'] ?? '', 'source' => $s['score_source'] ?? ''];
    }
    return $in;
}

/** Replace the scores of a visit (or the baseline) and log the changes. */
function nl_scores_save(PDO $db, array $op, ?int $fuId, array $rows, string $u, ?string $nlId): void {
    $old = nl_scores_as_input($db, (int)$op['id'], $fuId);
    if ($fuId === null) $db->prepare('DELETE FROM scores WHERE op_ref = ? AND followup_ref IS NULL')->execute([$op['id']]);
    else $db->prepare('DELETE FROM scores WHERE op_ref = ? AND followup_ref = ?')->execute([$op['id'], $fuId]);
    $ins = $db->prepare('INSERT INTO scores (op_ref, followup_ref, score, value, value_code, not_done_reason, score_source, cuser) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($rows as $r) $ins->execute([$op['id'], $fuId, $r['score'], $r['value'], $r['value_code'], $r['not_done_reason'], $r['score_source'], $u]);
    $new = nl_scores_as_input($db, (int)$op['id'], $fuId);
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
        if (json_encode($old[$k] ?? null) !== json_encode($new[$k] ?? null)) {
            nl_change($db, $u, $fuId === null ? 'operations' : 'followups', $fuId ?? (int)$op['id'], $nlId, "score $k",
                      isset($old[$k]) ? json_encode($old[$k]) : null, isset($new[$k]) ? json_encode($new[$k]) : null);
        }
    }
}

function nl_scores_view(PDO $db, array $info, array $in, array $errors): array {
    $cfg = nl_followup_cfg();
    $all = [];
    foreach ($cfg['scores'] as $code => $def) $all[$code] = ['label' => $def[0], 'type' => $def[1], 'min' => $def[2], 'max' => $def[3] ?? null,
        'asked' => in_array($code, $info['asked'], true), 'shown' => in_array($code, $info['asked'], true) || isset($in[$code])];
    return $all;
}

function nl_render_fu(PDO $db, array $op, ?array $fu, array $in, array $sc, array $errors, array $missing) {
    $info = nl_case_info($db, $op);
    $st = $db->prepare('SELECT fu_window FROM followups WHERE op_ref = ? AND deleted IS NULL AND id <> ?'); $st->execute([$op['id'], $fu['id'] ?? 0]);
    $cfg = nl_followup_cfg();
    $winText = [];
    foreach ($info['windows'] as $w) { [$a, $b] = $cfg['windows'][$w]; $winText[$w] = date('Y-m-d', strtotime("{$op['op_date']} +$a days")) . ' – ' . date('Y-m-d', strtotime("{$op['op_date']} +$b days")); }
    return Renderer::render('followup_form.zetem', ['op' => $op, 'nl' => nl_id_format($op['nl_id']), 'fu' => $fu, 'in' => $in, 'sc' => $sc,
        'errors' => $errors, 'missing' => $missing, 'info' => $info, 'taken' => $st->fetchAll(PDO::FETCH_COLUMN), 'win_text' => $winText,
        'scores' => nl_scores_view($db, $info, $sc, $errors), 'patient' => nl_patient_brief($db, (int)$op['patient_ref']),
        'lists_json' => nl_form_data($db)['lists_json'], 'baseline' => false]);
}

function nl_page_followup_new($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    $w = (string)($_GET['window'] ?? '');
    return nl_render_fu($db, $op, null, ['fu_window' => preg_match('/^(6w|3m|12m|24m|unscheduled)$/', $w) ? $w : '', 'status' => 'seen', 'visit_date' => date('Y-m-d')], [], [], []);
}

function nl_page_followup_edit($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $fu = nl_fu_load($db, (string)($params['id'] ?? ''));
    if (!$fu || !($op = nl_op_by_id($db, (int)$fu['op_ref']))) return error_404();
    nl_audit($db, $kernel->getUserName(), 'view', 'followups', (int)$fu['id'], $fu['nl_id'], 'edit form');
    if ($fu['locked_at']) { $kernel->addStatus('warning', 'This follow-up is locked; an administrator can unlock it.'); header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit(); }
    $in = $fu; $in['numbness_branches'] = $fu['numbness_branches'] ?? '';
    return nl_render_fu($db, $op, $fu, $in, nl_scores_as_input($db, (int)$op['id'], (int)$fu['id']), [], []);
}

function nl_page_followup_new_post($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    return nl_followup_save($db, $op, null);
}

function nl_page_followup_edit_post($params) {
    $db = dbConnection::getConnection();
    $fu = nl_fu_load($db, (string)($params['id'] ?? ''));
    if (!$fu || !($op = nl_op_by_id($db, (int)$fu['op_ref']))) return error_404();
    if ($fu['locked_at']) return error_403('This follow-up is locked.');
    return nl_followup_save($db, $op, $fu);
}

function nl_followup_save(PDO $db, array $op, ?array $fu) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $lists = nl_lists($db);
    $in = (array)($_POST['f'] ?? []); $sc = (array)($_POST['sc'] ?? []);
    $info = nl_case_info($db, $op);
    $st = $db->prepare("SELECT fu_window FROM followups WHERE op_ref = ? AND deleted IS NULL AND id <> ? AND fu_window <> 'unscheduled'");
    $st->execute([$op['id'], $fu['id'] ?? 0]);
    $v = nl_followup_validate($in, $info + ['taken' => $st->fetchAll(PDO::FETCH_COLUMN)], $lists, date('Y-m-d'));
    $seen = in_array($v['row']['status'], ['seen', 'phone'], true);
    [$scores, $sErr, $sMissing] = nl_scores_validate($sc, $seen ? $info['asked'] : [], $lists);
    // BNI numbness above I needs the branches
    if (isset($scores['bni_numbness']) && !in_array($scores['bni_numbness']['value_code'], [null, 'I'], true) && $v['row']['numbness_branches'] === null) $v['missing'][] = 'numbness branches';
    $errors = $v['errors'] + $sErr;
    if ($errors) { http_response_code(422); return nl_render_fu($db, $op, $fu, $in, $sc, $errors, array_merge($v['missing'], $sMissing)); }
    $u = $kernel->getUserName(); $row = $v['row'];
    $db->beginTransaction();
    try {
        if ($fu) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            $db->prepare("UPDATE followups SET $set, udate = NOW(), uuser = ? WHERE id = ?")->execute([...array_values($row), $u, $fu['id']]);
            $changed = [];
            foreach ($row as $k => $val) if ((string)($fu[$k] ?? '') !== (string)($val ?? '')) { nl_change($db, $u, 'followups', (int)$fu['id'], $fu['nl_id'], $k, $fu[$k], $val); $changed[] = $k; }
            $fid = (int)$fu['id']; $fnl = $fu['nl_id'];
            nl_audit($db, $u, 'update', 'followups', $fid, $fnl, $changed ? 'fields: ' . implode(', ', $changed) : 'no field changed');
        } else {
            $row += ['nl_id' => nl_id_new_unique($db, 'followups', 'F'), 'op_ref' => $op['id'], 'cuser' => $u];
            $db->prepare('INSERT INTO followups (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
               ->execute(array_values($row));
            $fid = (int)$db->lastInsertId(); $fnl = $row['nl_id'];
            nl_audit($db, $u, 'create', 'followups', $fid, $fnl, 'of ' . $op['nl_id'] . ', window ' . $row['fu_window']);
        }
        nl_scores_save($db, $op, $fid, array_values($scores), $u, $fnl);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    $miss = array_merge($v['missing'], $sMissing);
    $kernel->addStatus('notice', 'Follow-up saved (' . htmlspecialchars((string)$row['fu_window'], ENT_QUOTES, 'UTF-8') . ')' . ($miss ? '; still missing: ' . htmlspecialchars(implode(', ', $miss), ENT_QUOTES, 'UTF-8') : ''));
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit();
}

function nl_page_followup_lock_post($params) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $fu = nl_fu_load($db, (string)($params['id'] ?? ''));
    if (!$fu || !($op = nl_op_by_id($db, (int)$fu['op_ref']))) return error_404();
    $unlock = !empty($_POST['unlock']);
    if ($unlock && !in_array('admin', nl_user_roles(), true)) return error_403('Only an administrator unlocks a follow-up.');
    $db->prepare('UPDATE followups SET locked_at = ' . ($unlock ? 'NULL' : 'NOW()') . ', locked_by = ? WHERE id = ?')->execute([$unlock ? null : $kernel->getUserName(), $fu['id']]);
    nl_audit($db, $kernel->getUserName(), $unlock ? 'unlock' : 'lock', 'followups', (int)$fu['id'], $fu['nl_id']);
    $kernel->addStatus('notice', $unlock ? 'Unlocked' : 'Locked');
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit();
}

/** Baseline scores (before the operation). */
function nl_page_baseline($params) {
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    return nl_render_baseline($db, $op, nl_scores_as_input($db, (int)$op['id'], null), [], []);
}

function nl_render_baseline(PDO $db, array $op, array $sc, array $errors, array $missing) {
    $info = nl_case_info($db, $op);
    return Renderer::render('followup_form.zetem', ['op' => $op, 'nl' => nl_id_format($op['nl_id']), 'fu' => null, 'in' => [], 'sc' => $sc,
        'errors' => $errors, 'missing' => $missing, 'info' => $info, 'taken' => [], 'win_text' => [],
        'scores' => nl_scores_view($db, $info, $sc, $errors), 'patient' => nl_patient_brief($db, (int)$op['patient_ref']),
        'lists_json' => nl_form_data($db)['lists_json'], 'baseline' => true]);
}

function nl_page_baseline_post($params) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $op = nl_op_load($db, (string)($params['id'] ?? ''));
    if (!$op) return error_404();
    $sc = (array)($_POST['sc'] ?? []);
    [$scores, $err, $miss] = nl_scores_validate($sc, nl_case_info($db, $op)['asked'], nl_lists($db));
    if ($err) { http_response_code(422); return nl_render_baseline($db, $op, $sc, $err, $miss); }
    $u = $kernel->getUserName();
    nl_scores_save($db, $op, null, array_values($scores), $u, $op['nl_id']);
    nl_audit($db, $u, 'update', 'operations', (int)$op['id'], $op['nl_id'], 'baseline scores');
    $kernel->addStatus('notice', 'Baseline scores saved' . ($miss ? '; still missing: ' . htmlspecialchars(implode(', ', $miss), ENT_QUOTES, 'UTF-8') : ''));
    header('location: ' . rel_url('/operations/' . nl_id_format($op['nl_id'])) . '#outcomes'); exit();
}

/** Follow-ups due: per surgeon (default: the signed-in surgeon's operations), upcoming, due and overdue windows. */
function nl_page_followups_due($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $mine = nl_my_member_ids($db);
    $who = (string)($_GET['surgeon'] ?? ($mine ? (string)$mine[0] : 'all'));
    $members = $db->query("SELECT id, name, kind, active FROM members ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $where = 'o.deleted IS NULL AND o.op_date >= ?'; $args = [date('Y-m-d', strtotime('-30 months'))];
    if ($who !== 'all' && ctype_digit($who)) { $where .= ' AND o.primary_surgeon_ref = ?'; $args[] = (int)$who; }
    $st = $db->prepare("SELECT o.*, pa.surname, pa.given_name, pa.nl_id AS pnl, m.name AS surgeon FROM operations o JOIN patients pa ON pa.id = o.patient_ref
                         LEFT JOIN members m ON m.id = o.primary_surgeon_ref WHERE $where ORDER BY o.op_date");
    $st->execute($args);
    $today = date('Y-m-d'); $rows = [];
    $fst = $db->prepare('SELECT fu_window, status FROM followups WHERE op_ref = ? AND deleted IS NULL');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $op) {
        $fst->execute([$op['id']]); $f = $fst->fetchAll(PDO::FETCH_ASSOC);
        $info = nl_case_info($db, $op);
        foreach (nl_followups_due($op['op_date'], $info['windows'], array_column($f, 'fu_window'), in_array('died', array_column($f, 'status'), true), $today) as $d) {
            $rows[] = $d + ['nl' => nl_id_format($op['nl_id']), 'op_date' => $op['op_date'], 'patient' => trim($op['surname'] . ' ' . ($op['given_name'] ?? '')),
                            'pnl' => nl_id_format($op['pnl']), 'surgeon' => $op['surgeon']];
        }
    }
    $rank = ['overdue' => 0, 'due' => 1, 'upcoming' => 2];
    usort($rows, fn($a, $b) => [$rank[$a['state']], $a['to']] <=> [$rank[$b['state']], $b['to']]);
    nl_audit($db, $kernel->getUserName(), 'view', 'followups', null, null, 'due list; rows: ' . count($rows));
    return Renderer::render('followups_due.zetem', ['rows' => $rows, 'who' => $who, 'members' => $members, 'can_edit' => nl_can_edit()]);
}
