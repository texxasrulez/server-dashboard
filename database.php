<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
$REQUIRE_ADMIN = true;

require_once __DIR__ . '/lib/Config.php';
\App\Config::init(__DIR__);

// JSON endpoint
if (($_GET['action'] ?? '') === 'scan') {
  header('Content-Type: application/json; charset=utf-8');
  $host = (string) \App\Config::get('integrations.mysql.host', '127.0.0.1');
  $port = (int) \App\Config::get('integrations.mysql.port', 3306);
  $user = (string) \App\Config::get('integrations.mysql.username', '');
  $pass = (string) \App\Config::get('integrations.mysql.password', '');

  mysqli_report(MYSQLI_REPORT_OFF);
  $mysqli = @mysqli_init();
  if (!$mysqli) { echo json_encode(['ok'=>false,'error'=>'mysqli_init failed']); exit; }
  @mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
  $link = @$mysqli->real_connect($host, $user, $pass, null, $port);
  if (!$link) {
    echo json_encode(['ok'=>false, 'error'=>'Connect failed', 'errno'=>$mysqli->connect_errno, 'err'=>$mysqli->connect_error]); exit;
  }

  $version = $mysqli->server_info;
  $statusRows = [];
  if ($res = $mysqli->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Threads_running')")) {
    while ($row = $res->fetch_assoc()) { $statusRows[$row['Variable_name']] = (int)$row['Value']; }
    $res->free();
  }
  $uptime = $statusRows['Uptime'] ?? null;
  $threads_connected = $statusRows['Threads_connected'] ?? null;
  $threads_running   = $statusRows['Threads_running'] ?? null;

  // Get list of schemas (and default collation) visible to this user
  $schemas = [];
  if ($res = $mysqli->query("SELECT SCHEMA_NAME AS name, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA")) {
    while ($r = $res->fetch_assoc()) { $schemas[$r['name']] = $r['collation']; }
    $res->free();
  }

  $sys = ['information_schema','mysql','performance_schema','sys'];
  $dbs = [];

  foreach ($schemas as $name => $collation) {
    $tables = 0; $rows = 0; $data = 0; $index = 0; $last = null;

    // SHOW TABLE STATUS is widely supported (no mysqlnd dependency)
    $dbIdent = str_replace('`','``',$name);
    if ($res = @$mysqli->query("SHOW TABLE STATUS FROM `{$dbIdent}`")) {
      while ($t = $res->fetch_assoc()) {
        $tables++;
        $rows   += (int)($t['Rows'] ?? 0);
        $data   += (int)($t['Data_length'] ?? 0);
        $index  += (int)($t['Index_length'] ?? 0);
        $lu = $t['Update_time'] ?: ($t['Create_time'] ?: ($t['Check_time'] ?: null));
        if ($lu && (!$last || strcmp($lu, $last) > 0)) $last = $lu;
      }
      $res->free();
    } else {
      // If we can't see tables in this DB, leave zeros (user may lack privileges)
    }

    $dbs[] = [
      'name' => $name,
      'collation' => $collation,
      'tables' => $tables,
      'rows' => $rows,
      'data_bytes' => $data,
      'index_bytes' => $index,
      'total_bytes' => $data + $index,
      'last_update' => $last,
      'system' => in_array($name, $sys, true),
    ];
  }

  // Sort by name for stability
  usort($dbs, function($a,$b){ return strcasecmp($a['name'], $b['name']); });

  echo json_encode([
    'ok'=>true,
    'server'=>[
      'host'=>$host,'port'=>$port,'version'=>$version,
      'uptime'=>$uptime,'threads_connected'=>$threads_connected,'threads_running'=>$threads_running
    ],
    'databases'=>$dbs
  ]);
  exit;
}


