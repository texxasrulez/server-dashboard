<?php
// assets/build_js.php -- resilient bundler (core) — idempotent, memory-friendly
header("Content-Type: application/javascript; charset=utf-8");

// ---- params & maps ----
$bundle = $_GET['b'] ?? 'core';
$ROOT = realpath(__DIR__ . '/..');
$DS   = DIRECTORY_SEPARATOR;
$MAP = [
  'core' => [
    'assets/js/app.js',
    'assets/js/autoprobe.js',
  ],
];

$list = $MAP[$bundle] ?? [];

// ---- ETag: avoid work/transfer if unchanged ----
$sig = '';
foreach ($list as $rel) {
  $abs = $ROOT . $DS . str_replace(['/', '\\'], $DS, $rel);
  if (is_file($abs)) { $sig .= filemtime($abs) . ':' . filesize($abs) . ';'; }
}
$etag = '"' . sha1($bundle . '|' . $sig) . '"';
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=300');
if ($ifNoneMatch && $ifNoneMatch === $etag) {
  http_response_code(304);
  exit;
}

// ---- Stream bundle (no whole-file reads) ----
function js_out($s){ echo $s; }
$warnings = [];
js_out("(function(){try\n{\n");
foreach ($list as $rel) {
  $abs = $ROOT . $DS . str_replace(['/', '\\'], $DS, $rel);
  if (!is_file($abs)) { $warnings[] = "[bundle:$bundle] missing file: $rel"; continue; }
  js_out("\n/* ---- begin {$rel} ---- */\n");
  $h = @fopen($abs, 'rb');
  if ($h) {
    while (!feof($h)) { js_out(fread($h, 8192)); }
    fclose($h);
  }
  js_out("\n/* ---- end {$rel} ---- */\n");
}
js_out("\n}catch(e){ console.error('[bundle:$bundle] fatal bundling error', e); }})();\n");
if ($warnings) { foreach ($warnings as $w) { js_out("console.warn(" . json_encode($w, JSON_UNESCAPED_SLASHES) . ");\n"); } }
?>
