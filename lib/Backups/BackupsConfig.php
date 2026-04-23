<?php

namespace App\Backups;

final class BackupsConfig
{
    public static function pageData(string $projectRoot): array
    {
        $csrfToken = csrf_token();
        $backupRoot = self::cfgString("backups.fs_root", "/mnt/backupz");
        $hestiaSourceDir = self::cfgString("backups.hestia_source_dir", "/backup");
        $backupScriptPath = self::cfgString("backups.script_path", $projectRoot . "/scripts");
        $backupDirs = self::backupDirs();

        return [
            "csrf_token" => $csrfToken,
            "has_any_backup_fs" => self::hasAnyBackupFs($backupRoot, $backupDirs),
            "backup_orchestrator_json" => json_encode(self::orchestratorDefaults($projectRoot), JSON_UNESCAPED_SLASHES),
            "initial_status" => self::initialStatus($projectRoot),
        ];
    }

    public static function orchestratorDefaults(string $projectRoot): array
    {
        $backupRoot = self::cfgString("backups.fs_root", "/mnt/backupz");
        $hestiaSourceDir = self::cfgString("backups.hestia_source_dir", "/backup");
        $hestiaUser = self::cfgString("backups.hestia_user", "user");
        $backupScriptPath = self::cfgString("backups.script_path", $projectRoot . "/scripts");

        return [
            "backup_root" => $backupRoot,
            "script_path" => $backupScriptPath,
            "snap_script" => self::cfgString("backups.snap_script", rtrim($backupScriptPath, "/") . "/make-snapshots.sh"),
            "micro_script" => self::cfgString("backups.micro_script", rtrim($backupScriptPath, "/") . "/make-micro-backups.sh"),
            "hestia_cmd" => self::cfgString("backups.hestia_cmd", "/usr/local/hestia/bin/v-backup-user"),
            "hestia_user" => $hestiaUser,
            "hestia_source_dir" => $hestiaSourceDir,
            "hestia_bind_source" => self::cfgString("backups.hestia_bind_source", ""),
            "hestia_bind_target" => self::cfgString("backups.hestia_bind_target", $hestiaSourceDir),
            "hestia_bind_options" => self::cfgString("backups.hestia_bind_options", "bind,nofail"),
            "exclude_dirs" => self::cfgString("backups.exclude_dirs", ""),
            "backupctl" => self::cfgString("backups.backupctl", rtrim($backupScriptPath, "/") . "/backupctl"),
            "pipeline_script" => self::cfgString("backups.pipeline_script", "/usr/local/bin/backup-nightly.sh"),
            "log_file" => self::cfgString("backups.log_file", "/var/log/backup-nightly.log"),
            "cron_time" => self::cfgString("backups.cron_time", "02:00"),
            "service_name" => self::cfgString("backups.service_name", "backup-nightly"),
            "system_user" => self::cfgString("backups.system_user", "root"),
            "include_health" => cfg_local("backups.include_health", true),
            "include_integrity" => cfg_local("backups.include_integrity", true),
            "include_prune" => cfg_local("backups.include_prune", true),
            "suspend" => cfg_local("backups.suspend", false),
            "disable_on_mount_fail" => cfg_local(
                "backups.disable_on_mount_fail",
                cfg_local("backups.require_dedicated_mount", false),
            ),
        ];
    }

    public static function backupDirs(): array
    {
        $dirsRaw = (string) cfg_local("backups.fs_dirs", "hestia\nmicro\nsnapshots");
        $backupDirs = array_values(array_filter(array_map("trim", preg_split("/[\s,]+/", $dirsRaw) ?: [])));
        return $backupDirs ?: ["hestia", "micro", "snapshots"];
    }

    public static function excludeList(): array
    {
        $excludeRaw = self::cfgString("backups.exclude_dirs", "");
        return array_values(array_filter(array_map("trim", preg_split("/[\s,]+/", $excludeRaw) ?: [])));
    }

    public static function excludeEnvPrefix(): string
    {
        $excludeList = self::excludeList();
        return $excludeList ? ("BACKUP_EXCLUDES=" . escapeshellarg(implode(" ", $excludeList)) . " ") : "";
    }

    public static function excludeSudoEnvPrefix(): string
    {
        $excludeList = self::excludeList();
        return $excludeList ? ("env BACKUP_EXCLUDES=" . escapeshellarg(implode(" ", $excludeList)) . " ") : "";
    }

    public static function backupActionSuspended(): bool
    {
        return (bool) cfg_local("backups.suspend", false);
    }

    public static function disableOnMountFail(): bool
    {
        return (bool) cfg_local(
            "backups.disable_on_mount_fail",
            cfg_local("backups.require_dedicated_mount", false),
        );
    }

