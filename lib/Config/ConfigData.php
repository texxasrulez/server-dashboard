<?php

namespace App\Config;

final class ConfigData
{
    public static function readLocal(string $configPath): array
    {
        $file = $configPath . "/local.json";
        if (!file_exists($file)) {
            $local = [];
        } else {
            $json = file_get_contents($file);
            $data = json_decode($json, true);
            $local = is_array($data) ? $data : [];
        }

        return self::normalizeFeatureConfig(
            ConfigLegacy::mergeLegacySecurity($configPath, $local),
        );
    }

    public static function writeLocal(string $configPath, array $data): void
    {
        \write_json_atomic($configPath . "/local.json", $data);
    }

    public static function ensureSecret(string $configPath, string $key): string
    {
        $curr = self::readLocal($configPath);
        if (!isset($curr["security"])) {
            $curr["security"] = [];
        }
        if (empty($curr["security"][$key])) {
            $curr["security"][$key] = bin2hex(random_bytes(32));
            self::writeLocal($configPath, $curr);
        }
        return $curr["security"][$key];
    }

    public static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::merge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    public static function diff(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $bv = $b[$k] ?? null;
            if (is_array($v) && is_array($bv)) {
                $d = self::diff($v, $bv);
                if ($d !== []) {
                    $out[$k] = $d;
                }
            } elseif ($v !== $bv) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function normalizeFeatureConfig(array $local): array
    {
        $features = isset($local["features"]) && is_array($local["features"])
            ? $local["features"]
            : [];
        $legacyMap = [
            "enable_server_tests" => "server_tests",
            "enable_bookmarks" => "bookmarks",
            "enable_diagnostics" => "diagnostics",
        ];

        foreach ($legacyMap as $oldKey => $newKey) {
            if (
                array_key_exists($oldKey, $features) &&
                !array_key_exists($newKey, $features)
            ) {
                $features[$newKey] = (bool) $features[$oldKey];
            }
            unset($features[$oldKey]);
        }

        if ($features === []) {
            unset($local["features"]);
            return $local;
        }

        $local["features"] = $features;
        return $local;
    }

    public static function pruneUnchangedEmpty(array $node, array $patch): array
    {
        foreach ($node as $k => $v) {
            $p = $patch[$k] ?? null;
            if (is_array($v)) {
                if ($v === [] && $p === null) {
                    unset($node[$k]);
                    continue;
                }
                if (is_array($p)) {
                    $node[$k] = self::pruneUnchangedEmpty($v, $p);
                }
            }
        }
        return $node;
    }

    public static function applyEnvOverrides(array $cfg): array
    {
        foreach ($_ENV as $k => $v) {
            if (strpos($k, "APP__") !== 0) {
                continue;
            }
            $path = strtolower(str_replace("__", ".", substr($k, 5)));
            $segments = explode(".", $path);
            $node = &$cfg;
            foreach ($segments as $i => $seg) {
                if ($i === count($segments) - 1) {
                    $node[$seg] = self::castEnv($v);
                } else {
                    if (!isset($node[$seg]) || !is_array($node[$seg])) {
                        $node[$seg] = [];
                    }
                    $node = &$node[$seg];
                }
            }
            unset($node);
        }
        return $cfg;
    }

    public static function removePath(array &$tree, array $segments): bool
    {
        $key = array_shift($segments);
        if ($key === null || !array_key_exists($key, $tree)) {
            return false;
        }
        if ($segments === []) {
            unset($tree[$key]);
            return true;
        }
        if (!is_array($tree[$key])) {
            return false;
        }
        $changed = self::removePath($tree[$key], $segments);
        if ($changed && $tree[$key] === []) {
            unset($tree[$key]);
        }
        return $changed;
    }

    private static function castEnv($v)
    {
        $s = trim((string) $v);
        if ($s === "true") {
            return true;
        }
        if ($s === "false") {
            return false;
        }
        if (is_numeric($s)) {
            return $s + 0;
        }
        return $s;
    }
}
