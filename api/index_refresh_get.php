<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');
$cfgPath = __DIR__ . '/../config/index_refresh.json';
if (!file_exists($cfgPath)) { echo json_encode(['interval'=>60]); exit; }
$cfg = json_decode(file_get_contents($cfgPath), true);
$interval = (int)($cfg['interval'] ?? 60);
if ($interval < 5) $interval = 60;
echo json_encode(['interval'=>$interval]);
