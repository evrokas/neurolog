<?php
declare(strict_types=1);
/**
 * NeuroLog RF protocol string: reference parser (plain PHP, no dependencies).
 *
 * One text field records every RF cycle of a procedure, in the order given. Specification:
 * docs/rf-protocol.md. This file is the reference the application will reuse.
 *
 *   V2 S 50Hz 0.2V ok; V2 P 45V 2Hz 20ms 120s 42C; V2 T 75C 90s x2
 *
 * rf_protocol_parse($text) returns:
 *   ok        bool     true when there are no errors
 *   canonical string   the normalised string to store (only when ok)
 *   cycles    list     one entry per cycle, repeats expanded (seq, target, side, mode, values…)
 *   summary   array    rf_mode (thermal | pulsed | pulsed+thermal | stimulation_only), targets,
 *                      per-target thermal seconds and maximum temperature
 *   errors    list     messages, each naming the cycle number
 */

const RF_MODES = ['T' => 'thermal', 'P' => 'pulsed', 'S' => 'stimulation'];

/** Unit => [canonical key, label]. Spelling variants are mapped before lookup. */
const RF_UNITS = [
    'C' => 'temp_c', 's' => 'duration_s', 'V' => 'voltage_v', 'mA' => 'current_ma',
    'Hz' => 'frequency_hz', 'ms' => 'pulse_width_ms', 'ohm' => 'impedance_ohm',
];
/** Canonical order of values inside a cycle, per mode (the order the values are usually read). */
const RF_ORDER = [
    'T' => ['C', 's', 'V', 'ohm'],                 // 75C 90s
    'P' => ['V', 'Hz', 'ms', 's', 'C', 'ohm'],     // 45V 2Hz 20ms 120s 42C
    'S' => ['Hz', 'V', 'mA', 'ohm'],               // 50Hz 0.2V
];

/**
 * Plausibility limits per mode (validation only, not clinical guidance; confirm with the surgeon).
 * A value outside its range is refused so that typos (750C, 9s instead of 90s) are caught at entry.
 */
const RF_LIMITS = [
    'T' => ['C' => [40, 95], 's' => [1, 1200], 'V' => [0, 100], 'ohm' => [50, 2000]],
    'P' => ['C' => [37, 45], 's' => [1, 1200], 'V' => [1, 100], 'Hz' => [1, 20], 'ms' => [1, 50], 'ohm' => [50, 2000]],
    'S' => ['V' => [0.01, 5], 'mA' => [0.01, 10], 'Hz' => [1, 100], 'ohm' => [50, 2000]],
];
const RF_REQUIRED = [
    'T' => [['C'], ['s']],
    'P' => [['V'], ['Hz'], ['ms'], ['s']],
    'S' => [['Hz'], ['V', 'mA']],          // one of V or mA
];
const RF_RESPONSES = ['ok' => 'concordant', 'no' => 'not_concordant', 'mot' => 'motor'];
const RF_MAX_REPEAT = 10;

/** Trigeminal V1–V3 (V = ganglion, branch not determined) or spinal root C1–C8, T1–T12, L1–L5, S1–S5. */
function rf_parse_target(string $tok): ?array
{
    $t = strtoupper($tok);
    // Greek letters the group uses for spine regions: Α cervical, Θ thoracic, Ο lumbar, Ι sacral.
    $t = strtr($t, ['Α' => 'C', 'Θ' => 'T', 'Ο' => 'L', 'Ι' => 'S']);
    if (!preg_match('/^(V[123]?|C[1-8]|T(?:[1-9]|1[0-2])|L[1-5]|S[1-5])(?:-([LR]))?$/', $t, $m)) {
        return null;
    }
    return ['target' => $m[1], 'side' => $m[2] ?? null];
}

/** "75C", "75°C", "0,2V", "90sec", "250Ω" → [unit, number]; null when not a value. */
function rf_parse_value(string $tok): ?array
{
    $t = str_replace(',', '.', $tok);
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*(°?c|sec|s|v|ma|hz|ms|ohm|Ω)$/iu', $t, $m)) {
        return null;
    }
    $u = strtolower($m[2]);
    $unit = match (true) {
        $u === 'c' || $u === '°c' => 'C',
        $u === 's' || $u === 'sec' => 's',
        $u === 'v' => 'V',
        $u === 'ma' => 'mA',
        $u === 'hz' => 'Hz',
        $u === 'ms' => 'ms',
        default => 'ohm',
    };
    return [$unit, (float)$m[1]];
}

