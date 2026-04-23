<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../api/_state_path.php";
require_once __DIR__ . "/AuditLog.php";
require_once __DIR__ . "/PrivilegedLogs.php";

final class ServiceDetails
{
    public static function get(string $id): ?array
    {
        $catalog = self::serviceCatalog();
        if (!isset($catalog[$id])) {
            return null;
        }

        $service = $catalog[$id];
        $history = self::historyRows([$id], 120);
        $failures = array_values(array_filter($history, function (array $row): bool {
            return in_array((string) ($row["status"] ?? ""), ["down", "warn"], true);
        }));
        $restarts = self::restartHistory($history);
        $alerts = self::recentAlerts($id);
        $audit = self::recentAudit($service);

        return [
            "service" => $service,
            "history" => $history,
            "failures" => array_slice(array_reverse($failures), 0, 12),
            "restarts" => array_slice(array_reverse($restarts), 0, 12),
            "alerts" => $alerts,
            "audit" => $audit,
            "privileged_logs" => self::matchingPrivilegedLogs($service),
        ];
    }

    public static function historyRows(array $serviceIds, int $limit = 120, ?int $from = null, ?int $to = null): array
    {
        $wanted = array_fill_keys(array_values(array_filter($serviceIds)), true);
        $path = dashboard_state_path("services_status_history.jsonl");
        if (!is_file($path) || $wanted === []) {
            return [];
        }

        $rows = [];
        $fh = @fopen($path, "rb");
        if (!$fh) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row)) {
                continue;
            }
            $sid = (string) ($row["id"] ?? ($row["service_id"] ?? ""));
            if (!isset($wanted[$sid])) {
                continue;
            }
            $ts = (int) ($row["ts"] ?? 0);
            if ($from !== null && $ts < $from) {
                continue;
            }
            if ($to !== null && $ts > $to) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > $limit * max(1, count($wanted))) {
                array_shift($rows);
            }
        }
        fclose($fh);

        usort($rows, function (array $a, array $b): int {
            return ($a["ts"] ?? 0) <=> ($b["ts"] ?? 0);
        });

        return array_slice($rows, -max(1, $limit));
    }

    private static function serviceCatalog(): array
    {
        $path = DATA_DIR . "/services.json";
        $payload = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $items = is_array($payload["items"] ?? null) ? $payload["items"] : [];
        $map = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item["id"])) {
                continue;
            }
            $item["status_meta"] = self::statusMeta((string) $item["id"]);
            $map[(string) $item["id"]] = $item;
        }
        return $map;
    }

    private static function statusMeta(string $id): array
    {
        $path = dashboard_state_path("services_status.json");
        $payload = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $results = is_array($payload["results"] ?? null) ? $payload["results"] : [];
        foreach ($results as $row) {
            if (($row["id"] ?? "") === $id) {
                return $row;
            }
        }
        return [];
    }

    private static function recentAlerts(string $serviceId): array
    {
        $path = dashboard_state_path("alerts_events.jsonl");
        if (!is_file($path)) {
            return [];
        }
        $rows = [];
        $fh = @fopen($path, "rb");
        if (!$fh) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || (string) ($row["service_id"] ?? "") !== $serviceId) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > 20) {
                array_shift($rows);
            }
        }
        fclose($fh);
        return array_reverse($rows);
    }

    private static function restartHistory(array $history): array
    {
        $events = [];
        $prev = null;
        foreach ($history as $row) {
            $status = (string) ($row["status"] ?? "");
            if ($prev !== null && $prev === "down" && $status === "up") {
                $events[] = [
                    "ts" => (int) ($row["ts"] ?? 0),
                    "label" => "Recovered from down state",
                ];
            } elseif ($prev !== null && $prev === "warn" && $status === "up") {
                $events[] = [
                    "ts" => (int) ($row["ts"] ?? 0),
                    "label" => "Recovered from warning state",
                ];
            }
            $prev = $status;
        }
        return $events;
    }

    private static function recentAudit(array $service): array
    {
        $id = (string) ($service["id"] ?? "");
        $host = (string) ($service["host"] ?? "");
        $rows = AuditLog::tail(160);
        return array_values(array_filter($rows, function (array $row) use ($id, $host): bool {
            $text = strtolower(
                (string) ($row["message"] ?? "") . " " . (string) ($row["context"] ?? "")
            );
            return ($id !== "" && strpos($text, strtolower($id)) !== false)
                || ($host !== "" && strpos($text, strtolower($host)) !== false);
        }));
    }

    private static function matchingPrivilegedLogs(array $service): array
    {
        $name = strtolower((string) (($service["name"] ?? "") ?: ""));
        $type = strtolower((string) (($service["type"] ?? "") ?: ""));
        $matches = [];
        foreach (PrivilegedLogs::publicDefinitions() as $item) {
            $key = strtolower((string) ($item["key"] ?? ""));
            if (
                ($name !== "" && strpos($key, $name) !== false)
                || ($type !== "" && strpos($key, $type) !== false)
                || self::logHeuristicMatch($key, $name)
            ) {
                $matches[] = $item;
            }
        }
        return $matches;
    }

    private static function logHeuristicMatch(string $key, string $name): bool
    {
        $pairs = [
            "nginx" => ["web", "proxy", "site"],
            "exim" => ["mail", "smtp"],
            "auth" => ["ssh", "login"],
            "syslog" => ["system", "cron"],
        ];
        foreach ($pairs as $logKey => $needles) {
            if (strpos($key, $logKey) === false) {
                continue;
            }
            foreach ($needles as $needle) {
                if (strpos($name, $needle) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
