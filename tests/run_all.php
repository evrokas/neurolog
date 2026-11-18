<?php
/**
 * NeuroLog test runner (bin/run_tests.sh).
 *   php tests/run_all.php [--static-only]
 * static: php -l, bash -n, YAML parse of config and entities, every route handler exists,
 *         every .zetem template compiles, unit tests (tests/static/*_test.php, tools/rf_protocol/test.php).
 * db:     bin/check_schema.php (tables, code lists, IDs) and bin/check_web.php (real HTTP through php -S).
 */
$root = dirname(__DIR__);
$o = getopt('', ['static-only']);
$fail = 0; $n = 0;
function ok(string $label, bool $pass, string $detail = ''): void {
    global $fail, $n; $n++;
    if (!$pass) $fail++;
    if (!$pass || getenv('VERBOSE')) echo ($pass ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? "  $detail" : '') . "\n";
}

$skip = '#/(\.git|fw|web/core|web/cache|web/files|var|node_modules)/#';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$zetem = [];
foreach ($it as $f) {
    $p = $f->getPathname(); $rel = substr($p, strlen($root) + 1);
    if (preg_match($skip, $p) || str_starts_with($rel, 'web/classes/') && !str_starts_with($rel, 'web/classes/yaml/')) continue;
    if (str_ends_with($p, '.php') || str_ends_with($p, '.php.in')) {
        $out = []; exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $c);
        ok("php -l $rel", $c === 0, implode(' ', $out));
    } elseif (str_ends_with($p, '.sh')) {
        $out = []; exec('bash -n ' . escapeshellarg($p) . ' 2>&1', $out, $c);
        ok("bash -n $rel", $c === 0, implode(' ', $out));
    } elseif (str_ends_with($p, '.zetem')) {
        $zetem[] = $p;
    }
}

if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
foreach (array_merge(glob("$root/config/*.yaml"), glob("$root/config/*.yaml.in"), glob("$root/web/classes/yaml/*.yaml"), glob("$root/vocab/*.yaml")) as $y) {
    ok('yaml ' . substr($y, strlen($root) + 1), is_array(@yaml_parse_file($y)));
}

// every route handler named in settings.info.yaml exists
$cfg = yaml_parse_file("$root/config/settings.info.yaml");
$core = ['login', 'login_post', 'logout'];
$src = implode("\n", array_map('file_get_contents', glob("$root/web/*.php")));
foreach ($cfg['routes'] as $name => $r) {
    $h = $r['handler'] ?? null;
    ok("route $name handler $h", $h && (in_array($h, $core, true) || preg_match('/function\s+' . preg_quote($h, '/') . '\s*\(/', $src)));
}
// every access: names a declared role
$roles = array_keys($cfg['roles']);
foreach ($cfg['routes'] as $name => $r) {
    foreach (preg_split('/\s+/', trim((string)($r['access'] ?? ''))) as $a) {
        if ($a !== '') ok("route $name access role $a declared", in_array($a, $roles, true));
    }
}

// every .zetem compiles to valid PHP (ZeusFW's own compiler)
if (is_file("$root/fw/core/templates/ZETEMTemplate.php")) {
    foreach ($zetem as $z) {
        $out = []; exec('php ' . escapeshellarg("$root/tests/lib/compile_zetem.php") . ' ' . escapeshellarg($z) . ' 2>&1', $out, $c);
        ok('zetem ' . substr($z, strlen($root) + 1), $c === 0, implode(' ', $out));
    }
} else {
    ok('zetem compile (fw link present)', false, 'create the fw symlink first');
}

foreach (array_merge(glob("$root/tests/static/*_test.php"), ["$root/tools/rf_protocol/test.php"]) as $t) {
    if (!is_file($t)) continue;
    $out = []; exec('php ' . escapeshellarg($t) . ' 2>&1', $out, $c);
    ok('unit ' . substr($t, strlen($root) + 1), $c === 0, implode("\n", array_slice($out, -5)));
}

// JavaScript: syntax of every web/js file and the shared-case tests in tests/js (needs node)
exec('command -v node', $nodeOut, $nodeMissing);
foreach (glob("$root/web/js/*.js") as $js) {
    if ($nodeMissing) break;
    $out = []; exec('node --check ' . escapeshellarg($js) . ' 2>&1', $out, $c);
    ok('node --check ' . substr($js, strlen($root) + 1), $c === 0, implode(' ', $out));
}
foreach (glob("$root/tests/js/*_test.js") as $t) {
    if ($nodeMissing) { ok('node available for ' . basename($t), false); continue; }
    $out = []; exec('node ' . escapeshellarg($t) . ' 2>&1', $out, $c);
    ok('js ' . substr($t, strlen($root) + 1), $c === 0, implode("
", array_slice($out, -5)));
}

if (!isset($o['static-only'])) {
    foreach (['check_schema', 'check_web'] as $chk) {
        passthru('php ' . escapeshellarg("$root/bin/$chk.php"), $c);
        ok("bin/$chk.php", $c === 0);
    }
}

echo "\n" . ($n - $fail) . "/$n passed" . ($fail ? ", $fail FAILED" : '') . "\n";
exit($fail ? 1 : 0);
