#!/usr/bin/env php
<?php
/**
 * Load the controlled vocabularies from vocab/ into nl_codes (docs/data-dictionary.md, "Controlled vocabularies").
 *
 *   php bin/load_vocab.php [--dry-run]
 *
 * Sources: vocab/procedures.csv (list procedure), vocab/diagnoses.csv (diagnosis), vocab/complications.csv
 * (complication), vocab/facets.yaml (procedure.*, diagnosis.*, followup.*, anatomy.*), vocab/fields.yaml (field.*).
 * Idempotent: inserts new values, updates labels/notes of existing ones, and RETIRES (retired_at) values that are
 * no longer in vocab/; it never deletes or renames, so old records keep their meaning. A retired value that comes
 * back is un-retired. Values added in the app (origin = admin) are left alone. Run by bin/post-update.sh.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once("$root/bin/lib/cli_boot.php");
require_once("$root/web/vocab.php");
$dry = in_array('--dry-run', $argv, true);

$rows = nl_vocab_rows($root);
$pdo = dbConnection::getConnection();
$have = [];
foreach ($pdo->query('SELECT * FROM nl_codes') as $r) $have[$r['list_code']] = $r;

$ins = $pdo->prepare('INSERT INTO nl_codes (list, code, list_code, parent, label_el, label_en, notes, extra, sort, origin) VALUES (?,?,?,?,?,?,?,?,?,?)');
$upd = $pdo->prepare('UPDATE nl_codes SET parent=?, label_el=?, label_en=?, notes=?, extra=?, sort=?, retired_at=NULL WHERE id=?');
$ret = $pdo->prepare('UPDATE nl_codes SET retired_at=NOW() WHERE id=?');
$n = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'retired' => 0, 'unretired' => 0];
$pdo->beginTransaction();
foreach ($rows as $key => $r) {
    if (!isset($have[$key])) {
        $n['added']++;
        if (!$dry) $ins->execute([$r['list'], $r['code'], $key, $r['parent'], $r['label_el'], $r['label_en'], $r['notes'], $r['extra'], $r['sort'], 'vocab']);
        continue;
    }
    $h = $have[$key];
    $same = $h['parent'] === $r['parent'] && $h['label_el'] === $r['label_el'] && $h['label_en'] === $r['label_en']
        && $h['notes'] === $r['notes'] && $h['extra'] === $r['extra'] && (int)$h['sort'] === $r['sort'] && $h['retired_at'] === null;
    if ($same) { $n['unchanged']++; continue; }
    $n[$h['retired_at'] !== null ? 'unretired' : 'updated']++;
    if (!$dry) $upd->execute([$r['parent'], $r['label_el'], $r['label_en'], $r['notes'], $r['extra'], $r['sort'], $h['id']]);
}
foreach ($have as $key => $h) {
    if (isset($rows[$key]) || $h['origin'] !== 'vocab' || $h['retired_at'] !== null) continue;
    $n['retired']++;
    echo "retired: $key (no longer in vocab/)\n";
    if (!$dry) $ret->execute([$h['id']]);
}
$dry ? $pdo->rollBack() : $pdo->commit();

$lists = count(array_unique(array_column($rows, 'list')));
echo ($dry ? '[dry run] ' : '') . "vocab: " . count($rows) . " values in $lists lists; "
   . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($n), $n)) . "\n";
