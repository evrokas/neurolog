<?php
/**
 * Route handlers for the M0 shell (docs/implementation-plan.md): dashboard, profile, system
 * status, and placeholders for the pages later milestones build.
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

    // Counters: real numbers arrive with the schema (M1); until then they read 0.
    $stats = [
        ['label' => 'Operations recorded', 'value' => 0],
        ['label' => 'This year',           'value' => 0],
        ['label' => 'Codes to confirm',    'value' => 0],
        ['label' => 'Follow-ups due',      'value' => 0],
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

function nl_page_codelists($params) {
    return nl_placeholder('Code lists', 'Procedures, diagnoses and facet values loaded from vocab/. Values are retired, never renamed or deleted.', 'M1');
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
