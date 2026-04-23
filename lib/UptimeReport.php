<?php

declare(strict_types=1);

final class UptimeReport
{
    public static function summarizeTimeline(
        array $points,
        int $startTs,
        int $endTs,
    ): array {
        $points = array_values(
            array_filter($points, function ($point) {
                return is_array($point) && isset($point["ts"]);
            }),
        );

        usort($points, function (array $a, array $b): int {
            return ((int) ($a["ts"] ?? 0)) <=> ((int) ($b["ts"] ?? 0));
        });

        $periodSeconds = max(0, $endTs - $startTs);
        $upSeconds = 0;
        $downSeconds = 0;
        $coveredSeconds = 0;
        $lastStatus = "unknown";
        $lastSeenTs = 0;

        foreach ($points as $index => $point) {
            $ts = (int) ($point["ts"] ?? 0);
            $status = strtolower((string) ($point["status"] ?? "unknown"));
            $nextTs = isset($points[$index + 1])
                ? (int) ($points[$index + 1]["ts"] ?? $endTs)
                : $endTs;

            $intervalStart = max($startTs, $ts);
            $intervalEnd = min($endTs, max($intervalStart, $nextTs));
            $duration = max(0, $intervalEnd - $intervalStart);

            if ($duration > 0) {
                $coveredSeconds += $duration;
                if ($status === "up") {
                    $upSeconds += $duration;
                } elseif ($status === "down") {
                    $downSeconds += $duration;
                }
            }

            if ($ts < $endTs && $ts >= $lastSeenTs) {
                $lastSeenTs = $ts;
                $lastStatus = $status;
            }
        }

        $unknownSeconds = max(0, $periodSeconds - $coveredSeconds);
        $uptimePercent =
            $coveredSeconds > 0
                ? round(($upSeconds / $coveredSeconds) * 100, 3)
                : 0.0;
        $coveragePercent =
            $periodSeconds > 0
                ? round(($coveredSeconds / $periodSeconds) * 100, 3)
                : 0.0;

        return [
            "period_seconds" => $periodSeconds,
            "covered_seconds" => $coveredSeconds,
            "up_seconds" => $upSeconds,
            "down_seconds" => $downSeconds,
            "unknown_seconds" => $unknownSeconds,
            "uptime_percent" => $uptimePercent,
            "coverage_percent" => $coveragePercent,
            "last_status" => $lastStatus,
            "last_seen_ts" => $lastSeenTs,
        ];
    }
}
