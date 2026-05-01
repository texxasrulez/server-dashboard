<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/Config.php";
require_once __DIR__ . "/../lib/UptimeReport.php";
require_once __DIR__ . "/_state_path.php";

$authorized = false;
if (
    !empty($_SESSION["user"]) &&
    ($_SESSION["user"]["role"] ?? "") === "admin"
) {
    $authorized = true;
}
if (!$authorized && cron_token_is_valid(cron_request_token())) {
    $authorized = true;
}
if (!$authorized) {
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => "forbidden"]);
    exit();
}

\App\Config::init(dirname(__DIR__));

$timezoneName = (string) \App\Config::get(
    "site.timezone",
    date_default_timezone_get(),
);
try {
    $timezone = new DateTimeZone($timezoneName);
} catch (Throwable $e) {
    $timezone = new DateTimeZone(date_default_timezone_get());
    $timezoneName = $timezone->getName();
}

$month = trim((string) ($_GET["month"] ?? date("Y-m")));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["ok" => false, "error" => "month must be YYYY-MM"]);
    exit();
}

$format = strtolower(trim((string) ($_GET["format"] ?? "json")));
$start = new DateTimeImmutable($month . "-01 00:00:00", $timezone);
$end = $start->modify("first day of next month");
$startTs = $start->getTimestamp();
$endTs = $end->getTimestamp();

$historyPath = dashboard_state_path("services_status_history.jsonl");
$servicesPath = dirname(__DIR__) . "/data/services.json";

$serviceMap = [];
if (is_file($servicesPath)) {
    $decoded = json_decode((string) @file_get_contents($servicesPath), true);
    if (
        is_array($decoded) &&
        !empty($decoded["items"]) &&
        is_array($decoded["items"])
    ) {
        foreach ($decoded["items"] as $service) {
            $id = trim((string) ($service["id"] ?? ""));
            if ($id !== "") {
                $serviceMap[$id] = $service;
            }
        }
    }
}

$rows = [];
$totals = [
    "samples" => 0,
    "up_samples" => 0,
    "down_samples" => 0,
    "up_seconds" => 0,
    "down_seconds" => 0,
    "unknown_seconds" => 0,
    "covered_seconds" => 0,
];

if (is_file($historyPath)) {
    $fh = @fopen($historyPath, "rb");
    if ($fh) {
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            $item = json_decode(trim($line), true);
            if (!is_array($item)) {
                continue;
            }

            $ts = (int) ($item["ts"] ?? 0);
            if ($ts <= 0) {
                continue;
            }

            $serviceId = trim(
                (string) ($item["id"] ?? ($item["service_id"] ?? "unknown")),
            );
            $serviceName = trim(
                (string) ($item["name"] ??
                    ($serviceMap[$serviceId]["name"] ??
                        ($serviceMap[$serviceId]["host"] ?? $serviceId))),
            );
            $status =
                ($item["status"] ?? "") === "up" ||
                ($item["ok"] ?? null) === true ||
                (int) ($item["ok"] ?? 0) === 1
                    ? "up"
                    : "down";

            if (!isset($rows[$serviceId])) {
                $rows[$serviceId] = [
                    "service_id" => $serviceId,
                    "service_name" =>
                        $serviceName !== "" ? $serviceName : $serviceId,
                    "before_start" => null,
                    "timeline" => [],
                    "samples" => 0,
                    "up_samples" => 0,
                    "down_samples" => 0,
                    "latency_sum_ms" => 0.0,
                    "latency_samples" => 0,
                    "max_latency_ms" => 0,
                ];
            }

            $point = ["ts" => $ts, "status" => $status];
            if ($ts < $startTs) {
                if (
                    !is_array($rows[$serviceId]["before_start"]) ||
                    $ts >= (int) ($rows[$serviceId]["before_start"]["ts"] ?? 0)
                ) {
                    $rows[$serviceId]["before_start"] = $point;
                }
                continue;
            }

            if ($ts >= $endTs) {
                continue;
            }

            $rows[$serviceId]["timeline"][] = $point;
            $rows[$serviceId]["samples"]++;
            $totals["samples"]++;

            if ($status === "up") {
                $rows[$serviceId]["up_samples"]++;
                $totals["up_samples"]++;
            } else {
                $rows[$serviceId]["down_samples"]++;
                $totals["down_samples"]++;
            }

            $latency = $item["latency_ms"] ?? null;
            if (is_numeric($latency)) {
                $latencyValue = (float) $latency;
                $rows[$serviceId]["latency_sum_ms"] += $latencyValue;
                $rows[$serviceId]["latency_samples"]++;
                $rows[$serviceId]["max_latency_ms"] = max(
                    $rows[$serviceId]["max_latency_ms"],
                    (int) round($latencyValue),
                );
            }
        }
        fclose($fh);
    }
}

