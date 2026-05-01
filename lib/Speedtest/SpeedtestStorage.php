<?php

namespace App\Speedtest;

final class SpeedtestStorage
{
    public static function stateDir(): string
    {
        $baseDir = dirname(dashboard_state_path("speedtest/.keep"));
        $legacyDir = $baseDir . "/speedtest";
        foreach ([$baseDir, $legacyDir] as $candidate) {
            if (is_file($candidate . "/speedtest_history.ndjson") || is_file($candidate . "/speedtest_meta.json")) {
                if (!is_dir($candidate)) {
                    @mkdir($candidate, 0775, true);
                }
                return $candidate;
            }
        }
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        return $baseDir;
    }

    public static function historyPath(): string
    {
        return self::stateDir() . "/speedtest_history.ndjson";
    }

    public static function metaPath(): string
    {
        return self::stateDir() . "/speedtest_meta.json";
    }

    public static function loadMeta(): array
    {
        $defaults = [
            "last_attempted_at" => null,
            "last_success_at" => null,
            "last_backend" => "",
            "last_backend_version" => "",
            "last_error" => "",
            "next_due_at" => null,
            "last_duration_ms" => null,
            "history_invalid_lines" => 0,
        ];
        $path = self::metaPath();
        if (!is_file($path)) {
            return $defaults;
        }
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            return $defaults;
        }
        return array_merge($defaults, $json);
    }

    public static function saveMeta(array $patch): array
    {
        $meta = array_merge(self::loadMeta(), $patch);
        dashboard_atomic_write(self::metaPath(), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return $meta;
    }
}