function rf_fmt(float $n): string
{
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function rf_protocol_parse(string $text): array
{
    $errors = [];
    $cycles = [];
    $canon = [];
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return ['ok' => false, 'canonical' => null, 'cycles' => [], 'summary' => null, 'errors' => ['empty protocol']];
    }
    $parts = array_map('trim', explode(';', rtrim($text, '; ')));
    $seq = 0;
    foreach ($parts as $i => $part) {
        $n = $i + 1;
        $toks = $part === '' ? [] : explode(' ', $part);
        if (count($toks) < 2) {
            $errors[] = "cycle $n: needs at least a target and a mode";
            continue;
        }
        $tg = rf_parse_target($toks[0]);
        if ($tg === null) {
            $errors[] = "cycle $n: unknown target '{$toks[0]}' (V1, V2, V3, V, or a root such as L5, S1, C6; side -L/-R)";
            continue;
        }
        $mode = strtoupper($toks[1]);
        if (!isset(RF_MODES[$mode])) {
            $errors[] = "cycle $n: unknown mode '{$toks[1]}' (T thermal, P pulsed, S stimulation test)";
            continue;
        }
        $vals = [];
        $repeat = 1;
        $response = null;
        $bad = false;
        foreach (array_slice($toks, 2) as $tok) {
            if (preg_match('/^[x×](\d+)$/iu', $tok, $m)) {
                $repeat = (int)$m[1];
                if ($repeat < 1 || $repeat > RF_MAX_REPEAT) {
                    $errors[] = "cycle $n: repeat x$repeat out of range 1–" . RF_MAX_REPEAT;
                    $bad = true;
                }
                continue;
            }
            if (isset(RF_RESPONSES[strtolower($tok)])) {
                if ($mode !== 'S') {
                    $errors[] = "cycle $n: a response ('$tok') belongs only to a stimulation test (S)";
                    $bad = true;
                }
                $response = strtolower($tok);
                continue;
            }
            $v = rf_parse_value($tok);
            if ($v === null) {
                $errors[] = "cycle $n: cannot read '$tok'";
                $bad = true;
                continue;
            }
            [$unit, $num] = $v;
            if (isset($vals[$unit])) {
                $errors[] = "cycle $n: $unit given twice";
                $bad = true;
                continue;
            }
            if (!isset(RF_LIMITS[$mode][$unit])) {
                $errors[] = "cycle $n: $unit does not apply to mode $mode";
                $bad = true;
                continue;
            }
            [$lo, $hi] = RF_LIMITS[$mode][$unit];
            if ($num < $lo || $num > $hi) {
                $errors[] = "cycle $n: " . rf_fmt($num) . "$unit outside " . rf_fmt($lo) . '–' . rf_fmt($hi) . "$unit for mode $mode";
                $bad = true;
            }
            $vals[$unit] = $num;
        }
        foreach (RF_REQUIRED[$mode] as $alts) {
            $have = array_filter($alts, fn($u) => isset($vals[$u]));
            if (!$have) {
                $errors[] = "cycle $n: mode $mode needs " . implode(' or ', $alts);
                $bad = true;
            }
        }
        if ($bad) {
            continue;
        }
        $c = $tg['target'] . ($tg['side'] ? '-' . $tg['side'] : '') . ' ' . $mode;
        foreach (RF_ORDER[$mode] as $u) {
            if (isset($vals[$u])) {
                $c .= ' ' . rf_fmt($vals[$u]) . $u;
            }
        }
        if ($response) {
            $c .= ' ' . $response;
        }
        if ($repeat > 1) {
            $c .= ' x' . $repeat;
        }
        $canon[] = $c;
        for ($r = 0; $r < $repeat; $r++) {
            $row = ['seq' => ++$seq, 'cycle' => $n, 'target' => $tg['target'], 'side' => $tg['side'],
                    'mode' => RF_MODES[$mode]];
            foreach ($vals as $u => $num) {
                $row[RF_UNITS[$u]] = $num;
            }
            if ($response) {
                $row['response'] = RF_RESPONSES[$response];
            }
            $cycles[] = $row;
        }
    }
    if ($errors) {
        return ['ok' => false, 'canonical' => null, 'cycles' => [], 'summary' => null, 'errors' => $errors];
    }
    return ['ok' => true, 'canonical' => implode('; ', $canon), 'cycles' => $cycles,
            'summary' => rf_protocol_summary($cycles), 'errors' => []];
}

/** Derived fields stored next to the string, so searches never have to parse it. */
function rf_protocol_summary(array $cycles): array
{
    $modes = array_unique(array_column($cycles, 'mode'));
    $hasT = in_array('thermal', $modes, true);
    $hasP = in_array('pulsed', $modes, true);
    $rfMode = $hasT && $hasP ? 'pulsed+thermal' : ($hasT ? 'thermal' : ($hasP ? 'pulsed' : 'stimulation_only'));
    $targets = [];
    foreach ($cycles as $c) {
        $k = $c['target'] . ($c['side'] ? '-' . $c['side'] : '');
        $targets[$k] ??= ['thermal_s' => 0.0, 'pulsed_s' => 0.0, 'max_temp_c' => null, 'lesions' => 0];
        if ($c['mode'] === 'stimulation') {
            continue;
        }
        $targets[$k]['lesions']++;
        $targets[$k][$c['mode'] === 'thermal' ? 'thermal_s' : 'pulsed_s'] += $c['duration_s'];
        if (isset($c['temp_c'])) {
            $targets[$k]['max_temp_c'] = max($targets[$k]['max_temp_c'] ?? 0, $c['temp_c']);
        }
    }
    $order = fn($t) => [str_starts_with($t, 'V') ? 0 : 1, $t];
    uksort($targets, fn($a, $b) => $order($a) <=> $order($b));
    $branches = array_values(array_filter(array_keys($targets), fn($t) => preg_match('/^V[123]/', $t)));
    return ['rf_mode' => $rfMode, 'targets' => $targets,
            'branch_pattern' => $branches ? implode('+', array_map(fn($t) => substr($t, 0, 2), $branches)) : null];
}
