<?php
// web/quickline.php against tests/cases/quickline.json, with vocab/aliases.yaml and vocab/facets.yaml. Exit 0 = pass.
$root = dirname(__DIR__, 2);
require "$root/web/quickline.php";
if (!function_exists('yaml_parse_file')) require_once("$root/fw/core/lib/yaml_compat.php");
$dia = yaml_parse_file("$root/vocab/facets.yaml")['diadermiki'];
$al = nl_aliases($root);
$cases = json_decode(file_get_contents("$root/tests/cases/quickline.json"), true)['cases'];
$fail = 0; $n = 0;
$sub = function (array $want, array $got): bool { foreach ($want as $k => $v) if (($got[$k] ?? null) !== $v) return false; return true; };
foreach ($cases as $c) {
    $r = nl_quickline_parse($c['in'], $al, $dia); $n++;
    $ok = count($r['procedures']) === count($c['pr'] ?? []);
    foreach ($c['pr'] ?? [] as $k => $w) $ok = $ok && isset($r['procedures'][$k]) && $sub($w, $r['procedures'][$k]);
    if (isset($c['dx'])) { $ok = $ok && count($r['diagnoses']) === count($c['dx']); foreach ($c['dx'] as $k => $w) $ok = $ok && isset($r['diagnoses'][$k]) && $sub($w, $r['diagnoses'][$k]); }
    if (isset($c['unplaced'])) $ok = $ok && $r['unplaced'] === $c['unplaced'];
    if (isset($c['tags'])) $ok = $ok && $r['tags'] === $c['tags'];
    if (!$ok) { $fail++; echo "FAIL «{$c['in']}»\n  got ", json_encode(['pr' => $r['procedures'], 'dx' => $r['diagnoses'], 'unplaced' => $r['unplaced'], 'tags' => $r['tags']], JSON_UNESCAPED_UNICODE), "\n"; }
}
// reading look: tag words hidden, value kept, text escaped
$h = nl_tags_render('Δισκεκτομή @επ Ο4/5 <b> @δγ κήλη, τέλος');
$n++; if ($h !== 'Δισκεκτομή <span class="tagv tag-lvl">Ο4/5 &lt;b&gt;</span> <span class="tagv tag-dx">κήλη</span>, τέλος') { $fail++; echo "FAIL render: $h\n"; }
$n++; if (nl_tags_find('a@ep.gr') !== []) { $fail++; echo "FAIL e-mail read as a tag\n"; }
$n++; if (count(nl_tags_find('@επίπεδα Ο4/5')) !== 1 || nl_tags_find('@επίπεδα Ο4/5')[0][2] !== 'lvl') { $fail++; echo "FAIL long tag name\n"; }
echo ($n - $fail) . "/$n passed\n";
exit($fail ? 1 : 0);
