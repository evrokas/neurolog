#!/usr/bin/env php
<?php
/**
 * Web check (M0): starts `php -S` on the app, signs in with a temporary account per role and checks
 * which pages each role may open, that the admin pages refuse non-admins, that sign-in needs the
 * CSRF token, and that no page shows a PHP warning. The accounts are removed afterwards.
 *   php bin/check_web.php            (needs the database, config/db.php, fw symlink)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
include_once("$root/config/db.php");
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

$fail = 0;
function check(string $label, bool $pass, string $detail = ''): void {
    global $fail; if (!$pass) $fail++;
    if (!$pass || getenv('VERBOSE')) echo ($pass ? 'PASS  ' : 'FAIL  ') . "web: $label" . ($detail ? "  ($detail)" : '') . "\n";
}

$pass = 'wt-' . bin2hex(random_bytes(6));
$users = ['surgeon' => 'wt_surgeon', 'admin' => 'wt_admin', 'viewer' => 'wt_viewer'];
function cleanup(mysqli $db, array $users): void {
    foreach ($users as $u) {
        $st = $db->prepare('SELECT id FROM users WHERE uname = ?'); $st->bind_param('s', $u); $st->execute();
        if ($row = $st->get_result()->fetch_assoc()) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int)$row['id']);
            $db->query('DELETE FROM user_tokens WHERE uname = "' . $db->real_escape_string($u) . '"');
            $db->query('DELETE FROM users WHERE id = ' . (int)$row['id']);
        }
    }
}
cleanup($db, $users);
passthru('php ' . escapeshellarg("$root/bin/seed_users.php") . ' > /dev/null');
foreach ($users as $role => $u) {
    exec('NEUROLOG_PASSWORD=' . escapeshellarg($pass) . ' php ' . escapeshellarg("$root/bin/seed_users.php") . " --user=$u --role=$role", $o, $c);
    check("create $role account", $c === 0);
}

$port = 18000 + random_int(0, 999);
$log = tempnam(sys_get_temp_dir(), 'nlweb');
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$root/web", "$root/router.php"], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, $root);
register_shutdown_function(function () use ($proc, $db, $users, $log) { proc_terminate($proc); cleanup($db, $users); @unlink($log); });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$base = "http://127.0.0.1:$port";

function req(string $url, string $jar, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'neurolog-check-web', CURLOPT_TIMEOUT => 20]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = (string)curl_exec($ch);
    $r = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'loc' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'body' => $body];
    curl_close($ch);
    return $r;
}
function token(string $html): string { return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function clean(string $body): bool { return !preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error)<\/b>:/', $body); }

// signed out
$jar = tempnam(sys_get_temp_dir(), 'nlj');
$r = req("$base/operations", $jar);
check('signed out: /operations redirects to /login', $r['code'] === 302 && str_ends_with($r['loc'], '/login'), "{$r['code']} {$r['loc']}");
$r = req("$base/login", $jar);
check('login page renders with logo and favicon', $r['code'] === 200 && str_contains($r['body'], 'assets/logo.png') && str_contains($r['body'], 'favicon.ico') && clean($r['body']));
check('login page loads nothing from another host', !preg_match('#(src|href)="https?://(?!127\.0\.0\.1)#', $r['body']));
$r = req("$base/login", $jar, ['username' => $users['surgeon'], 'password' => $pass]);
$r2 = req("$base/operations", $jar);
check('sign-in without CSRF token is refused', $r2['code'] === 302 && str_ends_with($r2['loc'], '/login'));
unlink($jar);

// what each role may open: 'ok' = 200 page, 'login' = redirected, 'denied' = refusal page
$matrix = [
    '/'            => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'ok'],
    '/operations'  => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'ok'],
    '/patients'    => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'ok'],
    '/statistics'  => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'ok'],
    '/profile'     => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'ok'],
    '/codelists'   => ['surgeon' => 'login', 'admin' => 'ok', 'viewer' => 'login'],
    '/presets'     => ['surgeon' => 'ok', 'admin' => 'ok', 'viewer' => 'login'],
    '/settings/members' => ['surgeon' => 'login', 'admin' => 'ok', 'viewer' => 'login'],
    '/codelists?list=procedure' => ['surgeon' => 'login', 'admin' => 'ok', 'viewer' => 'login'],
    '/status'      => ['surgeon' => 'login', 'admin' => 'ok', 'viewer' => 'login'],
    '/admin/users' => ['surgeon' => 'denied', 'admin' => 'ok', 'viewer' => 'denied'],
];
foreach ($users as $role => $u) {
    $jar = tempnam(sys_get_temp_dir(), 'nlj');
    $t = token(req("$base/login", $jar)['body']);
    $r = req("$base/login", $jar, ['csrf_token' => $t, 'username' => $u, 'password' => $pass]);
    check("$role signs in", $r['code'] === 302 && !str_ends_with($r['loc'], '/login'), "{$r['code']} {$r['loc']}");
    foreach ($matrix as $path => $want) {
        $r = req($base . $path, $jar);
        $got = $r['code'] === 302 ? 'login' : ($r['code'] === 200 && preg_match("/\\b40[13] - You don/", $r['body']) ? 'denied' : ($r['code'] === 200 ? 'ok' : (string)$r['code']));
        check("$role $path is {$want[$role]}", $got === $want[$role], "got $got");
        if ($r['code'] === 200) check("$role $path has no PHP warning", clean($r['body']));
    }
    $home = req("$base/", $jar)['body'];
    check("$role menu shows Admin only to admin", str_contains($home, '/admin/users') === ($role === 'admin'));
    $r = req("$base/logout", $jar);
    check("$role signs out", req("$base/operations", $jar)['code'] === 302);
    unlink($jar);
}

// ---- M2: saving operations over HTTP (made-up data; removed afterwards) ----
$hid = 0; $mS = 0; $mA = 0;
$db->query("INSERT INTO hospitals (name, active, cuser) VALUES ('wt hospital', 1, 'wt')"); $hid = $db->insert_id;
$db->query("INSERT INTO members (name, kind, uname, active, cuser) VALUES ('wt surgeon', 'surgeon', 'wt_surgeon', 1, 'wt')"); $mS = $db->insert_id;
$db->query("INSERT INTO members (name, kind, active, cuser) VALUES ('wt other surgeon', 'surgeon', 1, 'wt')"); $mO = $db->insert_id;
$created = [];
// leftovers of an earlier crashed run (made-up patient WT-Test) go first
foreach ($db->query("SELECT o.id FROM operations o JOIN patients p ON p.id = o.patient_ref WHERE p.surname = 'WT-Test'")->fetch_all() as $row) $created[] = (int)$row[0];
register_shutdown_function(function () use ($db, &$created, &$hid, &$mS, &$mO) {
    foreach ($created as $oid) {
        $r = $db->query("SELECT patient_ref FROM operations WHERE id = $oid")->fetch_row();
        $db->query("DELETE c FROM rf_cycles c JOIN procedures p ON p.id = c.procedure_ref WHERE p.op_ref = $oid");
        foreach (['procedures', 'diagnoses'] as $t) $db->query("DELETE FROM $t WHERE op_ref = $oid");
        $db->query("DELETE FROM change_log WHERE entity = 'operations' AND entity_ref = $oid");
        $db->query("DELETE FROM operations WHERE id = $oid");
        if ($r) $db->query("DELETE FROM patients WHERE id = " . (int)$r[0] . " AND NOT EXISTS (SELECT 1 FROM operations WHERE patient_ref = " . (int)$r[0] . ")");
    }
    $db->query("DELETE FROM members WHERE id IN (" . (int)$mS . "," . (int)$mO . ")");
    $db->query("DELETE FROM hospitals WHERE id = " . (int)$hid);
});
function login_as(string $base, string $u, string $pass): string {
    $jar = tempnam(sys_get_temp_dir(), 'nlj');
    $t = token(req("$base/login", $jar)['body']);
    req("$base/login", $jar, ['csrf_token' => $t, 'username' => $u, 'password' => $pass]);
    return $jar;
}
$opPost = function (string $jar, string $url, int $surgeon, array $extra = []) use ($base, $hid) {
    $t = token(req($base . $url, $jar)['body']);
    $post = ['csrf_token' => $t, 'new_patient' => ['surname' => 'WT-Test', 'given_name' => 'Patient', 'birth_date' => '1960-01-01'],
             'op' => ['op_date' => date('Y-m-d'), 'primary_surgeon_ref' => (string)$surgeon, 'hospital_ref' => (string)$hid],
             'dx' => ['0' => ['diagnosis_code' => 'disc_herniation', 'levels' => 'Ο4/5', 'lesion_side' => 'left']],
             'pr' => ['0' => ['procedure_code' => 'discectomy', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'tubular_MIS', 'technology' => '["microscope"]', 'treats' => '0']],
             'main_dx' => '0', 'main_pr' => '0'];
    return req($base . $url, $jar, array_replace_recursive($post, $extra));
};
$js = login_as($base, $users['surgeon'], $pass);
$r = $opPost($js, '/operations/new', $mS);
$okSave = $r['code'] === 302 && preg_match('#/operations/(O-[0-9A-Z*~$=]{4}-[0-9A-Z*~$=]{4}-[0-9A-Z*~$=])$#', urldecode($r['loc']), $m);
check('surgeon saves an operation (redirect to its page)', (bool)$okSave, "{$r['code']} {$r['loc']}");
if ($okSave) {
    $nl = str_replace('-', '', $m[1]);
    $op = $db->query("SELECT id FROM operations WHERE nl_id = '" . $db->real_escape_string($nl) . "'")->fetch_assoc();
    $created[] = (int)$op['id'];
    $st = $db->query("SELECT coding_status, coded_by, levels_list, region FROM procedures WHERE op_ref = {$op['id']}")->fetch_assoc();
    check('own case: codes confirmed, levels and region stored', $st['coding_status'] === 'confirmed' && $st['coded_by'] === $users['surgeon'] && $st['levels_list'] === '["L4-L5"]' && $st['region'] === 'lumbar', json_encode($st));
    $v = req("$base/operations/{$m[1]}", $js);
    check('operation page shows the record', $v['code'] === 200 && str_contains($v['body'], $m[1]) && clean($v['body']));
    $a = $db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'view' AND entity = 'operations' AND entity_ref = {$op['id']}")->fetch_row()[0];
    check('viewing the operation is audited', $a >= 1);
    $b = $db->query("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%WT-Test%'")->fetch_row()[0];
    check('no patient name in the audit log', (int)$b === 0);
    // edit: change only the operation note; rows unchanged keep their status
    $e = req("$base/operations/{$m[1]}/edit", $js);
    check('edit form opens with the stored levels', $e['code'] === 200 && str_contains($e['body'], 'value="L4-L5"'));
    $jv = login_as($base, $users['viewer'], $pass);
    check('viewer can read the operation', req("$base/operations/{$m[1]}", $jv)['code'] === 200);
    check('viewer cannot open the edit form', req("$base/operations/{$m[1]}/edit", $jv)['code'] === 302);
    $t = token(req("$base/operations/new", $js)['body']);
    $r2 = req("$base/operations/{$m[1]}/confirm", $jv, ['csrf_token' => $t]);
    check('viewer cannot confirm codes', $r2['code'] !== 200 || !str_contains($r2['body'], 'codes confirmed'));
}
// someone else's case: suggested until that surgeon confirms
$r = $opPost($js, '/operations/new', $mO);
if ($r['code'] === 302 && preg_match('#/operations/(O-[^/]+)$#', urldecode($r['loc']), $m2)) {
    $op2 = $db->query("SELECT id FROM operations WHERE nl_id = '" . $db->real_escape_string(str_replace('-', '', $m2[1])) . "'")->fetch_assoc();
    $created[] = (int)$op2['id'];
    $st = $db->query("SELECT coding_status FROM procedures WHERE op_ref = {$op2['id']}")->fetch_assoc();
    check("another surgeon's case: codes suggested", $st['coding_status'] === 'suggested');
    $t = token(req("$base/operations/new", $js)['body']);
    req("$base/operations/{$m2[1]}/confirm", $js, ['csrf_token' => $t]);
    $st = $db->query("SELECT coding_status FROM procedures WHERE op_ref = {$op2['id']}")->fetch_assoc();
    check("only the primary surgeon confirms", $st['coding_status'] === 'suggested');
    $db->query("UPDATE members SET uname = '{$users['surgeon']}' WHERE id = $mO");
    req("$base/operations/{$m2[1]}/confirm", $js, ['csrf_token' => $t]);
    $st = $db->query("SELECT coding_status FROM procedures WHERE op_ref = {$op2['id']}")->fetch_assoc();
    check('the primary surgeon confirms suggested codes', $st['coding_status'] === 'confirmed');
} else check("save another surgeon's case", false, "{$r['code']}");
// quick line: the endpoint reads a line (CSRF in the header), a viewer may not use it, proposed rows stay suggested
function req_json(string $url, string $jar, array $post, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_HTTPHEADER => ["X-CSRF-Token: $token"], CURLOPT_USERAGENT => 'neurolog-check-web']);
    $body = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $code, 'body' => $body];
}
$page = req("$base/operations/new", $js)['body'];
$tok = preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page, $mm) ? $mm[1] : '';
$r = req_json("$base/operations/parse", $js, ['text' => 'Διαδερμική σπονδυλοδεσία Ο4/5/Ι1, PLIF Ο5/Ι1'], $tok);
$d = json_decode($r['body'], true);
check('quick line endpoint reads the line', $r['code'] === 200 && ($d['procedures'][0]['procedure_code'] ?? '') === 'pedicle_screw_fixation'
      && ($d['procedures'][0]['approach_type'] ?? '') === 'percutaneous' && ($d['procedures'][1]['approach_route'] ?? '') === 'posterior_midline', substr($r['body'], 0, 200));
check('quick line endpoint refuses a missing CSRF token', req_json("$base/operations/parse", $js, ['text' => 'x'], 'bad')['code'] === 403);
check('viewer cannot use the quick line endpoint', req_json("$base/operations/parse", login_as($base, $users['viewer'], $pass), ['text' => 'x'], $tok)['code'] !== 200);
$r = $opPost($js, '/operations/new', $mS, ['op' => ['quick_line' => 'Διαδερμική δισκεκτομή Ο4/5 αρ'], 'pr' => ['0' => ['proposed' => '1']]]);
if ($r['code'] === 302 && preg_match('#/operations/(O-[^/]+)$#', urldecode($r['loc']), $m4)) {
    $o4 = $db->query("SELECT id, quick_line FROM operations WHERE nl_id = '" . $db->real_escape_string(str_replace('-', '', $m4[1])) . "'")->fetch_assoc();
    $created[] = (int)$o4['id'];
    $st = $db->query("SELECT coding_status FROM procedures WHERE op_ref = {$o4['id']}")->fetch_assoc();
    check('a proposed row the surgeon did not accept is saved as suggested', $st['coding_status'] === 'suggested');
    check('the quick line is kept verbatim', $o4['quick_line'] === 'Διαδερμική δισκεκτομή Ο4/5 αρ');
} else check('save with a proposed row', false, (string)$r['code']);

// presets: saved from an operation without levels or side, offered on the form, refused to a viewer
if (!empty($m[1])) {
    $t = token(req("$base/operations/{$m[1]}", $js)['body']);
    $r = req("$base/operations/{$m[1]}/preset", $js, ['csrf_token' => $t, 'name' => 'wt preset']);
    $pr = $db->query("SELECT id, rows_json FROM presets WHERE name = 'wt preset'")->fetch_assoc();
    check('preset saved from an operation', $r['code'] === 302 && $pr !== null);
    if ($pr) {
        $rows = json_decode($pr['rows_json'], true);
        check('preset keeps the procedure, not levels or side', ($rows[0]['procedure_code'] ?? '') === 'discectomy' && !isset($rows[0]['levels_list']) && !isset($rows[0]['lesion_side']) && !isset($rows[0]['level_from']));
        check('preset offered on the form', str_contains(req("$base/operations/new", $js)['body'], 'wt preset'));
        $db->query("DELETE FROM presets WHERE id = {$pr['id']}");
    }
    $jv2 = login_as($base, $users['viewer'], $pass);
    $r = req("$base/operations/{$m[1]}/preset", $jv2, ['csrf_token' => token(req("$base/operations/{$m[1]}", $jv2)['body']), 'name' => 'wt viewer preset']);
    check('viewer cannot save a preset', $db->query("SELECT COUNT(*) FROM presets WHERE name = 'wt viewer preset'")->fetch_row()[0] == 0);
}

// ---- M3: finding operations; procedure filters match one row ----
$mkOp = function (array $pr) use ($opPost, $js, $mS, $db, &$created) {
    $r = $opPost($js, '/operations/new', $mS, ['pr' => $pr]);
    if ($r['code'] === 302 && preg_match('#/operations/(O-[^/]+)$#', urldecode($r['loc']), $mm)) {
        $created[] = (int)$db->query("SELECT id FROM operations WHERE nl_id = '" . $db->real_escape_string(str_replace('-', '', $mm[1])) . "'")->fetch_row()[0];
        return $mm[1];
    }
    return null;
};
$A = $mkOp([]);   // the default row: discectomy L4-L5 left tubular_MIS
$B = $mkOp(['0' => ['levels' => 'Ο5/Ι1'], '1' => ['procedure_code' => 'laminectomy_decompression', 'levels' => 'Ο4/5', 'lesion_side' => 'left', 'approach_type' => 'tubular_MIS', 'technology' => '["microscope"]']]);
$Cop = $mkOp(['0' => ['procedure_code' => 'laminectomy_decompression', 'levels' => 'Ο3-5', 'lesion_side' => 'bilateral', 'approach_type' => 'open']]);
check('three test operations saved', $A && $B && $Cop);
$find = function (array $q) use ($base, $js) {
    $r = req("$base/operations?" . http_build_query($q), $js);
    if ($r['code'] === 302) $r = req($r['loc'], $js);    // the search line redirects to its filters
    preg_match_all('#/operations/(O-[0-9A-Z*~$=-]{11,13})"#', urldecode($r['body']), $mm);
    return ['code' => $r['code'], 'ids' => array_values(array_unique($mm[1])), 'body' => $r['body']];
};
$y = date('Y');
$r = $find(['s' => "left L4-L5 tubular discectomies $y", 'surgeon' => (string)$mS]);
check('acceptance query: one line finds the left L4-L5 tubular discectomy', in_array($A, $r['ids'], true), json_encode($r['ids']));
check('…and not a discectomy at L5-S1 beside a left L4-L5 decompression (same-row rule)', !in_array($B, $r['ids'], true));
check('…and not the bilateral open L3-L5 decompression', !in_array($Cop, $r['ids'], true));
$r = $find(['s' => "αριστερές διαδερμικές δισκεκτομές Ο4/5 $y", 'surgeon' => (string)$mS]);
check('the same query in Greek gives the same result', in_array($A, $r['ids'], true) && !in_array($B, $r['ids'], true), json_encode($r['ids']));
$r = $find(['proc' => 'laminectomy_decompression', 'level' => 'L4-L5', 'surgeon' => (string)$mS]);
check('a level finds ranges that cover it (L3-L5 for L4-L5)', in_array($Cop, $r['ids'], true) && in_array($B, $r['ids'], true) && !in_array($A, $r['ids'], true), json_encode($r['ids']));
check('active filters shown as chips', str_contains($r['body'], 'filter-chip') && clean($r['body']));
$r = $find(['level' => 'not a level', 'proc' => "x' OR 1=1 --", 'surgeon' => (string)$mS]);
check('bad filter values are ignored, not an error', $r['code'] === 200 && clean($r['body']));
$al = $db->query("SELECT detail FROM audit_log WHERE action = 'view' AND entity = 'operations' AND entity_ref IS NULL ORDER BY id DESC LIMIT 1")->fetch_row()[0] ?? '';
check('list view audited with filter names only', str_contains($al, 'filters: ') && !str_contains($al, 'L4-L5') && !str_contains($al, 'discectomy'), $al);
// patient page and patient form
$pnl = $db->query("SELECT p.nl_id FROM operations o JOIN patients p ON p.id = o.patient_ref WHERE o.id = " . (int)end($created))->fetch_row()[0];
$pd = substr($pnl, 0, 1) . '-' . substr($pnl, 1, 4) . '-' . substr($pnl, 5, 4) . '-' . substr($pnl, 9, 1);
$r = req("$base/patients/$pd", $js);
check('patient page shows the patient and their operations', $r['code'] === 200 && str_contains($r['body'], 'WT-Test') && str_contains($r['body'], $Cop ?? '-') && clean($r['body']));
check('viewer can read the patient page, not edit it', req("$base/patients/$pd", login_as($base, $users['viewer'], $pass))['code'] === 200
      && req("$base/patients/$pd/edit", login_as($base, $users['viewer'], $pass))['code'] === 302);
$t = token(req("$base/patients/$pd/edit", $js)['body']);
$r = req("$base/patients/$pd/edit", $js, ['csrf_token' => $t, 'p' => ['surname' => 'WT-Test', 'birth_date' => '1960-01-01', 'amka' => '01016012345']]);
check('patient form refuses an invalid AMKA', $r['code'] === 422 && str_contains($r['body'], 'AMKA'));
$r = req("$base/patients/$pd/edit", $js, ['csrf_token' => $t, 'p' => ['surname' => 'WT-Test', 'birth_date' => '1960-01-01', 'smoking' => 'former', 'research_consent' => 'yes']]);
check('consent yes needs its date', $r['code'] === 422);
$r = req("$base/patients/$pd/edit", $js, ['csrf_token' => $t, 'p' => ['surname' => 'WT-Test', 'birth_date' => '1960-01-01', 'smoking' => 'former']]);
check('patient form saves and logs the change', $r['code'] === 302 && $db->query("SELECT smoking FROM patients WHERE nl_id = '$pnl'")->fetch_row()[0] === 'former'
      && $db->query("SELECT COUNT(*) FROM change_log WHERE entity = 'patients' AND nl_id = '$pnl' AND field = 'smoking'")->fetch_row()[0] >= 1);
$db->query("DELETE FROM change_log WHERE entity = 'patients' AND nl_id = '$pnl'");
check('patient list search works by name', str_contains(req("$base/patients?q=wt-test", $js)['body'], $pd));

// refused input is not saved and comes back with the messages
$before = $db->query('SELECT COUNT(*) FROM operations')->fetch_row()[0];
$r = $opPost($js, '/operations/new', $mS, ['pr' => ['0' => ['levels' => 'Ο7', 'approach_type' => '']]]);
check('impossible level refused, nothing saved', $r['code'] === 422 && str_contains($r['body'], 'L7 does not exist') && $db->query('SELECT COUNT(*) FROM operations')->fetch_row()[0] == $before, (string)$r['code']);
$r = $opPost($js, '/operations/new', $mS, ['csrf_token' => 'bad']);
check('operation save without a valid CSRF token is refused (403, nothing saved)', $r['code'] === 403 && $db->query('SELECT COUNT(*) FROM operations')->fetch_row()[0] == $before, (string)$r['code']);
if ($r['code'] === 302 && preg_match('#/operations/(O-[^/]+)$#', urldecode($r['loc']), $m3)) $created[] = (int)$db->query("SELECT id FROM operations WHERE nl_id = '" . $db->real_escape_string(str_replace('-', '', $m3[1])) . "'")->fetch_row()[0];
$r = req("$base/settings/hospitals", login_as($base, $users['admin'], $pass), ['csrf_token' => 'bad', 'name' => 'wt csrf']);
check('settings save without a valid CSRF token is refused', $r['code'] === 403);

$srv = (string)@file_get_contents($log);
check('server log has no PHP warning', !preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $srv), substr($srv, 0, 300));
exit($fail ? 1 : 0);
