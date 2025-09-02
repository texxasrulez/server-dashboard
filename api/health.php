<?php
// api/health.php — simple app health probe
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/init.php';
$state = realpath(__DIR__ . '/../state');
echo json_encode([
  'ok' => true,
  'time' => date('c'),
  'build' => BUILD,
  'state_dir' => $state ?: null,
  'state_writable' => $state ? is_writable($state) : false
]);