// Tables list endpoint
if (($_GET['action'] ?? '') === 'tables') {
  header('Content-Type: application/json; charset=utf-8');
  $db = (string)($_GET['db'] ?? '');
  if ($db === '') { echo json_encode(['ok'=>false,'error'=>'missing db']); exit; }

  $host = (string) \App\Config::get('integrations.mysql.host', '127.0.0.1');
  $port = (int) \App\Config::get('integrations.mysql.port', 3306);
  $user = (string) \App\Config::get('integrations.mysql.username', '');
  $pass = (string) \App\Config::get('integrations.mysql.password', '');

  mysqli_report(MYSQLI_REPORT_OFF);
  $mysqli = @mysqli_init();
  if (!$mysqli) { echo json_encode(['ok'=>false,'error'=>'mysqli_init failed']); exit; }
  @mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
  $link = @$mysqli->real_connect($host, $user, $pass, null, $port);
  if (!$link) { echo json_encode(['ok'=>false,'error'=>'Connect failed','errno'=>$mysqli->connect_errno,'err'=>$mysqli->connect_error]); exit; }

  $dbIdent = str_replace('`','``',$db);
  $tables = [];
  if ($res = @$mysqli->query("SHOW TABLE STATUS FROM `{$dbIdent}`")) {
    while ($t = $res->fetch_assoc()) {
      $tables[] = [
        'name' => $t['Name'] ?? '',
        'engine' => $t['Engine'] ?? '',
        'rows' => (int)($t['Rows'] ?? 0),
        'data_bytes' => (int)($t['Data_length'] ?? 0),
        'index_bytes' => (int)($t['Index_length'] ?? 0),
        'update_time' => $t['Update_time'] ?: ($t['Create_time'] ?: ($t['Check_time'] ?: null)),
        'collation' => $t['Collation'] ?? null,
      ];
    }
    $res->free();
  } else {
    echo json_encode(['ok'=>false,'error'=>'Query failed','errno'=>$mysqli->errno,'err'=>$mysqli->error]); exit;
  }
  echo json_encode(['ok'=>true,'db'=>$db,'tables'=>$tables]); exit;
}

// Preview endpoint (first N rows)
if (($_GET['action'] ?? '') === 'preview') {
  header('Content-Type: application/json; charset=utf-8');
  $db = (string)($_GET['db'] ?? '');
  $table = (string)($_GET['table'] ?? '');
  $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
  if ($db === '' || $table === '') { echo json_encode(['ok'=>false,'error'=>'missing args']); exit; }

  $host = (string) \App\Config::get('integrations.mysql.host', '127.0.0.1');
  $port = (int) \App\Config::get('integrations.mysql.port', 3306);
  $user = (string) \App\Config::get('integrations.mysql.username', '');
  $pass = (string) \App\Config::get('integrations.mysql.password', '');

  mysqli_report(MYSQLI_REPORT_OFF);
  $mysqli = @mysqli_init();
  if (!$mysqli) { echo json_encode(['ok'=>false,'error'=>'mysqli_init failed']); exit; }
  @mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 5);
  $link = @$mysqli->real_connect($host, $user, $pass, null, $port);
  if (!$link) { echo json_encode(['ok'=>false,'error'=>'Connect failed','errno'=>$mysqli->connect_errno,'err'=>$mysqli->connect_error]); exit; }

  $dbIdent = str_replace('`','``',$db);
  $tblIdent = str_replace('`','``',$table);
  if (!@$mysqli->select_db($db)) { echo json_encode(['ok'=>false,'error'=>'select_db failed']); exit; }

  $sql = "SELECT * FROM `{$dbIdent}`.`{$tblIdent}` LIMIT {$limit}";
  if (!($res = @$mysqli->query($sql))) {
    echo json_encode(['ok'=>false,'error'=>'query failed','errno'=>$mysqli->errno,'err'=>$mysqli->error]); exit;
  }
  $cols = [];
  $fields = $res->fetch_fields();
  foreach ($fields as $f) { $cols[] = $f->name; }
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
  }
  $res->free();
  echo json_encode(['ok'=>true,'db'=>$db,'table'=>$table,'columns'=>$cols,'rows'=>$rows,'count'=>count($rows),'limit'=>$limit]); exit;
}

$PAGE_TITLE = 'Databases';
$PAGE_JS    = 'assets/js/pages/database.js';

include __DIR__ . '/includes/head.php';
?>
<style>
  #dbTableWrap { overflow: auto; }
  #dbTable thead th { position: sticky; top: 0; z-index: 2; }
</style>
<div id="dbRoot"
     data-scan="<?= h(project_url('/database.php?action=scan')) ?>">
  <div class="card" id="dbCard">
    <div class="row between" id="dbControls">
      <div class="section-title">Databases</div>
      <div class="row gap-sm">
        <button id="btnRefresh" class="btn small">Refresh</button>
      </div>
    </div>
    <div id="dbServer" class="muted small" style="margin-top:2px"></div>
    <div id="dbTableWrap" style="margin-top:8px;">
      <div class="table-scroll"><table id="dbTable" class="js-sortable table compact">
        <thead>
          <tr>
            <th>Name</th>
            <th>Collation</th>
            <th style="text-align:right">Tables</th>
            <th style="text-align:right">Rows</th>
            <th style="text-align:right">Data</th>
            <th style="text-align:right">Index</th>
            <th style="text-align:right">Total</th>
            <th>Last Update</th>
            <th>System</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="9" class="muted">Loading…</td></tr>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/foot.php'; ?>

<script src="assets/js/utils/sortable-table.js" defer></script>
<script src="assets/js/pages/database.explorer.js?v=<?= h(BUILD) ?>" defer></script>
