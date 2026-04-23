<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/NvmeHealth.php';

use App\NvmeHealth;
use PHPUnit\Framework\TestCase;

final class NvmeHealthTest extends TestCase
{
    public function testFilterOptionsAllowsAllRange(): void
    {
        $filters = NvmeHealth::filterOptions(['range' => 'all']);

        $this->assertSame('all', $filters['range']);
        $this->assertNull($filters['since_ts']);
    }

    public function testBuildSeriesCreatesPerDevicePoints(): void
    {
        $snapshots = [
            [
                'recorded_at' => '2026-04-18T02:00:00+00:00',
                'devices' => [
                    [
                        'device' => '/dev/nvme0n1',
                        'label' => 'Main OS SSD',
                        'serial' => 'SERIAL-1',
                        'available' => true,
                        'percentage_used' => 5,
                        'power_on_hours' => 100,
                        'temperature_c' => 38,
                        'data_units_written_bytes' => 1024,
                        'media_and_data_integrity_errors' => 0,
                        'error_information_log_entries' => 1,
                    ],
                ],
            ],
            [
                'recorded_at' => '2026-04-19T02:00:00+00:00',
                'devices' => [
                    [
                        'device' => '/dev/nvme0n1',
                        'label' => 'Main OS SSD',
                        'serial' => 'SERIAL-1',
                        'available' => true,
                        'percentage_used' => 6,
                        'power_on_hours' => 124,
                        'temperature_c' => 39,
                        'data_units_written_bytes' => 2048,
                        'media_and_data_integrity_errors' => 0,
                        'error_information_log_entries' => 2,
                    ],
                    [
                        'device' => '/dev/nvme1n1',
                        'label' => 'Backup SSD',
                        'serial' => 'SERIAL-2',
                        'available' => false,
                        'percentage_used' => null,
                        'temperature_c' => null,
                        'data_units_written_bytes' => null,
                        'media_and_data_integrity_errors' => null,
                        'error_information_log_entries' => null,
                    ],
                ],
            ],
        ];

        $series = NvmeHealth::buildSeries($snapshots);

        $this->assertCount(2, $series);
        $this->assertSame('/dev/nvme0n1', $series[0]['device']);
        $this->assertCount(2, $series[0]['points']);
        $this->assertSame(124, $series[0]['points'][1]['power_on_hours']);
        $this->assertSame(2048, $series[0]['points'][1]['data_units_written_bytes']);
    }

    public function testBuildInsightsCalculatesWriteRateAndProjection(): void
    {
        $rows = array_map([NvmeHealth::class, 'normalizeSnapshot'], [
            [
                'recorded_at' => '2026-03-01T00:00:00+00:00',
                'devices' => [[
                    'device' => '/dev/nvme0n1',
                    'label' => 'Main OS SSD',
                    'serial' => 'A',
                    'available' => true,
                    'percentage_used' => 10,
                    'power_on_hours' => 10,
                    'data_units_written_bytes' => 1000,
                    'temperature_c' => 35,
                    'media_and_data_integrity_errors' => 0,
                    'error_information_log_entries' => 0,
                ]],
            ],
            [
                'recorded_at' => '2026-03-31T00:00:00+00:00',
                'devices' => [[
                    'device' => '/dev/nvme0n1',
                    'label' => 'Main OS SSD',
                    'serial' => 'A',
                    'available' => true,
                    'percentage_used' => 16,
                    'power_on_hours' => 70,
                    'data_units_written_bytes' => 7000,
                    'temperature_c' => 37,
                    'media_and_data_integrity_errors' => 0,
                    'error_information_log_entries' => 1,
                ]],
            ],
        ]);

        $ref = new \ReflectionClass(NvmeHealth::class);
        $method = $ref->getMethod('buildInsights');
        $method->setAccessible(true);
        $series = NvmeHealth::buildSeries($rows);
        $insights = $method->invoke(null, $series, $series);

        $this->assertCount(1, $insights);
        $this->assertSame('ok', $insights[0]['range']['average_write_rate_status']);
        $this->assertGreaterThan(0, $insights[0]['range']['average_write_rate_bytes_per_day']);
        $this->assertSame('ok', $insights[0]['last_30d']['status']);
        $this->assertSame(6, $insights[0]['last_30d']['wear_delta']);
        $this->assertSame('ok', $insights[0]['projection_80']['status']);
    }

    public function testNormalizeSnapshotCastsBlankAndMissingValuesToNulls(): void
    {
        $snapshot = NvmeHealth::normalizeSnapshot([
            'recorded_at' => '2026-04-18T02:00:00+00:00',
            'devices' => [[
                'device' => '/dev/nvme0n1',
                'label' => '',
                'available' => false,
                'model' => '',
                'serial' => '',
                'critical_warning' => '',
                'percentage_used' => '',
                'power_on_hours' => '25',
                'data_units_written_bytes' => '',
                'temperature_c' => '',
                'media_and_data_integrity_errors' => '',
                'error_information_log_entries' => '',
                'error' => '',
            ]],
        ]);

        $this->assertSame('/dev/nvme0n1', $snapshot['devices'][0]['device']);
        $this->assertNull($snapshot['devices'][0]['label']);
        $this->assertNull($snapshot['devices'][0]['model']);
        $this->assertNull($snapshot['devices'][0]['serial']);
        $this->assertNull($snapshot['devices'][0]['critical_warning']);
        $this->assertNull($snapshot['devices'][0]['percentage_used']);
        $this->assertSame(25, $snapshot['devices'][0]['power_on_hours']);
        $this->assertNull($snapshot['devices'][0]['error']);
        $this->assertSame(1, $snapshot['summary']['device_count']);
        $this->assertSame(1, $snapshot['summary']['unavailable_count']);
    }

    public function testBuildSummaryCountsAvailableAndUnavailableDevices(): void
    {
        $summary = NvmeHealth::buildSummary([
            'recorded_at' => '2026-04-18T02:00:00+00:00',
            'devices' => [
                ['device' => '/dev/nvme0n1', 'available' => true],
                ['device' => '/dev/nvme1n1', 'available' => false],
            ],
        ]);

        $this->assertSame('2026-04-18T02:00:00+00:00', $summary['recorded_at']);
        $this->assertSame(2, $summary['device_count']);
        $this->assertSame(1, $summary['available_count']);
        $this->assertSame(1, $summary['unavailable_count']);
    }
}
