#!/usr/bin/env php
<?php

require_once __DIR__ . '/../lib/CronRunners.php';

$args = dashboard_cli_parse_args($argv);
$result = dashboard_alerts_eval_run(
    dashboard_cli_bool_arg($args, 'probe'),
    dashboard_cli_bool_arg($args, 'dry'),
);
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
