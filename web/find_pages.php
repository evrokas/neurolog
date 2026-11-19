<?php
/**
 * M3 pages: the operation list with a filter on every facet (web/search.php), the patient list, the patient page and
 * the patient form. Every list and every patient view is written to audit_log (filter names, never values or names).
 */

const NL_PAGE_SIZE = 100;

function nl_filter_ctx(PDO $db): array {
    return [nl_lists($db), $db->query('SELECT id, name FROM members ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR),
            $db->query('SELECT id, name FROM hospitals ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR),
            $db->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR)];
}

/** Human label of one filter value (for the chips). */
function nl_filter_label(string $k, $v, array $lists, array $members, array $hospitals, array $companies = []): string {
    $spec = nl_filter_spec()[$k][1];
    switch ($spec) {
        case ':member': return $members[$v] ?? (string)$v;
        case ':hospital': return $hospitals[$v] ?? (string)$v;
        case ':company': return $companies[$v] ?? (string)$v;
        case ':iparam': return str_replace('_', ' ', (string)$v);
        case ':num': return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        case ':patient': return nl_id_format($v);
        case ':region': case ':route': case ':coding': return str_replace('_', ' ', (string)$v);
        case ':level': case ':date': case ':branch': case ':text': return (string)$v;
        default: return nl_label($lists, $spec, (string)$v);
    }
}

function nl_page_operations($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    [$lists, $members, $hospitals, $companies] = nl_filter_ctx($db);
    // the search line becomes filters, then the page reloads with them in the address (bookmarkable)
    $s = trim((string)($_GET['s'] ?? ''));
    if ($s !== '') {
        $r = nl_search_line(mb_substr($s, 0, 300), nl_aliases(__APPDIR__), nl_diadermiki());
        if ($r['unplaced']) $kernel->addStatus('warning', 'Not read: ' . htmlspecialchars(implode(' · ', $r['unplaced']), ENT_QUOTES, 'UTF-8'));
        if ($r['more']) $kernel->addStatus('warning', 'Only the first procedure of the search line is used as a filter.');
        $keep = array_intersect_key($_GET, array_flip(['surgeon', 'hospital', 'coding']));
        header('location: ' . rel_url('/operations') . '?' . http_build_query($r['filters'] + $keep)); exit();
    }
    $f = nl_filters_from_get($_GET, $lists, $members, $hospitals, $companies);
    [$where, $args] = nl_filters_sql($f);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $st = $db->prepare("SELECT COUNT(*) FROM operations o WHERE $where"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    $sql = "SELECT o.id, o.nl_id, o.op_date, o.construct, pa.surname, pa.given_name, pa.nl_id AS pnl, m.name AS surgeon, h.name AS hospital,
                   p.procedure_code, p.levels_list, p.lesion_side, p.approach_type, p.location,
                   (SELECT COUNT(*) FROM procedures x WHERE x.op_ref = o.id) AS nproc,
                   (SELECT COUNT(*) FROM procedures x WHERE x.op_ref = o.id AND x.coding_status <> 'confirmed')
                 + (SELECT COUNT(*) FROM diagnoses y WHERE y.op_ref = o.id AND y.coding_status <> 'confirmed') AS suggested
              FROM operations o
              JOIN patients pa ON pa.id = o.patient_ref
              LEFT JOIN members m ON m.id = o.primary_surgeon_ref
              LEFT JOIN hospitals h ON h.id = o.hospital_ref
              LEFT JOIN procedures p ON p.op_ref = o.id AND p.is_main = 1
             WHERE $where ORDER BY o.op_date DESC, o.id DESC LIMIT " . NL_PAGE_SIZE . ' OFFSET ' . (($page - 1) * NL_PAGE_SIZE);
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['nl'] = nl_id_format($r['nl_id']); $r['pnl'] = nl_id_format($r['pnl']);
        $r['where'] = $r['levels_list'] ? implode(', ', json_decode($r['levels_list'], true)) : ($r['location'] ? implode(', ', json_decode($r['location'], true)) : '');
    }
    unset($r);
    nl_audit($db, $kernel->getUserName(), 'view', 'operations', null, null,
             'list' . ($f ? ' filters: ' . implode(',', array_keys($f)) : '') . "; rows: $total");
    $chips = [];
    foreach ($f as $k => $v) {
        $rest = $f; unset($rest[$k]);
        $chips[] = ['label' => nl_filter_spec()[$k][0], 'value' => nl_filter_label($k, $v, $lists, $members, $hospitals, $companies),
                    'href' => rel_url('/operations') . ($rest ? '?' . http_build_query($rest) : '')];
    }
    $data = nl_form_data($db);
    return Renderer::render('operations_list.zetem', [
        'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / NL_PAGE_SIZE)),
        'f' => $f, 'chips' => $chips, 'qs' => $f, 'lists' => $lists, 'members' => $data['members'], 'hospitals' => $data['hospitals'],
        'lists_json' => $data['lists_json'], 'can_edit' => nl_can_edit(), 'companies' => $companies, 'iparams' => nl_iparams(),
    ]);
}

/** Numeric implant parameters for the filter panel: name => label with unit. */
function nl_iparams(): array {
    $out = [];
    foreach (nl_implant_spec() as $params) foreach ($params as $n => $ps) {
        if (in_array($ps['type'], ['num', 'int'], true)) $out[$n] = str_replace('_', ' ', preg_replace('/_(mm|ml|deg)$/', '', $n)) . (isset($ps['unit']) ? " ({$ps['unit']})" : '');
    }
    ksort($out);
    return $out;
}

// ---------- patients ----------

/** AMKA: 11 digits, Luhn check digit, the first six a valid date DDMMYY. */
function nl_amka_valid(string $a): bool {
    if (!preg_match('/^\d{11}$/', $a)) return false;
    if (!checkdate((int)substr($a, 2, 2), (int)substr($a, 0, 2), 2000 + (int)substr($a, 4, 2))) return false;
    $sum = 0;
    for ($i = 0; $i < 11; $i++) {
        $d = (int)$a[10 - $i];
        if ($i % 2 === 1) { $d *= 2; if ($d > 9) $d -= 9; }
        $sum += $d;
    }
    return $sum % 10 === 0;
}

function nl_page_patients($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $q = trim((string)($_GET['q'] ?? ''));
    $rows = [];
    if ($q !== '') {
        $id = nl_id_normalize($q, 'P');
        if ($id !== null) { $st = $db->prepare('SELECT * FROM patients WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]); }
        else {
            $st = $db->prepare('SELECT * FROM patients WHERE deleted IS NULL AND name_match LIKE ? ORDER BY surname, given_name LIMIT 200');
            $st->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], nl_name_match($q)) . '%']);
        }
    } else {
        $st = $db->query('SELECT pa.* FROM patients pa WHERE pa.deleted IS NULL ORDER BY (SELECT MAX(op_date) FROM operations o WHERE o.patient_ref = pa.id AND o.deleted IS NULL) DESC, pa.id DESC LIMIT 100');
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ops = $db->prepare('SELECT COUNT(*), MAX(op_date) FROM operations WHERE patient_ref = ? AND deleted IS NULL');
    foreach ($rows as &$r) { $ops->execute([$r['id']]); [$r['n_ops'], $r['last_op']] = $ops->fetch(PDO::FETCH_NUM); $r['nl'] = nl_id_format($r['nl_id']); }
    unset($r);
    nl_audit($db, $kernel->getUserName(), 'view', 'patients', null, null, ($q !== '' ? 'search' : 'list') . '; rows: ' . count($rows));
    return Renderer::render('patients_list.zetem', ['rows' => $rows, 'q' => $q]);
}

