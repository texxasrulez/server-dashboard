<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../api/_state_path.php";
require_once __DIR__ . "/AdminAudit.php";
require_once __DIR__ . "/AuditLog.php";
require_once __DIR__ . "/Redaction.php";
require_once __DIR__ . "/ServiceDetails.php";
require_once __DIR__ . "/Speedtest.php";

final class IncidentManager
{
    private const CONFIG_FILE = BASE_DIR . "/config/incident_rules.json";
    private const STATE_FILE = STATE_DIR . "/incidents_state.json";

    public static function recent(int $limit = 40): array
    {
        $incidents = self::correlate();
        usort($incidents, function (array $a, array $b): int {
            return ($b["last_ts"] ?? 0) <=> ($a["last_ts"] ?? 0);
        });
        return array_slice($incidents, 0, max(1, $limit));
    }

    public static function get(string $id): ?array
    {
        foreach (self::correlate() as $incident) {
            if (($incident["id"] ?? "") === $id) {
                $incident["timeline"] = self::timeline($incident);
                return $incident;
            }
        }
        return null;
    }

    public static function setState(string $id, string $state): bool
    {
        $state = strtolower(trim($state));
        if (!in_array($state, ["open", "acknowledged", "resolved", "suppressed"], true)) {
            return false;
        }

        $current = self::stateMap();
        $current[$id] = [
            "state" => $state,
            "updated_at" => date("c"),
            "updated_by" => dashboard_log_current_user(),
        ];
        write_json_atomic(self::STATE_FILE, $current);
        AuditLog::record(
            "incident.state",
            $id,
            true,
            ["state" => $state],
            "incident state updated",
            "admin",
        );
        return true;
    }

    public static function correlate(): array
    {
        $rules = self::rules();
        $window = max(300, (int) ($rules["window_sec"] ?? 1800));
        $events = self::alertEvents(
            time() - max($window * 8, (int) ($rules["recent_window_sec"] ?? 259200)),
        );
        $services = self::serviceCatalog();
        $dependencies = self::dependencyIndex($rules);
        $incidents = [];

        usort($events, function (array $a, array $b): int {
            return ($a["ts"] ?? 0) <=> ($b["ts"] ?? 0);
        });

        foreach ($events as $event) {
            $serviceId = (string) ($event["service_id"] ?? "");
            $host = (string) (($services[$serviceId]["host"] ?? "") ?: "host");
            $target = self::matchIncident($incidents, $event, $host, $window, $dependencies);

            $entry = [
                "type" => "alert",
                "ts" => (int) ($event["ts"] ?? 0),
                "severity" => (string) ($event["severity"] ?? "warn"),
                "service_id" => $serviceId,
                "service_name" => (string) (($event["service_name"] ?? "") ?: ($services[$serviceId]["name"] ?? $serviceId)),
                "host" => $host,
                "alert_name" => (string) ($event["alert_name"] ?? ""),
                "metric" => (string) ($event["metric"] ?? ""),
                "threshold" => $event["threshold"] ?? null,
                "value" => $event["value"] ?? null,
                "status" => $target === null ? "root" : "downstream",
            ];

            if ($target === null) {
                $rootService = $serviceId !== "" ? $serviceId : "unknown";
                $incidentId = "inc_" . substr(sha1($rootService . "|" . $host . "|" . (string) $entry["ts"]), 0, 14);
                $incidents[$incidentId] = [
                    "id" => $incidentId,
                    "title" => self::incidentTitle($entry),
                    "host" => $host,
                    "root_service_id" => $rootService,
                    "root_service_name" => (string) (($services[$rootService]["name"] ?? "") ?: $entry["service_name"]),
                    "severity" => (string) $entry["severity"],
                    "first_ts" => (int) $entry["ts"],
                    "last_ts" => (int) $entry["ts"],
                    "status" => "open",
                    "suppressed_count" => 0,
                    "event_count" => 1,
                    "events" => [$entry],
                    "services" => array_values(array_unique(array_filter([$serviceId]))),
                ];
            } else {
                $incidents[$target]["events"][] = $entry;
                $incidents[$target]["last_ts"] = max((int) $incidents[$target]["last_ts"], (int) $entry["ts"]);
                $incidents[$target]["event_count"]++;
                $incidents[$target]["suppressed_count"]++;
                $incidents[$target]["services"] = array_values(array_unique(array_merge(
                    $incidents[$target]["services"],
                    array_values(array_filter([$serviceId]))
                )));
                if (self::severityRank($entry["severity"]) > self::severityRank($incidents[$target]["severity"])) {
                    $incidents[$target]["severity"] = $entry["severity"];
                }
            }
        }

        $states = self::stateMap();
        foreach ($incidents as &$incident) {
            $override = $states[$incident["id"]] ?? null;
            if (is_array($override) && !empty($override["state"])) {
                $incident["status"] = (string) $override["state"];
                $incident["status_updated_at"] = (string) ($override["updated_at"] ?? "");
                $incident["status_updated_by"] = (string) ($override["updated_by"] ?? "");
            }
        }
        unset($incident);

        return array_values($incidents);
    }

