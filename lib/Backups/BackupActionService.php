<?php

namespace App\Backups;

require_once __DIR__ . "/../AuditLog.php";
require_once __DIR__ . "/../Redaction.php";
require_once __DIR__ . "/BackupsConfig.php";

final class BackupActionService
{
    public static function handle(string $projectRoot, string $action): array
    {
        if ($action === "") {
            return self::error("Missing action", 400);
        }

        $excludeEnv = BackupsConfig::excludeEnvPrefix();
        $excludeEnvSudo = BackupsConfig::excludeSudoEnvPrefix();
        $backupActions = ["os_snapshot", "micro_backup", "hestia_user", "all_backups"];

        if (BackupsConfig::backupActionSuspended() && in_array($action, $backupActions, true)) {
            self::logAction($projectRoot, $action, [
                "ok" => false,
                "message" => "Backups suspended via config",
            ]);
            return self::error("Backups are suspended in config.", 409);
        }

        if (BackupsConfig::disableOnMountFail() && in_array($action, $backupActions, true)) {
            $mountOk = BackupsConfig::mountStatus($projectRoot);
            if ($mountOk === false) {
                self::logAction($projectRoot, $action, [
                    "ok" => false,
                    "message" => "Backup mount not present; backups disabled via config",
                    "meta" => ["backup_root" => BackupsConfig::backupRoot()],
                ]);
                return self::error("Backup mount not present; backups disabled via config.", 409);
            }
        }

        switch ($action) {
            case "os_snapshot":
                return self::startOsSnapshot($projectRoot, $excludeEnv);
            case "micro_backup":
                return self::startMicroBackup($projectRoot, $excludeEnv);
            case "hestia_user":
                return self::startHestiaUserBackup($projectRoot, $excludeEnvSudo);
            case "all_backups":
                return self::startAllBackups($projectRoot, $excludeEnv, $excludeEnvSudo);
            case "health_check":
                return self::startHealthCheck($projectRoot, $excludeEnv);
            case "clear_backup_logs":
                return self::clearFiles($projectRoot, "clear_backup_logs", "Cleared backup-health logs", [
                    "/var/log/backup-health.log",
                    "/var/log/backup-health*.log",
                ], true);
            case "clear_prune_logs":
                return self::clearFiles($projectRoot, "clear_prune_logs", "Cleared prune logs", [
                    "/var/log/prune-hestia.log",
                    "/var/log/prune-micro.log",
                    "/var/log/prune-snapshots.log",
                ], false);
            case "clear_integrity_log":
                return self::clearFiles($projectRoot, "clear_integrity_log", "Cleared integrity log", [
                    "/var/log/backup-integrity.log",
                ], false);
            default:
                return self::error("Unknown action", 400);
        }
    }

    private static function startOsSnapshot(string $projectRoot, string $excludeEnv): array
    {
        $script = BackupsConfig::resolveExecutablePath(
            BackupsConfig::scriptCandidates($projectRoot, "backups.snap_script", "make-snapshots.sh", [
                "/usr/local/sbin/make-snapshots.sh",
                "/usr/local/sbin/make-snapshot.sh",
                $projectRoot . "/scripts/make-snapshots.sh",
            ]),
        );
        if ($script === null) {
            return self::error("Snapshot script not found.", 500);
        }

        $cmd = $excludeEnv . "/bin/bash " . escapeshellarg($script);
        $job = self::runBackground($cmd, $projectRoot . "/state/logs/os-snapshot.log");

        self::logAction($projectRoot, "os_snapshot", [
            "job" => $job,
            "ok" => true,
            "message" => "Started OS snapshot",
        ]);

        return self::ok([
            "job_id" => $job["pid"],
            "script" => $job["cmd"],
            "log" => $job["log"],
            "message" => "Started OS snapshot",
        ]);
    }

    private static function startMicroBackup(string $projectRoot, string $excludeEnv): array
    {
        $script = BackupsConfig::resolveExecutablePath(
            BackupsConfig::scriptCandidates($projectRoot, "backups.micro_script", "make-micro-backups.sh", [
                "/usr/local/sbin/make-micro-backups.sh",
                $projectRoot . "/scripts/make-micro-backups.sh",
            ]),
        );
        if ($script === null) {
            return self::error("Micro backup script not found.", 500);
        }

        $cmd = $excludeEnv . "/bin/bash " . escapeshellarg($script);
        $job = self::runBackground($cmd, $projectRoot . "/state/logs/micro-backup.log");

        self::logAction($projectRoot, "micro_backup", [
            "job" => $job,
            "ok" => true,
            "message" => "Started micro backup",
        ]);

        return self::ok([
            "job_id" => $job["pid"],
            "script" => $job["cmd"],
            "log" => $job["log"],
            "message" => "Started micro backup",
        ]);
    }

