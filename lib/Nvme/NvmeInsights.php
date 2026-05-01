<?php

namespace App\Nvme;

final class NvmeInsights
{
    public static function buildSeries(array $snapshots): array
    {
        $series = [];
        foreach ($snapshots as $snapshot) {
            $recordedAt = (string) ($snapshot["recorded_at"] ?? "");
            $ts = NvmeSnapshotNormalizer::snapshotTimestamp($snapshot);
            foreach ((array) ($snapshot["devices"] ?? []) as $device) {
                $deviceKey = (string) ($device["device"] ?? "");
                if ($deviceKey === "") {
                    continue;
                }
                if (!isset($series[$deviceKey])) {
                    $series[$deviceKey] = [
                        "device" => $deviceKey,
                        "label" => $device["label"] ?? $deviceKey,
                        "serial" => $device["serial"] ?? null,
                        "points" => [],
                    ];
                }
                if (!empty($device["serial"])) {
                    $series[$deviceKey]["serial"] = $device["serial"];
                }
                $series[$deviceKey]["points"][] = [
                    "recorded_at" => $recordedAt,
                    "ts" => $ts,
                    "available" => (bool) ($device["available"] ?? false),
                    "percentage_used" => $device["percentage_used"] ?? null,
                    "power_on_hours" => $device["power_on_hours"] ?? null,
                    "temperature_c" => $device["temperature_c"] ?? null,
                    "data_units_written_bytes" => $device["data_units_written_bytes"] ?? null,
                    "media_and_data_integrity_errors" => $device["media_and_data_integrity_errors"] ?? null,
                    "error_information_log_entries" => $device["error_information_log_entries"] ?? null,
                ];
            }
        }
        foreach ($series as &$entry) {
            usort($entry["points"], static function (array $a, array $b): int {
                return ((int) ($a["ts"] ?? 0)) <=> ((int) ($b["ts"] ?? 0));
            });
        }
        unset($entry);
        return array_values($series);
    }

    public static function buildInsights(array $rangeSeries, array $allSeries): array
    {
        $allMap = [];
        foreach ($allSeries as $series) {
            $allMap[(string) ($series["device"] ?? "")] = $series;
        }
        $insights = [];
        foreach ($rangeSeries as $series) {
            $device = (string) ($series["device"] ?? "");
            $fullSeries = $allMap[$device] ?? $series;
            $insights[$device] = [
                "device" => $device,
                "label" => $series["label"] ?? $device,
                "serial" => $series["serial"] ?? null,
                "range" => self::rangeInsights($series),
                "last_30d" => self::windowInsights($fullSeries, 30),
                "projection_80" => self::projectionInsights($fullSeries, 80),
                "projection_90" => self::projectionInsights($fullSeries, 90),
            ];
        }
        return array_values($insights);
    }

    private static function rangeInsights(array $series): array
    {
        $points = self::availablePoints($series);
        $rate = self::ratePerDay($points, "data_units_written_bytes");
        return [
            "average_write_rate_bytes_per_day" => $rate["value"],
            "average_write_rate_status" => $rate["status"],
            "average_write_rate_message" => $rate["message"],
        ];
    }

    private static function windowInsights(array $series, int $days): array
    {
        $points = self::availablePoints($series);
        if (!$points) {
            return [
                "wear_delta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        $latest = end($points);
        if (!is_array($latest) || empty($latest["ts"])) {
            return [
                "wear_delta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        $windowStart = ((int) $latest["ts"]) - ($days * 86400);
        $windowPoints = array_values(
            array_filter($points, static function (array $point) use ($windowStart): bool {
                return isset($point["ts"]) &&
                    (int) $point["ts"] >= $windowStart &&
                    isset($point["percentage_used"]) &&
                    $point["percentage_used"] !== null;
            }),
        );
        if (count($windowPoints) < 2) {
            return [
                "wear_delta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough 30-day history yet.",
            ];
        }
        $first = reset($windowPoints);
        $last = end($windowPoints);
        if (!is_array($first) || !is_array($last)) {
            return [
                "wear_delta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough 30-day history yet.",
            ];
        }
        return [
            "wear_delta" => ((int) ($last["percentage_used"] ?? 0)) - ((int) ($first["percentage_used"] ?? 0)),
            "status" => "ok",
            "message" => "",
        ];
    }

    private static function projectionInsights(array $series, int $targetPercent): array
    {
        $points = self::availablePoints($series);
        $rate = self::ratePerDay($points, "percentage_used");
        $latest = $points ? end($points) : null;
        if (
            !is_array($latest) ||
            !isset($latest["percentage_used"]) ||
            $latest["percentage_used"] === null
        ) {
            return [
                "target_percent" => $targetPercent,
                "days" => null,
                "eta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        $current = (float) $latest["percentage_used"];
        $perDay = $rate["value"];
        if ($rate["status"] !== "ok" || $perDay === null || $perDay <= 0) {
            return [
                "target_percent" => $targetPercent,
                "days" => null,
                "eta" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        if ($current >= $targetPercent) {
            return [
                "target_percent" => $targetPercent,
                "days" => 0,
                "eta" => $latest["recorded_at"] ?? null,
                "status" => "reached",
                "message" => "Target already reached.",
            ];
        }
        $days = ($targetPercent - $current) / $perDay;
        $etaTs = isset($latest["ts"])
            ? ((int) $latest["ts"]) + (int) round($days * 86400)
            : null;
        return [
            "target_percent" => $targetPercent,
            "days" => $days,
            "eta" => $etaTs !== null ? gmdate("c", $etaTs) : null,
            "status" => "ok",
            "message" => "",
        ];
    }

    private static function ratePerDay(array $points, string $metric): array
    {
        $metricPoints = array_values(
            array_filter($points, static function (array $point) use ($metric): bool {
                return isset($point[$metric]) &&
                    $point[$metric] !== null &&
                    isset($point["ts"]) &&
                    $point["ts"] !== null;
            }),
        );
        if (count($metricPoints) < 2) {
            return [
                "value" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        $first = reset($metricPoints);
        $last = end($metricPoints);
        if (!is_array($first) || !is_array($last)) {
            return [
                "value" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        $deltaValue = (float) $last[$metric] - (float) $first[$metric];
        $deltaDays = (((int) $last["ts"]) - ((int) $first["ts"])) / 86400;
        if ($deltaDays <= 0 || $deltaValue < 0) {
            return [
                "value" => null,
                "status" => "not_enough_history",
                "message" => "Not enough history yet.",
            ];
        }
        return [
            "value" => $deltaValue / $deltaDays,
            "status" => "ok",
            "message" => "",
        ];
    }

    private static function availablePoints(array $series): array
    {
        return array_values(
            array_filter((array) ($series["points"] ?? []), static function (array $point): bool {
                return !empty($point["available"]);
            }),
        );
    }
}
