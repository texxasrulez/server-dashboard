<?php

namespace App\Auth;

final class AuthRateLimiter
{
    public static function config(): array
    {
        $cfg = AuthSupport::config("security.login_rate_limit", []);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        return [
            "enabled" => array_key_exists("enabled", $cfg) ? (bool) $cfg["enabled"] : true,
            "max_attempts" => max(1, (int) ($cfg["max_attempts"] ?? 5)),
            "window_sec" => max(30, (int) ($cfg["window_sec"] ?? 900)),
            "base_delay_sec" => max(1, (int) ($cfg["base_delay_sec"] ?? 30)),
            "max_delay_sec" => max(5, (int) ($cfg["max_delay_sec"] ?? 900)),
        ];
    }

    public static function filePath(): string
    {
        $dir = dirname(__DIR__, 2) . "/state/auth";
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . "/login_rate.json";
    }

    public static function load(): array
    {
        $file = self::filePath();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === "") {
            return [];
        }
        $data = @json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function save($data): void
    {
        $file = self::filePath();
        $tmp = $file . ".tmp";
        @file_put_contents(
            $tmp,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
        @chmod($tmp, 0640);
        @rename($tmp, $file);
    }

    public static function key($username, $ip): string
    {
        $u = strtolower(trim((string) $username));
        $i = trim((string) $ip);
        if ($u === "") {
            $u = "unknown";
        }
        if ($i === "") {
            $i = "0.0.0.0";
        }
        return hash("sha256", $u . "|" . $i);
    }

    public static function prune($all, $windowSec, $now): array
    {
        $out = [];
        foreach ((array) $all as $k => $row) {
            if (!is_array($row)) {
                continue;
            }
            $last = isset($row["last"]) ? (int) $row["last"] : 0;
            $next = isset($row["next_allowed"]) ? (int) $row["next_allowed"] : 0;
            if (
                $last >= ($now - ($windowSec * 2)) ||
                $next >= ($now - ($windowSec * 2))
            ) {
                $out[$k] = $row;
            }
        }
        return $out;
    }

    public static function blockSeconds($username, $ip): int
    {
        $cfg = self::config();
        if (empty($cfg["enabled"])) {
            return 0;
        }
        $all = self::load();
        $row = isset($all[self::key($username, $ip)]) &&
            is_array($all[self::key($username, $ip)])
            ? $all[self::key($username, $ip)]
            : [];
        $now = time();
        $next = isset($row["next_allowed"]) ? (int) $row["next_allowed"] : 0;
        return $next > $now ? $next - $now : 0;
    }

    public static function registerFailure($username, $ip): void
    {
        $cfg = self::config();
        if (empty($cfg["enabled"])) {
            return;
        }
        $all = self::load();
        $now = time();
        $all = self::prune($all, $cfg["window_sec"], $now);
        $key = self::key($username, $ip);
        $row = isset($all[$key]) && is_array($all[$key])
            ? $all[$key]
            : ["count" => 0, "first" => $now, "last" => $now, "next_allowed" => 0];
        $first = isset($row["first"]) ? (int) $row["first"] : $now;
        if (($now - $first) > $cfg["window_sec"]) {
            $row = ["count" => 0, "first" => $now, "last" => $now, "next_allowed" => 0];
        }
        $row["count"] = ((int) ($row["count"] ?? 0)) + 1;
        $row["last"] = $now;
        if ($row["count"] >= $cfg["max_attempts"]) {
            $overs = $row["count"] - $cfg["max_attempts"];
            $delay = $cfg["base_delay_sec"] * (1 << min(6, $overs));
            if ($delay > $cfg["max_delay_sec"]) {
                $delay = $cfg["max_delay_sec"];
            }
            $row["next_allowed"] = $now + $delay;
        }
        $all[$key] = $row;
        self::save($all);
    }

    public static function clear($username, $ip): void
    {
        $cfg = self::config();
        if (empty($cfg["enabled"])) {
            return;
        }
        $all = self::load();
        $key = self::key($username, $ip);
        if (isset($all[$key])) {
            unset($all[$key]);
        }
        self::save(self::prune($all, $cfg["window_sec"], time()));
    }
}
