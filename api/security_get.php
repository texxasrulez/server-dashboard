<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$SEC_FILE = DATA_DIR . '/security_config.json';
$LEGACY   = DATA_DIR . '/config.json';

// one-time migration of legacy config.json -> security_config.json
if (!file_exists($SEC_FILE) && file_exists($LEGACY)) {
  @rename($LEGACY, $SEC_FILE);
}

$settings = [];
if (file_exists($SEC_FILE)) {
  $raw = @file_get_contents($SEC_FILE);
  $json = json_decode($raw, true);
  if (is_array($json)) $settings = $json;
}

echo json_encode(['ok' => true, 'settings' => $settings], JSON_UNESCAPED_SLASHES);
