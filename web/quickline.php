<?php
/**
 * Quick line and `@` tags (docs/plans/tags-and-chips.md B1–B5): the operation as the group writes it ->
 * SUGGESTED procedure and diagnosis rows. Nothing here confirms a code (decisions 2026-11-18); the same parser
 * will read the APYweb texts in M5, so typed and imported operations are read alike.
 *
 *   nl_quickline_parse('Διαδερμική δισκεκτομή Ο4/5 αρ, TLIF', $lists, $aliases)
 *     -> procedures: discectomy L4-L5 left tubular_MIS; interbody_fusion L4-L5 left tubular_MIS transforaminal
 *
 * Rules (decisions log 2026-11-15): «Διαδερμική» = tubular MIS for discectomy, decompression, foraminotomy and
 * interbody fusion, percutaneous for pedicle screws; procedures percutaneous by nature are always percutaneous
 * (vocab/facets.yaml `diadermiki`). PLIF/TLIF is a cage and never implies screws; «σπονδυλοδεσία» is screws.
 * A part without levels takes the levels of the part before it; a part of levels only adds to the part before.
 * Tags: @δγ diagnosis, @επ levels, @πλ side, @προσ approach, @υλ implant/product (ZPMS syntax: longest name wins,
 * value ends at , · ; a full stop + space, a line break, the next tag; an e-mail address is not a tag).
 */

require_once __DIR__ . '/levels.php';

const NL_TAGS = ['ΔΙΑΓΝΩΣΗ' => 'dx', 'ΔΓ' => 'dx', 'DX' => 'dx', 'ΕΠΙΠΕΔΑ' => 'lvl', 'ΕΠ' => 'lvl', 'LVL' => 'lvl',
                 'ΠΛΕΥΡΑ' => 'side', 'ΠΛ' => 'side', 'SIDE' => 'side', 'ΠΡΟΣΠΕΛΑΣΗ' => 'appr', 'ΠΡΟΣ' => 'appr', 'APPR' => 'appr',
                 'ΥΛΙΚΟ' => 'impl', 'ΥΛ' => 'impl', 'IMPL' => 'impl'];

/** Uppercase, Greek accents removed, spaces collapsed. Keeps string length per character (for spans). */
function nl_ql_norm(string $s): string {
    $s = mb_strtoupper($s, 'UTF-8');
    return strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ϊ' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ϋ' => 'Υ', 'Ώ' => 'Ω', 'ΐ' => 'Ι', 'ΰ' => 'Υ']);
}

