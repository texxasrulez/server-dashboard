<?php
// api/config_backup.php — retention + tools + persisted keep (config/ or data/ aware)
//
// Modes:
//  POST/GET (default): snapshot local.json into <LOCAL_DIR>/backups/config-YYYYMMDD-HHMMSS.json and prune to keep=N
//  ?prune=1: only prune to keep=N
//  ?latest=1: newest backup info {ok, exists, file, size, mtime, backup_dir}
//  ?download_latest=1: stream newest backup (attachment)
//  ?stats=1: { ok, count, total_size, total_size_human, keep, backup_dir }
//
// keep selection order (with clamps):
//  1) explicit request ?keep=, POST keep
//  2) site.backup_keep from local.json (if numeric)
//  3) default 20
header('Content-Type: application/json; charset=UTF-8');

function resp($ok, $extra = []) {
  echo json_encode(array_merge(['ok'=>$ok], $extra));
  exit;
}

function clamp_keep($n) {
  $n = (int)$n;
  if ($n < 5) $n = 5;
  if ($n > 200) $n = 200; // UI bound
  if ($n > 500) $n = 500; // absolute safety cap
  return $n;
}

function find_local_json($root) {
  $candidates = [
    $root . '/config/local.json',
    $root . '/data/local.json',
    $root . '/local.json',
    dirname($root) . '/config/local.json',
    dirname($root) . '/data/local.json',
    dirname($root) . '/local.json',
  ];
  foreach ($candidates as $p) {
    if (is_readable($p)) return $p;
  }
  return null;
}

function pick_backup_dir($root, $src) {
  if ($src) {
    // Put backups next to local.json (config/backups or data/backups)
    $dir = dirname($src) . '/backups';
    return $dir;
  }
  // Fallback: prefer config/backups, then data/backups, else default to data/backups under root
  $pref = [$root . '/config/backups', $root . '/data/backups'];
  foreach ($pref as $d) { if (is_dir($d) || @mkdir($d, 0775, true)) return $d; }
  return $root . '/data/backups';
}

try {
  $root = dirname(__DIR__);
  $src  = find_local_json($root);
  $backupDir = pick_backup_dir($root, $src);
  if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0775, true) && !is_dir($backupDir)) throw new Exception('cannot create backup dir');
  }

  // Determine 'keep'
  $keep = null;
  if (isset($_REQUEST['keep'])) $keep = (int)$_REQUEST['keep'];
  if ($keep === null && $src) {
    // Try reading from local.json
    $raw = @file_get_contents($src);
    if ($raw !== false) {
      $cfg = json_decode($raw, true);
      if (json_last_error() === JSON_ERROR_NONE && isset($cfg['site']['backup_keep']) && is_numeric($cfg['site']['backup_keep'])) {
        $keep = (int)$cfg['site']['backup_keep'];
      }
    }
  }
  if ($keep === null) $keep = 20;
  $keep = clamp_keep($keep);

  // helper: list backups newest->oldest
  $list = glob($backupDir . '/config-*.json');
  if (!is_array($list)) $list = [];
  usort($list, function($a,$b){ return @filemtime($b) - @filemtime($a); });

  // stats mode
  if (isset($_GET['stats'])) {
    $count = count($list);
    $total = 0;
    foreach ($list as $f) { $sz = @filesize($f); if ($sz>0) $total += $sz; }
    $human = ($total>=1048576) ? round($total/1048576,2).' MB' : ($total>=1024 ? round($total/1024,2).' KB' : $total.' B');
    resp(true, ['count'=>$count, 'total_size'=>$total, 'total_size_human'=>$human, 'keep'=>$keep, 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
  }

  // latest info
  if (isset($_GET['latest'])) {
    if (!empty($list)) {
      $f = $list[0];
      resp(true, ['exists'=>true, 'file'=>basename($f), 'size'=>@filesize($f), 'mtime'=>@filemtime($f), 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
    } else {
      resp(true, ['exists'=>false, 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
    }
  }

  // download latest
  if (isset($_GET['download_latest'])) {
    if (!empty($list)) {
      $f = $list[0];
      if (is_readable($f)) {
        @ob_end_clean();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="'.basename($f).'"');
        readfile($f);
        exit;
      }
    }
    http_response_code(404);
    resp(false, ['error'=>'No backup to download', 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
  }

  // prune-only mode
  if (isset($_GET['prune']) || isset($_POST['prune'])) {
    $deleted = 0;
    if (count($list) > $keep) {
      foreach (array_slice($list, $keep) as $f) { if (@unlink($f)) $deleted++; }
    }
    resp(true, ['keep'=>$keep, 'deleted'=>$deleted, 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
  }

  // default: create new backup and prune
  if (!$src) throw new Exception('local.json not found (checked config/local.json, data/local.json, local.json)');
  $json = file_get_contents($src);
  if ($json === false) throw new Exception('read failed');
  json_decode($json, true);
  if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('invalid JSON');

  $ts = date('Ymd-His');
  $dst = $backupDir . '/config-' . $ts . '.json';
  if (file_put_contents($dst, $json) === false) throw new Exception('write failed');

  // prune to keep newest N
  $list = glob($backupDir . '/config-*.json');
  if (!is_array($list)) $list = [];
  usort($list, function($a,$b){ return @filemtime($b) - @filemtime($a); });
  $deleted = 0;
  if (count($list) > $keep) {
    foreach (array_slice($list, $keep) as $f) { if (@unlink($f)) $deleted++; }
  }

  resp(true, ['path'=>basename($dst), 'keep'=>$keep, 'deleted'=>$deleted, 'backup_dir'=>basename(dirname($backupDir)).'/'.basename($backupDir)]);
} catch (Throwable $e) {
  http_response_code(500);
  resp(false, ['error'=>$e->getMessage()]);
}
