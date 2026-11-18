<?php
/**
 * Spinal levels as the group writes them (docs/data-dictionary.md "Level entry", vocab/facets.yaml level_notation).
 *
 *   nl_levels_parse('Ο4/5')        -> items [[L4, L5, segment]], text "L4-L5", region lumbar
 *   nl_levels_parse('Α5/6/7')      -> C5-C6, C6-C7 (a chain of vertebrae = the segments between neighbours)
 *   nl_levels_parse('Ο2/3,3/4')    -> L2-L3, L3-L4 (a pair without a letter after a comma continues the region)
 *   nl_levels_parse('Ο3-5')        -> L3-L5 (dash between non-neighbours = a range)
 *   nl_levels_parse('Ο1')          -> L1 (one vertebra)
 * Letters: Greek Α Θ Ο Ι or Latin C T L S (Latin A, O, I read as Α, Ο, Ι), any case. Items separated by , ; + «και».
 * Refused: impossible vertebrae (Ο6 without the transitional flag, Θ13, Α9), reversed (Ο5/4), a slash between
 * non-neighbours (Ο4/6), a bare number on its own. Pure functions; web/js/levels.js is the same parser.
 */

const NL_VERTEBRAE = ['C0','C1','C2','C3','C4','C5','C6','C7','T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12',
                      'L1','L2','L3','L4','L5','L6','S1','S2','S3','S4','S5'];
const NL_LEVEL_MAX = ['C' => 7, 'T' => 12, 'L' => 5, 'S' => 5];
// Spine region from the levels: pure regions first, then junctions (vocab/facets.yaml anatomy.spine_region).
const NL_SPINE_REGIONS = [
    'cervical' => ['C2', 'C7'], 'thoracic' => ['T1', 'T12'], 'lumbar' => ['L1', 'L5'], 'sacral_coccygeal' => ['S1', 'S5'],
    'craniocervical' => ['C0', 'C2'], 'cervicothoracic' => ['C7', 'T2'], 'thoracolumbar' => ['T11', 'L2'], 'lumbosacral' => ['L5', 'S1'],
];

function nl_vertebra_index(string $v, bool $transitional): ?int {
    if ($v === 'L6' && !$transitional) return null;
    $i = array_search($v, NL_VERTEBRAE, true);
    if ($i === false) return null;
    // without a transitional vertebra L5 and S1 are neighbours: skip L6 in the order
    if (!$transitional && $i > array_search('L6', NL_VERTEBRAE, true)) $i--;
    return $i;
}

function nl_levels_parse(string $text, bool $transitional = false): array {
    $out = ['ok' => false, 'items' => [], 'text' => '', 'region' => null, 'errors' => []];
    $s = mb_strtoupper(trim($text), 'UTF-8');
    if ($s === '') { $out['errors'][] = 'no level given'; return $out; }
    $s = preg_replace('/(?<![\p{L}])(?:ΚΑΙ|KAI|AND)(?![\p{L}])/u', ',', $s);     // «και», kai, and separate items
    $s = strtr($s, ['Α' => 'C', 'Θ' => 'T', 'Ο' => 'L', 'Ι' => 'S', 'Ά' => 'C', 'Ό' => 'L', 'Ί' => 'S',
                    'A' => 'C', 'O' => 'L', 'I' => 'S', '–' => '-', '—' => '-', '\\' => '/']);
    $parts = preg_split('/[,;+&]|\s+(?=[CTLS]\d)/u', $s);
    $prevLetter = null;
    foreach ($parts as $part) {
        $part = preg_replace('/\s+/u', '', $part);
        if ($part === '') continue;
        if (!preg_match('/^([CTLS]?\d{1,2})((?:[\/-][CTLS]?\d{1,2})*)$/u', $part, $m)) {
            $out['errors'][] = "«" . $part . "» is not a level"; continue;
        }
        preg_match_all('/([\/-]?)([CTLS]?)(\d{1,2})/u', $part, $tok, PREG_SET_ORDER);
        if (count($tok) === 1 && $tok[0][2] === '') { $out['errors'][] = "«{$part}»: a number alone is not a level"; continue; }
        $verts = []; $seps = []; $letter = null;
        foreach ($tok as $k => $t) {
            $L = $t[2] !== '' ? $t[2] : ($letter ?? $prevLetter);
            if ($L === null) { $out['errors'][] = "«{$part}»: which region (Α Θ Ο Ι)?"; continue 2; }
            $n = (int)$t[3];
            if ($n < ($L === 'C' ? 0 : 1) || $n > NL_LEVEL_MAX[$L] + ($L === 'L' && $transitional ? 1 : 0)) {
                $out['errors'][] = "$L$n does not exist" . ($L === 'L' && $n === 6 ? ' (tick «transitional vertebra» for L6)' : ''); continue 2;
            }
            $verts[] = "$L$n"; if ($k > 0) $seps[] = $t[1]; $letter = $L;
        }
        $prevLetter = $letter;
        if (count($verts) === 1) { $out['items'][] = [$verts[0], $verts[0], 'vertebra']; continue; }
        $idx = array_map(fn($v) => nl_vertebra_index($v, $transitional), $verts);
        for ($k = 1; $k < count($idx); $k++) {
            if ($idx[$k] <= $idx[$k - 1]) { $out['errors'][] = "«{$part}»: levels go from top to bottom ({$verts[$k-1]} before {$verts[$k]})"; continue 2; }
        }
        if (count($verts) === 2 && $idx[1] - $idx[0] > 1) {
            if ($seps[0] === '/') { $out['errors'][] = "«{$part}»: {$verts[0]}/{$verts[1]} are not neighbours (use a dash for a range)"; continue; }
            $out['items'][] = [$verts[0], $verts[1], 'range']; continue;
        }
        for ($k = 1; $k < count($verts); $k++) {
            if ($idx[$k] - $idx[$k - 1] !== 1) { $out['errors'][] = "«{$part}»: {$verts[$k-1]} and {$verts[$k]} are not neighbours"; continue 2; }
            $out['items'][] = [$verts[$k - 1], $verts[$k], 'segment'];
        }
    }
    if ($out['errors'] || !$out['items']) { if (!$out['errors']) $out['errors'][] = 'no level given'; $out['items'] = []; return $out; }
    $out['ok'] = true;
    $out['text'] = implode(', ', array_map(fn($it) => $it[0] === $it[1] ? $it[0] : "$it[0]-$it[1]", $out['items']));
    $out['region'] = nl_spine_region($out['items'], $transitional);
    return $out;
}

/** The spine region that contains every level (pure region first, then a junction), or null if none does. */
function nl_spine_region(array $items, bool $transitional = false): ?string {
    $lo = PHP_INT_MAX; $hi = -1;
    foreach ($items as [$a, $b]) { $lo = min($lo, nl_vertebra_index($a, $transitional)); $hi = max($hi, nl_vertebra_index($b, $transitional)); }
    foreach (NL_SPINE_REGIONS as $name => [$from, $to]) {
        $f = nl_vertebra_index($from, $transitional); $t = nl_vertebra_index($to === 'L5' && $transitional ? 'L6' : $to, $transitional);
        if ($lo >= $f && $hi <= $t) return $name;
    }
    return null;
}