    private static function startHestiaUserBackup(string $projectRoot, string $excludeEnvSudo): array
    {
        $bin = BackupsConfig::resolveExecutablePath([
            (string) cfg_local("backups.hestia_cmd", ""),
            "/usr/local/hestia/bin/v-backup-user",
        ]);
        if ($bin === null) {
            return self::error("Hestia backup command not found.", 500);
        }

        $hUser = (string) cfg_local("backups.hestia_user", "user");
        if ($hUser === "") {
            $hUser = "user";
        }

        $cmd = "sudo -n " . $excludeEnvSudo . escapeshellarg($bin) . " " . escapeshellarg($hUser);
        $job = self::runBackground($cmd, $projectRoot . "/state/logs/hestia-backup-user.log");

        self::logAction($projectRoot, "hestia_user", [
            "job" => $job,
            "ok" => true,
            "message" => "Started Hestia backup (" . $hUser . ")",
        ]);

        return self::ok([
            "job_id" => $job["pid"],
            "script" => $job["cmd"],
            "log" => $job["log"],
            "message" => "Started Hestia backup (" . $hUser . ")",
        ]);
    }

    private static function startAllBackups(string $projectRoot, string $excludeEnv, string $excludeEnvSudo): array
    {
        $snap = BackupsConfig::resolveExecutablePath(
            BackupsConfig::scriptCandidates($projectRoot, "backups.snap_script", "make-snapshots.sh", [
                "/usr/local/sbin/make-snapshots.sh",
                "/usr/local/sbin/make-snapshot.sh",
                $projectRoot . "/scripts/make-snapshots.sh",
            ]),
        );
        $micro = BackupsConfig::resolveExecutablePath(
            BackupsConfig::scriptCandidates($projectRoot, "backups.micro_script", "make-micro-backups.sh", [
                "/usr/local/sbin/make-micro-backups.sh",
                $projectRoot . "/scripts/make-micro-backups.sh",
            ]),
        );
        $hestiaBin = BackupsConfig::resolveExecutablePath([
            (string) cfg_local("backups.hestia_cmd", ""),
            "/usr/local/hestia/bin/v-backup-user",
        ]);
        $hUser = (string) cfg_local("backups.hestia_user", "user");
        if ($hUser === "") {
            $hUser = "user";
        }

        if ($snap === null || $micro === null) {
            $missing = [];
            if ($snap === null) {
                $missing[] = "snap";
            }
            if ($micro === null) {
                $missing[] = "micro";
            }
            return self::error("Required backup component missing: " . implode(",", $missing), 500);
        }

        $chain = sprintf(
            "%s/bin/bash %s && %s/bin/bash %s",
            $excludeEnv,
            escapeshellarg($snap),
            $excludeEnv,
            escapeshellarg($micro),
        );
        $ranHestia = false;
        if ($hestiaBin !== null) {
            $chain .= sprintf(
                " && sudo -n %s%s %s",
                $excludeEnvSudo,
                escapeshellarg($hestiaBin),
                escapeshellarg($hUser),
            );
            $ranHestia = true;
        }

        $cmd = "/bin/bash -c " . escapeshellarg($chain);
        $job = self::runBackground($cmd, $projectRoot . "/state/logs/all-backups.log");
        $message = $ranHestia ? "Started all backups (snap,micro,hestia)" : "Started backups (snap,micro)";

        self::logAction($projectRoot, "all_backups", [
            "job" => $job,
            "ok" => true,
            "message" => $message,
        ]);

        return self::ok([
            "job_id" => $job["pid"],
            "script" => $job["cmd"],
            "log" => $job["log"],
            "message" => $message,
            "ran_hestia" => $ranHestia,
        ]);
    }

    private static function startHealthCheck(string $projectRoot, string $excludeEnv): array
    {
        $script = BackupsConfig::resolveExecutablePath(
            BackupsConfig::scriptCandidates($projectRoot, "backups.health_script", "backup_health_check.sh", [
                "/usr/local/sbin/backup_health_check.sh",
                $projectRoot . "/scripts/backup_health_check.sh",
            ]),
        );
        if ($script === null) {
            return self::error("Backup health script not found.", 500);
        }
        if (!is_readable($script)) {
            return self::error("Backup health script not readable: " . $script, 500);
        }

        $cmd = $excludeEnv . "/bin/bash " . escapeshellarg($script);
        $job = self::runBackground($cmd, $projectRoot . "/state/logs/backup-health.log");

        self::logAction($projectRoot, "health_check", [
            "job" => $job,
            "ok" => true,
            "message" => "Started backup health check",
        ]);

        return self::ok([
            "job_id" => $job["pid"],
            "script" => $job["cmd"],
            "log" => $job["log"],
            "message" => "Started backup health check",
        ]);
    }

