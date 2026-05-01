<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/paths.php";

final class AdminAudit
{
    public static function sources(): array
    {
        return [
            [
                "key" => "admin",
                "label" => "Structured Admin Audit",
                "paths" => [STATE_DIR . "/logs/admin_audit.log"],
                "description" =>
                    "Structured audit trail for config changes, backup actions, service actions, bundle generation, and privileged reads.",
            ],
            [
                "key" => "security",
                "label" => "Security Events",
                "paths" => [DATA_DIR . "/logs/security.log"],
                "description" =>
                    "Token rotations, reveal authorizations, and other admin security actions.",
            ],
            [
                "key" => "diagnostics",
                "label" => "Diagnostic Actions",
                "paths" => [
                    STATE_DIR . "/logs/diag_audit.log",
                    STATE_DIR . "/diag_audit.log",
                ],
                "description" =>
                    "Manual diagnostic and server-test actions recorded by the dashboard.",
            ],
        ];
    }

    public static function buildReport(int $perSourceLimit = 80): array
    {
        $sources = [];
        $summary = [
            "total_events" => 0,
            "sources_present" => 0,
            "latest_time" => null,
        ];

        foreach (self::sources() as $source) {
            $paths =
                isset($source["paths"]) && is_array($source["paths"])
                    ? $source["paths"]
                    : [(string) ($source["path"] ?? "")];
            $events = self::tailEventsFromPaths($paths, $perSourceLimit);
            $counts = [];
            foreach ($events as $event) {
                $message = (string) ($event["message"] ?? "event");
                $counts[$message] = ($counts[$message] ?? 0) + 1;
                $summary["total_events"]++;
                if (
                    !empty($event["time"]) &&
                    ($summary["latest_time"] === null ||
                        strcmp(
                            (string) $event["time"],
                            (string) $summary["latest_time"],
                        ) > 0)
                ) {
                    $summary["latest_time"] = $event["time"];
                }
            }
            arsort($counts);
            $source["exists"] = false;
            $source["path"] = "";
            foreach ($paths as $path) {
                if ($path !== "" && is_file($path)) {
                    $source["exists"] = true;
                    $source["path"] = $path;
                    break;
                }
            }
            if ($source["path"] === "" && $paths) {
                $source["path"] = (string) $paths[0];
            }
            $source["events"] = $events;
            $source["message_counts"] = $counts;
            $source["event_count"] = count($events);
            if ($source["exists"]) {
                $summary["sources_present"]++;
            }
            $sources[] = $source;
        }

        return [
            "generated_at" => date("c"),
            "summary" => $summary,
            "sources" => $sources,
        ];
    }

    public static function tailEvents(string $path, int $limit = 80): array
    {
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || !$lines) {
            return [];
        }

        $slice = array_slice($lines, -(1 * max(1, $limit)));
        $events = [];
        foreach ($slice as $line) {
            $event = self::parseLine((string) $line);
            if ($event !== []) {
                $events[] = $event;
            }
        }

        usort($events, function (array $a, array $b): int {
            return strcmp(
                (string) ($b["time"] ?? ""),
                (string) ($a["time"] ?? ""),
            );
        });

        return $events;
    }

    public static function tailEventsFromPaths(
        array $paths,
        int $limit = 80,
    ): array {
        $events = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === "" || !is_file($path)) {
                continue;
            }
            $events = array_merge($events, self::tailEvents($path, $limit));
        }
        usort($events, function (array $a, array $b): int {
            return strcmp(
                (string) ($b["time"] ?? ""),
                (string) ($a["time"] ?? ""),
            );
        });
        return array_slice($events, 0, max(1, $limit));
    }

    public static function parseLine(string $line): array
    {
        $line = trim($line);
        if ($line === "") {
            return [];
        }

        return [
            "raw" => $line,
            "time" => self::extractField($line, "Time"),
            "category" => self::extractField($line, "Category"),
            "message" => self::extractField($line, "Message"),
            "user" => self::extractField($line, "User"),
            "ip" => self::extractField($line, "IP"),
            "context" => self::extractField($line, "Context"),
            "pid" => self::extractField($line, "PID"),
        ];
    }

    private static function extractField(string $line, string $field): string
    {
        if (
            !preg_match(
                "/\b" .
                    preg_quote($field, "/") .
                    '=("([^"\\\\]|\\\\.)*"|\{.*?\}|\S+)/',
                $line,
                $m,
            )
        ) {
            return "";
        }
        $value = (string) $m[1];
        $decoded = json_decode($value, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        if (is_array($decoded)) {
            $json = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
            return is_string($json) ? $json : $value;
        }
        return trim($value, '"');
    }
}
