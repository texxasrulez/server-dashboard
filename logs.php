<?php
require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/logger.php';
require_once __DIR__.'/lib/PrivilegedLogs.php';
require_once __DIR__.'/lib/Logs/LogsPageSupport.php';
require_login();

$PAGE_TITLE = 'Logs';
$PAGE_CSS   = 'assets/css/pages/logs.css';

/* ====================== Paths & Setup ====================== */
$logsSetup = \App\Logs\LogsPageSupport::setup(__DIR__);
$LOG_SRC   = $logsSetup['log_src'];
$STATE_DIR = $logsSetup['state_dir'];
$LOG_DEST  = $logsSetup['log_dest'];
$LOG_CFG   = $logsSetup['log_cfg'];

/* ====================== Helpers ====================== */
function mirror_activity_log($status, $action, $name, $note = '')
{
    \App\Logs\LogsPageSupport::mirrorActivityLog(__DIR__, (string)$status, (string)$action, (string)$name, (string)$note);
}

// Accept relative under /var/log or absolute; allow .log, .log.N, .log.gz; must live under allowed roots
function resolve_candidate_path($input, $roots)
{
    return \App\Logs\LogsPageSupport::resolveCandidatePath(strval($input), is_array($roots) ? $roots : []);
}

function copy_log_file($srcFile, $destDir)
{
    global $LOG_SRC, $LOG_DEST;
    return \App\Logs\LogsPageSupport::copyLogFile(__DIR__, (string)$srcFile, (string)$destDir, (string)$LOG_SRC, (string)$LOG_DEST);
}

function mirror_logs_dir($srcDir, $destDir)
{
    global $LOG_SRC, $LOG_DEST;
    \App\Logs\LogsPageSupport::mirrorLogsDir(__DIR__, (string)$srcDir, (string)$destDir, (string)$LOG_SRC, (string)$LOG_DEST);
}

function read_log_cfg($path)
{
    return \App\Logs\LogsPageSupport::readLogConfig((string)$path);
}
function write_log_cfg($path, $arr)
{
    \App\Logs\LogsPageSupport::writeLogConfig((string)$path, is_array($arr) ? $arr : []);
}

/* ------ time helpers ------ */
function _fmt_utc_from_dt($dt)
{
    if (!($dt instanceof DateTime)) {
        return '';
    }
    try {
        $dt->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return '';
    }
    return $dt->format('n/j/Y, g:i:s A');
}
function _fmt_utc_from_ts($ts)
{
    if (!$ts) {
        return '';
    }
    return gmdate('n/j/Y, g:i:s A', intval($ts));
}

/* ------ smarter parsing ------ */
function classify_category($prog, $msg)
{
    $p = strtolower(strval($prog));
    $m = strtolower(strval($msg));

    if (preg_match('/(fail(ed)?|denied|forbidden|unauthori[sz]ed|invalid user|ban|blocked)/', $m)
        || preg_match('/^(sshd|sudo|fail2ban|security|auth|polkit)/', $p)) {
        return 'security';
    }

    if (preg_match('/(error|warn|warning|critical|panic|oops|segfault)/', $m)) {
        return 'warning';
    }

    if (preg_match('/^(mysql|mysqld|mariadb|mariadbd|postgres|postgresql|innodb)/', $p)
        || preg_match('/\b(database|sql|query)\b/', $m)) {
        return 'database';
    }

    if (preg_match('/^(nginx|apache|httpd|caddy|haproxy|php-fpm)/', $p)
        || preg_match('/\b(http|tls|ssl|proxy|upstream|socket|listen|port)\b/', $m)) {
        return 'network';
    }

    if (preg_match('/\b(latency|timeout|slow|slower|performance|throughput)\b/', $m)) {
        return 'performance';
    }

    if (preg_match('/^(cron|crond|systemd|kernel|dpkg|apt|rsyslog)/', $p)) {
        return 'info';
    }

    return 'info';
}


