<?php
// api/_state_path.php — resolve writable state dir with optional config override
if (!function_exists('dashboard_state_path')) {
  function dashboard_state_path(string $filename): string {
    $root = dirname(__DIR__);
    $cfgFile = $root . '/data/config.json';
    $pref = null;
    if (is_file($cfgFile)) {
      $json = @json_decode(@file_get_contents($cfgFile), true);
      if (isset($json['runtime_dir']) && is_string($json['runtime_dir']) && $json['runtime_dir']!=='') {
        $pref = rtrim($json['runtime_dir'], '/');
      }
    }
    $candidates = array_values(array_filter([
      $pref,
      $root . '/data',
      $root . '/state',
    ]));
    $chosen = null;
    foreach ($candidates as $dir) {
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      if (is_dir($dir) && is_writable($dir)) { $chosen = $dir; break; }
    }
    if (!$chosen) $chosen = $candidates[0] ?? ($root . '/data');
    return rtrim($chosen, '/') . '/' . ltrim($filename, '/');
  }
}
if (!function_exists('dashboard_atomic_write')) {
  function dashboard_atomic_write(string $path, string $contents): array {
    $dir = dirname($path);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_writable($dir)) {
      return ['ok'=>false, 'error'=>'dir_not_writable', 'dir'=>$dir, 'path'=>$path];
    }
    $tmp = $path . '.tmp';
    $bytes = @file_put_contents($tmp, $contents, LOCK_EX);
    if ($bytes === false) return ['ok'=>false,'error'=>'write_failed','path'=>$tmp];
    if (!@rename($tmp, $path)) { @unlink($tmp); return ['ok'=>false,'error'=>'rename_failed','path'=>$path]; }
    return ['ok'=>true,'path'=>$path,'bytes'=>$bytes];
  }
}