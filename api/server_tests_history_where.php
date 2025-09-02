<?php
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');
$dir = dirname(dashboard_state_path('history/.keep')) . '/history';
@mkdir($dir, 0775, true);
echo json_encode(['dir'=>$dir, 'exists'=>is_dir($dir), 'writable'=>is_writable($dir)]);
