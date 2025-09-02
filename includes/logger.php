<?php
// includes/logger.php — tiny JSON logger
declare(strict_types=1);
require_once __DIR__ . '/paths.php';

const LOG_KIND_APP    = 'app';    // data/logs
const LOG_KIND_SYSTEM = 'system'; // state/logs

if (!function_exists('app_log')) {
  function app_log(string $channel, string $message, array $ctx = [], string $kind = LOG_KIND_APP): void {
    $root = ($kind === LOG_KIND_SYSTEM) ? STATE_DIR : DATA_DIR;
    $dir  = $root . '/logs';
    try { ensure_dir($dir); } catch (Throwable $e) { /* swallow */ }
    $line = json_encode([
      'ts'      => date('c'),
      'channel' => $channel,
      'msg'     => $message,
      'ctx'     => $ctx,
      'pid'     => function_exists('getmypid') ? getmypid() : null,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($dir . '/' . $channel . '.log', $line, FILE_APPEND | LOCK_EX);
  }
}
