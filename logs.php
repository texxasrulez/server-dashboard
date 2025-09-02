<?php
require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_login();

$PAGE_TITLE = 'Logs';
$PAGE_CSS   = 'assets/css/pages/logs.css';

/* ====================== Paths & Setup ====================== */
$LOG_SRC   = '/var/log';
$STATE_DIR = __DIR__ . '/state';
$LOG_DEST  = $STATE_DIR . '/logs_mirror';
$LOG_CFG   = $STATE_DIR . '/logs_config.json';

@umask(0002);
if (!is_dir($STATE_DIR)) { @mkdir($STATE_DIR, 0775, true); }
@mkdir($LOG_DEST, 0775, true);
if (!is_dir($LOG_DEST))  { @mkdir($LOG_DEST, 0775, true); }
if (!file_exists($LOG_CFG)) { @file_put_contents($LOG_CFG, json_encode(array())); @chmod($LOG_CFG, 0664); }

/* ====================== Helpers ====================== */
function mirror_activity_log($status,$action,$name,$note=''){
  $state = __DIR__ . '/state';
  @mkdir($state,0775,true);
  $logf = $state . '/mirror_activity.log';
  $line = date('Y-m-d H:i:s') . "\t" . $status . "\t" . $action . "\t" . $name . (strlen($note) ? "\t".$note : '') . "\n";
  @file_put_contents($logf, $line, FILE_APPEND | LOCK_EX);
  @chmod($logf, 0664);
}

// Accept relative under /var/log or absolute; allow .log, .log.N, .log.gz; must live under allowed roots
function resolve_candidate_path($input, $roots) {
  $input = trim(strval($input));
  if ($input === '') return array(null, null);
  if ($input[0] !== '/') { $input = '/var/log/' . ltrim($input, '/'); }
  $real = @realpath($input);
  if (!$real) return array(null, null);
  if (!preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', basename($real))) return array(null, null);
  foreach ($roots as $r) {
    $rreal = @realpath($r);
    if ($rreal && strpos($real, rtrim($rreal,'/')) === 0) return array($real, $rreal);
  }
  return array(null, null);
}

function copy_log_file($srcFile, $destDir) {
  if (!is_file($srcFile) || is_link($srcFile) || !is_readable($srcFile)) { mirror_activity_log('fail','copy',basename($srcFile),'not-readable'); return false; }
  if (!preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', basename($srcFile))) { mirror_activity_log('skip','copy',basename($srcFile),'not-log'); return false; }
  $srcReal = @realpath($srcFile);
  if (!$srcReal) { mirror_activity_log('fail','copy',basename($srcFile),'no-realpath'); return false; }

  // Flatten subpaths for uniqueness (nginx/error.log -> nginx__error.log)
  global $LOG_SRC, $LOG_DEST;
  $rel = null;
  foreach (array($LOG_SRC, $LOG_DEST) as $rt) {
    $rreal = @realpath($rt);
    if ($rreal && strpos($srcReal, rtrim($rreal,'/')) === 0) {
      $rel = ltrim(substr($srcReal, strlen(rtrim($rreal,'/'))), '/');
      break;
    }
  }
  if ($rel === null || $rel === '') $rel = basename($srcReal);
  $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '__', $rel);
  if (!preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', $safe)) { mirror_activity_log('skip','copy',$safe,'not-log'); return false; }

  $dest = rtrim($destDir,'/').'/'.$safe;
  $need = !file_exists($dest) || (filemtime($srcReal) > @filemtime($dest));
  if (!$need) { mirror_activity_log('skip','copy',$safe,'up-to-date'); return true; }

  $in = @fopen($srcReal,'rb'); $out = @fopen($dest,'wb');
  if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); mirror_activity_log('fail','copy',$safe,'open'); return false; }
  stream_copy_to_stream($in, $out);
  fclose($in); fclose($out);
  @chmod($dest, 0644);
  mirror_activity_log('ok','copy',$safe);
  return true;
}

