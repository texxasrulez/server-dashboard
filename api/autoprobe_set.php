<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');
if (!function_exists('is_admin') || !is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
$payload = json_decode(file_get_contents('php://input'), true);
$enabled = !empty($payload['enabled']);
$interval = (int)($payload['interval'] ?? 60);
if ($interval < 5) $interval = 60;
$cfgPath = __DIR__ . '/../config/autoprobe.json';
@mkdir(dirname($cfgPath), 0775, true);
file_put_contents($cfgPath, json_encode(['enabled'=>$enabled,'interval'=>$interval], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['ok'=>true,'enabled'=>$enabled,'interval'=>$interval]);
