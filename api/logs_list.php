<?php
// api/logs_list.php — returns allowlisted logs from config
require __DIR__.'/_guard.php'; guard_api(['key'=>'logs_list','require_token'=>false,'type'=>'json']);
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

$items = [];
$list = cfg_get('logs.allow', []);
if (!is_array($list)) $list = [];
foreach ($list as $s) {
  $s = (string)$s;
  $label = ''; $path = $s;
  if (strpos($s, '|') !== false) {
    list($label, $path) = array_map('trim', explode('|', $s, 2));
  } else {
    $path = trim($s);
    $label = basename($path);
  }
  $path = trim($path);
  $size = @filesize($path);
  $mtime = @filemtime($path);
  $items[] = ['label'=>$label, 'path'=>$path, 'exists'=>is_file($path), 'size'=>($size!==false?$size:null), 'mtime'=>($mtime!==false?$mtime:null)];
}
echo json_encode(['ok'=>true,'items'=>$items]);
