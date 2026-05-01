<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/logger.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/AdminAudit.php";
require_once __DIR__ . "/Redaction.php";

final class AuditLog
{
    public const FILE = STATE_DIR . "/logs/admin_audit.log";

    public static function record(
        string $action,
        string $target,
        bool $ok,
        array $meta = [],
        ?string $message = null,
        ?string $category = null
    ): void {
        $ctx = [
            "action" => $action,
            "target" => $target,
            "result" => $ok ? "success" : "failure",
            "meta" => Redaction::sanitizeForPersisted($meta),
        ];

        dashboard_log_append(
            self::FILE,
            $category ?: self::categoryFor($action),
            $message ?: self::messageFor($action, $target, $ok),
            $ctx,
        );
    }

    public static function tail(int $limit = 120): array
    {
        if (!is_file(self::FILE)) {
            return [];
        }

        $lines = @file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        $slice = array_slice($lines, -max(1, $limit));
        $events = [];
        foreach ($slice as $line) {
            $event = AdminAudit::parseLine((string) $line);
            if ($event !== []) {
                $events[] = $event;
            }
        }

        usort($events, function (array $a, array $b): int {
            return strcmp((string) ($b["time"] ?? ""), (string) ($a["time"] ?? ""));
        });

        return $events;
    }

    private static function categoryFor(string $action): string
    {
        if (strpos($action, "config.") === 0) {
            return "config";
        }
        if (strpos($action, "backup.") === 0 || strpos($action, "restore.") === 0) {
            return "backup";
        }
        if (strpos($action, "service.") === 0) {
            return "service";
        }
        if (strpos($action, "bundle.") === 0) {
            return "bundle";
        }
        if (strpos($action, "privileged_log.") === 0) {
            return "privileged_logs";
        }
        return "admin";
    }

    private static function messageFor(string $action, string $target, bool $ok): string
    {
        $suffix = $ok ? "ok" : "failed";
        return trim($action . " " . $target . " " . $suffix);
    }
}
