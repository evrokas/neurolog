<?php
// Unit tests for web/nl_id.php. Exit 0 = pass.
require __DIR__ . '/../../web/nl_id.php';
$fail = 0; $n = 0;
function t(string $label, bool $ok): void { global $fail, $n; $n++; if (!$ok) { $fail++; echo "FAIL $label\n"; } }

// shape and round trip
for ($i = 0; $i < 2000; $i++) {
    foreach (NL_ID_PREFIXES as $p) {
        $id = nl_id_new($p);
        if (!preg_match('/^' . $p . '[0-9A-HJKMNP-TV-Z]{8}[0-9A-HJKMNP-TV-Z*~$=U]$/', $id) || !nl_id_valid($id)) { t("shape $id", false); break 2; }
        if (nl_id_normalize(strtolower(nl_id_format($id))) !== $id) { t("round trip $id", false); break 2; }
    }
}
t('shape and round trip', true);
t('format', nl_id_format('P7K3QXM2AR') === 'P-7K3Q-XM2A-R');

// every single substitution and every neighbour swap in the body+check is refused (exhaustive on 300 IDs)
$sym = str_split(NL_ID_ALPHABET); $chk = str_split(NL_ID_CHECK_ALPHABET);
$missSub = 0; $missSwap = 0;
for ($i = 0; $i < 300; $i++) {
    $id = nl_id_new('O');
    for ($pos = 1; $pos <= 9; $pos++) {
        foreach ($pos === 9 ? $chk : $sym as $c) {
            if ($c === $id[$pos]) continue;
            $bad = substr_replace($id, $c, $pos, 1);
            if (nl_id_normalize($bad) !== null) $missSub++;
        }
        if ($pos < 9 && $id[$pos] !== $id[$pos + 1]) {
            $bad = $id; [$bad[$pos], $bad[$pos + 1]] = [$bad[$pos + 1], $bad[$pos]];
            if (nl_id_normalize($bad) !== null) $missSwap++;
        }
    }
}
t("every single substitution caught ($missSub missed)", $missSub === 0);
t("every neighbour swap caught ($missSwap missed)", $missSwap === 0);

// input rules: case, hyphens, spaces; O->0, I/L->1; prefix filter; junk
$body = '0011ABCD'; $id = 'P' . $body . nl_id_check_char($body);
t('lowercase + hyphens', nl_id_normalize('p-0011-abcd-' . strtolower($id[9])) === $id);
t('spaces', nl_id_normalize(' P 0011 ABCD ' . $id[9] . ' ') === $id);
t('O read as 0, I and L as 1', nl_id_normalize('PoOIlABCD' . $id[9]) === $id);
t('prefix filter refuses other prefix', nl_id_normalize($id, 'O') === null);
t('prefix filter accepts own prefix', nl_id_normalize($id, 'P') === $id);
t('unknown prefix refused', nl_id_normalize('X' . substr($id, 1)) === null);
t('too short refused', nl_id_normalize(substr($id, 0, 9)) === null);
t('U refused in body', nl_id_normalize('P0011ABCU' . $id[9]) === null);
t('empty refused', nl_id_normalize('') === null);
$threw = false; try { nl_id_new('Z'); } catch (InvalidArgumentException $e) { $threw = true; }
t('unknown prefix throws on create', $threw);

// the 5 extra check symbols occur (all 37 values reachable)
$seen = [];
for ($i = 0; $i < 20000 && count($seen) < 37; $i++) $seen[nl_id_new('P')[9]] = true;
t('all 37 check symbols occur (' . count($seen) . ')', count($seen) === 37);

echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
