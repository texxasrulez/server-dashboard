<?php

namespace App\ServerDiag;

final class ServerDiagRuntimeChecks
{
    public static function build(): array
    {
        $checks = [];
        $checks[] = self::checkPhpVersion();
        foreach (self::checkPhpExtensions() as $check) {
            $checks[] = $check;
        }
        $checks[] = self::checkRuntimeIni();
        return $checks;
    }

    private static function checkPhpVersion(): array
    {
        $version = PHP_VERSION;
        if (version_compare($version, "8.1.0", ">=")) {
            return self::item("PHP version", "ok", $version, "Runtime is within the supported baseline.");
        }
        if (version_compare($version, "8.0.0", ">=")) {
            return self::item("PHP version", "warn", $version, "Upgrade to PHP 8.1+ for a safer maintenance baseline.");
        }
        return self::item("PHP version", "fail", $version, "Upgrade PHP before relying on this dashboard in production.");
    }

    private static function checkPhpExtensions(): array
    {
        $required = ["json", "session", "openssl"];
        $recommended = ["curl", "mbstring"];
        $out = [];
        foreach ($required as $ext) {
            $loaded = extension_loaded($ext);
            $out[] = self::item(
                "PHP extension: " . $ext,
                $loaded ? "ok" : "fail",
                $loaded ? "loaded" : "missing",
                $loaded ? "Required extension is available." : "Install/enable this extension; parts of the dashboard depend on it.",
            );
        }
        foreach ($recommended as $ext) {
            $loaded = extension_loaded($ext);
            $out[] = self::item(
                "PHP extension: " . $ext,
                $loaded ? "ok" : "warn",
                $loaded ? "loaded" : "missing",
                $loaded ? "Recommended extension is available." : "Recommended for better compatibility and fewer fallback code paths.",
            );
        }
        return $out;
    }

    private static function checkRuntimeIni(): array
    {
        $memory = ini_get("memory_limit");
        $memoryBytes = \App\ServerDiag::bytes_from_ini($memory);
        $execTime = (int) ini_get("max_execution_time");
        $parts = [];
        $status = "ok";
        if ($memoryBytes !== -1 && $memoryBytes !== null && $memoryBytes < 128 * 1024 * 1024) {
            $status = "warn";
        }
        $parts[] = "memory_limit=" . $memory;
        if ($execTime > 0 && $execTime < 30) {
            $status = "warn";
        }
        $parts[] = "max_execution_time=" . $execTime . "s";
        $opcacheEnabled = (bool) ini_get("opcache.enable");
        if (!$opcacheEnabled) {
            $status = $status === "fail" ? "fail" : "warn";
            $parts[] = "opcache=off";
        } else {
            $parts[] = "opcache=on";
        }
        return self::item(
            "Runtime tuning",
            $status,
            implode(" | ", $parts),
            $status === "ok" ? "No obvious PHP runtime limits detected." : "Review PHP runtime limits before expecting stable admin usage under load.",
        );
    }

    private static function item(string $name, string $status, string $details, string $action): array
    {
        return ["name" => $name, "status" => $status, "details" => $details, "action" => $action];
    }
}
