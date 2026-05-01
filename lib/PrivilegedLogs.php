<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../includes/logger.php";
require_once __DIR__ . "/AuditLog.php";

final class PrivilegedLogs
{
    private const CONFIG_FILE = BASE_DIR . "/config/privileged_logs.json";
    private const BRIDGE_FILE = BASE_DIR . "/scripts/log_bridge.sh";
    private const AUDIT_FILE = STATE_DIR . "/logs/privileged_log_access.log";
    private const GLOBAL_MAX_LINES = 500;

    public static function all(): array
    {
        $raw = read_json_or_default(self::CONFIG_FILE, ["logs" => []]);
        $items = isset($raw["logs"]) && is_array($raw["logs"]) ? $raw["logs"] : [];
        $catalog = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = strtolower(trim((string) ($item["key"] ?? "")));
            if ($key === "" || !preg_match("/^[a-z0-9_]+$/", $key)) {
                continue;
            }

            $label = trim((string) ($item["label"] ?? ""));
            if ($label === "") {
                $label = strtoupper(str_replace("_", " ", $key));
            }

            $source = isset($item["source"]) && is_array($item["source"]) ? $item["source"] : [];
            $type = strtolower(trim((string) ($source["type"] ?? "file")));
            if (!in_array($type, ["file", "journal"], true)) {
                continue;
            }

            $path = trim((string) ($source["path"] ?? ""));
            $unit = trim((string) ($source["unit"] ?? ""));
            if ($type === "file" && ($path === "" || $path[0] !== "/")) {
                continue;
            }
            if ($type === "journal" && ($unit === "" || !preg_match("/^[A-Za-z0-9_.@:-]+$/", $unit))) {
                continue;
            }

            $defaultLines = max(25, (int) ($item["default_lines"] ?? 120));
            $maxLines = max($defaultLines, (int) ($item["max_lines"] ?? 300));
            $maxLines = min($maxLines, self::GLOBAL_MAX_LINES);
            $defaultLines = min($defaultLines, $maxLines);

            $catalog[$key] = [
                "key" => $key,
                "label" => $label,
                "description" => trim((string) ($item["description"] ?? "")),
                "source" => [
                    "type" => $type,
                    "path" => $path,
                    "unit" => $unit,
                ],
                "allow_search" => !array_key_exists("allow_search", $item)
                    ? true
                    : (bool) $item["allow_search"],
                "default_lines" => $defaultLines,
                "max_lines" => $maxLines,
            ];
        }

        return $catalog;
    }

    public static function get(string $key): ?array
    {
        $catalog = self::all();
        return $catalog[$key] ?? null;
    }

    public static function publicDefinitions(): array
    {
        $items = [];
        foreach (self::all() as $item) {
            $items[] = [
                "key" => $item["key"],
                "label" => $item["label"],
                "description" => $item["description"],
                "allow_search" => $item["allow_search"],
                "default_lines" => $item["default_lines"],
                "max_lines" => $item["max_lines"],
                "source_type" => $item["source"]["type"],
            ];
        }
        return $items;
    }

    public static function canAccessLive(): bool
    {
        return function_exists("user_is_admin") && user_is_admin();
    }

    public static function clampLines(?int $requested, array $definition): int
    {
        $default = (int) ($definition["default_lines"] ?? 120);
        $max = (int) ($definition["max_lines"] ?? 300);
        $max = max(25, min($max, self::GLOBAL_MAX_LINES));
        $value = $requested ?? $default;
        if ($value < 1) {
            $value = $default;
        }
        if ($value < 25) {
            $value = 25;
        }
        if ($value > $max) {
            $value = $max;
        }
        return $value;
    }

    public static function sanitizeSearch(string $search, array $definition): string
    {
        if (empty($definition["allow_search"])) {
            return "";
        }
        $search = trim(str_replace(["\r", "\n", "\0"], " ", $search));
        if ($search === "") {
            return "";
        }
        return substr($search, 0, 160);
    }

    public static function bridgePath(): string
    {
        return self::BRIDGE_FILE;
    }

    public static function run(string $key, ?int $requestedLines, string $search = ""): array
    {
        if (!self::canAccessLive()) {
            return ["ok" => false, "error" => "Admin privileges are required for live privileged logs."];
        }

        $definition = self::get($key);
        if ($definition === null) {
            return ["ok" => false, "error" => "Unknown privileged log key."];
        }

        $bridge = self::bridgePath();
        if (!is_file($bridge)) {
            return ["ok" => false, "error" => "Privileged log bridge is not installed."];
        }

        $lines = self::clampLines($requestedLines, $definition);
        $search = self::sanitizeSearch($search, $definition);

        // Trust boundary: the browser sends only a logical key and safe literals.
        // PHP validates that key, clamps inputs, and invokes one exact sudo target.
        $cmd = "sudo -n "
            . escapeshellarg($bridge)
            . " --key " . escapeshellarg($definition["key"])
            . " --mode tail"
            . " --lines " . escapeshellarg((string) $lines);
        if ($search !== "") {
            $cmd .= " --search " . escapeshellarg($search);
        }

        $spec = [
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];
        $proc = @proc_open($cmd, $spec, $pipes, BASE_DIR);
        if (!is_resource($proc)) {
            return ["ok" => false, "error" => "Failed to start privileged log bridge."];
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $detail = trim((string) $stderr);
            $error = "Privileged log read failed.";
            if (stripos($detail, "password is required") !== false || stripos($detail, "a password is required") !== false) {
                $error = "sudoers is not configured for the privileged log bridge.";
            } elseif (stripos($detail, "not allowed") !== false || stripos($detail, "denied") !== false) {
                $error = "This privileged log request was denied.";
            } elseif ($detail !== "") {
                $error = $detail;
            }

            self::audit($definition["key"], $lines, $search, false, $error);
            return ["ok" => false, "error" => $error];
        }

        $output = is_string($stdout) ? str_replace("\r\n", "\n", $stdout) : "";
        self::audit($definition["key"], $lines, $search, true, "");

        return [
            "ok" => true,
            "key" => $definition["key"],
            "label" => $definition["label"],
            "lines" => $lines,
            "search" => $search,
            "text" => rtrim($output, "\n"),
        ];
    }

    private static function audit(
        string $key,
        int $lines,
        string $search,
        bool $ok,
        string $error
    ): void {
        dashboard_log_append(
            self::AUDIT_FILE,
            "privileged_logs",
            $ok ? "read " . $key : "read_failed " . $key,
            [
                "requested_lines" => $lines,
                "search" => $search !== "" ? $search : "(none)",
                "status" => $ok ? "ok" : "fail",
                "note" => $error,
            ],
        );
        AuditLog::record(
            "privileged_log.read",
            $key,
            $ok,
            [
                "requested_lines" => $lines,
                "search" => $search !== "" ? $search : "(none)",
                "note" => $error,
            ],
            $ok ? "privileged log read ok" : "privileged log read failed",
            "privileged_logs",
        );
    }
}