function mirror_logs_dir($srcDir, $destDir) {
  if (!is_dir($srcDir) || !is_readable($srcDir)) return;
  $dh = @opendir($srcDir); if (!$dh) return;
  while (($e = readdir($dh)) !== false) {
    if ($e === '.' || $e === '..') continue;
    $sp = $srcDir.'/'.$e;
    if (!is_file($sp) || is_link($sp)) continue;
    if (!preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', $e)) continue; // .log + rotations
    copy_log_file($sp, $destDir);
  }
  closedir($dh);
}

function read_log_cfg($path){
  $raw=@file_get_contents($path);
  $arr=json_decode($raw,true);
  return is_array($arr)?$arr:array();
}
function write_log_cfg($path,$arr){
  @file_put_contents($path,json_encode(array_values($arr), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  @chmod($path,0664);
}

/* ------ time helpers ------ */
function _fmt_utc_from_dt($dt){
  if (!($dt instanceof DateTime)) return '';
  try { $dt->setTimezone(new DateTimeZone('UTC')); } catch (Exception $e) { return ''; }
  return $dt->format('n/j/Y, g:i:s A');
}
function _fmt_utc_from_ts($ts){
  if (!$ts) return '';
  return gmdate('n/j/Y, g:i:s A', intval($ts));
}

/* ------ smarter parsing ------ */
function classify_category($prog, $msg){
  $p = strtolower(strval($prog));
  $m = strtolower(strval($msg));

  if (preg_match('/(fail(ed)?|denied|forbidden|unauthori[sz]ed|invalid user|ban|blocked)/', $m)
      || preg_match('/^(sshd|sudo|fail2ban|security|auth|polkit)/', $p)) return 'security';

  if (preg_match('/(error|warn|warning|critical|panic|oops|segfault)/', $m)) return 'warning';

  if (preg_match('/^(mysql|mysqld|mariadb|mariadbd|postgres|postgresql|innodb)/', $p)
      || preg_match('/\b(database|sql|query)\b/', $m)) return 'database';

  if (preg_match('/^(nginx|apache|httpd|caddy|haproxy|php-fpm)/', $p)
      || preg_match('/\b(http|tls|ssl|proxy|upstream|socket|listen|port)\b/', $m)) return 'network';

  if (preg_match('/\b(latency|timeout|slow|slower|performance|throughput)\b/', $m)) return 'performance';

  if (preg_match('/^(cron|crond|systemd|kernel|dpkg|apt|rsyslog)/', $p)) return 'info';

  return 'info';
}


function parse_log_line($line){
  $ln = trim(strval($line));
  if ($ln === '') return array('time'=>'','ts'=>0,'category'=>'info','message'=>'','user'=>'','ip'=>'');

  $time=''; $prog=''; $msg=$ln; $user=''; $ip=''; $ts=0; $lvl='';

  // Apache/Nginx access (CLF/combined): host ident authuser [date] "req" status bytes
  if (preg_match('/^(\S+)\s+\S+\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]*)"\s+(\d{3})\s+(\S+)/', $ln, $m)) {
    $host = $m[1];
    $auth = $m[2];
    $time = $m[3];
    $prog = 'httpd';
    $msg  = $m[4] . ' ' . $m[5] . ' ' . $m[6];
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $time, new DateTimeZone('UTC'));
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
    if ($auth !== '-' && $auth !== '') $user = $auth;
    if ($ip==='' && preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host)) $ip = $host;
  }
  elseif (preg_match('/^(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})\s+\[([a-z]+)\]\s+\d+#[0-9]+:/i', $ln, $m)) {
    $prog = 'nginx';
    $lvl  = strtolower($m[3]);
    $msg  = preg_replace('/^[^:]+:\s*/','', $ln);
    $tz = new DateTimeZone(@date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y/m/d H:i:s', $m[1].' '.$m[2], $tz);
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
  }
  elseif (preg_match('/^\[([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?\s+\d{4})\]\s+\[([^\]]+)\]\s*(.*)$/', $ln, $m)) {
    $prog = 'apache';
    $lvl  = strtolower($m[2]);
    $rawt = preg_replace('/\.\d+/', '', $m[1]);
    $tz = new DateTimeZone(@date_default_timezone_get());
    $dt = DateTime::createFromFormat('D M j H:i:s Y', $rawt, $tz);
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
    $msg = $m[3];
  }
  elseif (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})\s+\S+\s+([A-Za-z0-9._-]+)(?:\[\d+\])?:\s+(.*)$/', $ln, $m)) {
    $mon = $m[1]; $day = intval($m[2]); $hms = $m[3];
    $prog = $m[4]; $msg = $m[5];
    $tz = new DateTimeZone(@date_default_timezone_get());
    $year = intval(date('Y'));
    $dt = DateTime::createFromFormat('Y M j H:i:s', sprintf('%04d %s %d %s', $year, $mon, $day, $hms), $tz);
    if ($dt) {
      $now = new DateTime('now', $tz);
      if ($dt->getTimestamp() - $now->getTimestamp() > 86400*7) { $dt->modify('-1 year'); }
      $ts = $dt->getTimestamp();
      $time = _fmt_utc_from_ts($ts);
    }
  }
  elseif (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+(.*)$/', $ln, $m)) {
    $prog = 'dpkg';
    $msg  = $m[3];
    $tz = new DateTimeZone(@date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1] . ' ' . $m[2], $tz);
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
  }
  elseif (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})?)\s+(.*)$/', $ln, $m)) {
    $msg  = $m[2];
    try {
      $dt = new DateTime($m[1]);
      $ts = $dt->getTimestamp();
      $time = _fmt_utc_from_ts($ts);
    } catch (Exception $e) { $time = $m[1]; }
  }
  elseif (preg_match('/^\[([0-9]{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+\-]?\d{4})\]\s*(.*)$/', $ln, $m)) {
    $msg  = $m[2];
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1], new DateTimeZone('UTC'));
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
  }
  elseif (preg_match('/^(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})\b(.*)$/', $ln, $m)) {
    $tz = new DateTimeZone(@date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y/m/d H:i:s', $m[1].' '.$m[2], $tz);
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
    $msg = trim($m[3]);
  }
  elseif (preg_match('/^(\d{4}\-\d{2}\-\d{2})\s+(\d{2}:\d{2}:\d{2})\b(.*)$/', $ln, $m)) {
    $tz = new DateTimeZone(@date_default_timezone_get());
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1].' '.$m[2], $tz);
    if ($dt) { $ts = $dt->getTimestamp(); $time = _fmt_utc_from_ts($ts); }
    $msg = trim($m[3]);
  }

  // ---- User extraction (expanded) ----
  if ($user==='' && preg_match('/\buser(name)?=([a-z0-9._-]+)/i', $msg, $u)) $user = $u[2];
  if ($user==='' && preg_match('/\bby\s+user\s+([a-z0-9._-]+)/i', $msg, $u))  $user = $u[1];
  if ($user==='' && preg_match('/\bAccepted (?:password|publickey|keyboard-interactive) for ([a-z0-9._-]+)/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/\bFailed password for (?:invalid user\s+)?([a-z0-9._-]+)/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/\bsession opened for user\s+([a-z0-9._-]+)/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/^sudo:\s*([a-z0-9._-]+)\s*:/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/\bUSER\s*=\s*([a-z0-9._-]+)/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/\bInvalid\s+user\s+([a-z0-9._-]+)/i', $msg, $u)) $user = $u[1];
  if ($user==='' && preg_match('/\b(www-data|root|system|cron|appuser|admin)\b/i', $msg, $u)) $user = strtolower($u[1]);

  // ---- IP fallback ----
  if ($ip==='' && preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $ln, $i)) $ip = $i[1];
  // IPv6 fallback
  if ($ip==='' && preg_match('/\b([0-9a-fA-F:]{2,}\b)/', $ln, $i)) $ip = $i[1];

  $cat = $lvl !== '' ? ($lvl === 'error' ? 'warning' : ($lvl==='warn'?'warning':$lvl)) : classify_category($prog, $msg);

  return array('time'=>$time, 'ts'=>$ts, 'category'=>$cat, 'message'=>$msg, 'user'=>$user, 'ip'=>$ip);
}


