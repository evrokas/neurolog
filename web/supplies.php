<?php
/**
 * Suppliers (docs/plans/suppliers.md): what a company supplied to an operation besides implants, one row per kind,
 * company and product (neuromonitoring, navigation, endoscope, sealant, drain…). Companies are one list for every
 * supplier, each tagged with what it supplies (companies.supplies, field.company.supplies; none ticked = everything).
 * nl_supplies_validate() and nl_supplies_expected() are pure and unit-tested.
 */

// a procedure-row technology (procedure.technology) or an operation field value -> the supply kind it is bought as
const NL_SUPPLY_FROM_TECHNOLOGY = ['neuromonitoring' => 'neuromonitoring', 'navigation' => 'navigation', 'robot' => 'robot',
    'endoscope' => 'endoscope', 'microscope' => 'microscope', 'exoscope' => 'exoscope', 'intraop_CT' => 'intraop_CT',
    'intraop_US' => 'intraop_US', 'intraop_MRI' => 'intraop_MRI'];

const NL_SU_COLS = ['supply_kind', 'company_ref', 'product', 'note'];

/**
 * Validate posted supply rows. $rows: [i => ['supply_kind', 'company_ref', 'product', 'note', 'for' => procedure row index]].
 * A row with only its kind (offered by the page when a technology is ticked, left blank) is skipped: the supplier is
 * optional (S3), and listed as missing by nl_supplies_expected() instead.
 * $ctx: lists, companies [id => true], keep. Returns [rows, errors]; rows carry `_for` (posted procedure index).
 */
function nl_supplies_validate(array $rows, array $ctx): array {
    $L = $ctx['lists']; $keep = $ctx['keep'] ?? [];
    $out = []; $err = []; $seen = [];
    foreach ($rows as $i => $d) {
        if (!is_array($d)) continue;
        $kind = trim((string)($d['supply_kind'] ?? ''));
        $co = trim((string)($d['company_ref'] ?? ''));
        $prod = trim((string)($d['product'] ?? ''));
        $note = trim((string)($d['note'] ?? ''));
        if ($co === '' && $prod === '' && $note === '') continue;   // blank, or only the kind
        $p = "su.$i";
        $row = ['supply_kind' => null, 'company_ref' => null, 'product' => $prod !== '' ? $prod : null, 'note' => $note !== '' ? $note : null];
        if ($kind === '') $err["$p.supply_kind"] = 'required';
        elseif (!nl_code_ok($L, 'field.supply.kind', $kind, false, $keep)) $err["$p.supply_kind"] = 'not in the list';
        else $row['supply_kind'] = $kind;
        if ($co === '') $err["$p.company_ref"] = 'required';
        elseif (!isset($ctx['companies'][(int)$co])) $err["$p.company_ref"] = 'unknown company';
        else $row['company_ref'] = (int)$co;
        if (mb_strlen($prod) > 96) $err["$p.product"] = 'at most 96 characters';
        if (mb_strlen($note) > 128) $err["$p.note"] = 'at most 128 characters';
        if ($kind === 'other' && $note === '') $err["$p.note"] = 'describe what was supplied';
        $sig = mb_strtolower("$kind|$co|$prod");
        if (isset($seen[$sig]) && !isset($err["$p.company_ref"])) $err["$p.product"] = 'listed twice';
        $seen[$sig] = true;
        $f = (string)($d['for'] ?? '');
        $row['_for'] = $f !== '' ? $f : null;
        $out[] = $row;
    }
    return [$out, $err];
}

/**
 * Supply kinds an operation implies: procedure-row technologies (JSON lists or arrays), the operation's
 * neuromonitoring field (any value but none) and its adjuncts. Returns kinds without a supply row, for "missing".
 */
function nl_supplies_expected(array $technologies, $neuromonitoring, $adjuncts, array $supplyRows): array {
    $want = [];
    foreach ($technologies as $t) foreach (nl_multi($t ?? '') as $c) if (isset(NL_SUPPLY_FROM_TECHNOLOGY[$c])) $want[NL_SUPPLY_FROM_TECHNOLOGY[$c]] = true;
    if (array_diff(nl_multi($neuromonitoring ?? ''), ['none'])) $want['neuromonitoring'] = true;
    foreach (nl_multi($adjuncts ?? '') as $c) if (isset(NL_SUPPLY_FROM_TECHNOLOGY[$c])) $want[NL_SUPPLY_FROM_TECHNOLOGY[$c]] = true;
    foreach ($supplyRows as $r) unset($want[$r['supply_kind'] ?? '']);
    return array_keys($want);
}

/** What each company supplies: [id => [codes]] (empty list = everything). */
function nl_company_supplies(PDO $db): array {
    $out = [];
    foreach ($db->query('SELECT id, supplies FROM companies') as $r) $out[(int)$r['id']] = $r['supplies'] ? (json_decode($r['supplies'], true) ?: []) : [];
    return $out;
}

/** Products used before, for suggestions: [company_ref => [kind or implant category => [product…]]]. */
function nl_products_used(PDO $db): array {
    $out = [];
    $q = 'SELECT DISTINCT company_ref, implant_category AS k, product FROM implants WHERE product IS NOT NULL AND company_ref IS NOT NULL
          UNION SELECT DISTINCT company_ref, supply_kind AS k, product FROM supplies WHERE product IS NOT NULL AND company_ref IS NOT NULL
          ORDER BY product';
    foreach ($db->query($q) as $r) $out[(int)$r['company_ref']][(string)$r['k']][] = $r['product'];
    return $out;
}

/**
 * What a supplier list entry named like this supplies, as a field.company.supplies code: `implants` for implant
 * words (cage, fusion, disc prosthesis, kyphoplasty…), else the supply kind read by nl_ql_supply(); null when the
 * words say nothing known (reported by the import, never guessed). General clinical words only, no list of names.
 */
function nl_supply_tag_for_name(string $name): ?string {
    $t = nl_ql_norm($name);
    if (preg_match('/(CAGE|ΚΛΩΒ|(?<![\p{L}])(TLIF|PLIF|ALIF|LLIF|XLIF|OLIF|ACDF|ADR|IPG)(?![\p{L}])|ΑΡΘΡΟΠΛΑΣΤ|ARTHROPLAST|DISC PROSTH'
                 . '|FUSION|ΣΠΟΝΔΥΛΟΔΕΣ|ΚΟΧΛΙ|SCREW|(?<![\p{L}])RODS?(?![\p{L}])|ΡΑΒΔ|(?<![\p{L}])PLATES?(?![\p{L}])|ΠΛΑΚ'
                 . '|KYPHOPLAST|ΚΥΦΟΠΛΑΣΤ|VERTEBROPLAST|ΣΠΟΝΔΥΛΟΠΛΑΣΤ|CEMENT|ΤΣΙΜΕΝΤ|SHUNT|VALVE|ΒΑΛΒΙΔ|INTERSPINOUS|ΜΕΣΑΚΑΝΘ'
                 . '|STIMULAT|ΔΙΕΓΕΡΤ|(?<![\p{L}])PUMP|ΑΝΤΛΙ|CRANIOPLAST|ΚΡΑΝΙΟΠΛΑΣΤ|IMPLANT|ΕΜΦΥΤΕΥΜ)/u', $t)) return 'implants';
    return nl_ql_supply($name, [])['supply_kind'] ?? null;
}
