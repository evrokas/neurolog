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
$dry = in_array('--dry-run', $argv, true);

/** All values from vocab/: list|code => [list, code, parent, label_el, label_en, notes, extra, sort] */
function nl_vocab_rows(string $root): array {
    $rows = [];
    $add = function (string $list, string $code, int $sort, array $more = []) use (&$rows) {
        $code = trim($code);
        if ($code === '') return;
        if (strlen($list) > 96 || strlen($code) > 64) throw new RuntimeException("too long: $list|$code");
        $key = "$list|$code";
        if (isset($rows[$key])) throw new RuntimeException("duplicate value $key in vocab/");
        $rows[$key] = ['list' => $list, 'code' => $code, 'parent' => $more['parent'] ?? null,
            'label_el' => ($more['label_el'] ?? '') ?: null, 'label_en' => ($more['label_en'] ?? '') ?: null,
            'notes' => ($more['notes'] ?? '') ?: null, 'extra' => !empty($more['extra']) ? json_encode($more['extra'], JSON_UNESCAPED_UNICODE) : null,
            'sort' => $sort];
    };
    $csv = function (string $file) use ($root): array {
        $fh = fopen("$root/vocab/$file", 'r');
        $head = fgetcsv($fh, null, ',', '"', '');
        $out = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($r === [null]) continue;
            $out[] = array_combine($head, array_pad($r, count($head), ''));
        }
        fclose($fh);
        return $out;
    };
    foreach ($csv('procedures.csv') as $i => $r) {
        $add('procedure', $r['code'], $i, ['parent' => $r['domain'], 'label_el' => $r['label_el'], 'notes' => $r['notes'],
            'extra' => array_filter(['erweb_page' => trim($r['erweb_page'], " —-")])]);
    }
    foreach ($csv('diagnoses.csv') as $i => $r) {
        $add('diagnosis', $r['code'], $i, ['parent' => $r['class'], 'label_el' => $r['label_el'],
            'extra' => array_filter(['qualifiers' => $r['qualifiers']])]);
    }
    foreach ($csv('complications.csv') as $i => $r) {
        $add('complication', $r['code'], $i, ['parent' => $r['group'], 'label_en' => $r['label_en']]);
    }
    // facets.yaml: a list of plain values becomes list "<dotted path>". These parts are rules, not value lists:
    $skip = ['procedure.rf_protocol', 'procedure.trigeminal.branch_pattern', 'anatomy.spine_region', 'level_notation', 'diadermiki'];
    $keep = ['procedure.rf_protocol.rf_mode'];
    $walk = function ($node, string $path) use (&$walk, $add, $skip, $keep) {
        foreach ($skip as $s) {
            if (($path === $s || str_starts_with($path, "$s.")) && !in_array($path, $keep, true)
                && !array_filter($keep, fn($k) => str_starts_with($k, "$path."))) return;
        }
        if (is_array($node) && array_is_list($node)) {
            foreach ($node as $i => $v) {
                if (!is_string($v)) throw new RuntimeException("$path: value " . var_export($v, true) . ' is not text (quote it in the YAML)');
                $add($path, $v, $i);
            }
        } elseif (is_array($node)) {
            foreach ($node as $k => $v) $walk($v, $path === '' ? (string)$k : "$path.$k");
        }
    };
    if (!function_exists('yaml_parse_file')) require_once(__FWDIR__ . '/lib/yaml_compat.php');
    $walk(yaml_parse_file("$root/vocab/facets.yaml"), '');
    $walk(yaml_parse_file("$root/vocab/fields.yaml"), 'field');
    return $rows;
}

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
