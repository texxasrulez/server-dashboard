<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');
$cfgPath = __DIR__ . '/../config/autoprobe.json';
if (!file_exists($cfgPath)) {
  echo json_encode(['enabled'=>true, 'interval'=>60]); exit;
}
$data = json_decode(file_get_contents($cfgPath), true);
if (!is_array($data)) { $data = ['enabled'=>true,'interval'=>60]; }
$enabled = !empty($data['enabled']);
$interval = (int)($data['interval'] ?? 60);
if ($interval < 5) $interval = 60;
echo json_encode(['enabled'=>$enabled,'interval'=>$interval]);