$items = array_values($rows);
usort($items, function (array $a, array $b): int {
    return strcmp($a["service_name"], $b["service_name"]);
});

foreach ($items as &$item) {
    $timeline = $item["timeline"];
    if (is_array($item["before_start"])) {
        array_unshift($timeline, $item["before_start"]);
    }

    $timing = UptimeReport::summarizeTimeline($timeline, $startTs, $endTs);
    $item["sla_percent"] = $timing["uptime_percent"];
    $item["uptime_percent"] = $timing["uptime_percent"];
    $item["coverage_percent"] = $timing["coverage_percent"];
    $item["up_seconds"] = $timing["up_seconds"];
    $item["down_seconds"] = $timing["down_seconds"];
    $item["covered_seconds"] = $timing["covered_seconds"];
    $item["unknown_seconds"] = $timing["unknown_seconds"];
    $item["last_status"] = $timing["last_status"];
    $item["last_seen_ts"] = $timing["last_seen_ts"];
    $item["avg_latency_ms"] =
        $item["latency_samples"] > 0
            ? round($item["latency_sum_ms"] / $item["latency_samples"], 1)
            : null;

    $totals["up_seconds"] += $timing["up_seconds"];
    $totals["down_seconds"] += $timing["down_seconds"];
    $totals["covered_seconds"] += $timing["covered_seconds"];
    $totals["unknown_seconds"] += $timing["unknown_seconds"];

    unset(
        $item["timeline"],
        $item["before_start"],
        $item["latency_sum_ms"],
        $item["latency_samples"],
    );
}
unset($item);

$servicePeriodSeconds = max(0, ($endTs - $startTs) * count($items));
$overall = [
    "services" => count($items),
    "samples" => $totals["samples"],
    "up_samples" => $totals["up_samples"],
    "down_samples" => $totals["down_samples"],
    "up_seconds" => $totals["up_seconds"],
    "down_seconds" => $totals["down_seconds"],
    "covered_seconds" => $totals["covered_seconds"],
    "unknown_seconds" => $totals["unknown_seconds"],
    "sla_percent" =>
        $totals["covered_seconds"] > 0
            ? round(
                ($totals["up_seconds"] / $totals["covered_seconds"]) * 100,
                3,
            )
            : 0.0,
    "coverage_percent" =>
        $servicePeriodSeconds > 0
            ? round(
                ($totals["covered_seconds"] / $servicePeriodSeconds) * 100,
                3,
            )
            : 0.0,
];

if ($format === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header(
        'Content-Disposition: attachment; filename="uptime-summary-' .
            $month .
            '.csv"',
    );
    $out = fopen("php://output", "w");
    fputcsv($out, [
        "month",
        "service_id",
        "service_name",
        "samples",
        "up_samples",
        "down_samples",
        "uptime_percent",
        "coverage_percent",
        "up_seconds",
        "down_seconds",
        "unknown_seconds",
        "avg_latency_ms",
        "max_latency_ms",
        "last_status",
        "last_seen",
    ]);
    foreach ($items as $item) {
        fputcsv($out, [
            $month,
            $item["service_id"],
            $item["service_name"],
            $item["samples"],
            $item["up_samples"],
            $item["down_samples"],
            $item["uptime_percent"],
            $item["coverage_percent"],
            $item["up_seconds"],
            $item["down_seconds"],
            $item["unknown_seconds"],
            $item["avg_latency_ms"],
            $item["max_latency_ms"],
            $item["last_status"],
            $item["last_seen_ts"]
                ? (new DateTimeImmutable("@" . $item["last_seen_ts"]))
                    ->setTimezone($timezone)
                    ->format("c")
                : "",
        ]);
    }
    fclose($out);
    exit();
}

