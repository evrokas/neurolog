<?php
/**
 * Admin lists the operation form needs: hospitals and group members (surgeons, anaesthetists, residents, external).
 * Rows are never deleted (operations refer to them); they are switched inactive instead.
 */

function nl_people_page(string $kind) {
    $db = dbConnection::getConnection();
    $cfg = nl_people_cfg($kind);
    $rows = $db->query("SELECT * FROM {$cfg['table']} ORDER BY active DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $users = $kind === 'members' ? $db->query('SELECT uname FROM users ORDER BY uname')->fetchAll(PDO::FETCH_COLUMN) : [];
    $kinds = $kind === 'members' ? array_keys(nl_lists($db)['field.member.kind'] ?? []) : [];
    $edit = null;
    if (isset($_GET['edit'])) foreach ($rows as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
    return Renderer::render('settings_people.zetem', ['kind' => $kind, 'cfg' => $cfg, 'rows' => $rows, 'users' => $users,
        'kinds' => $kinds, 'edit' => $edit, 'errors' => $_SESSION['nl_form_errors'] ?? [], 'old' => nl_take_flash()]);
}

function nl_people_cfg(string $kind): array {
    return $kind === 'hospitals'
        ? ['table' => 'hospitals', 'title' => 'Hospitals', 'url' => '/settings/hospitals']
        : ['table' => 'members', 'title' => 'Group members', 'url' => '/settings/members'];
}

function nl_people_post(string $kind) {
    global $kernel;
    if ($refused = csrfClass::requireValid()) return $refused;
    $db = dbConnection::getConnection();
    $cfg = nl_people_cfg($kind);
    $uname = $kernel->getUserName();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $err = [];
    if ($name === '' || mb_strlen($name) > 128) $err['name'] = 'required, at most 128 characters';
    $vals = ['name' => $name, 'apyweb_name' => trim((string)($_POST['apyweb_name'] ?? '')) ?: null, 'active' => !empty($_POST['active']) ? 1 : 0];
    if ($kind === 'hospitals') {
        $vals['short_name'] = mb_substr(trim((string)($_POST['short_name'] ?? '')), 0, 32) ?: null;
    } else {
        $k = (string)($_POST['kind'] ?? '');
        if (!isset(nl_lists($db)['field.member.kind'][$k])) $err['kind'] = 'choose one';
        $vals['kind'] = $k;
        $u = trim((string)($_POST['uname'] ?? ''));
        if ($u !== '') {
            $st = $db->prepare('SELECT 1 FROM users WHERE uname = ?'); $st->execute([$u]);
            if (!$st->fetchColumn()) $err['uname'] = 'no such account';
        }
        $vals['uname'] = $u ?: null;
    }
    if ($err) {
        $_SESSION['nl_form_errors'] = $err; $_SESSION['nl_flash'] = $_POST;
        header('location: ' . rel_url($cfg['url'] . ($id ? "?edit=$id" : ''))); exit();
    }
    if ($id) {
        $vals['udate'] = date('Y-m-d H:i:s'); $vals['uuser'] = $uname;
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals)));
        $db->prepare("UPDATE {$cfg['table']} SET $set WHERE id = ?")->execute([...array_values($vals), $id]);
        nl_audit($db, $uname, 'update', $cfg['table'], $id);
    } else {
        $vals['cuser'] = $uname;
        $db->prepare("INSERT INTO {$cfg['table']} (" . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')
           ->execute(array_values($vals));
        nl_audit($db, $uname, 'create', $cfg['table'], (int)$db->lastInsertId());
    }
    $kernel->addStatus('notice', 'Saved');
    header('location: ' . rel_url($cfg['url'])); exit();
}

/** The posted values kept across a redirect after a refused save, read once. */
function nl_take_flash(): array {
    $v = $_SESSION['nl_flash'] ?? [];
    unset($_SESSION['nl_flash'], $_SESSION['nl_form_errors']);
    return $v;
}

function nl_page_hospitals($p) { return nl_people_page('hospitals'); }
function nl_page_hospitals_post($p) { return nl_people_post('hospitals'); }
function nl_page_members($p) { return nl_people_page('members'); }
function nl_page_members_post($p) { return nl_people_post('members'); }
