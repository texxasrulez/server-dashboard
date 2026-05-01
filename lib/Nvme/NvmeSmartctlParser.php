<?php

namespace App\Nvme;

final class NvmeSmartctlParser
{
    private const DATA_UNIT_BYTES = 512000;

    public static function parseSmartctlOutput(
        string $output,
        string $device,
        string $label,
        string $recordedAt,
    ): array {
        $trimmed = trim($output);
        if ($trimmed === "") {
            return self::unavailableDevice($device, $label, $recordedAt, "No smartctl output");
        }
        if (self::looksUnavailable($trimmed)) {
            return self::unavailableDevice(
                $device,
                $label,
                $recordedAt,
                self::extractUnavailableReason($trimmed),
            );
        }

        $model = self::extractString($trimmed, [
            '/^\s*Model Number:\s*(.+)$/mi',
            '/^\s*Device Model:\s*(.+)$/mi',
        ]);
        $serial = self::extractString($trimmed, [
            '/^\s*Serial Number:\s*(.+)$/mi',
        ]);
        $criticalWarning = self::extractNumber($trimmed, [
            '/^\s*Critical Warning:\s*([0-9xa-fA-F,]+)\s*$/mi',
        ], true);
        $percentageUsed = self::extractNumber($trimmed, [
            '/^\s*Percentage Used:\s*([0-9,]+)\s*%?\s*$/mi',
        ]);
        $powerOnHours = self::extractNumber($trimmed, [
            '/^\s*Power On Hours:\s*([0-9,]+)\s*$/mi',
        ]);
        $dataUnitsWritten = self::extractNumber($trimmed, [
            '/^\s*Data Units Written:\s*([0-9,]+)\b.*$/mi',
        ]);
        $temperatureC = self::extractNumber($trimmed, [
            '/^\s*Temperature:\s*([0-9,]+)\s*(?:Celsius|C)\b.*$/mi',
            '/^\s*Temperature Sensor 1:\s*([0-9,]+)\s*(?:Celsius|C)\b.*$/mi',
        ]);
        $integrityErrors = self::extractNumber($trimmed, [
            '/^\s*Media and Data Integrity Errors:\s*([0-9,]+)\s*$/mi',
        ]);
        $errorLogEntries = self::extractNumber($trimmed, [
            '/^\s*Error Information Log Entries:\s*([0-9,]+)\s*$/mi',
        ]);
        $dataUnitsWrittenBytes = self::extractDataUnitsWrittenBytes($trimmed);

        if (
            !self::hasSmartHealthMetrics([
                $criticalWarning,
                $percentageUsed,
                $powerOnHours,
                $dataUnitsWritten,
                $dataUnitsWrittenBytes,
                $temperatureC,
                $integrityErrors,
                $errorLogEntries,
            ])
        ) {
            return self::unavailableDevice(
                $device,
                $label,
                $recordedAt,
                self::extractHealthUnavailableReason($trimmed),
            );
        }

        return [
            "recorded_at" => $recordedAt,
            "device" => $device,
            "label" => $label,
            "available" => true,
            "model" => $model,
            "serial" => $serial,
            "critical_warning" => $criticalWarning,
            "percentage_used" => $percentageUsed,
            "power_on_hours" => $powerOnHours,
            "data_units_written" => $dataUnitsWritten,
            "data_units_written_bytes" => $dataUnitsWrittenBytes,
            "temperature_c" => $temperatureC,
            "media_and_data_integrity_errors" => $integrityErrors,
            "error_information_log_entries" => $errorLogEntries,
            "error" => null,
        ];
    }

    public static function unavailableDevice(
        string $device,
        string $label,
        string $recordedAt,
        string $error,
    ): array {
        return [
            "recorded_at" => $recordedAt,
            "device" => $device,
            "label" => $label,
            "available" => false,
            "model" => null,
            "serial" => null,
            "critical_warning" => null,
            "percentage_used" => null,
            "power_on_hours" => null,
            "data_units_written" => null,
            "data_units_written_bytes" => null,
            "temperature_c" => null,
            "media_and_data_integrity_errors" => null,
            "error_information_log_entries" => null,
            "error" => $error !== "" ? $error : "Drive unavailable",
        ];
    }

    private static function looksUnavailable(string $output): bool
    {
        return preg_match(
            '/Smartctl open device:|No such device|No such file or directory|Permission denied|Operation not permitted|Unable to detect device type|Read Device Identity failed|Read NVMe SMART\/Health Information failed|Read NVMe Identify Controller failed|NVME_IOCTL_ADMIN_CMD/i',
            $output,
        ) === 1;
    }

    private static function extractUnavailableReason(string $output): string
    {
        $lines = preg_split('/\R+/', trim($output)) ?: [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== "" && self::looksUnavailable($line)) {
                return $line;
            }
        }
        return "Drive unavailable";
    }

    private static function extractHealthUnavailableReason(string $output): string
    {
        $reason = self::extractUnavailableReason($output);
        return $reason !== "Drive unavailable"
            ? $reason
            : "SMART health data unavailable";
    }

    private static function hasSmartHealthMetrics(array $metrics): bool
    {
        foreach ($metrics as $metric) {
            if ($metric !== null) {
                return true;
            }
        }
        return false;
    }

    private static function extractDataUnitsWrittenBytes(string $output): ?int
    {
        $units = self::extractNumber($output, [
            '/^\s*Data Units Written:\s*([0-9,]+)\b.*$/mi',
        ]);
        return $units === null ? null : $units * self::DATA_UNIT_BYTES;
    }

    private static function extractString(string $output, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $matches) === 1) {
                $value = trim((string) ($matches[1] ?? ""));
                if ($value !== "") {
                    return $value;
                }
            }
        }
        return null;
    }

    private static function extractNumber(
        string $output,
        array $patterns,
        bool $allowHex = false,
    ): ?int {
        $raw = self::extractString($output, $patterns);
        if ($raw === null) {
            return null;
        }
        $normalized = str_replace(",", "", $raw);
        if ($allowHex && preg_match('/^0x[0-9a-f]+$/i', $normalized) === 1) {
            return hexdec($normalized);
        }
        if (preg_match('/^-?\d+$/', $normalized) !== 1) {
            return null;
        }
        return (int) $normalized;
    }
}
