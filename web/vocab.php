<?php
/**
 * Controlled vocabularies: reading vocab/ (for bin/load_vocab.php and the unit tests) and nl_codes (for the app).
 */

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
        $add('procedure', $r['code'], $i, ['parent' => $r['domain'], 'label_el' => $r['label_el'], 'label_en' => $r['label_en'] ?? '',
            'notes' => $r['notes'], 'extra' => array_filter(['action' => $r['action'] ?? '', 'level_kind' => $r['level_kind'] ?? '',
            'erweb_page' => trim($r['erweb_page'], " —-")])]);
    }
    foreach ($csv('diagnoses.csv') as $i => $r) {
        $add('diagnosis', $r['code'], $i, ['parent' => $r['class'], 'label_el' => $r['label_el'], 'label_en' => $r['label_en'] ?? '',
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
    if (!function_exists('yaml_parse_file')) require_once((defined('__FWDIR__') ? __FWDIR__ : "$root/fw/core") . '/lib/yaml_compat.php');
    $walk(yaml_parse_file("$root/vocab/facets.yaml"), '');
    $walk(yaml_parse_file("$root/vocab/fields.yaml"), 'field');
    return $rows;
}


/**
 * Lists as the app uses them: [list => [code => ['label_el','label_en','parent','extra'(array),'retired'(bool)]]].
 * From the database (nl_codes) or, with $rows from nl_vocab_rows(), without one (unit tests).
 */
function nl_lists(?PDO $pdo, ?array $rows = null): array {
    $out = [];
    $src = $rows !== null ? array_values($rows) : $pdo->query('SELECT list, code, parent, label_el, label_en, extra, retired_at FROM nl_codes ORDER BY list, sort, code')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($src as $r) {
        $out[$r['list']][$r['code']] = ['label_el' => $r['label_el'], 'label_en' => $r['label_en'], 'parent' => $r['parent'],
            'extra' => $r['extra'] ? json_decode($r['extra'], true) : [], 'retired' => !empty($r['retired_at'])];
    }
    return $out;
}

/** Display label of a code: Greek, else English, else the code. */
function nl_label(array $lists, string $list, ?string $code): string {
    if ($code === null || $code === '') return '';
    $v = $lists[$list][$code] ?? null;
    return $v ? ($v['label_el'] ?: ($v['label_en'] ?: str_replace('_', ' ', $code))) : $code;
}
