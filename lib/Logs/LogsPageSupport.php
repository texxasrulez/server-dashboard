<?php

namespace App\Logs;

final class LogsPageSupport
{
    public static function setup(string $projectRoot): array
    {
        $logSrc = "/var/log";
        $stateDir = $projectRoot . "/state";
        $logDest = $stateDir . "/logs_mirror";
        $logCfg = $stateDir . "/logs_config.json";

        @umask(0002);
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0775, true);
        }
        @mkdir($logDest, 0775, true);
        if (!is_dir($logDest)) {
            @mkdir($logDest, 0775, true);
        }
        if (!file_exists($logCfg)) {
            @file_put_contents($logCfg, json_encode([]));
            @chmod($logCfg, 0664);
        }

        return [
            "log_src" => $logSrc,
            "state_dir" => $stateDir,
            "log_dest" => $logDest,
            "log_cfg" => $logCfg,
        ];
    }

    public static function mirrorActivityLog(string $projectRoot, string $status, string $action, string $name, string $note = ""): void
    {
        $state = $projectRoot . "/state";
        @mkdir($state, 0775, true);
        $logf = $state . "/mirror_activity.log";
        dashboard_log_append($logf, "logs_mirror", $action . " " . $name, [
            "status" => $status,
            "note" => $note,
        ]);
        @chmod($logf, 0664);
    }

    public static function resolveCandidatePath(string $input, array $roots): array
    {
        $input = trim($input);
        if ($input === "") {
            return [null, null];
        }
        if ($input[0] !== "/") {
            $input = "/var/log/" . ltrim($input, "/");
        }
        $real = @realpath($input);
        if (!$real) {
            return [null, null];
        }
        if (!preg_match("/\\.log(?:\\.\\d+)?(?:\\.gz)?$/i", basename($real))) {
            return [null, null];
        }
        foreach ($roots as $root) {
            $rootReal = @realpath($root);
            if ($rootReal && strpos($real, rtrim($rootReal, "/")) === 0) {
                return [$real, $rootReal];
            }
        }
        return [null, null];
    }

    public static function copyLogFile(string $projectRoot, string $srcFile, string $destDir, string $logSrc, string $logDest): bool
    {
        if (!is_file($srcFile) || is_link($srcFile) || !is_readable($srcFile)) {
            self::mirrorActivityLog($projectRoot, "fail", "copy", basename($srcFile), "not-readable");
            return false;
        }
        if (!preg_match("/\\.log(?:\\.\\d+)?(?:\\.gz)?$/i", basename($srcFile))) {
            self::mirrorActivityLog($projectRoot, "skip", "copy", basename($srcFile), "not-log");
            return false;
        }
        $srcReal = @realpath($srcFile);
        if (!$srcReal) {
            self::mirrorActivityLog($projectRoot, "fail", "copy", basename($srcFile), "no-realpath");
            return false;
        }

        $rel = null;
        foreach ([$logSrc, $logDest] as $root) {
            $rootReal = @realpath($root);
            if ($rootReal && strpos($srcReal, rtrim($rootReal, "/")) === 0) {
                $rel = ltrim(substr($srcReal, strlen(rtrim($rootReal, "/"))), "/");
                break;
            }
        }
        if ($rel === null || $rel === "") {
            $rel = basename($srcReal);
        }
        $safe = preg_replace("/[^a-zA-Z0-9._-]+/", "__", $rel) ?? basename($srcReal);
        if (!preg_match("/\\.log(?:\\.\\d+)?(?:\\.gz)?$/i", $safe)) {
            self::mirrorActivityLog($projectRoot, "skip", "copy", $safe, "not-log");
            return false;
        }

        $dest = rtrim($destDir, "/") . "/" . $safe;
        $need = !file_exists($dest) || (filemtime($srcReal) > @filemtime($dest));
        if (!$need) {
            self::mirrorActivityLog($projectRoot, "skip", "copy", $safe, "up-to-date");
            return true;
        }

        $in = @fopen($srcReal, "rb");
        $out = @fopen($dest, "wb");
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            self::mirrorActivityLog($projectRoot, "fail", "copy", $safe, "open");
            return false;
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @chmod($dest, 0644);
        self::mirrorActivityLog($projectRoot, "ok", "copy", $safe);
        return true;
    }

    public static function mirrorLogsDir(string $projectRoot, string $srcDir, string $destDir, string $logSrc, string $logDest): void
    {
        if (!is_dir($srcDir) || !is_readable($srcDir)) {
            return;
        }
        $dh = @opendir($srcDir);
        if (!$dh) {
            return;
        }
        while (($entry = readdir($dh)) !== false) {
            if ($entry === "." || $entry === "..") {
                continue;
            }
            $path = $srcDir . "/" . $entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            if (!preg_match("/\\.log(?:\\.\\d+)?(?:\\.gz)?$/i", $entry)) {
                continue;
            }
            self::copyLogFile($projectRoot, $path, $destDir, $logSrc, $logDest);
        }
        closedir($dh);
    }

    public static function readLogConfig(string $path): array
    {
        $raw = @file_get_contents($path);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    public static function writeLogConfig(string $path, array $arr): void
    {
        @file_put_contents($path, json_encode(array_values($arr), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0664);
    }
}