    public static function alertIncidentMap(): array
    {
        $map = [];
        foreach (self::correlate() as $incident) {
            foreach ($incident["events"] ?? [] as $event) {
                if (($event["type"] ?? "") !== "alert") {
                    continue;
                }
                $key = self::eventMapKey($event);
                $map[$key] = [
                    "incident_id" => $incident["id"],
                    "incident_title" => $incident["title"],
                    "incident_status" => $incident["status"],
                ];
            }
        }
        return $map;
    }

    public static function timeline(array $incident): array
    {
        $from = max(0, ((int) ($incident["first_ts"] ?? time())) - 900);
        $to = ((int) ($incident["last_ts"] ?? time())) + 900;
        $serviceIds = array_values(array_filter($incident["services"] ?? []));
        $timeline = $incident["events"] ?? [];

        foreach (ServiceDetails::historyRows($serviceIds, 80, $from, $to) as $row) {
            $timeline[] = [
                "type" => "service_state",
                "ts" => (int) ($row["ts"] ?? 0),
                "service_id" => (string) ($row["id"] ?? ""),
                "service_name" => (string) ($row["name"] ?? ($row["id"] ?? "")),
                "status" => (string) ($row["status"] ?? "unknown"),
                "latency_ms" => $row["latency_ms"] ?? null,
                "http_code" => $row["http_code"] ?? null,
            ];
        }

        foreach (AdminAudit::sources() as $source) {
            foreach (AdminAudit::tailEventsFromPaths($source["paths"] ?? [], 120) as $event) {
                $ts = strtotime((string) ($event["time"] ?? ""));
                if ($ts === false || $ts < $from || $ts > $to) {
                    continue;
                }
                $timeline[] = [
                    "type" => "audit",
                    "ts" => $ts,
                    "category" => (string) ($event["category"] ?? ($source["label"] ?? "audit")),
                    "message" => (string) ($event["message"] ?? ""),
                    "user" => (string) ($event["user"] ?? ""),
                    "context" => (string) ($event["context"] ?? ""),
                ];
            }
        }

        $backupActionsFile = STATE_DIR . "/backup_actions.json";
        if (is_file($backupActionsFile)) {
            $rows = json_decode((string) @file_get_contents($backupActionsFile), true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $ts = strtotime((string) ($row["ts"] ?? ""));
                    if ($ts === false || $ts < $from || $ts > $to) {
                        continue;
                    }
                    $timeline[] = [
                        "type" => "backup",
                        "ts" => $ts,
                        "action" => (string) ($row["action"] ?? ""),
                        "ok" => !empty($row["ok"]),
                        "message" => (string) ($row["message"] ?? ""),
                    ];
                }
            }
        }

        foreach (self::speedtestAnomalies($from, $to) as $row) {
            $timeline[] = $row;
        }

        usort($timeline, function (array $a, array $b): int {
            return ($a["ts"] ?? 0) <=> ($b["ts"] ?? 0);
        });

