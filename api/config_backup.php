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
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

header('Content-Type: application/json; charset=UTF-8');

function resp($ok, $extra = [])
{
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
}

function clamp_keep($n)
{
    $n = (int)$n;
    if ($n < 5) {
        $n = 5;
    }
    // Absolute safety cap first, then UI cap.
    if ($n > 500) {
        $n = 500;
    } // absolute safety cap
    if ($n > 200) {
        $n = 200;
    } // UI bound
    return $n;
}

function find_local_json($root)
{
    $candidates = [
      $root . '/config/local.json',
      $root . '/data/local.json',
      $root . '/local.json',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function find_alerts_json($root)
{
    $candidates = [
      $root . '/data/alerts.json',
      $root . '/state/alerts.json',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function find_services_json($root)
{
    $candidates = [
      $root . '/data/services.json',
      $root . '/state/services.json',
    ];
    foreach ($candidates as $p) {
        if (is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function canonical_backup_dir($root)
{
    return $root . '/config/backups';
}

function legacy_backup_dirs($root)
{
    return array_unique([
      $root . '/data/backups',
      $root . '/state/backups',
    ]);
}

function ensure_dir($dir)
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception('cannot create backup dir');
        }
    }
}

function migrate_legacy_backups($targetDir, array $legacyDirs)
{
    $patterns = ['config-*.json', 'alerts-*.json', 'services-*.json'];
    foreach ($legacyDirs as $legacy) {
        if (!$legacy || !is_dir($legacy)) {
            continue;
        }
        if (realpath($legacy) === realpath($targetDir)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            $files = glob(rtrim($legacy, '/') . '/' . $pattern);
            if (!$files) {
                continue;
            }
            foreach ($files as $src) {
                $dst = rtrim($targetDir, '/') . '/' . basename($src);
                if (!is_file($dst)) {
                    if (@rename($src, $dst)) {
                        continue;
                    }
                    if (@copy($src, $dst)) {
                        @unlink($src);
                        continue;
                    }
                }
            }
        }
    }
}

function list_backups($backupDir, $pattern = 'config-*.json')
{
    $list = glob(rtrim($backupDir, '/') . '/' . $pattern);
    if (!is_array($list)) {
        $list = [];
    }
    usort($list, function ($a, $b) {
        return @filemtime($b) - @filemtime($a);
    });
    return $list;
}

function rel_backup_dir($root, $dir)
{
    $rel = ltrim(str_replace($root, '', $dir), '/');
    return $rel !== '' ? $rel : basename($dir);
}

try {
    $csrf = $_GET['_csrf'] ?? $_POST['_csrf'] ?? '';
    if (!csrf_check($csrf)) {
        http_response_code(403);
        resp(false, ['error' => 'CSRF failed']);
    }
    $root = dirname(__DIR__);
    $src  = find_local_json($root);
    $alertsSrc = find_alerts_json($root);
    $servicesSrc = find_services_json($root);
    $backupDir = canonical_backup_dir($root);
    ensure_dir($backupDir);
    migrate_legacy_backups($backupDir, legacy_backup_dirs($root));
    $base = ['backup_dir' => rel_backup_dir($root, $backupDir)];

    // Determine 'keep'
    $keep = null;
    if (isset($_REQUEST['keep'])) {
        $keep = (int)$_REQUEST['keep'];
    }
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
    if ($keep === null) {
        $keep = 20;
    }
    $keep = clamp_keep($keep);

    // helper: list backups newest->oldest
    $list = list_backups($backupDir, 'config-*.json');
    $alertsList = list_backups($backupDir, 'alerts-*.json');
    $servicesList = list_backups($backupDir, 'services-*.json');

    // stats mode
    if (isset($_GET['stats'])) {
        $count = count($list);
        $total = 0;
        foreach ($list as $f) {
            $sz = @filesize($f);
            if ($sz > 0) {
                $total += $sz;
            }
        }
        $alertsCount = count($alertsList);
        $alertsTotal = 0;
        foreach ($alertsList as $f) {
            $sz = @filesize($f);
            if ($sz > 0) {
                $alertsTotal += $sz;
            }
        }
        $servicesCount = count($servicesList);
        $servicesTotal = 0;
        foreach ($servicesList as $f) {
            $sz = @filesize($f);
            if ($sz > 0) {
                $servicesTotal += $sz;
            }
        }
        $human = ($total >= 1048576) ? round($total / 1048576, 2).' MB' : ($total >= 1024 ? round($total / 1024, 2).' KB' : $total.' B');
        $alertsHuman = ($alertsTotal >= 1048576) ? round($alertsTotal / 1048576, 2).' MB' : ($alertsTotal >= 1024 ? round($alertsTotal / 1024, 2).' KB' : $alertsTotal.' B');
        $servicesHuman = ($servicesTotal >= 1048576) ? round($servicesTotal / 1048576, 2).' MB' : ($servicesTotal >= 1024 ? round($servicesTotal / 1024, 2).' KB' : $servicesTotal.' B');
        resp(true, array_merge($base, [
          'count' => $count,
          'total_size' => $total,
          'total_size_human' => $human,
          'alerts_count' => $alertsCount,
          'alerts_total_size' => $alertsTotal,
          'alerts_total_size_human' => $alertsHuman,
          'services_count' => $servicesCount,
          'services_total_size' => $servicesTotal,
          'services_total_size_human' => $servicesHuman,
          'keep' => $keep
        ]));
    }

    // latest info
    if (isset($_GET['latest'])) {
        if (!empty($list)) {
            $f = $list[0];
            resp(true, array_merge($base, ['exists' => true, 'file' => basename($f), 'size' => @filesize($f), 'mtime' => @filemtime($f)]));
        } else {
            resp(true, array_merge($base, ['exists' => false]));
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
        resp(false, array_merge($base, ['error' => 'No backup to download']));
    }

    // prune-only mode
    if (isset($_GET['prune']) || isset($_POST['prune'])) {
        $deleted = 0;
        if (count($list) > $keep) {
            foreach (array_slice($list, $keep) as $f) {
                if (@unlink($f)) {
                    $deleted++;
                }
            }
        }
        if (count($alertsList) > $keep) {
            foreach (array_slice($alertsList, $keep) as $f) {
                if (@unlink($f)) {
                    $deleted++;
                }
            }
        }
        if (count($servicesList) > $keep) {
            foreach (array_slice($servicesList, $keep) as $f) {
                if (@unlink($f)) {
                    $deleted++;
                }
            }
        }
        resp(true, array_merge($base, ['keep' => $keep, 'deleted' => $deleted]));
    }

    // default: create new backup and prune
    if (!$src) {
        throw new Exception('local.json not found (checked config/local.json, data/local.json, local.json)');
    }
    $json = file_get_contents($src);
    if ($json === false) {
        throw new Exception('read failed');
    }
    json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('invalid JSON');
    }

    $ts = date('Ymd-His');
    $dst = $backupDir . '/config-' . $ts . '.json';
    if (file_put_contents($dst, $json, LOCK_EX) === false) {
        throw new Exception('write failed');
    }
    @chmod($dst, 0640);

    $alertsPath = null;
    $servicesPath = null;
    if ($alertsSrc) {
        $alertsJson = @file_get_contents($alertsSrc);
        if ($alertsJson !== false) {
            json_decode($alertsJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $alertsDst = $backupDir . '/alerts-' . $ts . '.json';
                if (file_put_contents($alertsDst, $alertsJson, LOCK_EX) !== false) {
                    @chmod($alertsDst, 0640);
                    $alertsPath = basename($alertsDst);
                }
            }
        }
    }

    if ($servicesSrc) {
        $servicesJson = @file_get_contents($servicesSrc);
        if ($servicesJson !== false) {
            json_decode($servicesJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $servicesDst = $backupDir . '/services-' . $ts . '.json';
                if (file_put_contents($servicesDst, $servicesJson, LOCK_EX) !== false) {
                    @chmod($servicesDst, 0640);
                    $servicesPath = basename($servicesDst);
                }
            }
        }
    }

    // prune to keep newest N
    $list = list_backups($backupDir, 'config-*.json');
    $alertsList = list_backups($backupDir, 'alerts-*.json');
    $servicesList = list_backups($backupDir, 'services-*.json');
    $deleted = 0;
    if (count($list) > $keep) {
        foreach (array_slice($list, $keep) as $f) {
            if (@unlink($f)) {
                $deleted++;
            }
        }
    }
    if (count($alertsList) > $keep) {
        foreach (array_slice($alertsList, $keep) as $f) {
            if (@unlink($f)) {
                $deleted++;
            }
        }
    }
    if (count($servicesList) > $keep) {
        foreach (array_slice($servicesList, $keep) as $f) {
            if (@unlink($f)) {
                $deleted++;
            }
        }
    }

    resp(true, array_merge($base, ['path' => basename($dst), 'alerts_path' => $alertsPath, 'services_path' => $servicesPath, 'keep' => $keep, 'deleted' => $deleted]));
} catch (Throwable $e) {
    http_response_code(500);
    resp(false, array_merge(isset($base) ? $base : [], ['error' => $e->getMessage()]));
}
