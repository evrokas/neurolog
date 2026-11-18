<?php
/**
 * Implants (M4, docs/plans/m4.md A): one row per group of identical implants, with company, product, count, levels,
 * side and the parameters of its category (vocab/implants.yaml). nl_implants_validate() is pure and unit-tested.
 */

/** vocab/implants.yaml `categories`: category => [param => spec]. */
function nl_implant_spec(?string $root = null): array {
    static $cache = [];
    $root ??= defined('__APPDIR__') ? __APPDIR__ : dirname(__DIR__);
    if (!isset($cache[$root])) {
        if (!function_exists('yaml_parse_file')) require_once(__FWDIR__ . '/lib/yaml_compat.php');
        $cache[$root] = yaml_parse_file("$root/vocab/implants.yaml")['categories'] ?? [];
    }
    return $cache[$root];
}

/** Parse a number typed with a comma or a point; null if not a number. */
function nl_num(string $v): ?float {
    $v = str_replace(',', '.', trim($v));
    return preg_match('/^-?\d+(\.\d+)?$/', $v) ? (float)$v : null;
}

/** One value of a parameter: [clean value or null, error or null]. */
function nl_implant_param(array $spec, $raw, array $lists, int $count, array $keep = []): array {
    $t = $spec['type'];
    if ($t === 'grades') {
        $g = is_array($raw) ? $raw : preg_split('/[\s,;]+/u', strtoupper(str_replace(['Α', 'Β', 'Ε'], ['A', 'B', 'E'], mb_strtoupper(trim((string)$raw)))), -1, PREG_SPLIT_NO_EMPTY);
        if (count($g) === 1 && preg_match('/^[A-E]{2,}$/', $g[0])) $g = str_split($g[0]);
        if (!$g) return [null, null];
        foreach ($g as $x) if (!isset($lists['field.implant.' . $spec['list']][$x])) return [null, "«{$x}» is not a grade (A–E)"];
        if (count($g) > $count) return [null, 'more grades than implants'];
        return [$g, null];
    }
    $v = is_string($raw) ? trim($raw) : $raw;
    if ($v === '' || $v === null) return [null, null];
    switch ($t) {
        case 'num':
            $n = nl_num((string)$v);
            if ($n === null || $n < $spec['min'] || $n > $spec['max']) return [null, "a number {$spec['min']}–{$spec['max']}" . (isset($spec['unit']) ? " {$spec['unit']}" : '')];
            return [$n, null];
        case 'int':
            if (!preg_match('/^\d+$/', (string)$v) || (int)$v < $spec['min'] || (int)$v > $spec['max']) return [null, "a whole number {$spec['min']}–{$spec['max']}"];
            return [(int)$v, null];
        case 'bool':
            if (!in_array((string)$v, ['0', '1'], true)) return [null, 'yes or no'];
            return [(int)$v, null];
        case 'code':
            $list = 'field.implant.' . $spec['list'];
            if (!isset($lists[$list][$v]) || ($lists[$list][$v]['retired'] && !in_array($v, $keep, true))) return [null, 'not in the list'];
            return [$v, null];
        default:   // text
            $max = $spec['max'] ?? 64;
            if (mb_strlen((string)$v) > $max) return [null, "at most $max characters"];
            return [(string)$v, null];
    }
}

/**
 * Validate posted implant rows. $rows: [i => ['implant_category', 'company_ref', 'product', 'implant_count', 'levels',
 * 'transitional', 'side', 'p' => [param => value], 'for' => procedure row index, 'proposed']].
 * $ctx: lists, companies [id => true], keep. Returns [rows, errors, missing]; rows carry `_for` (posted procedure index).
 */