        return $timeline;
    }

    private static function alertEvents(int $sinceTs): array
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
            $item = json_decode(trim($line), true);
            if (!is_array($item)) {
                continue;
            }
            $ts = (int) ($item["ts"] ?? 0);
            if ($ts < $sinceTs) {
                continue;
            }
            $rows[] = $item;
        }
        fclose($fh);
        return $rows;
    }

    private static function rules(): array
    {
        return read_json_or_default(self::CONFIG_FILE, [
            "window_sec" => 1800,
            "recent_window_sec" => 259200,
            "dependencies" => [],
        ]);
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
            $map[(string) $item["id"]] = $item;
        }
        return $map;
    }

    private static function dependencyIndex(array $rules): array
    {
        $reverse = [];
        $deps = is_array($rules["dependencies"] ?? null) ? $rules["dependencies"] : [];
        foreach ($deps as $root => $children) {
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child) {
                $reverse[strtolower((string) $child)][] = strtolower((string) $root);
            }
        }
        return $reverse;
    }

    private static function matchIncident(array $incidents, array $event, string $host, int $window, array $dependencies): ?string
    {
        $serviceId = strtolower((string) ($event["service_id"] ?? ""));
        $ts = (int) ($event["ts"] ?? 0);
        foreach ($incidents as $incidentId => $incident) {
            if (($incident["host"] ?? "") !== $host) {
                continue;
            }
            if ($ts - (int) ($incident["last_ts"] ?? 0) > $window) {
                continue;
            }
            $root = strtolower((string) ($incident["root_service_id"] ?? ""));
            if ($root === $serviceId || $serviceId === "") {
                return $incidentId;
            }
            if (in_array($root, $dependencies[$serviceId] ?? [], true)) {
                return $incidentId;
            }
        }
        return null;
    }

    private static function incidentTitle(array $entry): string
    {
        $service = trim((string) ($entry["service_name"] ?? ""));
        $alert = trim((string) ($entry["alert_name"] ?? ""));
        if ($service !== "" && $alert !== "") {
            return $service . ": " . $alert;
        }
        return $alert !== "" ? $alert : ($service !== "" ? $service . " incident" : "Incident");
    }

    private static function severityRank(string $severity): int
    {
        $severity = strtolower($severity);
        if ($severity === "crit") {
            return 3;
        }
        if ($severity === "warn") {
            return 2;
        }
        return 1;
    }

    private static function stateMap(): array
    {
        $map = read_json_or_default(self::STATE_FILE, []);
        return is_array($map) ? $map : [];
    }

    private static function speedtestAnomalies(int $from, int $to): array
    {
        $payload = \App\Speedtest::buildPayload(
            \App\Speedtest::filterOptions(["range" => "24h", "include_failed" => true]),
        );
        $rows = is_array($payload["rows"] ?? null) ? $payload["rows"] : [];
        if ($rows === []) {
            return [];
        }

        $downloads = [];
        foreach ($rows as $row) {
            if (isset($row["download_mbps"]) && is_numeric($row["download_mbps"])) {
                $downloads[] = (float) $row["download_mbps"];
            }
        }
        $avg = $downloads ? array_sum($downloads) / count($downloads) : null;
        $out = [];
        foreach ($rows as $row) {
            $ts = strtotime((string) ($row["timestamp"] ?? ""));
            if ($ts === false || $ts < $from || $ts > $to) {
                continue;
            }
            $failed = strtolower((string) ($row["status"] ?? "")) !== "success";
            $download = isset($row["download_mbps"]) ? (float) $row["download_mbps"] : null;
            $packetLoss = isset($row["packet_loss"]) ? (float) $row["packet_loss"] : 0.0;
            $anomaly = $failed || $packetLoss > 0 || ($avg !== null && $download !== null && $download < ($avg * 0.6));
            if (!$anomaly) {
                continue;
            }
            $out[] = [
                "type" => "speedtest",
                "ts" => $ts,
                "status" => (string) ($row["status"] ?? "unknown"),
                "download_mbps" => $download,
                "upload_mbps" => isset($row["upload_mbps"]) ? (float) $row["upload_mbps"] : null,
                "packet_loss" => $packetLoss,
                "server" => (string) (($row["server_label"] ?? "") ?: ($row["server_name"] ?? "")),
            ];
        }
        return $out;
    }

    private static function eventMapKey(array $event): string
    {
        return implode("|", [
            (string) ($event["service_id"] ?? ""),
            (string) ($event["alert_name"] ?? ""),
            (string) ($event["ts"] ?? 0),
        ]);
    }
}
