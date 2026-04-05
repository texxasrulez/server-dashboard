<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../lib/Speedtest.php';

$force = in_array('--force', $argv ?? [], true);
$result = \App\Speedtest::runCollector(['force' => $force]);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