function nl_implants_validate(array $rows, array $ctx, ?array $spec = null): array {
    $spec ??= nl_implant_spec();
    $L = $ctx['lists']; $keep = $ctx['keep'] ?? [];
    $out = []; $err = []; $missing = [];
    foreach ($rows as $i => $d) {
        if (!is_array($d)) continue;
        $cat = trim((string)($d['implant_category'] ?? ''));
        $flat = implode('', array_map(fn($x) => is_array($x) ? implode('', array_map(fn($y) => is_array($y) ? implode('', $y) : (string)$y, $x)) : (string)$x, $d));
        if ($cat === '' && trim($flat) === '') continue;   // empty row
        $p = "im.$i";
        $row = ['implant_category' => null, 'company_ref' => null, 'product' => null, 'implant_count' => 1, 'side' => null, 'params' => null];
        if ($cat === '') $err["$p.implant_category"] = 'required';
        elseif (!nl_code_ok($L, 'field.implant.category', $cat, false, $keep)) $err["$p.implant_category"] = 'not in the list';
        else $row['implant_category'] = $cat;
        $co = trim((string)($d['company_ref'] ?? ''));
        if ($co !== '' && !isset($ctx['companies'][(int)$co])) $err["$p.company_ref"] = 'unknown company';
        elseif ($co !== '') $row['company_ref'] = (int)$co;
        elseif ($cat !== 'other' && $row['implant_category']) $missing[] = 'implant: company';
        $prod = trim((string)($d['product'] ?? ''));
        if (mb_strlen($prod) > 96) $err["$p.product"] = 'at most 96 characters';
        $row['product'] = $prod !== '' ? $prod : null;
        $n = trim((string)($d['implant_count'] ?? ''));
        if ($n === '') $n = '1';
        if (!preg_match('/^\d+$/', $n) || (int)$n < 1 || (int)$n > 50) $err["$p.implant_count"] = 'a whole number 1–50';
        else $row['implant_count'] = (int)$n;
        $side = trim((string)($d['side'] ?? ''));
        if ($side !== '' && !nl_code_ok($L, 'procedure.side', $side, false, $keep)) $err["$p.side"] = 'not in the list';
        $row['side'] = $side ?: null;
        nl_levels_into($row, $d, $p, false, $err);
        // parameters of the category; values of other categories are dropped
        $vals = [];
        foreach ($spec[$row['implant_category'] ?? ''] ?? [] as $name => $ps) {
            [$val, $e] = nl_implant_param($ps, $d['p'][$name] ?? '', $L, $row['implant_count'], $keep);
            if ($e !== null) { $err["$p.p.$name"] = $e; continue; }
            if ($val === null) {
                if (!empty($ps['block'])) $err["$p.p.$name"] = 'required';
                elseif (!empty($ps['required'])) $missing[] = 'implant: ' . str_replace('_', ' ', $name);
                continue;
            }
            $vals[$name] = $val;
        }
        $row['params'] = $vals ? json_encode($vals, JSON_UNESCAPED_UNICODE) : null;
        $f = (string)($d['for'] ?? '');
        $row['_for'] = $f !== '' ? $f : null;
        $row['_proposed'] = !empty($d['proposed']);
        $out[] = $row;
    }
    return [$out, $err, array_values(array_unique($missing))];
}

const NL_IM_COLS = ['implant_category', 'implant_count', 'company_ref', 'product', 'level_from', 'level_to', 'levels_list',
                    'region', 'side', 'params'];

/** A short text of an implant row's parameters: "diameter 6.5 mm, length 45 mm, cannulated". */
function nl_implant_params_text(?string $json, ?string $cat, ?array $spec = null): string {
    $spec ??= nl_implant_spec();
    $v = $json ? json_decode($json, true) : [];
    if (!$v) return '';
    $parts = [];
    foreach ($v as $k => $x) {
        $ps = $spec[$cat ?? ''][$k] ?? ['type' => 'text'];
        $name = preg_replace('/_(mm|ml|deg)$/', '', $k);
        $name = str_replace('_', ' ', $name);
        if ($ps['type'] === 'bool') { $parts[] = ($x ? '' : 'not ') . $name; continue; }
        if ($ps['type'] === 'grades') { $parts[] = 'breach ' . implode(' ', (array)$x); continue; }
        $unit = isset($ps['unit']) ? ($ps['unit'] === '°' ? '°' : ' ' . $ps['unit']) : '';
        $parts[] = $name . ' ' . str_replace('_', ' ', is_float($x) ? rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.') : (string)$x) . $unit;
    }
    return implode(', ', $parts);
}

/** Active companies [id => name] (plus inactive ones in $keepIds). */
function nl_companies(PDO $db, array $keepIds = []): array {
    $out = [];
    foreach ($db->query('SELECT id, name, active FROM companies ORDER BY name') as $r) {
        if ($r['active'] || in_array((int)$r['id'], $keepIds, true)) $out[(int)$r['id']] = $r['name'];
    }
    return $out;
}
