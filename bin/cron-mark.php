#!/usr/bin/env php
<?php

require_once __DIR__ . '/../lib/CronRunners.php';

$args = dashboard_cli_parse_args($argv);
$result = dashboard_cron_mark_run((string)($args['what'] ?? ''));
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
