<?php
// backups_action.php
// JSON API for dashboard backup buttons

require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_login();

header('Content-Type: application/json');

function json_ok(array $extra = []) {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function json_error(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Method guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// --- CSRF check: header or form field ---
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (!csrf_check($csrf_token)) {
    json_error('CSRF failed', 403);
}

/**
 * Run a command in background and return PID / log path.
 * Logfile is expected to be in a path writeable by the PHP user (gene).
 */
function run_background(string $cmd, string $logfile): array
{
    $logdir = dirname($logfile);
    if (!is_dir($logdir)) {
        @mkdir($logdir, 0755, true);
    }

    // nohup <cmd> >> logfile 2>&1 & echo $!
    $cmd_final = sprintf(
        'nohup %s >> %s 2>&1 & echo $!',
        $cmd,
        escapeshellarg($logfile)
    );

    $output = [];
    exec($cmd_final, $output);

    $pid = null;
    if (!empty($output[0]) && ctype_digit($output[0])) {
        $pid = (int)$output[0];
    }

    return [
        'pid' => $pid,
        'log' => $logfile,
        'cmd' => $cmd,
    ];
}

/**
 * Append a record to state/backup_actions.json
 * Use atomic write (tmp + rename) so the browser never sees partial JSON.
 */
function log_action(string $action, array $payload = []): void
{
    $stateDir = __DIR__ . '/state';
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0755, true);
    }

    $logFile = $stateDir . '/backup_actions.json';

    $entries = [];
    if (is_file($logFile)) {
        $raw = @file_get_contents($logFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $entries = $decoded;
            }
        }
    }

    $job = $payload['job'] ?? [];

    $entry = [
        'ts'      => date('c'),
        'action'  => $action,
        'ok'      => $payload['ok'] ?? true,
        'message' => $payload['message'] ?? null,
        'job_id'  => $job['pid'] ?? null,
        'script'  => $job['cmd'] ?? null,
        'log'     => $job['log'] ?? null,
        'meta'    => $payload['meta'] ?? null,
    ];

    $entries[] = $entry;

    // keep only the last 50
    $maxEntries = 50;
    if (count($entries) > $maxEntries) {
        $entries = array_slice($entries, -$maxEntries);
    }

    $json = json_encode($entries, JSON_PRETTY_PRINT);

    // Atomic write: temp file + rename
    $tmpFile = $logFile . '.tmp';
    @file_put_contents($tmpFile, $json, LOCK_EX);
    @rename($tmpFile, $logFile);
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if ($action === '') {
    json_error('Missing action', 400);
}

$exclude_raw = (string) cfg_local('backups.exclude_dirs', '');
$exclude_list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $exclude_raw) ?: [])));
$exclude_env = $exclude_list ? ('BACKUP_EXCLUDES=' . escapeshellarg(implode(' ', $exclude_list)) . ' ') : '';
$exclude_env_sudo = $exclude_list ? ('env BACKUP_EXCLUDES=' . escapeshellarg(implode(' ', $exclude_list)) . ' ') : '';

$suspend = (bool) cfg_local('backups.suspend', false);
$disable_on_mount_fail = (bool) cfg_local('backups.disable_on_mount_fail', false);
$backup_actions = ['os_snapshot','micro_backup','hestia_gene','all_backups'];
if ($suspend && in_array($action, $backup_actions, true)) {
    log_action($action, [
        'ok'      => false,
        'message' => 'Backups suspended via config',
    ]);
    json_error('Backups are suspended in config.', 409);
}

if ($disable_on_mount_fail && in_array($action, $backup_actions, true)) {
    $stateDir = __DIR__ . '/state';
    $statusFile = $stateDir . '/backup_status.json';
    $mountOk = null;

    if (is_file($statusFile)) {
        $raw = @file_get_contents($statusFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && array_key_exists('backup_mount_ok', $decoded)) {
                $mountOk = (bool)$decoded['backup_mount_ok'];
            }
        }
    }

    $backup_root = (string) cfg_local('backups.fs_root', '/mnt/backupz');
    if ($backup_root === '') $backup_root = '/mnt/backupz';

    if ($mountOk === null && function_exists('shell_exec')) {
        $cmd = 'findmnt -rn ' . escapeshellarg($backup_root) . ' >/dev/null 2>&1; echo $?';
        $exitCode = trim((string) shell_exec($cmd));
        if ($exitCode !== '') {
            if ($exitCode === '127') {
                $mountOk = null;
            } else {
                $mountOk = ($exitCode === '0');
            }
        }
    }

    if ($mountOk === false) {
        log_action($action, [
            'ok' => false,
            'message' => 'Backup mount not present; backups disabled via config',
            'meta' => ['backup_root' => $backup_root],
        ]);
        json_error('Backup mount not present; backups disabled via config.', 409);
    }
}

