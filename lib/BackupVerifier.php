<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/AuditLog.php";

final class BackupVerifier
{
    public const STATE_FILE = STATE_DIR . "/backup_restore_verification.json";

    public static function latest(): array
    {
        $state = read_json_or_default(self::STATE_FILE, ["history" => []]);
        $history = is_array($state["history"] ?? null) ? $state["history"] : [];
        $latest = end($history);
        return is_array($latest) ? $latest : [];
    }

    public static function history(int $limit = 12): array
    {
        $state = read_json_or_default(self::STATE_FILE, ["history" => []]);
        $history = is_array($state["history"] ?? null) ? $state["history"] : [];
        return array_slice(array_reverse($history), 0, max(1, $limit));
    }

    public static function verifyNow(): array
    {
        $checks = [];
        $backupRoot = trim((string) cfg_local("backups.fs_root", "/mnt/backupz"));
        $dirsRaw = (string) cfg_local("backups.fs_dirs", "hestia\nmicro\nsnapshots");
        $dirs = array_values(array_filter(array_map("trim", preg_split('/[\s,]+/', $dirsRaw) ?: [])));
        $dirs = $dirs ?: ["hestia", "micro", "snapshots"];

        foreach ($dirs as $dir) {
            $checks[] = self::verifyDirectory($backupRoot . "/" . $dir, $dir);
        }

        $hestiaDir = trim((string) cfg_local("backups.hestia_source_dir", "/backup"));
        if ($hestiaDir !== "") {
            $checks[] = self::verifyDirectory($hestiaDir, "hestia_source");
        }

        $overall = "pass";
        foreach ($checks as $check) {
            if (($check["result"] ?? "pass") === "fail") {
                $overall = "fail";
                break;
            }
            if (($check["result"] ?? "pass") === "warn" && $overall !== "fail") {
                $overall = "warn";
            }
        }

        $entry = [
            "ts" => date("c"),
            "method" => "integrity-verified",
            "result" => $overall,
            "checks" => $checks,
        ];

        $state = read_json_or_default(self::STATE_FILE, ["history" => []]);
        $history = is_array($state["history"] ?? null) ? $state["history"] : [];
        $history[] = $entry;
        if (count($history) > 24) {
            $history = array_slice($history, -24);
        }
        write_json_atomic(self::STATE_FILE, ["history" => $history]);

        AuditLog::record(
            "backup.verify",
            "artifacts",
            $overall !== "fail",
            ["result" => $overall, "checks" => $checks],
            "backup verification completed",
            "backup",
        );

        return $entry;
    }

    private static function verifyDirectory(string $path, string $label): array
    {
        if (!is_dir($path)) {
            return [
                "label" => $label,
                "path" => $path,
                "result" => "warn",
                "reason" => "directory missing",
            ];
        }

        $files = array_values(array_filter(glob(rtrim($path, "/") . "/*") ?: [], "is_file"));
        if ($files === []) {
            return [
                "label" => $label,
                "path" => $path,
                "result" => "warn",
                "reason" => "no files found",
            ];
        }

        usort($files, function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });
        $latest = $files[0];
        $size = (int) @filesize($latest);
        $mtime = (int) @filemtime($latest);
        $ext = strtolower(pathinfo($latest, PATHINFO_EXTENSION));

        $result = "pass";
        $reason = "latest artifact looks sane";
        if ($size <= 0) {
            $result = "fail";
            $reason = "latest file is empty";
        } elseif ($mtime < (time() - 14 * 86400)) {
            $result = "warn";
            $reason = "latest file is older than 14 days";
        } elseif (!self::lightweightIntegrityCheck($latest, $ext)) {
            $result = "fail";
            $reason = "lightweight integrity check failed";
        }

        return [
            "label" => $label,
            "path" => $path,
            "latest_file" => basename($latest),
            "latest_mtime" => date("c", $mtime),
            "size" => $size,
            "result" => $result,
            "reason" => $reason,
        ];
    }

    private static function lightweightIntegrityCheck(string $file, string $ext): bool
    {
        if (!is_readable($file)) {
            return false;
        }

        if ($ext === "zip" && class_exists("ZipArchive")) {
            $zip = new ZipArchive();
            $ok = $zip->open($file) === true;
            if ($ok) {
                $ok = $zip->numFiles > 0;
                $zip->close();
            }
            return $ok;
        }

        if ($ext === "tar" && class_exists("PharData")) {
            try {
                $tar = new PharData($file);
                return iterator_count($tar) >= 0;
            } catch (Throwable $e) {
                return false;
            }
        }

        if (in_array($ext, ["gz", "tgz"], true) && function_exists("gzopen")) {
            $fh = @gzopen($file, "rb");
            if (!is_resource($fh)) {
                return false;
            }
            $chunk = @gzread($fh, 512);
            @gzclose($fh);
            return is_string($chunk);
        }

        return filesize($file) > 0;
    }
}
