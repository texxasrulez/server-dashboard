<?php
$__t0 = microtime(true);
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$dataDir  = __DIR__ . '/../data';
$stateDir = __DIR__ . '/../state';
@mkdir($dataDir, 0775, true);
@mkdir($stateDir, 0775, true);
$dataPath  = $dataDir . '/alerts.json';
$statePath = $stateDir . '/alerts.json';

if (!file_exists($dataPath) && file_exists($statePath)) { @copy($statePath, $dataPath); }
if (!file_exists($dataPath)) { file_put_contents($dataPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

$payload = json_decode(@file_get_contents($dataPath), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

// normalize minimal fields
$changed = false;
foreach ($items as &$it) {
  if (!isset($it['id']) || !$it['id']) { $it['id'] = 'alert_'.bin2hex(random_bytes(6)); $changed = true; }
  if (!isset($it['name'])) { $it['name'] = ''; $changed = true; }
  if (!isset($it['service_id'])) { $it['service_id'] = ''; $changed = true; }
  if (!isset($it['metric'])) { $it['metric'] = 'status'; $changed = true; }
  if (!isset($it['op'])) { $it['op'] = '>'; $changed = true; }
  if (!isset($it['threshold'])) { $it['threshold'] = 1; $changed = true; }
  if (!isset($it['consecutive'])) { $it['consecutive'] = 3; $changed = true; }
  if (!isset($it['cooldown_min'])) { $it['cooldown_min'] = 30; $changed = true; }
  if (!isset($it['severity'])) { $it['severity'] = 'warn'; $changed = true; }
  if (!isset($it['notify'])) { $it['notify'] = ['email'=>'','webhook_url'=>'']; $changed = true; }
  if (!isset($it['enabled'])) { $it['enabled'] = true; $changed = true; }
}
unset($it);
if ($changed) file_put_contents($dataPath, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode(['items'=>$items], JSON_UNESCAPED_SLASHES);