if ($format === "html") {
    header("Content-Type: text/html; charset=utf-8"); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Uptime Summary <?= htmlspecialchars(
      $month,
      ENT_QUOTES,
      "UTF-8",
  ) ?></title>
  <style>
    body{font-family:system-ui,sans-serif;margin:24px;background:#f5f6f8;color:#18202a}
    .shell{max-width:1100px;margin:0 auto;background:#fff;border-radius:16px;padding:24px;box-shadow:0 12px 30px rgba(0,0,0,.08)}
    h1{margin-top:0}
    .muted{color:#5d6b7a}
    .summary{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0}
    .tile{background:#f1f4f7;border-radius:14px;padding:14px 16px;min-width:160px}
    table{width:100%;border-collapse:collapse;margin-top:16px}
    th,td{padding:10px 12px;border-bottom:1px solid #d9e0e7;text-align:left}
    th{font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#596579}
  </style>
</head>
<body>
  <div class="shell">
    <h1>Monthly Uptime Summary</h1>
    <p class="muted">Month <?= htmlspecialchars(
        $month,
        ENT_QUOTES,
        "UTF-8",
    ) ?> · Timezone <?= htmlspecialchars(
     $timezoneName,
     ENT_QUOTES,
     "UTF-8",
 ) ?> · Source <?= htmlspecialchars($historyPath, ENT_QUOTES, "UTF-8") ?></p>
    <div class="summary">
      <div class="tile"><strong><?= (int) $overall[
          "services"
      ] ?></strong><div class="muted">Services</div></div>
      <div class="tile"><strong><?= (int) $overall[
          "samples"
      ] ?></strong><div class="muted">Samples</div></div>
      <div class="tile"><strong><?= number_format(
          (float) $overall["sla_percent"],
          3,
      ) ?>%</strong><div class="muted">Time-weighted uptime</div></div>
      <div class="tile"><strong><?= number_format(
          (float) $overall["coverage_percent"],
          3,
      ) ?>%</strong><div class="muted">Coverage</div></div>
    </div>
    <table>
      <thead>
        <tr>
          <th>Service</th>
          <th>Uptime</th>
          <th>Coverage</th>
          <th>Samples</th>
          <th>Down</th>
          <th>Avg Latency</th>
          <th>Max Latency</th>
          <th>Last Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="8">No probe history was found for this month.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= htmlspecialchars(
                  $item["service_name"],
                  ENT_QUOTES,
                  "UTF-8",
              ) ?></td>
              <td><?= number_format((float) $item["uptime_percent"], 3) ?>%</td>
              <td><?= number_format(
                  (float) $item["coverage_percent"],
                  3,
              ) ?>%</td>
              <td><?= (int) $item["samples"] ?></td>
              <td><?= (int) $item["down_samples"] ?></td>
              <td><?= $item["avg_latency_ms"] === null
                  ? "n/a"
                  : htmlspecialchars(
                          (string) $item["avg_latency_ms"],
                          ENT_QUOTES,
                          "UTF-8",
                      ) . " ms" ?></td>
              <td><?= (int) $item["max_latency_ms"] ?> ms</td>
              <td><?= htmlspecialchars(
                  (string) $item["last_status"],
                  ENT_QUOTES,
                  "UTF-8",
              ) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
<?php exit();
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode(
    [
        "ok" => true,
        "month" => $month,
        "timezone" => $timezoneName,
        "source" => $historyPath,
        "range" => [
            "start" => $start->format(DATE_ATOM),
            "end" => $end->format(DATE_ATOM),
        ],
        "overall" => $overall,
        "items" => $items,
    ],
    JSON_UNESCAPED_SLASHES,
);