$LOG_ALLOWED_ROOTS = array($LOG_SRC, $LOG_DEST);

/* ====================== AJAX (JSON) ====================== */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');
  $act = $_POST['action'];

  if ($act === 'add_log') {
    $name = trim(strval(isset($_POST['name']) ? $_POST['name'] : ''));
    list($srcReal, $srcRoot) = resolve_candidate_path($name, $LOG_ALLOWED_ROOTS);
    if (!$srcReal || !$srcRoot || !is_file($srcReal) || !is_readable($srcReal)) {
      http_response_code(400); echo json_encode(array('ok'=>false,'error'=>'Invalid or unreadable log path')); exit;
    }
    $ok = copy_log_file($srcReal, $LOG_DEST);
    echo json_encode(array('ok'=>(bool)$ok,'file'=>basename($srcReal))); exit;
  }

  if ($act === 'list_log_configs') { echo json_encode(array('ok'=>true, 'items'=>read_log_cfg($LOG_CFG))); exit; }
  if ($act === 'create_log_config') {
    $label = trim(strval(isset($_POST['label']) ? $_POST['label'] : ''));
    $path  = trim(strval(isset($_POST['path']) ? $_POST['path'] : ''));
    if ($label === '' || $path === '') { echo json_encode(array('ok'=>false, 'error'=>'Label and path required')); exit; }
    list($real, $root) = resolve_candidate_path($path, $LOG_ALLOWED_ROOTS);
    if (!$real || !$root || !is_file($real) || !is_readable($real)) { echo json_encode(array('ok'=>false, 'error'=>'Invalid or unreadable log path')); exit; }
    $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/','-', $label)) . '-' . substr(md5($path),0,6);
    $items = read_log_cfg($LOG_CFG);
    $items[] = array('id'=>$id,'label'=>$label,'path'=>trim($path,'/'));
    write_log_cfg($LOG_CFG, $items);
    copy_log_file($real, $LOG_DEST);
    echo json_encode(array('ok'=>true, 'item'=>array('id'=>$id,'label'=>$label,'path'=>trim($path,'/')))); exit;
  }
  if ($act === 'update_log_config') {
    $id    = strval(isset($_POST['id']) ? $_POST['id'] : '');
    $label = trim(strval(isset($_POST['label']) ? $_POST['label'] : ''));
    $path  = trim(strval(isset($_POST['path']) ? $_POST['path'] : ''));
    $items = read_log_cfg($LOG_CFG);
    $found = false;
    foreach ($items as &$it) {
      $cur = (isset($it['id']) ? $it['id'] : '');
      if ($cur === $id) {
        if ($label !== '') $it['label'] = $label;
        if ($path  !== '') $it['path']  = trim($path,'/');
        $found = true; break;
      }
    }
    if (!$found) { echo json_encode(array('ok'=>false,'error'=>'Not found')); exit; }
    write_log_cfg($LOG_CFG, $items);
    if ($path !== '') {
      list($real, $root) = resolve_candidate_path($path, $LOG_ALLOWED_ROOTS);
      if ($real && $root && is_file($real) && is_readable($real)) copy_log_file($real, $LOG_DEST);
    }
    echo json_encode(array('ok'=>true)); exit;
  }
  if ($act === 'delete_log_config') {
    $id = strval(isset($_POST['id']) ? $_POST['id'] : '');
    $items = read_log_cfg($LOG_CFG);
    $new = array(); $found = false;
    foreach ($items as $it) { if ((isset($it['id']) ? $it['id'] : '') === $id) { $found = true; continue; } $new[] = $it; }
    if (!$found) { echo json_encode(array('ok'=>false,'error'=>'Not found')); exit; }
    write_log_cfg($LOG_CFG, $new); echo json_encode(array('ok'=>true)); exit;
  }

  if ($act === 'probe_log_path') {
    $p = strval(isset($_POST['path']) ? $_POST['path'] : '');
    list($real,$root) = resolve_candidate_path($p, $LOG_ALLOWED_ROOTS);
    if (!$real || !is_file($real) || !is_readable($real)) { echo json_encode(array('ok'=>false,'error'=>'Invalid path')); exit; }
    $st = @stat($real);
    $size = (isset($st['size']) ? $st['size'] : null);
    $mtime= (isset($st['mtime'])? $st['mtime']: null);
    echo json_encode(array('ok'=>true, 'real'=>$real, 'size'=>$size, 'mtime'=>$mtime)); exit;
  }
  if ($act === 'list_log_candidates') {
    $cands = array();
    $dirs = array_filter(array('/var/log','/var/log/apache2','/var/log/httpd','/var/log/nginx','/var/log/mysql','/var/log/mariadb','/var/log/postgresql','/var/log/hestia'), 'is_dir');
    foreach ($dirs as $d) {
      foreach (glob($d.'/*') as $f) {
        if (preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', basename($f))) $cands[] = ltrim(str_replace('/var/log','', $f), '/');
      }
    }
    $cands = array_values(array_unique($cands));
    sort($cands, SORT_NATURAL|SORT_FLAG_CASE);
    echo json_encode(array('ok'=>true,'items'=>array_slice($cands,0,300))); exit;
  }

  /* ===================== Mirror manager ===================== */
  if ($act === 'list_mirror_entries') {
    $items = array();
    foreach (glob($LOG_DEST.'/*') as $f) {
      $bn = basename($f);
      $type = is_dir($f) ? 'dir'
            : (preg_match('/\.zip$/i',$bn) ? 'zip'
            : ((preg_match('/\.log(\.\d+)?(\.gz)?$/i',$bn) || preg_match('/\.gz$/i',$bn)) ? 'log' : 'file'));
      $items[] = array('name'=>$bn,'size'=>@filesize($f)?@filesize($f):0,'mtime'=>@filemtime($f)?@filemtime($f):0,'type'=>$type);
    }
    usort($items, function($a,$b){ return strnatcasecmp($a['name'],$b['name']); });
    echo json_encode(array('ok'=>true,'items'=>$items)); exit;
  }

  if ($act === 'delete_mirror') {
    $name = basename(strval(isset($_POST['name']) ? $_POST['name'] : ''));
    if ($name===''){ echo json_encode(array('ok'=>false,'error'=>'No name')); exit; }
    $path = $LOG_DEST . '/' . $name;
    $real = @realpath($path); $root = @realpath($LOG_DEST);
    if (!$real || !$root || strpos($real,$root)!==0){ echo json_encode(array('ok'=>false,'error'=>'Bad path')); exit; }
    $ok = false;
    if (is_file($real)) { $ok = @unlink($real); }
    elseif (is_dir($real) && preg_match('/\bunzipped\b/', $real)) {
      $ok = true;
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($it as $p) { $ok = $ok && ( $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()) ); }
      $ok = $ok && @rmdir($real);
    }
    mirror_activity_log($ok?'ok':'fail','delete',$name);
    echo json_encode(array('ok'=>(bool)$ok)); exit;
  }

  if ($act === 'unzip_mirror') {
    $name = basename(strval(isset($_POST['name']) ? $_POST['name'] : ''));
    if (!preg_match('/\.zip$/i',$name)){ echo json_encode(array('ok'=>false,'error'=>'Not a zip')); exit; }
    $zipPath = $LOG_DEST . '/' . $name;
    $zipReal = @realpath($zipPath); $root = @realpath($LOG_DEST);
    if (!$zipReal || !$root || strpos($zipReal,$root)!==0 || !is_file($zipReal)){ echo json_encode(array('ok'=>false,'error'=>'Zip not found')); exit; }
    $dest = $LOG_DEST . '/unzipped/' . preg_replace('/[^a-zA-Z0-9._-]+/','_', basename($name, '.zip')) . '_' . date('Ymd_His');
    @mkdir($dest, 0775, true);
    $ok = false; $note='';
    if (class_exists('ZipArchive')){
      $zip = new ZipArchive();
      if ($zip->open($zipReal) === true) {
        for ($i=0; $i<$zip->numFiles; $i++){
          $entry = $zip->getNameIndex($i);
          if (substr($entry, -1) === '/') { @mkdir($dest.'/'.trim($entry,'/'), 0775, true); continue; }
          $target = $dest . '/' . $entry;
          $parent = dirname($target);
          $realParent = @realpath($parent); if (!$realParent) $realParent = $parent;
          if (strpos($realParent, $dest) !== 0) continue;
          $contents = $zip->getFromIndex($i);
          if ($contents !== false) {
            @mkdir($parent, 0775, true);
            @file_put_contents($target, $contents);
            @chmod($target, 0644);
          }
        }
        $zip->close(); $ok = true;
      } else { $note='open-fail'; }
    } else { $note='no-ziparchive'; }
    mirror_activity_log($ok?'ok':'fail','unzip',$name,$note);
    echo json_encode(array('ok'=>(bool)$ok, 'dest'=>basename($dest))); exit;
  }

  /* ---- Pulldown source (recursive) – *.log, *.log.N, *.log.gz ---- */
  if ($act === 'list_mirror_logs') {
    if (!is_dir($LOG_DEST)) { @mkdir($LOG_DEST,0775,true); echo json_encode(array('ok'=>true,'items'=>array())); exit; }
    try {
    $items = array();
    $root = realpath($LOG_DEST);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($LOG_DEST, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if ($f->isDir()) continue;
      $bn = $f->getBasename();
      if (!preg_match('/\.log(\.\d+|\.gz)?$/i', $bn)) continue;
      $rel = preg_replace('~^[\\\\/]+~', '', str_replace($root, '', realpath($f->getPathname())));
      $items[] = $rel;
    }
    sort($items, SORT_NATURAL|SORT_FLAG_CASE);
    echo json_encode(array('ok'=>true, 'items'=>$items)); exit;
    } catch (Throwable $e) { echo json_encode(array('ok'=>false,'error'=>'scan-failed')); exit; }
  }

  /* ---- Read last N lines from a mirrored log (supports .gz) ---- */
  if ($act === 'read_mirror_log') {
    $file  = strval(isset($_POST['file']) ? $_POST['file'] : '');
    $lines = intval(isset($_POST['lines']) ? $_POST['lines'] : 300);
    if ($lines < 10) $lines = 10; if ($lines > 2000) $lines = 2000;

    if ($file === '' || preg_match('/\.\./', $file)) {
      echo json_encode(array('ok'=>false, 'error'=>'Bad file')); exit;
    }

    $path = $LOG_DEST . '/' . ltrim($file, '/');
    $real = @realpath($path);
    $root = @realpath($LOG_DEST);
    if (!$real || !$root || strpos($real, $root) !== 0 || !is_file($real) || !is_readable($real)) {
      echo json_encode(array('ok'=>false, 'error'=>'Not found')); exit;
    }

    $lines_buf = array();
    if (preg_match('/\.gz$/i', $real)) {
      if (!function_exists('gzopen')) { echo json_encode(array('ok'=>false, 'error'=>'gz not supported')); exit; }
      $gz = @gzopen($real, 'rb');
      if (!$gz) { echo json_encode(array('ok'=>false, 'error'=>'gzopen failed')); exit; }
      while (!gzeof($gz)) {
        $ln = gzgets($gz, 8192);
        if ($ln === false) break;
        $lines_buf[] = rtrim($ln, "\r\n");
        if (count($lines_buf) > $lines) array_shift($lines_buf);
      }
      gzclose($gz);
    } else {
      $fh = @fopen($real, 'rb');
      if (!$fh) { echo json_encode(array('ok'=>false, 'error'=>'Open failed')); exit; }
      $buffer = '';
      $chunk  = 8192;
      $size   = filesize($real);
      $seek   = $size;
      $count  = 0;
      while ($seek > 0 && $count <= $lines) {
        $read = ($seek >= $chunk) ? $chunk : $seek;
        $seek -= $read;
        fseek($fh, $seek);
        $buffer = fread($fh, $read) . $buffer;
        $count = substr_count($buffer, "\n");
      }
      fclose($fh);
      $lines_buf = preg_split("/\r?\n/", rtrim($buffer));
      if (count($lines_buf) > $lines) $lines_buf = array_slice($lines_buf, -$lines);
    }

    $rows = array();
    foreach ($lines_buf as $ln) {
      $parsed = parse_log_line($ln);
      $rows[] = array(
        'time'     => (isset($parsed['time']) ? $parsed['time'] : ''),
        'category' => (isset($parsed['category']) ? $parsed['category'] : 'info'),
        'message'  => (isset($parsed['message']) ? $parsed['message'] : ''),
        'user'     => (isset($parsed['user']) ? $parsed['user'] : ''),
        'ip'       => (isset($parsed['ip']) ? $parsed['ip'] : '')
      );
    }
    echo json_encode(array('ok'=>true, 'rows'=>$rows)); exit;
  }

  http_response_code(400); echo json_encode(array('ok'=>false,'error'=>'Unknown action')); exit;
}

