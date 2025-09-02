<?php require_once __DIR__.'/_state_path.php'; ?>
<?php
$__t0 = microtime(true);

require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$state = dashboard_state_path('services_status.json');
if (!file_exists($state)) { echo json_encode(['items'=>[], 'summary'=>['up'=>0,'total'=>0]]); exit; }
$raw = file_get_contents($state);
if ($raw === false) { echo json_encode(['items'=>[], 'summary'=>['up'=>0,'total'=>0]]); exit; }
if (isset($_GET['trace'])) { $data = json_decode($raw,true) ?: ['items'=>[], 'summary'=>['up'=>0,'total'=>0]]; $data['trace']=['elapsed_ms'=> round((microtime(true)-$__t0)*1000,1),'source'=>'services_status.json']; echo json_encode($data); } else { echo $raw; }
