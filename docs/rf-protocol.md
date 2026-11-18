# RF protocol string

Every radiofrequency (RF) procedure records its stimulation tests and lesion cycles in **one text field,
`rf_protocol`**, in the order they were performed. The string is the only thing typed; it is validated when saved,
stored in a normalised form, and expanded into derived fields so that statistics never have to read it.

Applies to: `trigeminal_percutaneous` with technique `radiofrequency`, and `rf_neurotomy` (spinal root / DRG,
medial branch, SI joint, glossopharyngeal nerve). Reference parser: [`tools/rf_protocol/rf_protocol.php`](../tools/rf_protocol/rf_protocol.php),
tests: `php tools/rf_protocol/test.php`.

## Example

```
V2 S 50Hz 0.2V ok; V2 S 2Hz 0.8V mot; V2 P 45V 2Hz 20ms 120s 42C; V2 T 75C 90s x2
```

Sensory test on V2 (50 Hz, concordant at 0.2 V), motor test (2 Hz, motor response at 0.8 V), one pulsed cycle
(45 V, 2 Hz, 20 ms pulses, 120 s, 42 °C limit), then two thermal lesions (75 °C, 90 s each).

## Grammar

```
protocol  = cycle *( ";" cycle ) [";"]
cycle     = target SP mode *( SP value ) [ SP response ] [ SP repeat ]
target    = ( "V1" / "V2" / "V3" / "V" / root ) [ "-L" / "-R" ]
root      = "C" 1–8 / "T" 1–12 / "L" 1–5 / "S" 1–5
mode      = "T" / "P" / "S"
value     = number unit            ; number with "." or "," as decimal mark
unit      = "C" / "s" / "V" / "mA" / "Hz" / "ms" / "ohm"
response  = "ok" / "no" / "mot"    ; stimulation tests only
repeat    = "x" 1–10
```

| Part | Meaning |
| --- | --- |
| `V1` `V2` `V3` | Trigeminal branch. `V` = Gasserian ganglion, branch not determined. |
| `C1`–`C8`, `T1`–`T12`, `L1`–`L5`, `S1`–`S5` | Spinal root / dorsal root ganglion. Greek `Α`/`Θ`/`Ο`/`Ι` are accepted (`Ο5` = `L5`). |
| `-L` / `-R` | Side, only when it differs from the procedure's side (two roots on different sides in one session). |
| `T` | Thermal (conventional, continuous) RF lesion. |
| `P` | Pulsed RF. |
| `S` | Stimulation test before lesioning (sensory or motor). |
| `C` | Temperature in °C (thermal: lesion temperature; pulsed: temperature limit). |
| `s` | Duration in seconds. |
| `V` | Voltage (amplitude): pulsed output, or stimulation threshold. |
| `mA` | Current, for stimulation thresholds given in mA. |
| `Hz` | Frequency: pulse rate for pulsed RF; 50 Hz sensory / 2 Hz motor for tests. |
| `ms` | Pulse width (pulsed RF). |
| `ohm` | Impedance. |
| `ok` / `no` / `mot` | Test response: concordant paraesthesia / not concordant / motor response. |
| `x2` | The same cycle repeated, consecutive. |

A **combination** of pulsed and thermal is written as separate cycles in the order done: `V2 P …; V2 T …`.

Accepted spellings when typing, normalised on save: lower or upper case, `°C`, `sec`, `Ω`, `×2`, extra spaces, a
trailing `;`.

## Required values

| Mode | Required | Optional |
| --- | --- | --- |
| `T` thermal | `C`, `s` | `V`, `ohm` |
| `P` pulsed | `V`, `Hz`, `ms`, `s` | `C` (limit), `ohm` |
| `S` test | `Hz`, and `V` or `mA` | response, `ohm` |

A unit that does not apply to the mode, a unit given twice, or a missing required value is refused.

## Plausibility limits

Values outside these limits are refused to catch typos (`750C`, `9s` for `90s`). They are **validation settings,
not clinical guidance**, and are to be confirmed by the surgeon (`RF_LIMITS` in the parser).

| Mode | C | s | V | mA | Hz | ms | ohm |
| --- | --- | --- | --- | --- | --- | --- | --- |
| T | 40–95 | 1–1200 | 0–100 | — | — | — | 50–2000 |
| P | 37–45 | 1–1200 | 1–100 | — | 1–20 | 1–50 | 50–2000 |
| S | — | — | 0.01–5 | 0.01–10 | 1–100 | — | 50–2000 |

## Normalised form

The stored string has upper-case targets and modes, Latin letters for roots, one space between tokens, `; `
between cycles, and values in a fixed order per mode:

| Mode | Order |
| --- | --- |
| T | `C s V ohm` → `V2 T 75C 90s` |
| P | `V Hz ms s C ohm` → `L5 P 45V 2Hz 20ms 240s 42C` |
| S | `Hz V mA ohm` → `V2 S 50Hz 0.2V ok` |

Parsing the normalised form again gives the same string.

## Derived fields (stored, never typed)

| Field | Content |
| --- | --- |
| `rf_mode` | `thermal`, `pulsed`, `pulsed+thermal` or `stimulation_only` |
| `rf_cycles` | One row per cycle, repeats expanded: sequence, target, side, mode, temperature, duration, voltage, current, frequency, pulse width, impedance, response |
| per target | total thermal seconds, total pulsed seconds, maximum temperature, number of lesions |
| `branch_pattern` | Trigeminal branches lesioned, in order: `V2`, `V2+V3`, … |

Example: `V2 T 75C 90s x2; V2 T 80C 60s; V3 P 45V 2Hz 20ms 120s` gives `rf_mode = pulsed+thermal`,
V2 thermal 240 s, max 80 °C, 3 lesions; V3 pulsed 120 s; `branch_pattern = V2+V3`.

## Why one string

One field is faster to enter than a sub-form per cycle and keeps the whole session readable at a glance, the way it
is written in the operative note. Because the string has a strict grammar and is parsed on save, nothing is lost for
analysis: the derived fields carry every value.
