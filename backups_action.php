<?php
// backups_action.php
// JSON API for dashboard backup buttons

require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_login();

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Unhandled server exception in backups_action.php',
        'detail' => $e->getMessage(),
    ]);
    exit;
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'] ?? 0, $fatal, true)) return;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Fatal server error in backups_action.php',
        'detail' => (string)($err['message'] ?? 'unknown'),
    ]);
});

function json_ok(array $extra = []) {
    echo json_encode(array_merge(['ok' => true], $extra));
    exit;
}

function json_error(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function fn_enabled(string $name): bool {
    if (!function_exists($name)) return false;
    $disabled = (string)@ini_get('disable_functions');
    if ($disabled === '') return true;
    $list = array_map('trim', explode(',', $disabled));
    return !in_array($name, $list, true);
}

function run_shell_capture(string $cmd): ?string {
    if (fn_enabled('exec')) {
        $output = [];
        @exec($cmd, $output);
        if (is_array($output) && isset($output[0])) {
            return trim((string)$output[0]);
        }
    }

    if (fn_enabled('shell_exec')) {
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') return trim($out);
    }

    if (fn_enabled('popen')) {
        $h = @popen($cmd, 'r');
        if (is_resource($h)) {
            $out = stream_get_contents($h);
            @pclose($h);
            if (is_string($out) && trim($out) !== '') return trim($out);
        }
    }

    if (fn_enabled('proc_open')) {
        $des = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $des, $pipes);
        if (is_resource($proc)) {
            $out = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            if (isset($pipes[1]) && is_resource($pipes[1])) @fclose($pipes[1]);
            if (isset($pipes[2]) && is_resource($pipes[2])) @fclose($pipes[2]);
            @proc_close($proc);
            if (is_string($out) && trim($out) !== '') return trim($out);
        }
    }

    return null;
}

function resolve_executable_path(array $candidates): ?string {
    foreach ($candidates as $path) {
        if (!is_string($path)) continue;
        $path = trim($path);
        if ($path === '') continue;
        if (is_file($path) && is_readable($path)) return $path;
    }
    return null;
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

    $pid = null;
    $pidRaw = run_shell_capture($cmd_final);
    if (is_string($pidRaw) && preg_match('/^\d+$/', $pidRaw)) {
        $pid = (int)$pidRaw;
    } elseif ($pidRaw === null) {
        $disabled = (string)@ini_get('disable_functions');
        $disabled = trim($disabled) === '' ? '(none)' : $disabled;
        json_error(
            'No command execution functions are available (checked: exec, shell_exec, popen, proc_open). disable_functions=' . $disabled,
            503
        );
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
        $script = resolve_executable_path([
            (string) cfg_local('backups.snap_script', ''),
            '/usr/local/sbin/make-snapshots.sh',
            __DIR__ . '/scripts/make-snapshots.sh',
        ]);
        if ($script === null) json_error('Snapshot script not found.', 500);

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
        $script = resolve_executable_path([
            (string) cfg_local('backups.micro_script', ''),
            '/usr/local/sbin/make-micro-backups.sh',
            __DIR__ . '/scripts/make-micro-backups.sh',
        ]);
        if ($script === null) json_error('Micro backup script not found.', 500);

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
        $bin = resolve_executable_path([
            (string) cfg_local('backups.hestia_cmd', ''),
            '/usr/local/hestia/bin/v-backup-user',
        ]);
        if ($bin === null) json_error('Hestia backup command not found.', 500);
        $hUser = (string) cfg_local('backups.hestia_user', 'gene');
        if ($hUser === '') $hUser = 'gene';

        // Run via sudo so the actual backup runs as root.
        $cmd = 'sudo -n ' . $exclude_env_sudo . escapeshellarg($bin) . ' ' . escapeshellarg($hUser);

        $job = run_background($cmd, __DIR__ . '/state/logs/hestia-backup-gene.log');

        log_action('hestia_gene', [
            'job'     => $job,
            'ok'      => true,
            'message' => 'Started Hestia backup (' . $hUser . ')',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => 'Started Hestia backup (' . $hUser . ')'
        ]);
    } break;

    case 'all_backups': {
        $snap = resolve_executable_path([
            (string) cfg_local('backups.snap_script', ''),
            '/usr/local/sbin/make-snapshots.sh',
            __DIR__ . '/scripts/make-snapshots.sh',
        ]);
        $micro = resolve_executable_path([
            (string) cfg_local('backups.micro_script', ''),
            '/usr/local/sbin/make-micro-backups.sh',
            __DIR__ . '/scripts/make-micro-backups.sh',
        ]);
        $hestia_bin = resolve_executable_path([
            (string) cfg_local('backups.hestia_cmd', ''),
            '/usr/local/hestia/bin/v-backup-user',
        ]);
        $hUser = (string) cfg_local('backups.hestia_user', 'gene');
        if ($hUser === '') $hUser = 'gene';
        if ($snap === null || $micro === null) {
            $missing = [];
            if ($snap === null) $missing[] = 'snap';
            if ($micro === null) $missing[] = 'micro';
            json_error('Required backup component missing: ' . implode(',', $missing), 500);
        }

        // Chain: snapshots → micro, and include Hestia only when configured/available.
        $chain = sprintf(
            '%s/bin/bash %s && %s/bin/bash %s',
            $exclude_env,
            escapeshellarg($snap),
            $exclude_env,
            escapeshellarg($micro)
        );
        $ranHestia = false;
        if ($hestia_bin !== null) {
            $chain .= sprintf(
                ' && sudo -n %s%s %s',
                $exclude_env_sudo,
                escapeshellarg($hestia_bin),
                escapeshellarg($hUser)
            );
            $ranHestia = true;
        }

        $cmd = '/bin/bash -c ' . escapeshellarg($chain);
        $job = run_background($cmd, __DIR__ . '/state/logs/all-backups.log');

        log_action('all_backups', [
            'job'     => $job,
            'ok'      => true,
            'message' => $ranHestia ? 'Started all backups (snap,micro,hestia)' : 'Started backups (snap,micro)',
        ]);

        json_ok([
            'job_id'  => $job['pid'],
            'script'  => $job['cmd'],
            'log'     => $job['log'],
            'message' => $ranHestia ? 'Started all backups (snap,micro,hestia)' : 'Started backups (snap,micro)',
            'ran_hestia' => $ranHestia,
        ]);
    } break;

    case 'health_check': {
        $candidates = [
            (string) cfg_local('backups.health_script', ''),
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
