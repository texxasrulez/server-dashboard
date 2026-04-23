<?php

namespace App\Nvme;

final class NvmeHistory
{
    public static function loadSnapshotFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === "") {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return NvmeSnapshotNormalizer::normalizeSnapshot($decoded);
    }

    public static function loadLatestHistorySnapshot(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $last = null;
        $handle = @fopen($path, "rb");
        if (!$handle) {
            return null;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $last = NvmeSnapshotNormalizer::normalizeSnapshot($decoded);
                }
            }
        } finally {
            @fclose($handle);
        }
        return $last;
    }

    public static function loadHistory(string $path, array $filters): array
    {
        if (!is_file($path)) {
            return [];
        }
        $sinceTs = $filters["since_ts"] ?? null;
        $out = [];
        $handle = @fopen($path, "rb");
        if (!$handle) {
            return [];
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $snapshot = NvmeSnapshotNormalizer::normalizeSnapshot($decoded);
                $recordedTs = NvmeSnapshotNormalizer::snapshotTimestamp($snapshot);
                if (
                    $sinceTs !== null &&
                    ($recordedTs === null || $recordedTs < (int) $sinceTs)
                ) {
                    continue;
                }
                $out[] = $snapshot;
            }
        } finally {
            @fclose($handle);
        }
        return $out;
    }

    public static function latestRecordedAt(array $snapshots): ?string
    {
        if (!$snapshots) {
            return null;
        }
        $last = end($snapshots);
        if (!is_array($last)) {
            return null;
        }
        return isset($last["recorded_at"]) &&
            is_string($last["recorded_at"]) &&
            $last["recorded_at"] !== ""
            ? $last["recorded_at"]
            : null;
    }

    public static function csvRows(array $snapshots): array
    {
        $rows = [];
        foreach ($snapshots as $snapshot) {
            $recordedAt = (string) ($snapshot["recorded_at"] ?? "");
            foreach ((array) ($snapshot["devices"] ?? []) as $device) {
                $rows[] = [
                    "recorded_at" => $recordedAt,
                    "device" => $device["device"] ?? "",
                    "label" => $device["label"] ?? "",
                    "serial" => $device["serial"] ?? "",
                    "available" => !empty($device["available"]) ? "1" : "0",
                    "model" => $device["model"] ?? "",
                    "critical_warning" => $device["critical_warning"] ?? "",
                    "percentage_used" => $device["percentage_used"] ?? "",
                    "power_on_hours" => $device["power_on_hours"] ?? "",
                    "data_units_written" => $device["data_units_written"] ?? "",
                    "data_units_written_bytes" => $device["data_units_written_bytes"] ?? "",
                    "temperature_c" => $device["temperature_c"] ?? "",
                    "media_and_data_integrity_errors" => $device["media_and_data_integrity_errors"] ?? "",
                    "error_information_log_entries" => $device["error_information_log_entries"] ?? "",
                    "error" => $device["error"] ?? "",
                ];
            }
        }
        return $rows;
    }
}