/** Aliases from vocab/aliases.yaml, normalised, longest first: [[norm phrase, list, code], …]. */
function nl_aliases(string $root): array {
    static $cache = [];
    if (isset($cache[$root])) return $cache[$root];
    if (!function_exists('yaml_parse_file')) require_once((defined('__FWDIR__') ? __FWDIR__ : "$root/fw/core") . '/lib/yaml_compat.php');
    $y = yaml_parse_file("$root/vocab/aliases.yaml") ?: [];
    $out = [];
    foreach (['procedure', 'diagnosis'] as $list) {
        foreach ($y[$list] ?? [] as $code => $words) foreach ($words as $w) $out[] = [nl_ql_norm((string)$w), $list, (string)$code];
    }
    usort($out, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
    return $cache[$root] = $out;
}

/** Find `@tags` in a text: [[start, end of tag word, tag key, value start, value end], …] (character offsets). */
function nl_tags_find(string $text): array {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($chars); $out = [];
    $names = array_keys(NL_TAGS);
    usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    for ($i = 0; $i < $n; $i++) {
        if ($chars[$i] !== '@') continue;
        if ($i > 0 && preg_match('/[\p{L}\p{N}._%+-]/u', $chars[$i - 1])) continue;   // e-mail address, not a tag
        $rest = nl_ql_norm(implode('', array_slice($chars, $i + 1, 12)));
        foreach ($names as $nm) {
            $len = mb_strlen($nm);
            if (mb_substr($rest, 0, $len) !== $nm) continue;
            $after = $chars[$i + 1 + $len] ?? '';
            if ($after !== '' && preg_match('/[\p{L}\p{N}]/u', $after)) continue;
            $vs = $i + 1 + $len;
            while ($vs < $n && $chars[$vs] === ' ') $vs++;
            $ve = $vs;
            while ($ve < $n) {
                $c = $chars[$ve];
                // a comma between two digits is a decimal comma (6,5 mm), not the end of the value
                if ($c === ',' && $ve > $vs && ctype_digit($chars[$ve - 1]) && ctype_digit($chars[$ve + 1] ?? '')) { $ve++; continue; }
                if (in_array($c, [',', '·', '·', ';', "\n", "\r"], true) || $c === '@') break;
                if ($c === '.' && ($ve + 1 >= $n || preg_match('/\s/u', $chars[$ve + 1]))) break;
                $ve++;
            }
            while ($ve > $vs && $chars[$ve - 1] === ' ') $ve--;
            $out[] = [$i, $i + 1 + $len, NL_TAGS[$nm], $vs, $ve];
            $i = $ve - 1;
            break;
        }
    }
    return $out;
}

/** The reading look of a note (B5): tag words hidden, tagged values in their family's ink. Escaped HTML. */
function nl_tags_render(string $text): string {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $h = ''; $pos = 0;
    foreach (nl_tags_find($text) as [$s, $e, $tag, $vs, $ve]) {
        $h .= htmlspecialchars(implode('', array_slice($chars, $pos, $s - $pos)), ENT_QUOTES, 'UTF-8');
        $h .= '<span class="tagv tag-' . $tag . '">' . htmlspecialchars(implode('', array_slice($chars, $vs, $ve - $vs)), ENT_QUOTES, 'UTF-8') . '</span>';
        $pos = $ve;
    }
    return $h . htmlspecialchars(implode('', array_slice($chars, $pos)), ENT_QUOTES, 'UTF-8');
}

/** Levels written in a (normalised) piece of text: the level tokens joined, or ''. Bare pairs only if $bare. */
function nl_ql_levels(string $t, bool $bare = false): string {
    $re = '/(?<![\p{L}\p{N}])([ΑΘΟΙACTLSOI]\d{1,2}(?:\s*[\/-]\s*[ΑΘΟΙACTLSOI]?\d{1,2})*)(?![\p{N}])/u';
    preg_match_all($re, $t, $m);
    $tok = $m[1];
    if ($bare && !$tok && preg_match_all('/(?<![\p{L}\p{N}])(\d{1,2}\s*\/\s*\d{1,2}(?:\s*\/\s*\d{1,2})*)(?![\p{N}])/u', $t, $m2)) $tok = $m2[1];
    return implode(', ', array_map(fn($x) => preg_replace('/\s+/u', '', $x), $tok));
}

function nl_ql_side(string $t): ?string {
    if (preg_match('/(?<![\p{L}])(ΑΜΦ|BILAT)/u', $t)) return 'bilateral';
    if (preg_match('/(?<![\p{L}])(ΑΡΙΣΤΕΡ\p{L}*|ΑΡ\.?|LEFT|LT)(?![\p{L}])/u', $t)) return 'left';
    if (preg_match('/(?<![\p{L}])(ΔΕΞΙ\p{L}*|ΔΕΞ\.?|ΔΕ\.?|RIGHT|RT)(?![\p{L}])/u', $t)) return 'right';
    if (preg_match('/(?<![\p{L}])(ΜΕΣΗ ΓΡΑΜΜΗ|MIDLINE)/u', $t)) return 'midline';
    return null;
}

function nl_ql_approach(string $t): ?string {
    if (preg_match('/MINI[\s-]*OPEN/u', $t)) return 'mini_open';
    if (preg_match('/(ΔΙΠΟΡΤ|BIPORTAL|(?<![\p{L}])UBE(?![\p{L}]))/u', $t)) return 'endoscopic_biportal';
    if (preg_match('/(ΕΝΔΟΡΡΙΝ|ΔΙΑΡΡΙΝ|ENDONASAL)/u', $t)) return 'endoscopic_endonasal';
    if (preg_match('/(ΕΝΔΟΣΚΟΠ|ENDOSCOP)/u', $t)) return 'endoscopic_uniportal';
    if (preg_match('/(ΑΝΟΙΚΤ|(?<![\p{L}])OPEN)/u', $t)) return 'open';
    if (preg_match('/(ΣΩΛΗΝΩΤ|TUBULAR)/u', $t)) return 'tubular_MIS';
    return null;
}

/**
 * An implant read from an @υλ tag (M4): category from the words, company from the group's list, sizes, count.
 * Returns null when no category is recognised. $companies: [id => name]. $context: the whole line (normalised), for
 * the cage route (ACDF, ALIF/LLIF, else PLIF/TLIF).
 */
function nl_ql_implant(string $text, array $companies, string $context = ''): ?array {
    $t = nl_ql_norm($text);
    $cat = null;
    $rules = [
        ['/(LATERAL MASS|ΠΛΑΓΙ\p{L}* ΜΑΖ)/u', 'lateral_mass_screw'], ['/(ΚΟΧΛΙ|ΒΙΔ|SCREW)/u', 'pedicle_screw'],
        ['/(ΡΑΒΔ|(?<![\p{L}])RODS?(?![\p{L}]))/u', 'rod'], ['/(CAGE|ΚΛΩΒ)/u', 'cage'],
        ['/(ΤΕΧΝΗΤ\p{L}* ΔΙΣΚ|DISC PROSTH|ΑΡΘΡΟΠΛΑΣΤ|ARTHROPLAST)/u', 'cervical_disc'], ['/(ΠΛΑΚ|PLATE)/u', 'plate'],
        ['/(ΜΕΣΑΚΑΝΘ|INTERSPINOUS)/u', 'interspinous_device'], ['/(ΤΣΙΜΕΝΤ|CEMENT|PMMA)/u', 'vertebral_augmentation_cement'],
        ['/(ΒΑΛΒΙΔ|VALVE|SHUNT)/u', 'shunt_valve'], ['/((?<![\p{L}])EVD(?![\p{L}])|ΕΞΩΤΕΡΙΚ\p{L}* ΠΑΡΟΧΕΤ)/u', 'external_drain'],
        ['/(ΗΛΕΚΤΡΟΔ|(?<![\p{L}])LEADS?(?![\p{L}]))/u', 'neurostimulator_lead'], ['/((?<![\p{L}])IPG(?![\p{L}])|ΓΕΝΝΗΤΡ|ΓΕΝΝΗΤΡΙ)/u', 'IPG'],
        ['/(ΑΝΤΛΙ|PUMP)/u', 'intrathecal_pump'], ['/(ΚΡΑΝΙΟΠΛΑΣΤ|CRANIOPLAST)/u', 'cranioplasty_implant'],
        ['/(ΜΟΣΧΕΥΜ|GRAFT|ΥΠΟΚΑΤΑΣΤΑΤ|SUBSTITUTE|DBM)/u', 'bone_graft_substitute'],
    ];
    foreach ($rules as [$re, $c]) if (preg_match($re, $t)) { $cat = $c; break; }
    if ($cat === null) return null;
    if ($cat === 'cage') {
        $ctx = $t . ' ' . nl_ql_norm($context);
        $cat = preg_match('/(?<![\p{L}])ACDF(?![\p{L}])/u', $ctx) ? 'cage_ACDF'
             : (preg_match('/(?<![\p{L}])(ALIF|LLIF|XLIF|OLIF)(?![\p{L}])/u', $ctx) ? 'cage_ALIF_LLIF' : 'cage_PLIF_TLIF');
    }
    $row = ['implant_category' => $cat, 'company_ref' => null, 'implant_count' => null, 'levels' => nl_ql_levels($t), 'side' => nl_ql_side($t),
            'params' => [], 'source' => $text];
    // company: the longest name found in the text
    $best = 0;
    foreach ($companies as $id => $name) {
        $n = nl_ql_norm($name);
        if (mb_strlen($n) > $best && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/u', $t)) { $row['company_ref'] = (int)$id; $best = mb_strlen($n); }
    }
    $num = '(\d+(?:[.,]\d+)?)';
    $x = '\s*[XΧ×*]\s*';
    if (preg_match("/(?<![\\d.,])$num$x$num(?:[XΧ×*]$num)?(?:\s*MM)?/u", $t, $m)) {
        $a = (float)str_replace(',', '.', $m[1]); $b = (float)str_replace(',', '.', $m[2]); $c = isset($m[3]) ? (float)str_replace(',', '.', $m[3]) : null;
        if (in_array($cat, ['pedicle_screw', 'lateral_mass_screw', 'rod'], true)) $row['params'] = ['diameter_mm' => $a, 'length_mm' => $b];
        elseif (str_starts_with($cat, 'cage_')) $row['params'] = $c !== null ? ['height_mm' => $a, 'width_mm' => $b, 'length_mm' => $c] : ['width_mm' => $a, 'length_mm' => $b];
        $t = str_replace($m[0], ' ', $t);
    }
    if (str_starts_with($cat, 'cage_') && !isset($row['params']['height_mm']) && preg_match("/(?<![\\d.,X])$num\s*MM/u", $t, $m)) $row['params']['height_mm'] = (float)str_replace(',', '.', $m[1]);
    if (str_starts_with($cat, 'cage_') && preg_match("/$num\s*(°|ΜΟΙΡ|DEG)/u", $t, $m)) $row['params']['lordosis_deg'] = (float)str_replace(',', '.', $m[1]);
    if ($cat === 'vertebral_augmentation_cement' && preg_match("/$num\s*(ML|CC)/u", $t, $m)) $row['params']['volume_ml'] = (float)str_replace(',', '.', $m[1]);
    // count: «x4», «4 κοχλίες», «4 screws»
    if (preg_match('/(?<![\p{L}\d.,])[XΧ×]\s*(\d{1,2})(?![\d.,])/u', $t, $m) || preg_match('/(?<![\d.,])(\d{1,2})\s*(ΚΟΧΛΙ|ΒΙΔ|SCREW|ΡΑΒΔ|ROD|CAGE|ΚΛΩΒ|ΗΛΕΚΤΡΟΔ|LEAD)/u', $t, $m)) $row['implant_count'] = (int)$m[1];
    return $row;
}

/**
 * Parse a quick line. $diadermiki: vocab/facets.yaml `diadermiki` (tubular_MIS, percutaneous, percutaneous_by_nature).
 * Returns ['procedures' => [...], 'diagnoses' => [...], 'unplaced' => [part texts nothing was read from], 'tags' => n].
 */
function nl_quickline_parse(string $text, array $aliases, array $diadermiki, array $companies = []): array {
    $text = trim($text);
    $out = ['procedures' => [], 'diagnoses' => [], 'implants' => [], 'unplaced' => [], 'tags' => 0];
    if ($text === '') return $out;
    // 1. explicit tags: read them, then blank them out of the text
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $tagged = ['dx' => [], 'lvl' => [], 'side' => [], 'appr' => [], 'impl' => []];
    foreach (nl_tags_find($text) as [$s, $e, $tag, $vs, $ve]) {
        $tagged[$tag][] = implode('', array_slice($chars, $vs, $ve - $vs));
        for ($k = $s; $k < $ve; $k++) $chars[$k] = ' ';
        $out['tags']++;
    }
    $rest = nl_ql_norm(implode('', $chars));
    $lineSide = $tagged['side'] ? nl_ql_side(nl_ql_norm($tagged['side'][0])) : null;
    $lineAppr = $tagged['appr'] ? nl_ql_approach(nl_ql_norm($tagged['appr'][0])) : null;
    $lineLevels = $tagged['lvl'] ? implode(', ', $tagged['lvl']) : '';
    $diadermikiOn = (bool)preg_match('/ΔΙΑΔΕΡΜ|ΔΙΑΔΕΜΙΚ|ΔΙΑΔΡΕΜ|PERCUTAN/u', $rest);
    $lineSide ??= nl_ql_side($rest);
    $product = null;
    foreach (array_merge($tagged['impl'], [$rest]) as $t) {
        $t = nl_ql_norm($t);
        if (str_contains($t, 'DISCOGEL')) $product = 'DiscoGel';
        elseif (preg_match('/GELSTI(X|KS|KS)/u', $t)) $product = 'GelStix';
    }

    // 2. parts
    $parts = preg_split('/\s*(?:[,;··\n+]|(?<![\p{L}])ΚΑΙ(?![\p{L}])|(?<![\p{L}])AND(?![\p{L}]))\s*/u', $rest);
    $prevLevels = ''; $prev = null; $prevStart = 0;
    foreach ($parts as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part));
        if ($part === '') continue;
        $found = [];
        $work = $part;
        foreach ($aliases as [$w, $list, $code]) {
            // words of 6+ letters match on their stem (last letter dropped): plurals and Greek endings
            // (δισκεκτομή / δισκεκτομές, discectomy / discectomies)
            $stem = mb_strlen($w) >= 6 && preg_match('/\p{L}$/u', $w) ? mb_substr($w, 0, -1) : $w;
            if (!preg_match('/(?<![\p{L}\p{N}])' . preg_quote($stem, '/') . '\p{L}*/u', $work, $m, PREG_OFFSET_CAPTURE)) continue;
            $found[] = [$list, $code, $w];
            $work = substr_replace($work, str_repeat(' ', strlen($m[0][0])), $m[0][1], strlen($m[0][0]));
        }
        $lv = nl_ql_levels($part);
        $procs = array_values(array_unique(array_map(fn($f) => $f[1], array_filter($found, fn($f) => $f[0] === 'procedure'))));
        $dxs = array_values(array_unique(array_map(fn($f) => $f[1], array_filter($found, fn($f) => $f[0] === 'diagnosis'))));
        if (in_array('trigeminal_percutaneous', $procs, true)) $procs = array_values(array_diff($procs, ['rf_neurotomy']));
        if (!$procs && !$dxs) {
            $more = $lv !== '' ? $lv : nl_ql_levels($part, true);
            if ($more !== '' && $prev !== null) {          // levels only: they belong to the part before
                $i = count($out['procedures']) - 1;
                $out['procedures'][$i]['levels'] = trim($out['procedures'][$i]['levels'] . ', ' . $more, ', ');
                $prevLevels = $out['procedures'][$i]['levels'];
                continue;
            }
            if ($more !== '' && $lineLevels === '') { $lineLevels = $more; continue; }
            // a part with only a side or an approach («…, ανοικτή», «…, αρ») qualifies the procedures of the part before
            $ps = nl_ql_side($part); $pa = nl_ql_approach($part);
            if (($ps || $pa) && $prev !== null) {
                for ($k = $prevStart; $k < count($out['procedures']); $k++) {
                    if ($pa) $out['procedures'][$k]['approach_type'] = $pa;
                    if ($ps) $out['procedures'][$k]['lesion_side'] = $ps;
                }
                continue;
            }
            if (!nl_ql_side($part) && !nl_ql_approach($part) && !preg_match('/^(ΔΙΑΔΕΡΜ|ΔΙΑΔΕΜΙΚ)\p{L}*$/u', $part)) $out['unplaced'][] = $part;
            continue;
        }
        $levels = $lv !== '' ? $lv : ($prevLevels !== '' ? $prevLevels : $lineLevels);
        $side = nl_ql_side($part) ?? $lineSide;
        $appr = nl_ql_approach($part) ?? $lineAppr;
        if ($procs) $prevStart = count($out['procedures']);
        foreach ($procs as $code) {
            $row = ['procedure_code' => $code, 'levels' => $levels, 'lesion_side' => $side, 'approach_type' => null,
                    'approach_route' => null, 'product' => null, 'trigeminal_technique' => null, 'branches_treated' => [], 'source' => $part];
            if ($appr) $row['approach_type'] = $appr;
            elseif (in_array($code, $diadermiki['percutaneous_by_nature'] ?? [], true)) $row['approach_type'] = 'percutaneous';
            elseif ($diadermikiOn && in_array($code, $diadermiki['percutaneous'] ?? [], true)) $row['approach_type'] = 'percutaneous';
            elseif ($diadermikiOn && in_array($code, $diadermiki['tubular_MIS'] ?? [], true)) $row['approach_type'] = 'tubular_MIS';
            if ($code === 'interbody_fusion') {
                foreach (['TLIF' => 'transforaminal', 'PLIF' => 'posterior_midline', 'XLIF' => 'lateral_transpsoas', 'LLIF' => 'lateral_transpsoas',
                          'OLIF' => 'oblique_antepsoas', 'ALIF' => 'anterior_retroperitoneal'] as $k => $route) {
                    if (preg_match('/(?<![\p{L}])' . $k . '(?![\p{L}])/u', $part)) { $row['approach_route'] = $route; break; }
                }
            }
            if ($code === 'acdf') $row['approach_route'] = 'anterior_cervical';
            if ($code === 'intradiscal_hydrogel') $row['product'] = $product;
            if ($code === 'trigeminal_percutaneous') {
                if (preg_match('/(ΜΠΑΛΟΝ|BALLOON)/u', $part)) $row['trigeminal_technique'] = 'balloon_compression';
                elseif (preg_match('/(ΓΛΥΚΕΡΙΝ|GLYCEROL)/u', $part)) $row['trigeminal_technique'] = 'glycerol_rhizotomy';
                elseif (preg_match('/(ΘΕΡΜΟΠΗΞ|ΡΑΔΙΟΣΥΧΝ|(?<![\p{L}])RF(?![\p{L}])|RADIOFREQ)/u', $part)) $row['trigeminal_technique'] = 'radiofrequency';
                preg_match_all('/(?<![\p{L}\p{N}])V\s?([123])(?![\p{N}])/u', $part, $bm);
                $br = array_values(array_unique(array_map(fn($x) => 'V' . $x, $bm[1]))); sort($br);
                $row['branches_treated'] = $br ?: (in_array($row['trigeminal_technique'], ['balloon_compression', 'glycerol_rhizotomy'], true) ? ['non_selective'] : []);
                $row['levels'] = '';
            }
            $out['procedures'][] = $row;
        }
        foreach ($dxs as $code) $out['diagnoses'][] = ['diagnosis_code' => $code, 'levels' => $levels, 'lesion_side' => $side, 'source' => $part];
        if ($procs) { $prev = $part; $prevLevels = $levels; }
    }
    // 3. tagged diagnoses
    foreach ($tagged['dx'] as $t) {
        $tn = nl_ql_norm($t); $code = null;
        foreach ($aliases as [$w, $list, $c]) if ($list === 'diagnosis' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '/u', $tn)) { $code = $c; break; }
        if ($code) $out['diagnoses'][] = ['diagnosis_code' => $code, 'levels' => nl_ql_levels($tn) ?: $lineLevels, 'lesion_side' => nl_ql_side($tn) ?? $lineSide, 'source' => $t];
        else $out['unplaced'][] = '@δγ ' . $t;
    }
    // 4. tagged implants (M4): proposed implant rows; a tag that names only a hydrogel product is the product above
    foreach ($tagged['impl'] as $t) {
        $im = nl_ql_implant($t, $companies, $rest);
        if ($im) { if ($im['levels'] === '') $im['levels'] = $lineLevels; $out['implants'][] = $im; }
        elseif (!preg_match('/DISCOGEL|GELSTI/u', nl_ql_norm($t))) $out['unplaced'][] = '@υλ ' . $t;
    }
    // procedures with no levels yet take the line's levels (e.g. «@επ Ο4/5» written last)
    foreach ($out['procedures'] as &$p) if ($p['levels'] === '' && $lineLevels !== '' && $p['procedure_code'] !== 'trigeminal_percutaneous') $p['levels'] = $lineLevels;
    unset($p);
    // the same diagnosis twice -> once
    $seen = [];
    $out['diagnoses'] = array_values(array_filter($out['diagnoses'], function ($d) use (&$seen) { $k = $d['diagnosis_code'] . '|' . $d['levels']; if (isset($seen[$k])) return false; return $seen[$k] = true; }));
    return $out;
}
