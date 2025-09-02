<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/_state_path.php';

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'auth required']); exit; }
\App\Config::init(dirname(__DIR__));

$enabled = (bool)\App\Config::get('history.enabled', true);
if (!$enabled) { echo json_encode(['ok'=>true,'results'=>[],'score'=>0]); exit; }

$dir = dirname(dashboard_state_path('history/.keep')) . '/history'; @mkdir($dir, 0775, true);
$limit = (int)\App\Config::get('history.sample_limit', 500);
if ($limit < 50) $limit = 50;

$data = [];
if (is_dir($dir)) {
  $files = glob($dir.'/*.jsonl');
  sort($files); // chronological by month
  $lines = [];
  // read from newest back until limit
  for ($i=count($files)-1; $i>=0; $i--) {
    $f = $files[$i];
    if (!is_readable($f)) continue;
    $fh = @fopen($f, 'r');
    if (!$fh) continue;
    $buf = [];
    while (!feof($fh)) {
      $line = fgets($fh);
      if ($line === false) break;
      $buf[] = trim($line);
    }
    fclose($fh);
    // reverse per-file to get newest first
    $buf = array_reverse($buf);
    foreach ($buf as $line) {
      if ($line === '') continue;
      $lines[] = $line;
      if (count($lines) >= $limit) break 2;
    }
  }
  // reverse back to chronological ascending
  $lines = array_reverse($lines);
  foreach ($lines as $ln) {
    $row = json_decode($ln, true);
    if (!$row) continue;
    $data[] = $row;
  }
}

function parse_number($name, $items){
  foreach ($items as $it){
    if (($it['name'] ?? '') === $name){
      $d = (string)($it['details'] ?? '');
      if ($name === 'Disk Free' && preg_match('/([0-9]+(?:\.[0-9]+)?)%/', $d, $m)) return (float)$m[1];
      if ($name === 'Load Avg (1m)' && is_numeric($d)) return (float)$d;
      if ($name === 'Security updates' && preg_match('/(\d+)/', $d, $m)) return (int)$m[1];
      if ($name === 'TLS cert expiry' && preg_match('/(-?\d+)/', $d, $m)) return (int)$m[1];
    }
  }
  return null;
}

$out = ['ok'=>true,'points'=>[],'recent'=>[]];
foreach ($data as $row){
  $ts = strtotime($row['t'] ?? 'now');
  $it = $row['items'] ?? [];
  $out['points'][] = [
    't' => date('c', $ts),
    'score' => (float)($row['score'] ?? 0),
    'disk_free_pct' => parse_number('Disk Free', $it),
    'load_1m' => parse_number('Load Avg (1m)', $it),
    'security_updates' => parse_number('Security updates', $it),
    'tls_days' => parse_number('TLS cert expiry', $it),
  ];
}
$out['recent'] = array_slice($data, -10);

echo json_encode($out);
