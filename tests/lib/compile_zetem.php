<?php
// Compile one .zetem file with ZeusFW's own compiler and php -l the result. Usage: php compile_zetem.php FILE
$root = dirname(__DIR__, 2);
$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "no such file\n"); exit(2); }
require_once("$root/fw/core/templates/ZETEMTemplate.php");
$code = file_get_contents($file);
$code = preg_replace('/{% ?(extends|include) ?\'?(.*?)\'? ?%}/i', '', $code); // includes are compiled on their own
$php = Renderer::compileCode($code);
$tmp = tempnam(sys_get_temp_dir(), 'zt');
file_put_contents($tmp, $php);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $c);
unlink($tmp);
if ($c !== 0) { echo implode("\n", $out), "\n"; exit(1); }
