<?php
// api/logs_tail.php — tail + filter a allowlisted log
require __DIR__.'/_guard.php'; guard_api(['key'=>'logs_tail','require_token'=>false,'type'=>'json']);
header('Content-Type: application/json; charset=utf-8');

function cfg_get($dot, $default=null){
  $file = dirname(__DIR__) . '/config/local.json';
  if (!is_file($file)) return $default;
  $raw = @file_get_contents($file);
  if ($raw === false) return $default;
  $j = json_decode($raw, true);
  if (!is_array($j)) return $default;
  $cur = $j;
  foreach (explode('.', $dot) as $k) {
    if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; } else { return $default; }
  }
  return $cur;
}

$list = cfg_get('logs.allow', []);
$tailBytes = intval(cfg_get('logs.tail_bytes', 65536));
$maxLines = intval(cfg_get('logs.grep_max_lines', 1000));
if (!is_array($list)) $list = [];

$label = isset($_GET['name']) ? (string)$_GET['name'] : '';
$idx = isset($_GET['id']) ? intval($_GET['id']) : -1;
$needle = isset($_GET['q']) ? (string)$_GET['q'] : '';
$re = isset($_GET['re']) ? (string)$_GET['re'] : '';
$ci = !empty($_GET['ci']);
$limit = isset($_GET['max']) ? max(10, intval($_GET['max'])) : $maxLines;
$tail = isset($_GET['n']) ? max(1024, intval($_GET['n'])) : $tailBytes;

$path = null; $chosen_label = null;
if ($idx >= 0 && $idx < count($list)) {
  $s = (string)$list[$idx];
  if (strpos($s,'|')!==false){ list($chosen_label,$path) = array_map('trim', explode('|',$s,2)); } else { $path=trim($s); $chosen_label=basename($path); }
}
if (!$path && $label!=='') {
  foreach ($list as $s) {
    $s=(string)$s; $lab=''; $p=$s;
    if (strpos($s,'|')!==false){ list($lab,$p)=array_map('trim',explode('|',$s,2)); } else { $p=trim($s); $lab=basename($p); }
    if ($lab===$label) { $chosen_label=$lab; $path=$p; break; }
  }
}
if (!$path) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown log']); exit; }

if (!is_file($path)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found','path'=>$path]); exit; }

$size = @filesize($path); if ($size===false) $size = 0;
$fh = @fopen($path, 'rb');
if (!$fh) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'cannot open','path'=>$path]); exit; }

$start = 0;
if ($size > $tail) { $start = $size - $tail; }
@fseek($fh, $start);
$data = @stream_get_contents($fh);
@fclose($fh);
if ($data === false) $data = '';

$lines = preg_split("/\r?\n/", $data, -1, PREG_SPLIT_NO_EMPTY);

$out = [];
$rx = null;
if ($re !== '') {
  $flags = $ci ? 'i' : '';
  $rx = @preg_match('/'.$re.'/'.$flags, '') !== false ? ('/'.$re.'/'.$flags) : null;
}
foreach ($lines as $ln){
  if ($rx) {
    if (!@preg_match($rx, $ln)) continue;
  } else if ($needle !== '') {
    if ($ci) {
      if (stripos($ln, $needle) === false) continue;
    } else {
      if (strpos($ln, $needle) === false) continue;
    }
  }
  $out[] = $ln;
  if (count($out) >= $limit) {
    array_shift($out); // keep most recent
  }
}

echo json_encode([
  'ok'=>true,
  'label'=>$chosen_label,
  'path'=>$path,
  'size'=>$size,
  'returned'=>count($out),
  'lines'=>$out
]);
