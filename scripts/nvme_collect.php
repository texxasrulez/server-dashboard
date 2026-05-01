<?php

declare(strict_types=1);

// Daily cron example:
// 5 2 * * * cd /path/to/your/docroot && /usr/bin/php scripts/nvme_collect.php >> state/logs/nvme_collect.log 2>&1

require_once __DIR__ . '/../lib/NvmeCollector.php';

$collector = new \App\NvmeCollector();
$result = $collector->collect();

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
