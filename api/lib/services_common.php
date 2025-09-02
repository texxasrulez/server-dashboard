<?php
// api/lib/services_common.php
declare(strict_types=1);

function yn($v): bool {
  if (is_bool($v)) return $v;
  if (is_int($v) || is_float($v)) return ((int)$v) > 0;
  if (is_string($v)) {
    $s = strtolower(trim($v));
    return in_array($s, ['1','true','on','yes','y','t','enabled'], true);
  }
  return false;
}

function svc_is_enabled(array $row): bool {
  if (array_key_exists('disabled', $row)) return !yn($row['disabled']);
  if (array_key_exists('enabled',  $row)) return  yn($row['enabled']);
  if (array_key_exists('active',   $row)) return  yn($row['active']);
  return true;
}

function svc_root(): string {
  return realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
}

function svc_load_services(): array {
  $root = svc_root();
  $candidates = [
    $root . '/state/services.json',
    $root . '/config/services.json',
    $root . '/config/services.php',
  ];

  $items = [];
  foreach ($candidates as $file) {
    if (!is_readable($file)) continue;
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    try {
      if ($ext === 'json') {
        $raw = json_decode(file_get_contents($file) ?: '[]', true);
        if (is_array($raw)) $items = $raw;
      } elseif ($ext === 'php') {
        $raw = include $file;
        if (is_array($raw)) $items = $raw;
      }
    } catch (Throwable $e) {}
    if ($items) break;
  }

  $norm = [];
  $i = 0;
  foreach ($items as $row) {
    if (!is_array($row)) continue;
    $row['id']   = isset($row['id'])   ? (string)$row['id']   : null;
    $row['name'] = isset($row['name']) ? (string)$row['name'] : ($row['service'] ?? null);
    if (!$row['id']) {
      $seed = ($row['name'] ?? '') . '|' . ($row['host'] ?? '') . '|' . ($row['port'] ?? '') . '|' . $i;
      $row['id'] = 'svc_' . substr(sha1($seed), 0, 12);
    }
    $norm[] = $row;
    $i++;
  }

  return $norm;
}

function svc_load_status(): array {
  $root = svc_root();
  $file = $root . '/state/services_status.json';
  if (is_readable($file)) {
    $data = json_decode(file_get_contents($file) ?: '{}', true);
    if (is_array($data)) return $data;
  }
  return ['results' => [], 'ts' => time()];
}
