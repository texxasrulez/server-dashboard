<?php

namespace App\Config;

final class ConfigPaths
{
    public static function initConfigPath(string $rootDir): string
    {
        $path = rtrim($rootDir, "/") . "/config";
        if (!is_dir($path)) {
            throw new \RuntimeException("Missing config directory at: " . $path);
        }
        return $path;
    }

    public static function projectRoot(?string $configPath): string
    {
        if (is_string($configPath) && $configPath !== "") {
            return dirname($configPath);
        }
        return dirname(__DIR__, 2);
    }

    public static function resolveFirstExistingPage(
        ?string $configPath,
        array $candidates,
    ): ?string {
        $root = self::projectRoot($configPath);
        foreach ($candidates as $candidate) {
            if ($candidate !== "" && is_file($root . "/" . $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function listThemes(?string $configPath): array
    {
        $dir = self::projectRoot($configPath) . "/assets/css/themes";
        $out = [];

        if (is_dir($dir)) {
            foreach (glob($dir . "/*.css") ?: [] as $file) {
                $base = basename($file);
                if ($base === "core.css" || substr($base, -11) === ".mobile.css") {
                    continue;
                }
                $name = preg_replace('/\.css$/', "", $base);
                if (is_string($name) && $name !== "") {
                    $out[$name] = true;
                }
            }

            foreach (glob($dir . "/*/theme.css") ?: [] as $file) {
                $name = basename(dirname($file));
                if ($name !== "") {
                    $out[$name] = true;
                }
            }
        }

        $names = array_keys($out);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        if (
            !in_array("default", $names, true) &&
            is_file($dir . "/default.css")
        ) {
            array_unshift($names, "default");
        }

        return $names;
    }
}
