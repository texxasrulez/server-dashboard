<?php

namespace App\Speedtest;

final class SpeedtestSettings
{
    public static function load(callable $ensureConfig): array
    {
        $ensureConfig();
        $cfg = \App\Config::get("speedtest", []);
        $quiet = is_array($cfg["quiet_hours"] ?? null) ? $cfg["quiet_hours"] : [];
        $legacyPreferredServer = self::sanitizePreferredServer($cfg["preferred_server"] ?? "");

        return [
            "enabled" => (bool) ($cfg["enabled"] ?? false),
            "interval_minutes" => max(1, min(10080, (int) ($cfg["interval_minutes"] ?? 240))),
            "timeout_seconds" => max(5, min(300, (int) ($cfg["timeout_seconds"] ?? 90))),
            "preferred_backend" => self::normalizeBackend((string) ($cfg["preferred_backend"] ?? "auto")),
            "preferred_server_ookla" => self::sanitizePreferredServer($cfg["preferred_server_ookla"] ?? $legacyPreferredServer),
            "preferred_server_librespeed" => self::sanitizePreferredServer($cfg["preferred_server_librespeed"] ?? $legacyPreferredServer),
            "retention_days" => max(1, min(3650, (int) ($cfg["retention_days"] ?? 90))),
            "max_history_entries" => max(10, min(50000, (int) ($cfg["max_history_entries"] ?? 2000))),
            "log_failed_tests" => (bool) ($cfg["log_failed_tests"] ?? true),
            "randomize_schedule_window" => (bool) ($cfg["randomize_schedule_window"] ?? false),
            "randomize_window_minutes" => max(0, min(240, (int) ($cfg["randomize_window_minutes"] ?? 10))),
            "quiet_hours" => [
                "enabled" => (bool) ($quiet["enabled"] ?? false),
                "start_hour" => max(0, min(23, (int) ($quiet["start_hour"] ?? 0))),
                "end_hour" => max(0, min(23, (int) ($quiet["end_hour"] ?? 0))),
            ],
        ];
    }

    public static function validatePatch(array $patch): void
    {
        if (!isset($patch["speedtest"]) || !is_array($patch["speedtest"])) {
            return;
        }
        $speedtest = $patch["speedtest"];
        if (array_key_exists("preferred_backend", $speedtest)) {
            $backend = strtolower(trim((string) $speedtest["preferred_backend"]));
            if (!in_array($backend, ["auto", "ookla", "librespeed", "speedtest"], true)) {
                throw new \InvalidArgumentException("Preferred backend must be auto, Ookla speedtest, or librespeed-cli");
            }
        }
        foreach (["preferred_server", "preferred_server_ookla", "preferred_server_librespeed"] as $key) {
            if (array_key_exists($key, $speedtest)) {
                $rawServer = trim((string) $speedtest[$key]);
                $server = self::sanitizePreferredServer($speedtest[$key]);
                if ($rawServer !== "" && $server !== $rawServer) {
                    throw new \InvalidArgumentException("Preferred server values must use only letters, numbers, dots, colons, dashes, underscores, or slashes");
                }
            }
        }
        if (isset($speedtest["quiet_hours"]) && is_array($speedtest["quiet_hours"])) {
            $quiet = $speedtest["quiet_hours"];
            $start = array_key_exists("start_hour", $quiet) ? (int) $quiet["start_hour"] : null;
            $end = array_key_exists("end_hour", $quiet) ? (int) $quiet["end_hour"] : null;
            foreach (["start_hour" => $start, "end_hour" => $end] as $label => $hour) {
                if ($hour !== null && ($hour < 0 || $hour > 23)) {
                    throw new \InvalidArgumentException("Quiet hours " . str_replace("_", " ", $label) . " must be 0 through 23");
                }
            }
            if (!empty($quiet["enabled"]) && $start !== null && $end !== null && $start === $end) {
                throw new \InvalidArgumentException("Quiet hours start and end hour must differ when quiet hours are enabled");
            }
        }
    }

    private static function normalizeBackend(string $backend): string
    {
        $backend = strtolower(trim($backend));
        if ($backend === "speedtest") {
            return "ookla";
        }
        if (!in_array($backend, ["auto", "ookla", "librespeed"], true)) {
            return "auto";
        }
        return $backend;
    }

    private static function sanitizePreferredServer($value): string
    {
        $value = trim((string) $value);
        if ($value === "" || strlen($value) > 200) {
            return "";
        }
        return preg_match("/^[A-Za-z0-9._:\\/-]+$/", $value) ? $value : "";
    }
}