switch ($action) {

    // ------------------------------------------------------------------
    // BACKUP JOBS
    // ------------------------------------------------------------------

    case 'os_snapshot': {
        $script = '/usr/local/sbin/make-snapshots.sh';

        if (!file_exists($script)) {
            json_error('Snapshot script not found: ' . $script, 500);
        }
        if (!is_readable($script)) {
            json_error('Snapshot script not readable: ' . $script, 500);
        }

        $cmd = $exclude_env . '/bin/bash ' . escapeshellarg($script);

        // Log into app-local logs, not /var/log
        $job = run_background($cmd, __DIR__ . '/state/logs/os-snapshot.log');

        log_action('os_snapshot', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started OS snapshot',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started OS snapshot'
        ]);
    } break;

    case 'micro_backup': {
        $script = '/usr/local/sbin/make-micro-backups.sh';

        if (!file_exists($script)) {
            json_error('Micro backup script not found: ' . $script, 500);
        }
        if (!is_readable($script)) {
            json_error('Micro backup script not readable: ' . $script, 500);
        }

        $cmd = $exclude_env . '/bin/bash ' . escapeshellarg($script);

        $job = run_background($cmd, __DIR__ . '/state/logs/micro-backup.log');

        log_action('micro_backup', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started micro backup',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started micro backup'
        ]);
    } break;

    case 'hestia_gene': {
        $bin = '/usr/local/hestia/bin/v-backup-user';

        // Run via sudo so the actual backup runs as root.
        $cmd = 'sudo -n ' . $exclude_env_sudo . escapeshellcmd($bin) . ' gene';

        $job = run_background($cmd, __DIR__ . '/state/logs/hestia-backup-gene.log');

        log_action('hestia_gene', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started Hestia backup (gene)',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started Hestia backup (gene)'
        ]);
    } break;

    case 'all_backups': {
        $snap       = '/usr/local/sbin/make-snapshots.sh';
        $micro      = '/usr/local/sbin/make-micro-backups.sh';
        $hestia_bin = '/usr/local/hestia/bin/v-backup-user';

        foreach ([$snap, $micro] as $path) {
            if (!file_exists($path)) {
                json_error('Required backup component missing: ' . $path, 500);
            }
            if (!is_readable($path)) {
                json_error('Required backup component not readable: ' . $path, 500);
            }
        }

        // Chain: snapshots → micro → Hestia backup (via sudo)
        $chain = sprintf(
            '%s/bin/bash %s && %s/bin/bash %s && sudo -n %s%s gene',
            $exclude_env,
            escapeshellarg($snap),
            $exclude_env,
            escapeshellarg($micro),
            $exclude_env_sudo,
            escapeshellarg($hestia_bin)
        );

        $cmd = '/bin/bash -c ' . escapeshellarg($chain);
        $job = run_background($cmd, __DIR__ . '/state/logs/all-backups.log');

        log_action('all_backups', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started all backups',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started all backups'
        ]);
    } break;

    case 'health_check': {
        $candidates = [
            '/usr/local/sbin/backup_health_check.sh',
            __DIR__ . '/scripts/backup_health_check.sh',
        ];
        $script = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $script = $candidate;
                break;
            }
        }

        if ($script === null) {
            json_error('Backup health script not found.', 500);
        }
        if (!is_readable($script)) {
            json_error('Backup health script not readable: ' . $script, 500);
        }

        $cmd = $exclude_env . '/bin/bash ' . escapeshellarg($script);

        $job = run_background($cmd, __DIR__ . '/state/logs/backup-health.log');

        log_action('health_check', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started backup health check',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started backup health check'
        ]);
    } break;

    // ------------------------------------------------------------------
    // LOG MAINTENANCE
    // ------------------------------------------------------------------
    // NOTE: these still target /var/log/* and will hit permission issues
    // from PHP unless we wire them via sudo as well. We can revisit that
    // once the core backup buttons are behaving.

    case 'clear_backup_logs': {
        $patterns = [
            '/var/log/backup-health.log',
            '/var/log/backup-health*.log',
        ];
        $cleared = [];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern) ?: [];
            foreach ($matches as $file) {
                if (is_file($file)) {
                    @file_put_contents($file, '');
                    $cleared[] = $file;
                }
            }
        }

        log_action('clear_backup_logs', [
            'ok'      => true,
            'message' => 'Cleared backup-health logs',
            'meta'    => ['paths' => $cleared],
        ]);

        json_ok([
            'message' => 'Cleared backup-health logs',
            'paths'   => $cleared,
        ]);
    } break;

    case 'clear_prune_logs': {
        $targets = [
            '/var/log/prune-hestia.log',
            '/var/log/prune-micro.log',
            '/var/log/prune-snapshots.log',
        ];
        $cleared = [];

        foreach ($targets as $file) {
            if (is_file($file)) {
                @file_put_contents($file, '');
                $cleared[] = $file;
            }
        }

        log_action('clear_prune_logs', [
            'ok'      => true,
            'message' => 'Cleared prune logs',
            'meta'    => ['paths' => $cleared],
        ]);

        json_ok([
            'message' => 'Cleared prune logs',
            'paths'   => $cleared,
        ]);
    } break;

    case 'clear_integrity_log': {
        $file = '/var/log/backup-integrity.log';
        $cleared = [];

        if (is_file($file)) {
            @file_put_contents($file, '');
            $cleared[] = $file;
        }

        log_action('clear_integrity_log', [
            'ok'      => true,
            'message' => 'Cleared integrity log',
            'meta'    => ['paths' => $cleared],
        ]);

        json_ok([
            'message' => 'Cleared integrity log',
            'paths'   => $cleared,
        ]);
    } break;

    // ------------------------------------------------------------------
    // FALLBACK
    // ------------------------------------------------------------------

    default:
        json_error('Unknown action', 400);
}