function parse_log_line($line)
{
    $ln = trim(strval($line));
    if ($ln === '') {
        return array('time' => '','ts' => 0,'category' => 'info','message' => '','user' => '','ip' => '');
    }

    $time = '';
    $prog = '';
    $msg = $ln;
    $user = '';
    $ip = '';
    $ts = 0;
    $lvl = '';

    if (preg_match('/^Time=("[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\S+)\s+Category=("[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\S+)\s+Message=("[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\S+)(?:\s+User=("[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\S+))?(?:\s+IP=("[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\S+))?(?:\s+Context=(\{.*\}|\[.*\]))?(?:\s+PID=\d+)?$/', $ln, $m)) {
        $decode = function ($raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return '';
            }
            $decoded = json_decode($raw, true);
            if (is_string($decoded) || is_numeric($decoded)) {
                return (string)$decoded;
            }
            return trim($raw, "\"'");
        };
        $time = $decode($m[1] ?? '');
        $cat = $decode($m[2] ?? 'info');
        $msg = $decode($m[3] ?? '');
        $user = $decode($m[4] ?? '');
        $ip = $decode($m[5] ?? '');
        if ($time !== '') {
            try {
                $dt = new DateTime($time);
                $ts = $dt->getTimestamp();
                $time = _fmt_utc_from_ts($ts);
            } catch (Exception $e) {
            }
        }
        return array('time' => $time, 'ts' => $ts, 'category' => $cat !== '' ? strtolower($cat) : 'info', 'message' => $msg, 'user' => $user, 'ip' => $ip);
    }

    // Apache/Nginx access (CLF/combined): host ident authuser [date] "req" status bytes
    if (preg_match('/^(\S+)\s+\S+\s+(\S+)\s+\[([^\]]+)\]\s+"([^"]*)"\s+(\d{3})\s+(\S+)/', $ln, $m)) {
        $host = $m[1];
        $auth = $m[2];
        $time = $m[3];
        $prog = 'httpd';
        $msg  = $m[4] . ' ' . $m[5] . ' ' . $m[6];
        $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $time, new DateTimeZone('UTC'));
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
        if ($auth !== '-' && $auth !== '') {
            $user = $auth;
        }
        if ($ip === '' && preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host)) {
            $ip = $host;
        }
    } elseif (preg_match('/^(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})\s+\[([a-z]+)\]\s+\d+#[0-9]+:/i', $ln, $m)) {
        $prog = 'nginx';
        $lvl  = strtolower($m[3]);
        $msg  = preg_replace('/^[^:]+:\s*/', '', $ln);
        $tz = new DateTimeZone(@date_default_timezone_get());
        $dt = DateTime::createFromFormat('Y/m/d H:i:s', $m[1].' '.$m[2], $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
    } elseif (preg_match('/^\[([A-Za-z]{3}\s+[A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}(?:\.\d+)?\s+\d{4})\]\s+\[([^\]]+)\]\s*(.*)$/', $ln, $m)) {
        $prog = 'apache';
        $lvl  = strtolower($m[2]);
        $rawt = preg_replace('/\.\d+/', '', $m[1]);
        $tz = new DateTimeZone(@date_default_timezone_get());
        $dt = DateTime::createFromFormat('D M j H:i:s Y', $rawt, $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
        $msg = $m[3];
    } elseif (preg_match('/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})\s+\S+\s+([A-Za-z0-9._-]+)(?:\[\d+\])?:\s+(.*)$/', $ln, $m)) {
        $mon = $m[1];
        $day = intval($m[2]);
        $hms = $m[3];
        $prog = $m[4];
        $msg = $m[5];
        $tz = new DateTimeZone(@date_default_timezone_get());
        $year = intval(date('Y'));
        $dt = DateTime::createFromFormat('Y M j H:i:s', sprintf('%04d %s %d %s', $year, $mon, $day, $hms), $tz);
        if ($dt) {
            $now = new DateTime('now', $tz);
            if ($dt->getTimestamp() - $now->getTimestamp() > 86400 * 7) {
                $dt->modify('-1 year');
            }
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+(.*)$/', $ln, $m)) {
        $prog = 'dpkg';
        $msg  = $m[3];
        $tz = new DateTimeZone(@date_default_timezone_get());
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1] . ' ' . $m[2], $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})?)\s+(.*)$/', $ln, $m)) {
        $msg  = $m[2];
        try {
            $dt = new DateTime($m[1]);
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        } catch (Exception $e) {
            $time = $m[1];
        }
    } elseif (preg_match('/^\[([0-9]{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2}\s+[+\-]?\d{4})\]\s*(.*)$/', $ln, $m)) {
        $msg  = $m[2];
        $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1], new DateTimeZone('UTC'));
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
    } elseif (preg_match('/^(\d{4}\/\d{2}\/\d{2})\s+(\d{2}:\d{2}:\d{2})\b(.*)$/', $ln, $m)) {
        $tz = new DateTimeZone(@date_default_timezone_get());
        $dt = DateTime::createFromFormat('Y/m/d H:i:s', $m[1].' '.$m[2], $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
        $msg = trim($m[3]);
    } elseif (preg_match('/^(\d{4}\-\d{2}\-\d{2})\s+(\d{2}:\d{2}:\d{2})\b(.*)$/', $ln, $m)) {
        $tz = new DateTimeZone(@date_default_timezone_get());
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $m[1].' '.$m[2], $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $time = _fmt_utc_from_ts($ts);
        }
        $msg = trim($m[3]);
    }

    // ---- User extraction (expanded) ----
    if ($user === '' && preg_match('/\buser(name)?=([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[2];
    }
    if ($user === '' && preg_match('/\bby\s+user\s+([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\bAccepted (?:password|publickey|keyboard-interactive) for ([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\bFailed password for (?:invalid user\s+)?([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\bsession opened for user\s+([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/^sudo:\s*([a-z0-9._-]+)\s*:/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\bUSER\s*=\s*([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\bInvalid\s+user\s+([a-z0-9._-]+)/i', $msg, $u)) {
        $user = $u[1];
    }
    if ($user === '' && preg_match('/\b(www-data|root|system|cron|appuser|admin)\b/i', $msg, $u)) {
        $user = strtolower($u[1]);
    }

    // ---- IP fallback ----
    if ($ip === '' && preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $ln, $i)) {
        $ip = $i[1];
    }
    // IPv6 fallback
    if ($ip === '' && preg_match('/\b([0-9a-fA-F:]{2,}\b)/', $ln, $i)) {
        $ip = $i[1];
    }

    $cat = $lvl !== '' ? ($lvl === 'error' ? 'warning' : ($lvl === 'warn' ? 'warning' : $lvl)) : classify_category($prog, $msg);

    return array('time' => $time, 'ts' => $ts, 'category' => $cat, 'message' => $msg, 'user' => $user, 'ip' => $ip);
}