/* ====================== Mirror on Page Load ====================== */
mirror_logs_dir($LOG_SRC, $LOG_DEST);

/* ====================== HTML ====================== */
include __DIR__.'/includes/head.php';
?>
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div style="font-weight:700">Logs</div>
      <div class="row">
  <select id="logSelect" class="log-select"></select>
  <input id="logQ" placeholder="contains…" style="min-width:200px">
  <input id="lines" type="number" min="50" step="50" value="300" title="Lines">
  <label style="display:flex;align-items:center;gap:.35rem;margin-left:.35rem">
    <input id="logCi" type="checkbox"> <span class="muted">case-insensitive</span>
  </label>
  <input id="logRe" placeholder="regex …" style="min-width:160px">
  <button class="btn" id="btnRefresh">Refresh</button>
  <button class="btn" id="btnAddLog">Add Log</button>
  <a class="btn" id="btnDownload" href="#" download>Download</a>
  <button class="btn" id="btnExportCsv">Export CSV</button>
</div>
    </div>
  </div>

  <!-- Manage Logs modal (presets + mirror manager) -->
  <div id="logModal" hidden style="position:fixed;inset:0;z-index:1000">
    <div class="modal-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.35)"></div>
    <div class="modal-card card" style="position:relative;max-width:920px;margin:8vh auto;padding:1rem">
      <div class="row" style="justify-content:space-between">
        <div style="font-weight:700">Manage Logs</div>
        <button class="btn" id="logModalClose" aria-label="Close">Close</button>
      </div>
      <div class="muted" style="margin-top:.25rem">Add, edit, or delete saved log entries (supports <code>.log</code>, rotations, and <code>.log.gz</code>). The mirror can also unzip <code>.zip</code> files and delete old artifacts.</div>

      <div style="margin-top:.75rem">
        <form id="logForm" class="row" onsubmit="return false;">
          <input type="hidden" id="logId">
          <input id="logLabel" placeholder="Label (e.g., Nginx Error)" required>
          <input id="logPath" placeholder="Path under /var/log (e.g., nginx/error.log)" required>
          <button class="btn" id="logSave">Save</button>
          <button class="btn" id="logTest" type="button">Test</button>
          <button class="btn" id="logCommon" type="button">Common</button>
          <span class="muted" id="logStatus" style="margin-left:.5rem"></span>
        </form>
      </div>

      <div class="tablewrap" style="margin-top:.5rem;max-height:40vh;overflow:auto">
        <div class="table-scroll"><table id="logCfgTable" class="js-sortable sortable">
          <thead><tr><th>Label</th><th>Path</th><th>Actions</th></tr></thead>
          <tbody></tbody>
        </table></div>
      </div>

      <div class="mt-2">
        <div class="row" style="justify-content:space-between;align-items:center">
          <div style="font-weight:700">Mirror files</div>
          <div class="row">
            <button class="btn" id="mirrorRefresh" type="button">Refresh</button>
          </div>
        </div>
        <div class="tablewrap" style="margin-top:.5rem;max-height:40vh;overflow:auto">
          <table id="mirrorTable" class="js-sortable sortable">
            <thead><tr><th>Name</th><th>Size</th><th>Updated</th><th>Type</th><th>Actions</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div style="font-weight:700">History</div>
      <div class="row">
        <input id="filterText" placeholder="Regex filter (optional)">
        <input id="fromDate" type="date" placeholder="mm/dd/yyyy">
        <input id="toDate" type="date" placeholder="mm/dd/yyyy">
        <button class="btn" id="btnApply">Apply</button>
        <button class="btn" id="btnLive">Live: Off</button>
        <button class="btn" id="btnMore">Load More</button>
      </div>
    </div>
    <div class="muted" id="histMeta">Showing 0 of 0 entries</div>
    <div class="tablewrap">
      <table id="histTable" class="js-sortable sortable">
        <thead>
          <tr>
            <th>Time (UTC)</th>
            <th>Category</th>
            <th>Message</th>
            <th>User</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody id="histBody"></tbody>
      </table>
    </div>
  </div>

  <script defer src="assets/js/logs.js?v=<?php echo h(BUILD); ?>"></script>

  