function nl_patient_load(PDO $db, string $nl): ?array {
    $id = nl_id_normalize($nl, 'P');
    if ($id === null) return null;
    $st = $db->prepare('SELECT * FROM patients WHERE nl_id = ? AND deleted IS NULL'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function nl_page_patient($params) {
    global $kernel;
    $db = dbConnection::getConnection();
    $p = nl_patient_load($db, (string)($params['id'] ?? ''));
    if (!$p) return error_404();
    nl_audit($db, $kernel->getUserName(), 'view', 'patients', (int)$p['id'], $p['nl_id']);
    $lists = nl_lists($db);
    $st = $db->prepare("SELECT o.nl_id, o.op_date, o.construct, m.name AS surgeon, h.name AS hospital, p.procedure_code, p.levels_list, p.location, p.lesion_side,
                               (SELECT COUNT(*) FROM procedures x WHERE x.op_ref = o.id AND x.coding_status <> 'confirmed') AS suggested
                          FROM operations o LEFT JOIN procedures p ON p.op_ref = o.id AND p.is_main = 1
                          LEFT JOIN members m ON m.id = o.primary_surgeon_ref LEFT JOIN hospitals h ON h.id = o.hospital_ref
                         WHERE o.patient_ref = ? AND o.deleted IS NULL ORDER BY o.op_date DESC");
    $st->execute([$p['id']]);
    $ops = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ops as &$o) { $o['nl'] = nl_id_format($o['nl_id']); $o['where'] = $o['levels_list'] ? implode(', ', json_decode($o['levels_list'], true)) : ($o['location'] ? implode(', ', json_decode($o['location'], true)) : ''); }
    unset($o);
    $age = $p['birth_date'] ? (new DateTime($p['birth_date']))->diff(new DateTime('today'))->y : null;
    return Renderer::render('patient_view.zetem', ['p' => $p, 'nl' => nl_id_format($p['nl_id']), 'ops' => $ops, 'lists' => $lists,
        'age' => $age, 'can_edit' => nl_can_edit()]);
}

/** Patient fields editable on the patient form: name => [type, list or limit]. */
function nl_patient_fields(): array {
    return ['surname' => ['text', 128], 'given_name' => ['text', 128], 'birth_date' => ['date', null], 'sex' => ['code', 'field.patient.sex'],
            'amka' => ['amka', null], 'bmi' => ['dec', [10, 80]], 'smoking' => ['code', 'field.patient.smoking'],
            'diabetes' => ['code', 'field.patient.diabetes'], 'anticoagulant' => ['code', 'field.patient.anticoagulant'],
            'charlson_index' => ['int', [0, 37]], 'research_consent' => ['code', 'field.patient.research_consent'],
            'research_consent_date' => ['date', null]];
}

/** Validate the patient form. Pure. Returns [clean, errors]. */
function nl_patient_validate(array $in, array $lists): array {
    $c = []; $e = [];
    foreach (nl_patient_fields() as $f => [$type, $arg]) {
        $v = trim((string)($in[$f] ?? ''));
        $c[$f] = $v === '' ? null : $v;
        if ($v === '') continue;
        switch ($type) {
            case 'text': if (mb_strlen($v) > $arg) $e[$f] = "at most $arg characters"; break;
            case 'date': if (!(preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) && $v <= date('Y-m-d'))) $e[$f] = 'not a valid date'; break;
            case 'code': if (!isset($lists[$arg][$v])) $e[$f] = 'not in the list'; break;
            case 'amka': if (!nl_amka_valid($v)) $e[$f] = 'not a valid AMKA (11 digits, check digit)'; break;
            case 'int': if (!ctype_digit($v) || (int)$v < $arg[0] || (int)$v > $arg[1]) $e[$f] = "a whole number {$arg[0]}–{$arg[1]}"; else $c[$f] = (int)$v; break;
            case 'dec': $v = str_replace(',', '.', $v); if (!is_numeric($v) || $v < $arg[0] || $v > $arg[1]) $e[$f] = "{$arg[0]}–{$arg[1]}"; else $c[$f] = round((float)$v, 1); break;
        }
    }
    if ($c['surname'] === null) $e['surname'] = 'required';
    if ($c['research_consent'] === 'yes' && $c['research_consent_date'] === null) $e['research_consent_date'] = 'required when consent is yes';
    if ($c['amka'] !== null && $c['birth_date'] !== null && !isset($e['amka']) && substr($c['amka'], 0, 6) !== date('dmy', strtotime($c['birth_date']))) $e['amka'] = 'does not match the birth date';
    return [$c, $e];
}

function nl_page_patient_edit($params) {
    $db = dbConnection::getConnection();
    $p = nl_patient_load($db, (string)($params['id'] ?? ''));
    if (!$p) return error_404();
    $data = nl_form_data($db);
    return Renderer::render('patient_form.zetem', ['p' => $p, 'v' => $p, 'nl' => nl_id_format($p['nl_id']), 'errors' => [], 'lists_json' => $data['lists_json'], 'freq_json' => '{}']);
}

function nl_page_patient_edit_post($params) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $p = nl_patient_load($db, (string)($params['id'] ?? ''));
    if (!$p) return error_404();
    [$c, $e] = nl_patient_validate((array)($_POST['p'] ?? []), nl_lists($db));
    if ($e) {
        http_response_code(422);
        $data = nl_form_data($db);
        return Renderer::render('patient_form.zetem', ['p' => $p, 'v' => $_POST['p'] ?? [], 'nl' => nl_id_format($p['nl_id']), 'errors' => $e, 'lists_json' => $data['lists_json'], 'freq_json' => '{}']);
    }
    $uname = $kernel->getUserName();
    $c['name_match'] = nl_name_match($c['surname'] . ' ' . ($c['given_name'] ?? ''));
    $changed = [];
    foreach ($c as $f => $v) if ((string)($p[$f] ?? '') !== (string)($v ?? '')) { nl_change($db, $uname, 'patients', (int)$p['id'], $p['nl_id'], $f, $p[$f], $v); $changed[] = $f; }
    if ($changed) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
        $db->prepare("UPDATE patients SET $set, udate = NOW(), uuser = ? WHERE id = ?")->execute([...array_values($c), $uname, $p['id']]);
    }
    nl_audit($db, $uname, 'update', 'patients', (int)$p['id'], $p['nl_id'], $changed ? 'fields: ' . implode(', ', $changed) : 'no field changed');
    $kernel->addStatus('notice', 'Saved');
    header('location: ' . rel_url('/patients/' . nl_id_format($p['nl_id']))); exit();
}
