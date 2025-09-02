<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');
$t0 = microtime(true);
usleep(20000); // small predictable delay for testing (~20ms)
$resp = ['ok'=>true, 'now'=>date('c')];
$resp['trace'] = ['elapsed_ms' => round((microtime(true)-$t0)*1000,1)];
echo json_encode($resp);
