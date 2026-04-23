<?php

declare(strict_types=1);

namespace App;

require_once dirname(__DIR__) . '/includes/paths.php';
require_once __DIR__ . '/Nvme/NvmeHistory.php';
require_once __DIR__ . '/Nvme/NvmeInsights.php';
require_once __DIR__ . '/Nvme/NvmeSnapshotNormalizer.php';

use App\Nvme\NvmeHistory;
use App\Nvme\NvmeInsights;
use App\Nvme\NvmeSnapshotNormalizer;

final class NvmeHealth
{
    private const RANGE_SECONDS = [
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
        '90d' => 7776000,
        'all' => null,
    ];

    public static function snapshotPath(): string
    {
        return dirname(__DIR__) . '/state/nvme_status.json';
    }

    public static function historyPath(): string
    {
        return dirname(__DIR__) . '/data/nvme_history.jsonl';
    }

    public static function filterOptions(array $params): array
    {
        $range = strtolower(trim((string) ($params['range'] ?? '24h')));
        if (!array_key_exists($range, self::RANGE_SECONDS)) {
            $range = '24h';
        }

        $seconds = self::RANGE_SECONDS[$range];

        return [
            'range' => $range,
            'since_ts' => $seconds === null ? null : (time() - $seconds),
        ];
    }

    public static function statusPayload(): array
    {
        $stateSnapshot = self::loadSnapshotFile(self::snapshotPath());
        if ($stateSnapshot !== null) {
            return [
                'source' => 'state',
                'snapshot' => $stateSnapshot,
                'summary' => NvmeSnapshotNormalizer::buildSummary($stateSnapshot),
            ];
        }

        $historyFallback = self::loadLatestHistorySnapshot();
        if ($historyFallback !== null) {
            return [
                'source' => 'history_fallback',
                'snapshot' => $historyFallback,
                'summary' => NvmeSnapshotNormalizer::buildSummary($historyFallback),
            ];
        }

        $empty = NvmeSnapshotNormalizer::emptySnapshot();
        return [
            'source' => 'none',
            'snapshot' => $empty,
            'summary' => NvmeSnapshotNormalizer::buildSummary($empty),
        ];
    }

    public static function historyPayload(array $filters): array
    {
        $snapshots = self::loadHistory($filters);
        $series = self::buildSeries($snapshots);
        $allSeries = self::buildSeries(self::loadHistory(['since_ts' => null]));

        return [
            'filters' => $filters,
            'history' => $snapshots,
            'series' => $series,
            'insights' => self::buildInsights($series, $allSeries),
            'summary' => [
                'snapshot_count' => count($snapshots),
                'device_count' => count($series),
                'latest_recorded_at' => NvmeHistory::latestRecordedAt($snapshots),
            ],
        ];
    }

    public static function loadHistory(array $filters): array
    {
        return NvmeHistory::loadHistory(self::historyPath(), $filters);
    }

    public static function buildSeries(array $snapshots): array
    {
        return NvmeInsights::buildSeries($snapshots);
    }

    public static function normalizeSnapshot(array $snapshot): array
    {
        return NvmeSnapshotNormalizer::normalizeSnapshot($snapshot);
    }

    public static function buildSummary(array $snapshot): array
    {
        return NvmeSnapshotNormalizer::buildSummary($snapshot);
    }

    public static function csvRows(array $snapshots): array
    {
        return NvmeHistory::csvRows($snapshots);
    }

    private static function buildInsights(array $rangeSeries, array $allSeries): array
    {
        return NvmeInsights::buildInsights($rangeSeries, $allSeries);
    }

    private static function loadSnapshotFile(string $path): ?array
    {
        return NvmeHistory::loadSnapshotFile($path);
    }

    private static function loadLatestHistorySnapshot(): ?array
    {
        return NvmeHistory::loadLatestHistorySnapshot(self::historyPath());
    }
}
