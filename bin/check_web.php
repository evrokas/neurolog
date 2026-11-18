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

$srv = (string)@file_get_contents($log);
check('server log has no PHP warning', !preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $srv), substr($srv, 0, 300));
exit($fail ? 1 : 0);