$LOG_ALLOWED_ROOTS = array($LOG_SRC, $LOG_DEST);
$CAN_ACCESS_LIVE_LOGS = PrivilegedLogs::canAccessLive();
$PRIVILEGED_LOG_DEFS = $CAN_ACCESS_LIVE_LOGS ? PrivilegedLogs::publicDefinitions() : array();

/* ====================== AJAX (JSON) ====================== */
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!csrf_check_request()) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'CSRF validation failed'));
        exit;
    }
    $act = $_POST['action'];

    if ($act === 'add_log') {
        $name = trim(strval(isset($_POST['name']) ? $_POST['name'] : ''));
        list($srcReal, $srcRoot) = resolve_candidate_path($name, $LOG_ALLOWED_ROOTS);
        if (!$srcReal || !$srcRoot || !is_file($srcReal) || !is_readable($srcReal)) {
            http_response_code(400);
            echo json_encode(array('ok' => false,'error' => 'Invalid or unreadable log path'));
            exit;
        }
        $ok = copy_log_file($srcReal, $LOG_DEST);
        echo json_encode(array('ok' => (bool)$ok,'file' => basename($srcReal)));
        exit;
    }

    if ($act === 'list_log_configs') {
        echo json_encode(array('ok' => true, 'items' => read_log_cfg($LOG_CFG)));
        exit;
    }
    if ($act === 'create_log_config') {
        $label = trim(strval(isset($_POST['label']) ? $_POST['label'] : ''));
        $path  = trim(strval(isset($_POST['path']) ? $_POST['path'] : ''));
        if ($label === '' || $path === '') {
            echo json_encode(array('ok' => false, 'error' => 'Label and path required'));
            exit;
        }
        list($real, $root) = resolve_candidate_path($path, $LOG_ALLOWED_ROOTS);
        if (!$real || !$root || !is_file($real) || !is_readable($real)) {
            echo json_encode(array('ok' => false, 'error' => 'Invalid or unreadable log path'));
            exit;
        }
        $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label)) . '-' . substr(md5($path), 0, 6);
        $items = read_log_cfg($LOG_CFG);
        $items[] = array('id' => $id,'label' => $label,'path' => trim($path, '/'));
        write_log_cfg($LOG_CFG, $items);
        copy_log_file($real, $LOG_DEST);
        echo json_encode(array('ok' => true, 'item' => array('id' => $id,'label' => $label,'path' => trim($path, '/'))));
        exit;
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
                if ($label !== '') {
                    $it['label'] = $label;
                }
                if ($path  !== '') {
                    $it['path']  = trim($path, '/');
                }
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo json_encode(array('ok' => false,'error' => 'Not found'));
            exit;
        }
        write_log_cfg($LOG_CFG, $items);
        if ($path !== '') {
            list($real, $root) = resolve_candidate_path($path, $LOG_ALLOWED_ROOTS);
            if ($real && $root && is_file($real) && is_readable($real)) {
                copy_log_file($real, $LOG_DEST);
            }
        }
        echo json_encode(array('ok' => true));
        exit;
    }
    if ($act === 'delete_log_config') {
        $id = strval(isset($_POST['id']) ? $_POST['id'] : '');
        $items = read_log_cfg($LOG_CFG);
        $new = array();
        $found = false;
        foreach ($items as $it) {
            if ((isset($it['id']) ? $it['id'] : '') === $id) {
                $found = true;
                continue;
            } $new[] = $it;
        }
        if (!$found) {
            echo json_encode(array('ok' => false,'error' => 'Not found'));
            exit;
        }
        write_log_cfg($LOG_CFG, $new);
        echo json_encode(array('ok' => true));
        exit;
    }

    if ($act === 'probe_log_path') {
        $p = strval(isset($_POST['path']) ? $_POST['path'] : '');
        list($real, $root) = resolve_candidate_path($p, $LOG_ALLOWED_ROOTS);
        if (!$real || !is_file($real) || !is_readable($real)) {
            echo json_encode(array('ok' => false,'error' => 'Invalid path'));
            exit;
        }
        $st = @stat($real);
        $size = (isset($st['size']) ? $st['size'] : null);
        $mtime = (isset($st['mtime']) ? $st['mtime'] : null);
        echo json_encode(array('ok' => true, 'real' => $real, 'size' => $size, 'mtime' => $mtime));
        exit;
    }
    if ($act === 'list_log_candidates') {
        $cands = array();
        $dirs = array_filter(array('/var/log','/var/log/apache2','/var/log/httpd','/var/log/nginx','/var/log/mysql','/var/log/mariadb','/var/log/postgresql','/var/log/hestia'), 'is_dir');
        foreach ($dirs as $d) {
            foreach (glob($d.'/*') as $f) {
                if (preg_match('/\.log(?:\.\d+)?(?:\.gz)?$/i', basename($f))) {
                    $cands[] = ltrim(str_replace('/var/log', '', $f), '/');
                }
            }
        }
        $cands = array_values(array_unique($cands));
        sort($cands, SORT_NATURAL | SORT_FLAG_CASE);
        echo json_encode(array('ok' => true,'items' => array_slice($cands, 0, 300)));
        exit;
    }

    /* ===================== Mirror manager ===================== */
    if ($act === 'list_mirror_entries') {
        $items = array();
        foreach (glob($LOG_DEST.'/*') as $f) {
            $bn = basename($f);
            $type = is_dir($f) ? 'dir'
                  : (preg_match('/\.zip$/i', $bn) ? 'zip'
                  : ((preg_match('/\.log(\.\d+)?(\.gz)?$/i', $bn) || preg_match('/\.gz$/i', $bn)) ? 'log' : 'file'));
            $items[] = array('name' => $bn,'size' => @filesize($f) ? @filesize($f) : 0,'mtime' => @filemtime($f) ? @filemtime($f) : 0,'type' => $type);
        }
        usort($items, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        echo json_encode(array('ok' => true,'items' => $items));
        exit;
    }

    if ($act === 'delete_mirror') {
        $name = basename(strval(isset($_POST['name']) ? $_POST['name'] : ''));
        if ($name === '') {
            echo json_encode(array('ok' => false,'error' => 'No name'));
            exit;
        }
        $path = $LOG_DEST . '/' . $name;
        $real = @realpath($path);
        $root = @realpath($LOG_DEST);
        if (!$real || !$root || strpos($real, $root) !== 0) {
            echo json_encode(array('ok' => false,'error' => 'Bad path'));
            exit;
        }
        $ok = false;
        if (is_file($real)) {
            $ok = @unlink($real);
        } elseif (is_dir($real) && preg_match('/\bunzipped\b/', $real)) {
            $ok = true;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $p) {
                $ok = $ok && ($p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()));
            }
            $ok = $ok && @rmdir($real);
        }
        mirror_activity_log($ok ? 'ok' : 'fail', 'delete', $name);
        echo json_encode(array('ok' => (bool)$ok));
        exit;
    }

    if ($act === 'unzip_mirror') {
        $name = basename(strval(isset($_POST['name']) ? $_POST['name'] : ''));
        if (!preg_match('/\.zip$/i', $name)) {
            echo json_encode(array('ok' => false,'error' => 'Not a zip'));
            exit;
        }
        $zipPath = $LOG_DEST . '/' . $name;
        $zipReal = @realpath($zipPath);
        $root = @realpath($LOG_DEST);
        if (!$zipReal || !$root || strpos($zipReal, $root) !== 0 || !is_file($zipReal)) {
            echo json_encode(array('ok' => false,'error' => 'Zip not found'));
            exit;
        }
        $dest = $LOG_DEST . '/unzipped/' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($name, '.zip')) . '_' . date('Ymd_His');
        @mkdir($dest, 0775, true);
        $ok = false;
        $note = '';
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zipReal) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (substr($entry, -1) === '/') {
                        @mkdir($dest.'/'.trim($entry, '/'), 0775, true);
                        continue;
                    }
                    $target = $dest . '/' . $entry;
                    $parent = dirname($target);
                    $realParent = @realpath($parent);
                    if (!$realParent) {
                        $realParent = $parent;
                    }
                    if (strpos($realParent, $dest) !== 0) {
                        continue;
                    }
                    $contents = $zip->getFromIndex($i);
                    if ($contents !== false) {
                        @mkdir($parent, 0775, true);
                        @file_put_contents($target, $contents);
                        @chmod($target, 0644);
                    }
                }
                $zip->close();
                $ok = true;
            } else {
                $note = 'open-fail';
            }
        } else {
            $note = 'no-ziparchive';
        }
        mirror_activity_log($ok ? 'ok' : 'fail', 'unzip', $name, $note);
        echo json_encode(array('ok' => (bool)$ok, 'dest' => basename($dest)));
        exit;
    }

    /* ---- Pulldown source (recursive) – *.log, *.log.N, *.log.gz ---- */
    if ($act === 'list_mirror_logs') {
        if (!is_dir($LOG_DEST)) {
            @mkdir($LOG_DEST, 0775, true);
            echo json_encode(array('ok' => true,'items' => array()));
            exit;
        }
        try {
            $items = array();
            $root = realpath($LOG_DEST);
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($LOG_DEST, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isDir()) {
                    continue;
                }
                $bn = $f->getBasename();
                if (!preg_match('/\.log(\.\d+|\.gz)?$/i', $bn)) {
                    continue;
                }
                $rel = preg_replace('~^[\\\\/]+~', '', str_replace($root, '', realpath($f->getPathname())));
                $items[] = $rel;
            }
            sort($items, SORT_NATURAL | SORT_FLAG_CASE);
            echo json_encode(array('ok' => true, 'items' => $items));
            exit;
        } catch (Throwable $e) {
            echo json_encode(array('ok' => false,'error' => 'scan-failed'));
            exit;
        }
    }

    /* ---- Read last N lines from a mirrored log (supports .gz) ---- */
    if ($act === 'read_mirror_log') {
        $file  = strval(isset($_POST['file']) ? $_POST['file'] : '');
        $lines = intval(isset($_POST['lines']) ? $_POST['lines'] : 300);
        if ($lines < 10) {
            $lines = 10;
        } if ($lines > 2000) {
            $lines = 2000;
        }

        if ($file === '' || preg_match('/\.\./', $file)) {
            echo json_encode(array('ok' => false, 'error' => 'Bad file'));
            exit;
        }

        $path = $LOG_DEST . '/' . ltrim($file, '/');
        $real = @realpath($path);
        $root = @realpath($LOG_DEST);
        if (!$real || !$root || strpos($real, $root) !== 0 || !is_file($real) || !is_readable($real)) {
            echo json_encode(array('ok' => false, 'error' => 'Not found'));
            exit;
        }

        $lines_buf = array();
        if (preg_match('/\.gz$/i', $real)) {
            if (!function_exists('gzopen')) {
                echo json_encode(array('ok' => false, 'error' => 'gz not supported'));
                exit;
            }
            $gz = @gzopen($real, 'rb');
            if (!$gz) {
                echo json_encode(array('ok' => false, 'error' => 'gzopen failed'));
                exit;
            }
            while (!gzeof($gz)) {
                $ln = gzgets($gz, 8192);
                if ($ln === false) {
                    break;
                }
                $lines_buf[] = rtrim($ln, "\r\n");
                if (count($lines_buf) > $lines) {
                    array_shift($lines_buf);
                }
            }
            gzclose($gz);
        } else {
            $fh = @fopen($real, 'rb');
            if (!$fh) {
                echo json_encode(array('ok' => false, 'error' => 'Open failed'));
                exit;
            }
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
            if (count($lines_buf) > $lines) {
                $lines_buf = array_slice($lines_buf, -$lines);
            }
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
        echo json_encode(array('ok' => true, 'rows' => $rows));
        exit;
    }

    if ($act === 'list_privileged_logs') {
        if (!PrivilegedLogs::canAccessLive()) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'Admin privileges are required for live privileged logs.'));
            exit;
        }
        echo json_encode(array('ok' => true, 'items' => PrivilegedLogs::publicDefinitions()));
        exit;
    }

    if ($act === 'read_privileged_log') {
        $key = strtolower(trim(strval(isset($_POST['key']) ? $_POST['key'] : '')));
        $requestedLines = isset($_POST['lines']) ? intval($_POST['lines']) : null;
        $search = strval(isset($_POST['search']) ? $_POST['search'] : '');
        $result = PrivilegedLogs::run($key, $requestedLines, $search);
        if (empty($result['ok'])) {
            $errorText = strval($result['error'] ?? '');
            http_response_code($errorText === 'Unknown privileged log key.' ? 400 : 403);
        }
        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(array('ok' => false,'error' => 'Unknown action'));
    exit;
}

