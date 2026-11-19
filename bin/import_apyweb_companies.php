#!/usr/bin/env php
<?php
/**
 * Import APYweb's supplier list into NeuroLog's Companies (docs/plans/suppliers.md): every APYweb company, with what
 * it supplies taken from APYweb's technology × company catalogue. Reads APYweb through its own db_config.php in a
 * READ ONLY session and never writes to it. Re-runnable: a company is matched on apyweb_name, then on its name;
 * ticks are added, never removed. Without --apply it only shows what it would do.
 *
 *   php bin/import_apyweb_companies.php [--apy=../apy] [--apply]
 *
 * Operation ticks (APYweb operation_technologies) need the operations themselves and come with the M5 import.
 * Company and technology names are printed here and stored in the database, never written to files in this repository.
 */
require_once(__DIR__ . '/lib/cli_boot.php');
require_once(__DIR__ . '/../web/vocab.php');
require_once(__DIR__ . '/../web/operations.php');
require_once(__DIR__ . '/../web/quickline.php');

$o = getopt('', ['apy:', 'apply']);
$apyDir = realpath($o['apy'] ?? __DIR__ . '/../../apy');
$apply = isset($o['apply']);
if (!$apyDir || !is_file("$apyDir/db_config.php")) { fwrite(STDERR, "APYweb checkout not found (expected db_config.php in --apy)\n"); exit(2); }

// APYweb, read-only. db_config.php defines $db_config and db_connect(); nothing else of APYweb is loaded.
$apy = (function (string $dir): PDO {
    require "$dir/db_config.php";
    $pdo = db_connect($db_config);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
})($apyDir);
$aCompanies = $apy->query('SELECT id, name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$aTech = $apy->query('SELECT id, name FROM technologies ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$aPairs = $apy->query('SELECT company_id, technology_id FROM company_technologies')->fetchAll(PDO::FETCH_NUM);
$aUsed = (int)$apy->query('SELECT COUNT(*) FROM operation_technologies')->fetchColumn();
$apy->exec('ROLLBACK');

$db = dbConnection::getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$valid = nl_lists($db)['field.company.supplies'] ?? [];

// technology name -> supplies code
$tag = []; $unmapped = [];
foreach ($aTech as $id => $name) {
    $c = nl_supply_tag_for_name($name);
    if ($c !== null && isset($valid[$c])) $tag[$id] = $c; else $unmapped[] = $name;
}
$want = [];   // APYweb company id -> [codes]
foreach ($aPairs as [$cid, $tid]) if (isset($tag[$tid])) $want[$cid][$tag[$tid]] = true;

$mine = $db->query('SELECT id, name, name_match, supplies, apyweb_name FROM companies')->fetchAll(PDO::FETCH_ASSOC);
$byApy = []; $byMatch = [];
foreach ($mine as $r) { if ($r['apyweb_name'] !== null) $byApy[$r['apyweb_name']] = $r; $byMatch[$r['name_match']] = $r; }

$uname = 'apyweb-import';
$created = $updated = $same = 0;
echo ($apply ? '' : "DRY RUN (nothing is written; add --apply)\n") . 'APYweb: ' . count($aCompanies) . ' companies, ' . count($aTech) . ' technologies, '
   . count($aPairs) . " catalogue ticks\n\n";
if ($apply) $db->beginTransaction();
try {
    foreach ($aCompanies as $cid => $aName) {
        $aName = trim(preg_replace('/\s+/u', ' ', $aName));
        $codes = array_keys($want[$cid] ?? []); sort($codes);
        $name = mb_substr($aName, 0, 96);
        $r = $byApy[$aName] ?? $byMatch[nl_name_match($name)] ?? null;
        $label = $codes ? implode(', ', $codes) : 'everything (no technology ticked in APYweb)';
        if (!$r) {
            echo "  new      $name — $label" . ($name !== $aName ? ' (name shortened to 96 characters)' : '') . "\n";
            if ($apply) {
                $db->prepare('INSERT INTO companies (name, name_match, supplies, apyweb_name, active, cuser) VALUES (?,?,?,?,1,?)')
                   ->execute([$name, nl_name_match($name), $codes ? json_encode($codes) : null, $aName, $uname]);
                nl_audit($db, $uname, 'create', 'companies', (int)$db->lastInsertId(), null, 'from APYweb');
            }
            $created++; continue;
        }
        $old = $r['supplies'] ? (json_decode($r['supplies'], true) ?: []) : [];
        // an empty list means "everything": imported ticks replace it, otherwise they are added
        $new = $codes ? array_values(array_unique(array_merge($old, $codes))) : $old; sort($new);
        $oldSorted = $old; sort($oldSorted);
        $link = $r['apyweb_name'] === null;
        if ($new === $oldSorted && !$link) { $same++; echo "  same     {$r['name']}\n"; continue; }
        echo "  update   {$r['name']} — " . ($new === $oldSorted ? 'linked to APYweb' : ($old ? implode(', ', $oldSorted) : 'everything') . ' → ' . implode(', ', $new)) . "\n";
        if ($apply) {
            $db->prepare('UPDATE companies SET supplies = ?, apyweb_name = COALESCE(apyweb_name, ?), udate = NOW(), uuser = ? WHERE id = ?')
               ->execute([$new ? json_encode($new) : null, $aName, $uname, $r['id']]);
            nl_audit($db, $uname, 'update', 'companies', (int)$r['id'], null, 'from APYweb');
        }
        $updated++;
    }
    if ($apply) $db->commit();
} catch (Throwable $e) {
    if ($apply && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Import failed, nothing written: ' . $e->getMessage() . "\n"); exit(1);
}

echo "\nCompanies: $created new, $updated updated, $same unchanged" . ($apply ? '' : ' (dry run)') . "\n";
echo "Technologies read as:\n";
foreach ($aTech as $id => $n) echo '  ' . str_pad($n, 28) . ' → ' . ($tag[$id] ?? 'NOT READ (tick it by hand under Admin › Companies)') . "\n";
echo "\nOperation ticks in APYweb: $aUsed (imported with the operations, M5)\n";
exit(0);
