<?php
/**
 * Route handlers (docs/implementation-plan.md): dashboard, code lists, profile, system status, and
 * placeholders for the pages later milestones build.
 */

/** The signed-in user's role names. */
function nl_user_roles(): array {
    global $kernel;
    $roles = method_exists($kernel, 'getUserRoles') ? $kernel->getUserRoles() : null;
    $list = is_array($roles) ? $roles : preg_split('/[\s,]+/', (string)$roles);
    // 'authenticated' is the framework's marker for any signed-in session, not a NeuroLog role.
    return array_values(array_filter($list, fn($r) => $r !== '' && $r !== 'authenticated'));
}

function nl_placeholder(string $title, string $note, string $milestone): string {
    return Renderer::render("placeholder.zetem", ['heading' => $title, 'note' => $note, 'milestone' => $milestone]);
}

function nl_page_home($params) {
    global $kernel;
    if (!SecurityClass::userLoggedIn()) {
        header('location: ' . rel_url('/login'));
        exit();
    }
    $uname = $kernel->getUserName();
    $account = UsersClassEx::getUserAccount($uname);

    $cards = [
        ['url' => '/operations', 'title' => 'Operations', 'desc' => 'Record and find operations',          'roles' => ['surgeon', 'viewer', 'admin']],
        ['url' => '/patients',   'title' => 'Patients',   'desc' => 'Patients and their operations',       'roles' => ['surgeon', 'viewer', 'admin']],
        ['url' => '/statistics', 'title' => 'Statistics', 'desc' => 'Counts by procedure, level, approach', 'roles' => ['surgeon', 'viewer', 'admin']],
        ['url' => '/codelists',  'title' => 'Code lists', 'desc' => 'Procedures, diagnoses and facets',    'roles' => ['admin']],
        ['url' => '/admin/users','title' => 'Users',      'desc' => 'Accounts and roles',                  'roles' => ['admin']],
        ['url' => '/profile',    'title' => 'My profile', 'desc' => 'Your account',                        'roles' => []],
    ];
    $mine = nl_user_roles();
    $visible = array_values(array_filter($cards, fn($c) => !$c['roles'] || array_intersect($c['roles'], $mine)));

    $db = dbConnection::getConnection();
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $stats = [
        ['label' => 'Operations recorded', 'value' => $one('SELECT COUNT(*) FROM operations WHERE deleted IS NULL')],
        ['label' => 'This year', 'value' => $one('SELECT COUNT(*) FROM operations WHERE deleted IS NULL AND YEAR(op_date) = YEAR(CURDATE())')],
        // rows still to code or confirm; only confirmed codes enter statistics
        ['label' => 'Codes to confirm', 'value' => $one("SELECT (SELECT COUNT(*) FROM procedures p JOIN operations o ON o.id = p.op_ref
                                                                   WHERE o.deleted IS NULL AND p.coding_status <> 'confirmed')
                                                               + (SELECT COUNT(*) FROM diagnoses d JOIN operations o ON o.id = d.op_ref
                                                                   WHERE o.deleted IS NULL AND d.coding_status <> 'confirmed')")],
    ];

    return Renderer::render("homepage.zetem", [
        'display_name' => $account ? $account->getname() : $uname,
        'roles'        => implode(', ', $mine),
        'stats'        => $stats,
        'cards'        => $visible,
    ]);
}

function nl_page_operations($params) {
    return nl_placeholder('Operations', 'Entering an operation with its procedure rows, facets, levels and RF protocol; then the list with a filter on every facet.', 'M2 / M3');
}

function nl_page_patients($params) {
    return nl_placeholder('Patients', 'Patients with their NeuroLog ID and all their operations.', 'M3');
}

function nl_page_statistics($params) {
    return nl_placeholder('Statistics', 'Counts by procedure and facet, from confirmed codes only, and pseudonymised research export.', 'M6');
}

/** Admin: the controlled vocabularies as loaded from vocab/ (read-only; values change in vocab/, see bin/load_vocab.php). */
function nl_page_codelists($params) {
    $db = dbConnection::getConnection();
    $list = (string)($_GET['list'] ?? '');
    $lists = $db->query("SELECT list, SUM(retired_at IS NULL) AS active, SUM(retired_at IS NOT NULL) AS retired
                           FROM nl_codes GROUP BY list ORDER BY list")->fetchAll(PDO::FETCH_ASSOC);
    $values = [];
    if ($list !== '') {
        $st = $db->prepare('SELECT code, parent, label_el, label_en, notes, origin, retired_at FROM nl_codes WHERE list = ? ORDER BY retired_at IS NOT NULL, sort, code');
        $st->execute([$list]);
        $values = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$values) $list = '';
    }
    return Renderer::render("codelists.zetem", ['lists' => $lists, 'list' => $list, 'values' => $values]);
}

function nl_page_profile($params) {
    global $kernel;
    $uname = $kernel->getUserName();
    $account = UsersClassEx::getUserAccount($uname);
    return Renderer::render("profile.zetem", [
        'username' => $uname,
        'name'     => $account ? $account->getname() : '',
        'email'    => $account ? $account->getemail() : '',
        'roles'    => implode(', ', nl_user_roles()),
    ]);
}

function nl_page_status($params) {
    $checks = [];
    $checks[] = ['label' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.1', '>='), 'detail' => PHP_VERSION];
    $db = true;
    try { UsersClassEx::getUserAccount('__probe__'); } catch (Throwable $e) { $db = false; }
    $checks[] = ['label' => 'Database', 'ok' => $db, 'detail' => defined('DB_NAME') ? DB_NAME : '-'];
    $checks[] = ['label' => 'ErnsAuth sign-in', 'ok' => class_exists('ernsauthClass') && ernsauthClass::isEnabled(),
                 'detail' => 'config/ernsauth.php and lib/ernsauth'];
    $vocab = __APPDIR__ . '/vocab';
    $checks[] = ['label' => 'Code lists (vocab/)', 'ok' => is_file("$vocab/procedures.csv") && is_file("$vocab/facets.yaml"), 'detail' => 'vocab/'];
    $sess = __APPDIR__ . '/var/sessions';
    $checks[] = ['label' => 'Session folder writable', 'ok' => is_dir($sess) && is_writable($sess), 'detail' => 'var/sessions'];
    return Renderer::render("status.zetem", ['checks' => $checks]);
}
