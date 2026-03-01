<?php
declare(strict_types=1);

// Rotates app-managed secrets in config/local.json and cron token files.
// Usage:
//   php bin/rotate-runtime-secrets.php          # dry run
//   php bin/rotate-runtime-secrets.php --apply  # apply with backup

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$cfgPath = $root . '/config/local.json';
$dataCron = $root . '/data/cron_token.txt';
$stateCron = $root . '/state/cron_token.txt';
$backupDir = $root . '/config/backups';
$apply = in_array('--apply', $argv, true);

function rand_hex(int $bytes): string {
  return bin2hex(random_bytes($bytes));
}

function set_path(array &$arr, string $path, $value): void {
  $parts = explode('.', $path);
  $cur =& $arr;
  foreach ($parts as $p) {
    if (!is_array($cur)) $cur = [];
    if (!array_key_exists($p, $cur) || !is_array($cur[$p])) $cur[$p] = $cur[$p] ?? [];
    $cur =& $cur[$p];
  }
  $cur = $value;
}

if (!is_file($cfgPath)) {
  fwrite(STDERR, "Missing config/local.json\n");
  exit(1);
}

$raw = (string)file_get_contents($cfgPath);
$cfg = json_decode($raw, true);
if (!is_array($cfg)) {
  fwrite(STDERR, "Invalid JSON in config/local.json\n");
  exit(1);
}

$newCronToken = rand_hex(32);
$newApiToken = rand_hex(32);
$newCsrfSecret = rand_hex(32);

$changes = [
  'security.api_tokens' => [$newApiToken],
  'security.csrf_secret' => $newCsrfSecret,
  'alerts.cron_token' => $newCronToken,
];

// Keep all historical cron token paths aligned if they exist.
$optionalCronPaths = [
  'security.cron_token',
  'site.cron_token',
  'cron.token',
  'api.cron_token',
  'history.token',
];

foreach ($changes as $path => $value) {
  set_path($cfg, $path, $value);
}
foreach ($optionalCronPaths as $path) {
  $parts = explode('.', $path);
  $cur = $cfg;
  $exists = true;
  foreach ($parts as $p) {
    if (!is_array($cur) || !array_key_exists($p, $cur)) { $exists = false; break; }
    $cur = $cur[$p];
  }
  if ($exists) set_path($cfg, $path, $newCronToken);
}

echo "Rotation plan:\n";
echo "  - security.api_tokens[0] => <new random 64-hex token>\n";
echo "  - security.csrf_secret => <new random 64-hex secret>\n";
echo "  - alerts.cron_token (+ optional legacy cron paths) => <new random 64-hex token>\n";
echo "  - data/cron_token.txt (+ state/cron_token.txt if present) => new cron token\n";

if (!$apply) {
  echo "\nDry run only. Re-run with --apply to write changes.\n";
  exit(0);
}

if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);
$ts = date('Ymd-His');
$backupPath = $backupDir . '/local.pre-rotate-' . $ts . '.json';
if (@file_put_contents($backupPath, $raw) === false) {
  fwrite(STDERR, "Failed to write backup: {$backupPath}\n");
  exit(1);
}

$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || @file_put_contents($cfgPath, $json . PHP_EOL) === false) {
  fwrite(STDERR, "Failed to write updated config/local.json\n");
  exit(1);
}

if (!is_dir(dirname($dataCron))) @mkdir(dirname($dataCron), 0775, true);
@file_put_contents($dataCron, $newCronToken . PHP_EOL);
if (is_file($stateCron)) @file_put_contents($stateCron, $newCronToken . PHP_EOL);

@chmod($cfgPath, 0640);
@chmod($dataCron, 0640);
if (is_file($stateCron)) @chmod($stateCron, 0640);

echo "\nApplied successfully.\n";
echo "Backup: {$backupPath}\n";
echo "Important: update any external cron/monitor integrations to the new token.\n";
