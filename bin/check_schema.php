#!/usr/bin/env php
<?php
/**
 * Schema and code-list check (M1), against the real database:
 *  - every table and column declared in web/classes/yaml/ exists, nl_id columns are unique
 *  - vocab/ and nl_codes agree (a dry run of bin/load_vocab.php changes nothing)
 *  - a value that disappears from vocab/ is retired, not deleted, and comes back un-retired
 *  - nl_id_new_unique() draws valid IDs that are not in use
 *   php bin/check_schema.php
 */
require_once(__DIR__ . '/lib/cli_boot.php');
if (!function_exists('yaml_parse_file')) require_once(__FWDIR__ . '/lib/yaml_compat.php');
$root = dirname(__DIR__);
$pdo = dbConnection::getConnection();
$fail = 0;
function check(string $label, bool $pass, string $detail = ''): void {
    global $fail; if (!$pass) $fail++;
    if (!$pass || getenv('VERBOSE')) echo ($pass ? 'PASS  ' : 'FAIL  ') . "schema: $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

foreach (glob("$root/web/classes/yaml/*.yaml") as $y) {
    $t = yaml_parse_file($y)['table'];
    $cols = [];
    try { foreach ($pdo->query("SHOW COLUMNS FROM `{$t['name']}`") as $c) $cols[$c['Field']] = $c; }
    catch (Throwable $e) { check("table {$t['name']} exists", false, $e->getMessage()); continue; }
    $missing = array_diff(array_column($t['fields'], 'name'), array_keys($cols));
    check("table {$t['name']} has every declared column", !$missing, implode(', ', $missing));
    foreach ($t['fields'] as $f) {   // columns declared UNIQUE carry a unique index (NeuroLog IDs, source keys)
        if (stripos((string)$f['type'], 'UNIQUE') !== false && isset($cols[$f['name']])) {
            check("{$t['name']}.{$f['name']} is unique", in_array($cols[$f['name']]['Key'], ['UNI', 'PRI'], true));
        }
    }
}

$out = []; exec('php ' . escapeshellarg("$root/bin/load_vocab.php") . ' --dry-run 2>&1', $out, $c);
check('vocab/ and nl_codes agree (dry run changes nothing)', $c === 0 && str_contains(implode("\n", $out), ' 0 added, 0 updated') && str_contains(implode("\n", $out), ' 0 retired, 0 unretired'), implode(' ', $out));
$n = (int)$pdo->query("SELECT COUNT(*) FROM nl_codes WHERE list IN ('procedure','diagnosis','complication') AND retired_at IS NULL")->fetchColumn();
check('procedure, diagnosis and complication lists loaded', $n > 100, "$n values");
$el = (int)$pdo->query("SELECT COUNT(*) FROM nl_codes WHERE list='procedure' AND label_el IS NOT NULL")->fetchColumn();
check('procedure labels in Greek loaded', $el > 0);

// retire, never delete: a vocab value missing from vocab/ (simulated with a fake row) is retired by the loader
$pdo->prepare("DELETE FROM nl_codes WHERE list_code = 'zz_check|gone'")->execute();
$pdo->prepare("INSERT INTO nl_codes (list, code, list_code, origin) VALUES ('zz_check', 'gone', 'zz_check|gone', 'vocab')")->execute();
$pdo->prepare("INSERT INTO nl_codes (list, code, list_code, origin) VALUES ('zz_check', 'mine', 'zz_check|mine', 'admin')")->execute();
exec('php ' . escapeshellarg("$root/bin/load_vocab.php") . ' 2>&1', $out2, $c);
$r = $pdo->query("SELECT list_code, retired_at FROM nl_codes WHERE list = 'zz_check'")->fetchAll(PDO::FETCH_KEY_PAIR);
check('value no longer in vocab/ is retired, not deleted', isset($r['zz_check|gone']) && $r['zz_check|gone'] !== null);
check('value added in the app is left alone', array_key_exists('zz_check|mine', $r) && $r['zz_check|mine'] === null);
// a retired real value comes back un-retired
$pdo->exec("UPDATE nl_codes SET retired_at = NOW() WHERE list_code = 'procedure|discectomy'");
$out3 = []; exec('php ' . escapeshellarg("$root/bin/load_vocab.php") . ' 2>&1', $out3, $c);
check('retired value back in vocab/ is un-retired', $pdo->query("SELECT retired_at FROM nl_codes WHERE list_code = 'procedure|discectomy'")->fetchColumn() === null, implode(' ', $out3));
$pdo->exec("DELETE FROM nl_codes WHERE list = 'zz_check'");

$ids = [];
for ($i = 0; $i < 50; $i++) $ids[] = nl_id_new_unique($pdo, 'patients', 'P');
check('nl_id_new_unique draws valid, distinct IDs', count(array_unique($ids)) === 50 && count(array_filter($ids, 'nl_id_valid')) === 50);

exit($fail ? 1 : 0);
