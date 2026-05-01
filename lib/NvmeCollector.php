<?php

declare(strict_types=1);

namespace App;

require_once dirname(__DIR__) . '/includes/paths.php';
require_once __DIR__ . '/Nvme/NvmeCommandRunner.php';
require_once __DIR__ . '/Nvme/NvmeSmartctlParser.php';

use App\Nvme\NvmeCommandRunner;
use App\Nvme\NvmeSmartctlParser;

final class NvmeCollector
{
    private const DEFAULT_DEDUPE_WINDOW_SECONDS = 900;
    private const DEVICES = [
        [
            'device' => '/dev/nvme0n1',
            'label' => 'Main OS SSD',
        ],
        [
            'device' => '/dev/nvme1n1',
            'label' => 'Backup SSD',
        ],
    ];

    /** @var callable */
    private $runner;

    private string $statusPath;
    private string $historyPath;
    private int $dedupeWindowSeconds;

    public function __construct(
        ?callable $runner = null,
        ?string $statusPath = null,
        ?string $historyPath = null,
        int $dedupeWindowSeconds = self::DEFAULT_DEDUPE_WINDOW_SECONDS,
    ) {
        $root = dirname(__DIR__);
        $this->runner = $runner ?? [NvmeCommandRunner::class, 'runSmartctl'];
        $this->statusPath = $statusPath ?? ($root . '/state/nvme_status.json');
        $this->historyPath = $historyPath ?? ($root . '/data/nvme_history.jsonl');
        $this->dedupeWindowSeconds = max(0, $dedupeWindowSeconds);
    }

    public function collect(?string $recordedAt = null): array
    {
        $recordedAt = $this->normalizeRecordedAt($recordedAt);
        $devices = [];
        $available = 0;

        foreach (self::DEVICES as $definition) {
            $device = $this->collectDevice(
                (string) $definition['device'],
                (string) $definition['label'],
                $recordedAt,
            );
            if (!empty($device['available'])) {
                $available++;
            }
            $devices[] = $device;
        }

        $snapshot = [
            'recorded_at' => $recordedAt,
            'devices' => $devices,
            'summary' => [
                'device_count' => count($devices),
                'available_count' => $available,
                'unavailable_count' => count($devices) - $available,
            ],
        ];

        write_json_atomic($this->statusPath, $snapshot);
        $historyAppended = $this->appendHistoryIfNeeded($snapshot);

        return [
            'ok' => true,
            'recorded_at' => $recordedAt,
            'status_path' => $this->statusPath,
            'history_path' => $this->historyPath,
            'history_appended' => $historyAppended,
            'summary' => $snapshot['summary'],
            'devices' => $devices,
        ];
    }

    public function collectDevice(string $device, string $label, string $recordedAt): array
    {
        $result = ($this->runner)($device);
        $output = trim((string) ($result['output'] ?? ''));
        $exitCode = isset($result['exit_code']) ? (int) $result['exit_code'] : null;
        $error = trim((string) ($result['error'] ?? ''));

        if ($output === '' && $error !== '') {
            return self::unavailableDevice($device, $label, $recordedAt, $error);
        }

        $parsed = self::parseSmartctlOutput($output, $device, $label, $recordedAt);
        if ($parsed['available']) {
            return $parsed;
        }

        if ($error !== '') {
            $parsed['error'] = $error;
        } elseif ($exitCode !== null && $exitCode !== 0 && $output === '') {
            $parsed['error'] = 'smartctl exited with code ' . $exitCode;
        }

        return $parsed;
    }

    public static function parseSmartctlOutput(
        string $output,
        string $device,
        string $label,
        string $recordedAt,
    ): array {
        return NvmeSmartctlParser::parseSmartctlOutput(
            $output,
            $device,
            $label,
            $recordedAt,
        );
    }

    public static function unavailableDevice(
        string $device,
        string $label,
        string $recordedAt,
        string $error,
    ): array {
        return NvmeSmartctlParser::unavailableDevice(
            $device,
            $label,
            $recordedAt,
            $error,
        );
    }

    private function normalizeRecordedAt(?string $recordedAt): string
    {
        if (is_string($recordedAt) && $recordedAt !== '') {
            return $recordedAt;
        }
        return gmdate('c');
    }

    private function appendHistoryIfNeeded(array $snapshot): bool
    {
        $last = $this->readLastHistoryRecord();
        if ($last !== null && $this->isDuplicateSnapshot($last, $snapshot)) {
            return false;
        }

        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed for NVMe history');
        }

        append_line_locked($this->historyPath, $json . PHP_EOL);
        return true;
    }

    private function readLastHistoryRecord(): ?array
    {
        if (!is_file($this->historyPath)) {
            return null;
        }

        $handle = @fopen($this->historyPath, 'rb');
        if (!$handle) {
            return null;
        }

        $lastLine = null;
        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $lastLine = $trimmed;
                }
            }
        } finally {
            @fclose($handle);
        }

        if (!is_string($lastLine) || $lastLine === '') {
            return null;
        }

        $decoded = json_decode($lastLine, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isDuplicateSnapshot(array $last, array $current): bool
    {
        if ($this->dedupeWindowSeconds <= 0) {
            return false;
        }

        $lastAt = strtotime((string) ($last['recorded_at'] ?? ''));
        $currentAt = strtotime((string) ($current['recorded_at'] ?? ''));
        if ($lastAt === false || $currentAt === false) {
            return false;
        }

        if (abs($currentAt - $lastAt) > $this->dedupeWindowSeconds) {
            return false;
        }

        return $this->snapshotComparable($last) === $this->snapshotComparable($current);
    }

    private function snapshotComparable(array $snapshot): array
    {
        return [
            'devices' => array_map(static function ($device): array {
                return [
                    'device' => $device['device'] ?? null,
                    'label' => $device['label'] ?? null,
                    'available' => $device['available'] ?? null,
                    'model' => $device['model'] ?? null,
                    'serial' => $device['serial'] ?? null,
                    'critical_warning' => $device['critical_warning'] ?? null,
                    'percentage_used' => $device['percentage_used'] ?? null,
                    'power_on_hours' => $device['power_on_hours'] ?? null,
                    'data_units_written' => $device['data_units_written'] ?? null,
                    'data_units_written_bytes' => $device['data_units_written_bytes'] ?? null,
                    'temperature_c' => $device['temperature_c'] ?? null,
                    'media_and_data_integrity_errors' => $device['media_and_data_integrity_errors'] ?? null,
                    'error_information_log_entries' => $device['error_information_log_entries'] ?? null,
                    'error' => $device['error'] ?? null,
                ];
            }, is_array($snapshot['devices'] ?? null) ? $snapshot['devices'] : []),
        ];
    }

}
