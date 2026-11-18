<?php
/**
 * NeuroLog IDs (docs/data-dictionary.md, "Identifiers").
 *
 *   P-7K3Q-XM2A-R    prefix, 8 body characters (Crockford Base32, 40 random bits), 1 check character
 *   P7K3QXM2AR       stored form: no hyphens, 10 characters
 *
 * Prefixes: P patient, A admission, O operation, C complication, F follow-up (S reserved for study exports).
 * The check character is the body read as a base-32 number, mod 37, written with Crockford's check alphabet
 * (the 32 data symbols plus * ~ $ = U). 37 is prime and divides neither 32 nor 31, so every single changed
 * character and every swap of two neighbouring characters gives a different check. Pure functions, no DB.
 */

const NL_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const NL_ID_CHECK_ALPHABET = NL_ID_ALPHABET . '*~$=U';
const NL_ID_PREFIXES = ['P', 'A', 'O', 'C', 'F', 'S'];
const NL_ID_BODY = 8;

function nl_id_check_char(string $body): string {
    $v = 0;
    foreach (str_split($body) as $ch) {
        $d = strpos(NL_ID_ALPHABET, $ch);
        if ($d === false) throw new InvalidArgumentException('not a Crockford Base32 character');
        $v = $v * 32 + $d;                       // 8 characters = 40 bits, fits in a PHP int
    }
    return NL_ID_CHECK_ALPHABET[$v % 37];
}

/** A new random ID in stored form, e.g. P7K3QXM2AR. */
function nl_id_new(string $prefix): string {
    if (!in_array($prefix, NL_ID_PREFIXES, true)) throw new InvalidArgumentException("unknown ID prefix $prefix");
    $body = '';
    for ($i = 0; $i < NL_ID_BODY; $i++) $body .= NL_ID_ALPHABET[random_int(0, 31)];
    return $prefix . $body . nl_id_check_char($body);
}

/**
 * Typed or pasted text to stored form, or null if it is not a valid ID.
 * Case-insensitive; hyphens and spaces ignored; O read as 0, I and L read as 1 (not in the prefix or check).
 * $prefix limits the accepted prefix (e.g. only patient IDs in a patient field).
 */
function nl_id_normalize(string $text, ?string $prefix = null): ?string {
    $s = strtoupper(preg_replace('/[\s\-]+/u', '', $text));
    if (strlen($s) !== NL_ID_BODY + 2) return null;
    $p = $s[0];
    if (!in_array($p, NL_ID_PREFIXES, true) || ($prefix !== null && $p !== $prefix)) return null;
    $body = strtr(substr($s, 1, NL_ID_BODY), ['O' => '0', 'I' => '1', 'L' => '1']);
    if (strspn($body, NL_ID_ALPHABET) !== NL_ID_BODY) return null;
    $check = $s[NL_ID_BODY + 1];
    if (strpos(NL_ID_CHECK_ALPHABET, $check) === false) return null;
    return nl_id_check_char($body) === $check ? $p . $body . $check : null;
}

function nl_id_valid(string $stored): bool {
    return nl_id_normalize($stored) === $stored;
}

/** Stored form to display form: P7K3QXM2AR -> P-7K3Q-XM2A-R. */
function nl_id_format(string $stored): string {
    if (strlen($stored) !== NL_ID_BODY + 2) return $stored;
    return $stored[0] . '-' . substr($stored, 1, 4) . '-' . substr($stored, 5, 4) . '-' . $stored[9];
}

/**
 * A new ID not yet used in $table.nl_id. Deleted rows keep their ID (soft delete), so an ID is never reused.
 * The UNIQUE index is the real guarantee; the lookup only avoids a failed insert.
 */
function nl_id_new_unique(PDO $pdo, string $table, string $prefix): string {
    if (!preg_match('/^[a-z_]+$/', $table)) throw new InvalidArgumentException('bad table name');
    $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE nl_id = ?");
    for ($i = 0; $i < 10; $i++) {
        $id = nl_id_new($prefix);
        $st->execute([$id]);
        if (!$st->fetchColumn()) return $id;
    }
    throw new RuntimeException("could not draw a free $prefix ID");   // 10 collisions in a row: something is wrong
}
