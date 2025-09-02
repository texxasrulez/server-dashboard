<?php
require_once __DIR__ . '/../includes/init.php';

$format = strtolower($_GET['format'] ?? 'json');
$baseDir = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$pathsPhp = $baseDir . '/includes/paths.php';
if (is_file($pathsPhp)) { require_once $pathsPhp; }
$dataPath = defined('DATA_DIR') ? (DATA_DIR . '/services.json') : ($baseDir . '/data/services.json');
if (!file_exists($dataPath)) { $payload = ['items'=>[]]; } else { $payload = json_decode(file_get_contents($dataPath), true) ?: ['items'=>[]]; }
$items = $payload['items'] ?? [];

if ($format === 'csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="services.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','name','type','host','port','check','path','timeout_ms','enabled']);
  foreach ($items as $it) {
    fputcsv($out, [
      $it['id'] ?? '',
      $it['name'] ?? '',
      $it['type'] ?? 'other',
      $it['host'] ?? '',
      $it['port'] ?? '',
      $it['check'] ?? 'tcp',
      $it['path'] ?? '/',
      $it['timeout_ms'] ?? 800,
      !empty($it['enabled']) ? 1 : 0
    ]);
  }
  fclose($out);
  exit;
}

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="services.json"');
echo json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