<script>
(function(){
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function esc(s){ return String(s).replace(/[&<>]/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }
  function rxEscape(str){ return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  async function post(action, data){
    const fd = new FormData(); fd.append('action', action);
    for (const k in (data||{})) if (Object.prototype.hasOwnProperty.call(data,k)) fd.append(k, data[k]);
    const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials:'same-origin'});
    return res.json();
  }

  async function buildPulldownFromMirror(){
    const sel = $("#logSelect"); if (!sel) return;
    let d = null;
    try { d = await post('list_mirror_logs', {}); } catch(e){ console.error('list_mirror_logs failed', e); d = {items:[]}; }
    const items = (d && d.items) ? d.items : [];
    const keep = sel.value;
    sel.innerHTML = "";
    items.forEach(n => sel.appendChild(new Option(n, n)));
    if (keep && items.includes(keep)) sel.value = keep;
  }

  function parseEpoch(s){ const t = Date.parse((s||'')+' UTC') || Date.parse(s||'') || 0; return t; }

  async function refreshFromMirror(){
    const sel = $("#logSelect"); if (!sel || !sel.value) { $("#histBody").innerHTML=''; $("#histMeta").textContent='Showing 0 entries'; return; }
    const lines = parseInt($("#lines")?.value || "300", 10) || 300;
    const q = ($("#logQ")?.value || '').trim();
    const reSrc = ($("#logRe")?.value || '').trim().replace(/^\/|\/[a-z]*$/g,'');
    const ci = !!($("#logCi")?.checked);
    let re = null; if (reSrc) { try { re = new RegExp(reSrc, ci ? 'i' : ''); } catch(e){ re=null; } }

    let d = null;
    try { d = await post('read_mirror_log', {file: sel.value, lines}); } catch(e){ console.error('read_mirror_log failed', e); d = {ok:false, rows:[]}; }
    const rows = (d && d.ok && d.rows) ? d.rows : [];

    const match = (r)=>{
      const s = (r.time||'')+' '+(r.category||'')+' '+(r.message||'')+' '+(r.user||'')+' '+(r.ip||'');
      if (re) return re.test(s);
      if (q) return s.toLowerCase().includes(q.toLowerCase());
      return true;
    };
    const filtered = rows.filter(match);

    const tbody = $("#histBody"); if (!tbody) return;
    tbody.innerHTML = "";
    const hilite = (txt)=>{
      let s = esc(txt||'');
      if (re) try { s = s.replace(re, (m)=>'<mark>'+m+'</mark>'); } catch(e){}
      else if (q){ const rx = new RegExp(rxEscape(q), 'gi'); s = s.replace(rx, (m)=>'<mark>'+m+'</mark>'); }
      return s;
    };
    filtered.forEach(r=>{
      const tr = document.createElement('tr');
      const tdTime = document.createElement('td'); tdTime.dataset.value = String(parseEpoch(r.time)); tdTime.textContent = r.time || ''; tr.appendChild(tdTime);
      const tdCat = document.createElement('td'); const badge = document.createElement('span'); badge.className='badge '+(r.category||'info'); badge.textContent=r.category||'info'; tdCat.appendChild(badge); tr.appendChild(tdCat);
      const tdMsg = document.createElement('td'); tdMsg.innerHTML = hilite(r.message||''); tr.appendChild(tdMsg);
      tr.appendChild(document.createElement('td')).textContent = r.user || '';
      (function(){ const td = document.createElement('td'); 
      if (r.ip && typeof r.ip === 'string' && r.ip.trim() !== '') { 
        const a = document.createElement('a'); 
        a.href = 'https://www.whatismyip.com/ip/' + encodeURIComponent(r.ip.trim()); 
        a.target = '_blank'; 
        a.rel = 'noopener noreferrer'; 
        a.textContent = r.ip.trim(); 
        a.title = 'Lookup ' + r.ip.trim() + ' on whatismyip.com'; 
        td.appendChild(a); 
      } else { td.textContent = ''; } 
      tr.appendChild(td); })();
      tbody.appendChild(tr);
    });
    $("#histMeta").textContent = "Showing " + filtered.length + " of " + rows.length + " entries";

    const dl = $("#btnDownload");
    if (dl){
      const linesOut = filtered.map(r => (r.time||'')+' ['+(r.category||'')+'] '+(r.message||'')+(r.user?(' user='+r.user):'')+(r.ip?(' ip='+r.ip):''));
      const blob = new Blob([linesOut.join('\n')+'\n'], {type:'text/plain'});
      const url = URL.createObjectURL(blob);
      dl.href = url; dl.download = (sel.value||'log') + '.txt';
      setTimeout(()=>URL.revokeObjectURL(url), 15000);
    }
  }

  document.addEventListener('DOMContentLoaded', async function(){
    await buildPulldownFromMirror();
    $("#btnRefresh")?.addEventListener('click', function(e){ e.preventDefault(); refreshFromMirror(); });
    $("#logSelect")?.addEventListener('change', refreshFromMirror);
    $("#lines")?.addEventListener('change', refreshFromMirror);
    $("#logQ")?.addEventListener('change', refreshFromMirror);
    $("#logRe")?.addEventListener('change', refreshFromMirror);
    $("#logCi")?.addEventListener('change', refreshFromMirror);
    refreshFromMirror();
  });
})();
</script>



  <script defer src="<?= h(project_url('/assets/js/utils/sortable-table.js')) ?>"></script>

<?php include __DIR__.'/includes/foot.php'; ?>
