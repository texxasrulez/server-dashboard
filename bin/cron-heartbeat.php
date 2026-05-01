#!/usr/bin/env php
<?php

require_once __DIR__ . '/../lib/CronRunners.php';

$args = dashboard_cli_parse_args($argv);
$ts = isset($args['ts']) ? (int)$args['ts'] : null;
$result = dashboard_cron_heartbeat_run(
    (string)($args['id'] ?? ''),
    (string)($args['heartbeat'] ?? ''),
    $ts,
);
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