    public static function backupRoot(): string
    {
        return self::cfgString("backups.fs_root", "/mnt/backupz");
    }

    public static function mountStatus(string $projectRoot): ?bool
    {
        $statusFile = $projectRoot . "/state/backup_status.json";
        if (is_file($statusFile)) {
            $raw = @file_get_contents($statusFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && array_key_exists("backup_mount_ok", $decoded)) {
                    return (bool) $decoded["backup_mount_ok"];
                }
            }
        }

        if (!function_exists("shell_exec")) {
            return null;
        }

        $cmd = "findmnt -rn " . escapeshellarg(self::backupRoot()) . " >/dev/null 2>&1; echo $?";
        $exitCode = trim((string) shell_exec($cmd));
        if ($exitCode === "" || $exitCode === "127") {
            return null;
        }

        return $exitCode === "0";
    }

    public static function scriptCandidates(string $projectRoot, string $legacyKey, string $scriptFile, array $fallback = []): array
    {
        $candidates = [];
        $base = self::cfgString("backups.script_path", $projectRoot . "/scripts");
        if ($base !== "") {
            $candidates[] = rtrim($base, "/") . "/" . $scriptFile;
        }

        $legacy = self::cfgString($legacyKey, "");
        if ($legacy !== "") {
            $candidates[] = $legacy;
        }

        foreach ($fallback as $path) {
            $candidates[] = $path;
        }

        return $candidates;
    }

    public static function resolveExecutablePath(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim($path);
            if ($path === "") {
                continue;
            }
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function hasAnyBackupFs(string $backupRoot, array $backupDirs): bool
    {
        foreach ($backupDirs as $sub) {
            $path = $backupRoot . "/" . $sub;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $it = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
                if ($it->valid()) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }

        return false;
    }

    private static function initialStatus(string $projectRoot): array
    {
        $status = "UNKNOWN";
        $statusClass = "status-crit";
        $diskUsage = "--%";
        $diskStatus = "Unknown";

        try {
            $statusFile = $projectRoot . "/state/backup_status.json";
            if (is_readable($statusFile)) {
                $raw = @file_get_contents($statusFile);
                $json = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($json)) {
                    $rawStatus = strtoupper(trim((string) ($json["status"] ?? "")));
                    $mountOk = !array_key_exists("backup_mount_ok", $json) || ($json["backup_mount_ok"] !== false);
                    $warnings = isset($json["warnings"]) && is_array($json["warnings"]) ? $json["warnings"] : [];
                    $errors = isset($json["errors"]) && is_array($json["errors"]) ? $json["errors"] : [];
                    $usage = null;
                    if (
                        isset($json["disk"]) &&
                        is_array($json["disk"]) &&
                        isset($json["disk"]["usage_percent"]) &&
                        is_numeric($json["disk"]["usage_percent"])
                    ) {
                        $usage = (int) $json["disk"]["usage_percent"];
                    }
                    if ($usage !== null) {
                        $diskUsage = $usage . "%";
                    }

                    if (in_array($rawStatus, ["OK", "HEALTHY", "PASS"], true)) {
                        $status = "OK";
                    } elseif (in_array($rawStatus, ["WARN", "WARNING", "DEGRADED"], true)) {
                        $status = "WARN";
                    } elseif (in_array($rawStatus, ["CRIT", "CRITICAL", "FAIL", "ERROR"], true)) {
                        $status = "CRIT";
                    } elseif ($mountOk === false || count($errors) > 0 || ($usage !== null && $usage >= 95)) {
                        $status = "CRIT";
                    } elseif (count($warnings) > 0 || ($usage !== null && $usage >= 80)) {
                        $status = "WARN";
                    } else {
                        $status = "OK";
                    }

                    if ($mountOk === false) {
                        $diskStatus = "UNMOUNTED";
                    } elseif ($usage !== null) {
                        if ($usage >= 95) {
                            $diskStatus = "CRITICAL";
                        } elseif ($usage >= 90) {
                            $diskStatus = "HIGH";
                        } elseif ($usage >= 80) {
                            $diskStatus = "ELEVATED";
                        } else {
                            $diskStatus = "HEALTHY";
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        if ($status === "OK") {
            $statusClass = "status-ok";
        } elseif ($status === "WARN") {
            $statusClass = "status-warn";
        }

        return [
            "status" => $status,
            "status_class" => $statusClass,
            "disk_usage" => $diskUsage,
            "disk_status" => $diskStatus,
        ];
    }

    private static function cfgString(string $key, string $default): string
    {
        $value = (string) cfg_local($key, $default);
        return $value !== "" ? $value : $default;
    }
}
