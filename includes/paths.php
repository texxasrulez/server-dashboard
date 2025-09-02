<?php
// includes/paths.php — centralize filesystem roots and helpers
declare(strict_types=1);

if (!defined('BASE_DIR'))  define('BASE_DIR', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
if (!defined('DATA_DIR'))  define('DATA_DIR', getenv('APP_DATA_DIR')  ?: (BASE_DIR . '/data'));
if (!defined('STATE_DIR')) define('STATE_DIR', getenv('APP_STATE_DIR') ?: (BASE_DIR . '/state'));

if (!function_exists('ensure_dir')) {
  function ensure_dir(string $dir): void {
    if (is_dir($dir)) return;
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException('mkdir failed: ' . $dir);
    }
  }
}

if (!function_exists('write_json_atomic')) {
  function write_json_atomic(string $path, array $data): void {
    $dir = dirname($path);
    ensure_dir($dir);
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('json_encode failed');
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
      @unlink($tmp);
      throw new RuntimeException('write failed: ' . $tmp);
    }
    if (!@rename($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('rename failed: ' . $path);
    }
  }
}

if (!function_exists('read_json_or_default')) {
  function read_json_or_default(string $path, array $default): array {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return $default;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : $default;
  }
}
