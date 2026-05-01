<?php

namespace App\Nvme;

final class NvmeSnapshotNormalizer
{
    public static function normalizeSnapshot(array $snapshot): array
    {
        $recordedAt = trim((string) ($snapshot["recorded_at"] ?? ""));
        $devices = [];
        $available = 0;

        foreach ((array) ($snapshot["devices"] ?? []) as $device) {
            if (!is_array($device)) {
                continue;
            }
            $normalized = [
                "recorded_at" => $recordedAt,
                "device" => self::nullableString($device["device"] ?? null),
                "label" => self::nullableString($device["label"] ?? null),
                "available" => (bool) ($device["available"] ?? false),
                "model" => self::nullableString($device["model"] ?? null),
                "serial" => self::nullableString($device["serial"] ?? null),
                "critical_warning" => self::nullableInt($device["critical_warning"] ?? null),
                "percentage_used" => self::nullableInt($device["percentage_used"] ?? null),
                "power_on_hours" => self::nullableInt($device["power_on_hours"] ?? null),
                "data_units_written" => self::nullableInt($device["data_units_written"] ?? null),
                "data_units_written_bytes" => self::nullableInt($device["data_units_written_bytes"] ?? null),
                "temperature_c" => self::nullableInt($device["temperature_c"] ?? null),
                "media_and_data_integrity_errors" => self::nullableInt($device["media_and_data_integrity_errors"] ?? null),
                "error_information_log_entries" => self::nullableInt($device["error_information_log_entries"] ?? null),
                "error" => self::nullableString($device["error"] ?? null),
            ];
            if ($normalized["available"]) {
                $available++;
            }
            $devices[] = $normalized;
        }

        return [
            "recorded_at" => $recordedAt !== "" ? $recordedAt : null,
            "devices" => $devices,
            "summary" => [
                "device_count" => count($devices),
                "available_count" => $available,
                "unavailable_count" => count($devices) - $available,
            ],
        ];
    }

    public static function buildSummary(array $snapshot): array
    {
        $snapshot = self::normalizeSnapshot($snapshot);
        return [
            "recorded_at" => $snapshot["recorded_at"],
            "device_count" => $snapshot["summary"]["device_count"] ?? 0,
            "available_count" => $snapshot["summary"]["available_count"] ?? 0,
            "unavailable_count" => $snapshot["summary"]["unavailable_count"] ?? 0,
        ];
    }

    public static function emptySnapshot(): array
    {
        return [
            "recorded_at" => null,
            "devices" => [],
            "summary" => [
                "device_count" => 0,
                "available_count" => 0,
                "unavailable_count" => 0,
            ],
        ];
    }

    public static function snapshotTimestamp(array $snapshot): ?int
    {
        $recordedAt = (string) ($snapshot["recorded_at"] ?? "");
        if ($recordedAt === "") {
            return null;
        }
        $ts = strtotime($recordedAt);
        return $ts === false ? null : $ts;
    }

    private static function nullableString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== "" ? $trimmed : null;
    }

    private static function nullableInt($value): ?int
    {
        if ($value === null || $value === "") {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
