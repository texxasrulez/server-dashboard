<?php

// includes/paths.php — centralize filesystem roots and helpers
declare(strict_types=1);

if (!defined("BASE_DIR")) {
    define("BASE_DIR", realpath(__DIR__ . "/..") ?: __DIR__ . "/..");
}
if (!defined("DATA_DIR")) {
    define("DATA_DIR", getenv("APP_DATA_DIR") ?: BASE_DIR . "/data");
}
if (!defined("STATE_DIR")) {
    define("STATE_DIR", getenv("APP_STATE_DIR") ?: BASE_DIR . "/state");
}

if (!function_exists("ensure_dir")) {
    function ensure_dir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("mkdir failed: " . $dir);
        }
    }
}

if (!function_exists("write_json_atomic")) {
    function write_json_atomic(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("json_encode failed");
        }
        write_text_atomic($path, $json);
    }
}

if (!function_exists("write_text_atomic")) {
    function sync_default_metadata(string $path, ?string $source = null): void
    {
        $sourcePath = $source ?: (is_file($path) ? $path : dirname($path));
        $owner = @fileowner($sourcePath);
        $group = @filegroup($sourcePath);

        if ($owner !== false && is_int($owner)) {
            @chown($path, $owner);
        }
        if ($group !== false && is_int($group)) {
            @chgrp($path, $group);
        }
    }
}

if (!function_exists("write_text_atomic")) {
    function create_atomic_tmp_path(string $dir, string $path): string
    {
        $prefix = basename($path) . ".tmp.";
        $tmp = @tempnam($dir, $prefix);
        if (is_string($tmp) && $tmp !== "") {
            return $tmp;
        }

        try {
            $rand = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $rand = (string) mt_rand();
        }

        return $dir . DIRECTORY_SEPARATOR . $prefix . $rand;
    }
}

if (!function_exists("write_text_atomic")) {
    function write_text_atomic(
        string $path,
        string $contents,
        int $mode = 0640,
    ): void {
        $dir = dirname($path);
        ensure_dir($dir);
        $metadataSource = is_file($path) ? $path : $dir;
        $tmp = create_atomic_tmp_path($dir, $path);
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException("write failed: " . $tmp);
        }
        sync_default_metadata($tmp, $metadataSource);
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            if (@file_put_contents($path, $contents, LOCK_EX) !== false) {
                @unlink($tmp);
                sync_default_metadata($path, $metadataSource);
                @chmod($path, $mode);
                return;
            }
            @unlink($tmp);
            throw new RuntimeException("rename failed: " . $path);
        }
        sync_default_metadata($path, $metadataSource);
    }
}

if (!function_exists("append_line_locked")) {
    function append_line_locked(
        string $path,
        string $line,
        int $mode = 0640,
    ): void {
        $dir = dirname($path);
        ensure_dir($dir);
        $metadataSource = is_file($path) ? $path : $dir;
        $fh = @fopen($path, "ab");
        if (!$fh) {
            throw new RuntimeException("open failed: " . $path);
        }
        try {
            if (!@flock($fh, LOCK_EX)) {
                throw new RuntimeException("lock failed: " . $path);
            }
            if (@fwrite($fh, $line) === false) {
                throw new RuntimeException("append failed: " . $path);
            }
            @fflush($fh);
            @flock($fh, LOCK_UN);
        } finally {
            @fclose($fh);
        }
        sync_default_metadata($path, $metadataSource);
        if (!@chmod($path, $mode) && !is_file($path)) {
            throw new RuntimeException("chmod failed: " . $path);
        }
    }
}

if (!function_exists("read_json_or_default")) {
    function read_json_or_default(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === "") {
            return $default;
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : $default;
    }
}