    private static function clearFiles(
        string $projectRoot,
        string $action,
        string $message,
        array $paths,
        bool $glob
    ): array {
        $cleared = [];

        foreach ($paths as $path) {
            $targets = $glob ? (glob($path) ?: []) : [$path];
            foreach ($targets as $target) {
                if (is_file($target)) {
                    @file_put_contents($target, "");
                    $cleared[] = $target;
                }
            }
        }

        self::logAction($projectRoot, $action, [
            "ok" => true,
            "message" => $message,
            "meta" => ["paths" => $cleared],
        ]);

        return self::ok([
            "message" => $message,
            "paths" => $cleared,
        ]);
    }

    private static function fnEnabled(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = (string) @ini_get("disable_functions");
        if ($disabled === "") {
            return true;
        }
        $list = array_map("trim", explode(",", $disabled));
        return !in_array($name, $list, true);
    }

    private static function runShellCapture(string $cmd): ?string
    {
        if (self::fnEnabled("exec")) {
            $output = [];
            @exec($cmd, $output);
            if (is_array($output) && isset($output[0])) {
                return trim((string) $output[0]);
            }
        }

        if (self::fnEnabled("shell_exec")) {
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== "") {
                return trim($out);
            }
        }

        if (self::fnEnabled("popen")) {
            $handle = @popen($cmd, "r");
            if (is_resource($handle)) {
                $out = stream_get_contents($handle);
                @pclose($handle);
                if (is_string($out) && trim($out) !== "") {
                    return trim($out);
                }
            }
        }

        if (self::fnEnabled("proc_open")) {
            $descriptors = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (is_resource($proc)) {
                $out = isset($pipes[1]) ? stream_get_contents($pipes[1]) : "";
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    @fclose($pipes[1]);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    @fclose($pipes[2]);
                }
                @proc_close($proc);
                if (is_string($out) && trim($out) !== "") {
                    return trim($out);
                }
            }
        }

        return null;
    }

    private static function runBackground(string $cmd, string $logfile): array
    {
        $logdir = dirname($logfile);
        if (!is_dir($logdir)) {
            @mkdir($logdir, 0755, true);
        }

        $cmdFinal = sprintf("nohup %s >> %s 2>&1 & echo $!", $cmd, escapeshellarg($logfile));
        $pid = null;
        $pidRaw = self::runShellCapture($cmdFinal);

        if (is_string($pidRaw) && preg_match("/^\\d+$/", $pidRaw)) {
            $pid = (int) $pidRaw;
        } elseif ($pidRaw === null) {
            $disabled = (string) @ini_get("disable_functions");
            $disabled = trim($disabled) === "" ? "(none)" : $disabled;
            throw new \RuntimeException(
                "No command execution functions are available (checked: exec, shell_exec, popen, proc_open). disable_functions=" . $disabled,
            );
        }

        return [
            "pid" => $pid,
            "log" => $logfile,
            "cmd" => \Redaction::redactText($cmd),
        ];
    }

    private static function logAction(string $projectRoot, string $action, array $payload = []): void
    {
        $stateDir = $projectRoot . "/state";
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0755, true);
        }

        $logFile = $stateDir . "/backup_actions.json";
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

        $job = $payload["job"] ?? [];
        $meta = \Redaction::sanitizeForPersisted($payload["meta"] ?? null);
        $message = (string) ($payload["message"] ?? "");
        $ok = (bool) ($payload["ok"] ?? true);

        $entries[] = [
            "ts" => date("c"),
            "action" => $action,
            "ok" => $ok,
            "message" => $message !== "" ? \Redaction::redactText($message) : null,
            "job_id" => $job["pid"] ?? null,
            "script" => $job["cmd"] ?? null,
            "log" => $job["log"] ?? null,
            "meta" => $meta,
        ];

        if (count($entries) > 50) {
            $entries = array_slice($entries, -50);
        }

        $json = json_encode($entries, JSON_PRETTY_PRINT);
        $tmpFile = $logFile . ".tmp";
        @file_put_contents($tmpFile, $json, LOCK_EX);
        @rename($tmpFile, $logFile);

        \AuditLog::record(
            "backup." . $action,
            $action,
            $ok,
            [
                "job_id" => $job["pid"] ?? null,
                "log" => $job["log"] ?? null,
                "meta" => $meta,
                "message" => $message,
            ],
            "backup action " . $action,
            "backup",
        );
    }

    private static function ok(array $payload): array
    {
        return [
            "status" => 200,
            "payload" => array_merge(["ok" => true], $payload),
        ];
    }

    private static function error(string $message, int $status): array
    {
        return [
            "status" => $status,
            "payload" => ["ok" => false, "error" => $message],
        ];
    }
}