/* ====================== Mirror on Page Load ====================== */
mirror_logs_dir($LOG_SRC, $LOG_DEST);

/* ====================== HTML ====================== */
include __DIR__.'/includes/head.php';
?>
<div class="card">
  <div class="card logs-page-card">
    <div class="logs-toolbar">
      <div class="logs-toolbar-main">
        <div style="font-weight:700">Logs</div>
        <div class="logs-mode-switch" role="tablist" aria-label="Logs mode">
          <button class="logs-mode-btn active" id="logsModeCopied" type="button" data-mode="copied" aria-pressed="true">Copied Logs</button>
          <button class="logs-mode-btn" id="logsModeLive" type="button" data-mode="live" aria-pressed="false" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled title="Admin privileges required"' ?>>Live Privileged Logs</button>
        </div>
      </div>
      <div class="logs-mode-note muted" id="logsModeNote">Copied logs are read from the existing mirror under <code>state/logs_mirror</code>.</div>
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
          <input id="logPath" list="logCommonList" placeholder="Path under /var/log (e.g., nginx/error.log)" required>
          <button class="btn" id="logSave"><span data-i18n="common.save">Save</span></button>
          <button class="btn" id="logTest" type="button">Test</button>
          <button class="btn" id="logCommon" type="button">Common</button>
          <span class="muted" id="logStatus" style="margin-left:.5rem"></span>
        </form>
        <datalist id="logCommonList"></datalist>
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
            <button class="btn" id="mirrorRefresh" type="button"><span data-i18n="alerts.refresh">Refresh</span></button>
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

  <section id="copiedLogsPanel">
    <div class="card">
      <div class="row" style="justify-content:space-between">
        <div style="font-weight:700">Copied Logs</div>
        <div class="row logs-control-row">
          <select id="logSelect" class="log-select"></select>
          <input id="logQ" placeholder="contains…" style="min-width:200px">
          <input id="lines" type="number" min="50" step="50" value="300" title="Lines">
          <label style="display:flex;align-items:center;gap:.35rem;margin-left:.35rem">
            <input id="logCi" type="checkbox"> <span class="muted">case-insensitive</span>
          </label>
          <input id="logRe" placeholder="regex …" style="min-width:160px">
          <button class="btn" id="btnRefresh"><span data-i18n="alerts.refresh">Refresh</span></button>
          <button class="btn" id="btnAddLog">Add Log</button>
          <a class="btn" id="btnDownload" href="#" download>Download</a>
          <button class="btn" id="btnExportCsv">Export CSV</button>
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
    </div>
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
  </section>

  <section id="liveLogsPanel" hidden>
    <div class="card live-log-card">
      <div class="row" style="justify-content:space-between;align-items:flex-start;gap:1rem">
        <div>
          <div style="font-weight:700">Live Privileged Logs</div>
          <div class="muted live-log-note">These logs are read on demand from protected sources through the sudo bridge.</div>
        </div>
        <?php if (!$CAN_ACCESS_LIVE_LOGS) : ?>
          <div class="muted">Admin privileges are required for this mode.</div>
        <?php endif; ?>
      </div>

      <div class="logs-live-controls">
        <label>
          <span class="muted">Source</span>
          <select id="privilegedLogSelect" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled' ?>>
            <?php foreach ($PRIVILEGED_LOG_DEFS as $def) : ?>
              <option value="<?= h($def['key']) ?>" data-allow-search="<?= !empty($def['allow_search']) ? '1' : '0' ?>" data-default-lines="<?= h((string)$def['default_lines']) ?>" data-max-lines="<?= h((string)$def['max_lines']) ?>"><?= h($def['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span class="muted">Lines</span>
          <input id="privilegedLines" type="number" min="25" step="25" value="<?= $CAN_ACCESS_LIVE_LOGS && !empty($PRIVILEGED_LOG_DEFS) ? h((string)$PRIVILEGED_LOG_DEFS[0]['default_lines']) : '150' ?>" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled' ?>>
        </label>
        <label class="logs-live-search">
          <span class="muted">Literal Search</span>
          <input id="privilegedSearch" type="text" maxlength="160" placeholder="optional exact text" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled' ?>>
        </label>
        <div class="logs-live-actions">
          <button class="btn" id="privilegedRefresh" type="button" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled' ?>><span data-i18n="alerts.refresh">Refresh</span></button>
          <button class="btn" id="privilegedCopy" type="button" <?= $CAN_ACCESS_LIVE_LOGS ? '' : 'disabled' ?>>Copy Output</button>
        </div>
      </div>

      <div class="muted" id="privilegedMeta">Select a protected log source to read its latest lines.</div>
      <div class="muted" id="privilegedError" hidden></div>
      <pre id="privilegedOutput" class="live-log-output" aria-live="polite"></pre>
    </div>
  </section>
</div>

<script defer src="assets/js/logs.js?v=<?php echo h(BUILD); ?>"></script>
<script>
(function(){
  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function esc(s){ return String(s).replace(/[&<>]/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }
  function rxEscape(str){ return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const modeStorageKey = 'logs.page.mode.v1';
  const canAccessLive = document.body?.dataset?.admin === '1';
  const modeNoteCopied = 'Copied logs are read from the existing mirror under state/logs_mirror.';
  const modeNoteLive = 'Live privileged logs are fetched on demand from an allowlisted protected source through the sudo bridge.';
  let copiedAutoTimer = null;

  async function post(action, data){
    const fd = new FormData();
    fd.append('action', action);
    if (csrfToken) fd.append('_csrf', csrfToken);
    for (const k in (data||{})) if (Object.prototype.hasOwnProperty.call(data,k)) fd.append(k, data[k]);
    const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials:'same-origin'});
    return res.json();
  }

  function setMode(mode){
    const liveAllowed = canAccessLive && !$('#logsModeLive')?.disabled;
    const nextMode = (mode === 'live' && liveAllowed) ? 'live' : 'copied';
    $('#copiedLogsPanel').hidden = nextMode !== 'copied';
    $('#liveLogsPanel').hidden = nextMode !== 'live';
    $('#logsModeCopied')?.classList.toggle('active', nextMode === 'copied');
    $('#logsModeLive')?.classList.toggle('active', nextMode === 'live');
    $('#logsModeCopied')?.setAttribute('aria-pressed', nextMode === 'copied' ? 'true' : 'false');
    $('#logsModeLive')?.setAttribute('aria-pressed', nextMode === 'live' ? 'true' : 'false');
    const note = $('#logsModeNote');
    if (note) note.textContent = nextMode === 'live' ? modeNoteLive : modeNoteCopied;
    try { localStorage.setItem(modeStorageKey, nextMode); } catch(e) {}
    if (nextMode === 'live' && liveAllowed) refreshPrivilegedLog();
  }

  function selectedPrivilegedOption(){
    const sel = $('#privilegedLogSelect');
    if (!sel) return null;
    return sel.options[sel.selectedIndex] || null;
  }

  function syncPrivilegedControls(){
    const opt = selectedPrivilegedOption();
    const linesInput = $('#privilegedLines');
    const searchInput = $('#privilegedSearch');
    if (!opt || !linesInput) return;
    const defaultLines = parseInt(opt.dataset.defaultLines || '150', 10) || 150;
    const maxLines = parseInt(opt.dataset.maxLines || '300', 10) || 300;
    let current = parseInt(linesInput.value || String(defaultLines), 10) || defaultLines;
    if (current < 25) current = 25;
    if (current > maxLines) current = maxLines;
    linesInput.min = '25';
    linesInput.max = String(maxLines);
    linesInput.value = String(current);
    if (searchInput) {
      const allowed = opt.dataset.allowSearch === '1';
      searchInput.disabled = !allowed;
      if (!allowed) searchInput.value = '';
    }
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

  function setStatus(text, isError){
    const node = $('#logStatus');
    if (!node) return;
    node.textContent = text || '';
    node.style.color = isError ? '#ffb7b7' : '';
  }

  function closeLogModal(){
    const modal = $('#logModal');
    if (modal) modal.hidden = true;
  }

  function openLogModal(){
    const modal = $('#logModal');
    if (modal) modal.hidden = false;
  }

  function resetLogForm(){
    $('#logId').value = '';
    $('#logLabel').value = '';
    $('#logPath').value = '';
    setStatus('', false);
  }

  function fillLogForm(item){
    $('#logId').value = item.id || '';
    $('#logLabel').value = item.label || '';
    $('#logPath').value = item.path || '';
    setStatus('Loaded saved log entry.', false);
    openLogModal();
  }

  async function loadCommonCandidates(){
    let data = null;
    try { data = await post('list_log_candidates', {}); } catch(e) { data = {ok:false, items:[]}; }
    const list = $('#logCommonList');
    if (!list) return;
    list.innerHTML = '';
    (data.items || []).forEach(function(path){
      const option = document.createElement('option');
      option.value = path;
      list.appendChild(option);
    });
    setStatus((data.items || []).length ? 'Loaded common log paths.' : 'No common log candidates found.', false);
  }

  async function renderLogConfigs(){
    let data = null;
    try { data = await post('list_log_configs', {}); } catch(e) { data = {ok:false, items:[]}; }
    const tbody = $('#logCfgTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    (data.items || []).forEach(function(item){
      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + esc(item.label || '') + '</td><td><code>' + esc(item.path || '') + '</code></td><td></td>';
      const actions = tr.lastElementChild;
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', function(){ fillLogForm(item); });
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', async function(){
        let result = null;
        try { result = await post('delete_log_config', {id: item.id}); } catch(e) { result = {ok:false, error:'Delete failed'}; }
        setStatus(result && result.ok ? 'Saved log entry deleted.' : ((result && result.error) || 'Delete failed.'), !(result && result.ok));
        if (result && result.ok) {
          await renderLogConfigs();
          await buildPulldownFromMirror();
          await refreshFromMirror();
        }
      });
      actions.appendChild(editBtn);
      actions.appendChild(document.createTextNode(' '));
      actions.appendChild(deleteBtn);
      tbody.appendChild(tr);
    });
  }

  async function renderMirrorEntries(){
    let data = null;
    try { data = await post('list_mirror_entries', {}); } catch(e) { data = {ok:false, items:[]}; }
    const tbody = $('#mirrorTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    (data.items || []).forEach(function(item){
      const tr = document.createElement('tr');
      const updated = item.mtime ? new Date(item.mtime * 1000).toLocaleString() : '';
      tr.innerHTML = '<td>' + esc(item.name || '') + '</td><td>' + esc(String(item.size || 0)) + '</td><td>' + esc(updated) + '</td><td>' + esc(item.type || '') + '</td><td></td>';
      const actions = tr.lastElementChild;
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn';
      deleteBtn.textContent = 'Delete';
      deleteBtn.addEventListener('click', async function(){
        let result = null;
        try { result = await post('delete_mirror', {name: item.name}); } catch(e) { result = {ok:false, error:'Delete failed'}; }
        setStatus(result && result.ok ? 'Mirror file deleted.' : ((result && result.error) || 'Delete failed.'), !(result && result.ok));
        if (result && result.ok) {
          await renderMirrorEntries();
          await buildPulldownFromMirror();
          await refreshFromMirror();
        }
      });
      actions.appendChild(deleteBtn);
      if ((item.type || '') === 'zip') {
        actions.appendChild(document.createTextNode(' '));
        const unzipBtn = document.createElement('button');
        unzipBtn.type = 'button';
        unzipBtn.className = 'btn';
        unzipBtn.textContent = 'Unzip';
        unzipBtn.addEventListener('click', async function(){
          let result = null;
          try { result = await post('unzip_mirror', {name: item.name}); } catch(e) { result = {ok:false, error:'Unzip failed'}; }
          setStatus(result && result.ok ? 'Zip extracted into mirror workspace.' : ((result && result.error) || 'Unzip failed.'), !(result && result.ok));
          if (result && result.ok) {
            await renderMirrorEntries();
            await buildPulldownFromMirror();
          }
        });
        actions.appendChild(unzipBtn);
      }
      tbody.appendChild(tr);
    });
  }

  async function saveLogConfig(){
    const id = ($('#logId')?.value || '').trim();
    const label = ($('#logLabel')?.value || '').trim();
    const path = ($('#logPath')?.value || '').trim();
    if (!label || !path) {
      setStatus('Label and path are required.', true);
      return;
    }
    const action = id ? 'update_log_config' : 'create_log_config';
    const payload = id ? {id, label, path} : {label, path};
    let result = null;
    try { result = await post(action, payload); } catch(e) { result = {ok:false, error:'Save failed'}; }
    setStatus(result && result.ok ? 'Saved log entry updated.' : ((result && result.error) || 'Save failed.'), !(result && result.ok));
    if (result && result.ok) {
      if (!id) resetLogForm();
      await renderLogConfigs();
      await renderMirrorEntries();
      await buildPulldownFromMirror();
      await refreshFromMirror();
    }
  }

  async function probeLogPath(){
    const path = ($('#logPath')?.value || '').trim();
    if (!path) {
      setStatus('Enter a path to test.', true);
      return;
    }
    let result = null;
    try { result = await post('probe_log_path', {path}); } catch(e) { result = {ok:false, error:'Probe failed'}; }
    if (!result || !result.ok) {
      setStatus((result && result.error) || 'Probe failed.', true);
      return;
    }
    const size = result.size ? ' size=' + result.size : '';
    setStatus('Readable: ' + (result.real || path) + size, false);
  }

  function exportCsv(){
    const rows = Array.from(document.querySelectorAll('#histBody tr'));
    if (!rows.length) return;
    const csv = [['Time (UTC)','Category','Message','User','IP']].concat(rows.map(function(tr){
      return Array.from(tr.children).slice(0, 5).map(function(td){ return (td.textContent || '').trim(); });
    }));
    const body = csv.map(function(row){
      return row.map(function(cell){ return '"' + String(cell).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\n') + '\n';
    const blob = new Blob([body], {type:'text/csv'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'copied-logs.csv';
    a.click();
    setTimeout(function(){ URL.revokeObjectURL(url); }, 15000);
  }

  function setCopiedLive(enabled){
    const btn = $('#btnLive');
    if (!btn) return;
    if (copiedAutoTimer) {
      clearInterval(copiedAutoTimer);
      copiedAutoTimer = null;
    }
    if (enabled) {
      copiedAutoTimer = setInterval(refreshFromMirror, 5000);
    }
    btn.textContent = enabled ? 'Live: On' : 'Live: Off';
    btn.dataset.live = enabled ? '1' : '0';
  }

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
        a.href = 'https://iplookup.flagfox.net/?ip=' + encodeURIComponent(r.ip.trim());
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = r.ip.trim();
        a.title = 'Lookup ' + r.ip.trim() + ' iplookup.flagfox.net';
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

  async function refreshPrivilegedLog(){
    if (!canAccessLive) return;
    const sel = $('#privilegedLogSelect');
    const output = $('#privilegedOutput');
    const meta = $('#privilegedMeta');
    const errorBox = $('#privilegedError');
    if (!sel || !output || !meta || !errorBox || !sel.value) return;
    syncPrivilegedControls();
    const opt = selectedPrivilegedOption();
    const maxLines = parseInt(opt?.dataset.maxLines || '300', 10) || 300;
    let lines = parseInt($('#privilegedLines')?.value || opt?.dataset.defaultLines || '150', 10) || 150;
    if (lines < 25) lines = 25;
    if (lines > maxLines) lines = maxLines;
    $('#privilegedLines').value = String(lines);
    const search = ($('#privilegedSearch')?.disabled ? '' : ($('#privilegedSearch')?.value || '')).trim();
    errorBox.hidden = true;
    errorBox.textContent = '';
    meta.textContent = 'Loading protected log output…';
    output.textContent = '';
    let data = null;
    try { data = await post('read_privileged_log', {key: sel.value, lines, search}); } catch(e) { data = {ok:false, error:'Request failed while reading the privileged log.'}; }
    if (!data || !data.ok) {
      errorBox.hidden = false;
      errorBox.textContent = (data && data.error) ? data.error : 'Privileged log read failed.';
      meta.textContent = 'No privileged log output available.';
      output.textContent = '';
      return;
    }
    const label = opt ? opt.textContent : (data.label || sel.value);
    const lineLabel = String(data.lines || lines) + ' line' + ((data.lines || lines) === 1 ? '' : 's');
    meta.textContent = search ? label + ' • ' + lineLabel + ' • literal filter: "' + search + '"' : label + ' • ' + lineLabel;
    output.textContent = data.text && data.text !== '' ? data.text : '[no matching output]';
  }

  document.addEventListener('DOMContentLoaded', async function(){
    await buildPulldownFromMirror();
    await renderLogConfigs();
    await renderMirrorEntries();
    await loadCommonCandidates();
    syncPrivilegedControls();
    $("#btnRefresh")?.addEventListener('click', function(e){ e.preventDefault(); refreshFromMirror(); });
    $("#logSelect")?.addEventListener('change', refreshFromMirror);
    $("#lines")?.addEventListener('change', refreshFromMirror);
    $("#logQ")?.addEventListener('change', refreshFromMirror);
    $("#logRe")?.addEventListener('change', refreshFromMirror);
    $("#logCi")?.addEventListener('change', refreshFromMirror);
    $("#btnAddLog")?.addEventListener('click', function(){ openLogModal(); });
    $("#logModalClose")?.addEventListener('click', closeLogModal);
    $("#logModal .modal-backdrop")?.addEventListener('click', closeLogModal);
    $("#logSave")?.addEventListener('click', saveLogConfig);
    $("#logTest")?.addEventListener('click', probeLogPath);
    $("#logCommon")?.addEventListener('click', loadCommonCandidates);
    $("#mirrorRefresh")?.addEventListener('click', async function(){ await renderMirrorEntries(); await buildPulldownFromMirror(); });
    $("#btnExportCsv")?.addEventListener('click', function(e){ e.preventDefault(); exportCsv(); });
    $("#btnApply")?.addEventListener('click', function(e){ e.preventDefault(); refreshFromMirror(); });
    $("#btnMore")?.addEventListener('click', function(e){
      e.preventDefault();
      const input = $("#lines");
      if (!input) return;
      const current = parseInt(input.value || '300', 10) || 300;
      input.value = String(Math.min(2000, current + 100));
      refreshFromMirror();
    });
    $("#btnLive")?.addEventListener('click', function(e){
      e.preventDefault();
      const enabled = this.dataset.live !== '1';
      setCopiedLive(enabled);
      if (enabled) refreshFromMirror();
    });
    $("#logsModeCopied")?.addEventListener('click', function(){ setMode('copied'); });
    $("#logsModeLive")?.addEventListener('click', function(){ setMode('live'); });
    $("#privilegedLogSelect")?.addEventListener('change', function(){ syncPrivilegedControls(); refreshPrivilegedLog(); });
    $("#privilegedLines")?.addEventListener('change', refreshPrivilegedLog);
    $("#privilegedSearch")?.addEventListener('change', refreshPrivilegedLog);
    $("#privilegedRefresh")?.addEventListener('click', function(e){ e.preventDefault(); refreshPrivilegedLog(); });
    $("#privilegedCopy")?.addEventListener('click', async function(){
      const text = $("#privilegedOutput")?.textContent || '';
      if (!text) return;
      try { await navigator.clipboard.writeText(text); } catch(e) {}
    });
    setCopiedLive(false);
    refreshFromMirror();
    let initialMode = 'copied';
    try {
      const storedMode = localStorage.getItem(modeStorageKey);
      if (storedMode === 'live') initialMode = 'live';
    } catch(e) {}
    setMode(initialMode);
  });
})();
</script>

<script defer src="<?= h(project_url('/assets/js/utils/sortable-table.js')) ?>"></script>

<?php include __DIR__.'/includes/foot.php'; ?>
