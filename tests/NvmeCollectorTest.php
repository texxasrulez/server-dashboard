<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/NvmeCollector.php';

use App\NvmeCollector;
use PHPUnit\Framework\TestCase;

final class NvmeCollectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir =
            sys_get_temp_dir() .
            '/server-dashboard-nvme-' .
            bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testParseSmartctlOutputExtractsNvmeFields(): void
    {
        $sample = <<<'TXT'
smartctl 7.3 2022-02-28 r5338 [x86_64-linux-6.8.0] (local build)

=== START OF INFORMATION SECTION ===
Model Number:                       Samsung SSD 990 PRO 2TB
Serial Number:                      S7ABC123456789X

=== START OF SMART DATA SECTION ===
SMART/Health Information (NVMe Log 0x02)
Critical Warning:                   0x00
Temperature:                        38 Celsius
Percentage Used:                    7%
Data Units Written:                 7,792,166 [3.98 TB]
Power On Hours:                     1,234
Media and Data Integrity Errors:    0
Error Information Log Entries:      12
TXT;

        $parsed = NvmeCollector::parseSmartctlOutput(
            $sample,
            '/dev/nvme0n1',
            'Main OS SSD',
            '2026-04-18T00:00:00+00:00',
        );

        $this->assertTrue($parsed['available']);
        $this->assertSame('Samsung SSD 990 PRO 2TB', $parsed['model']);
        $this->assertSame('S7ABC123456789X', $parsed['serial']);
        $this->assertSame(0, $parsed['critical_warning']);
        $this->assertSame(7, $parsed['percentage_used']);
        $this->assertSame(1234, $parsed['power_on_hours']);
        $this->assertSame(7792166, $parsed['data_units_written']);
        $this->assertSame(7792166 * 512000, $parsed['data_units_written_bytes']);
        $this->assertSame(38, $parsed['temperature_c']);
        $this->assertSame(0, $parsed['media_and_data_integrity_errors']);
        $this->assertSame(12, $parsed['error_information_log_entries']);
    }

    public function testCollectWritesSnapshotAndSkipsDuplicateHistoryWithinWindow(): void
    {
        $sample = <<<'TXT'
Model Number:                       WD_BLACK SN850X 2000GB
Serial Number:                      WD-EXAMPLE-0001
Critical Warning:                   0x00
Temperature:                        41 Celsius
Percentage Used:                    2%
Data Units Written:                 123,456 [63.20 GB]
Power On Hours:                     321
Media and Data Integrity Errors:    0
Error Information Log Entries:      0
TXT;

        $collector = new NvmeCollector(
            static function (string $device) use ($sample): array {
                if ($device === '/dev/nvme1n1') {
                    return [
                        'output' => 'Smartctl open device: /dev/nvme1n1 failed: No such device',
                        'exit_code' => 2,
                        'error' => '',
                    ];
                }

                return [
                    'output' => $sample,
                    'exit_code' => 0,
                    'error' => '',
                ];
            },
            $this->tmpDir . '/state/nvme_status.json',
            $this->tmpDir . '/data/nvme_history.jsonl',
            900,
        );

        $first = $collector->collect('2026-04-18T02:05:00+00:00');
        $second = $collector->collect('2026-04-18T02:10:00+00:00');

        $this->assertTrue($first['history_appended']);
        $this->assertFalse($second['history_appended']);

        $snapshot = json_decode((string) file_get_contents($this->tmpDir . '/state/nvme_status.json'), true);
        $historyLines = file($this->tmpDir . '/data/nvme_history.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($snapshot);
        $this->assertSame(2, $snapshot['summary']['device_count']);
        $this->assertSame(1, $snapshot['summary']['available_count']);
        $this->assertSame('WD-EXAMPLE-0001', $snapshot['devices'][0]['serial']);
        $this->assertFalse($snapshot['devices'][1]['available']);
        $this->assertCount(1, $historyLines);
    }

    public function testParseSmartctlOutputTreatsMissingHealthMetricsAsUnavailable(): void
    {
        $sample = <<<'TXT'
smartctl 7.3 2022-02-28 r5338 [x86_64-linux-6.8.0] (local build)

=== START OF INFORMATION SECTION ===
Model Number:                       Samsung SSD 990 PRO 2TB
Serial Number:                      S7ABC123456789X
TXT;

        $parsed = NvmeCollector::parseSmartctlOutput(
            $sample,
            '/dev/nvme0n1',
            'Main OS SSD',
            '2026-04-18T00:00:00+00:00',
        );

        $this->assertFalse($parsed['available']);
        $this->assertSame('SMART health data unavailable', $parsed['error']);
    }

    public function testCollectPreservesPermissionFailureReasonFromSmartctlOutput(): void
    {
        $collector = new NvmeCollector(
            static function (string $device): array {
                return [
                    'output' => "Smartctl open device: {$device} failed: Operation not permitted",
                    'exit_code' => 2,
                    'error' => '',
                ];
            },
            $this->tmpDir . '/state/nvme_status.json',
            $this->tmpDir . '/data/nvme_history.jsonl',
            900,
        );

        $result = $collector->collect('2026-04-18T02:05:00+00:00');

        $this->assertFalse($result['devices'][0]['available']);
        $this->assertStringContainsString('Operation not permitted', (string) $result['devices'][0]['error']);
    }

    public function testParseSmartctlOutputHandlesHexCriticalWarning(): void
    {
        $sample = <<<'TXT'
Model Number:                       Example NVMe
Serial Number:                      EXAMPLE-SERIAL
Critical Warning:                   0x0A
Temperature:                        40 Celsius
Percentage Used:                    1%
Data Units Written:                 10
Power On Hours:                     20
Media and Data Integrity Errors:    0
Error Information Log Entries:      0
TXT;

        $parsed = NvmeCollector::parseSmartctlOutput(
            $sample,
            '/dev/nvme0n1',
            'Main OS SSD',
            '2026-04-18T00:00:00+00:00',
        );

        $this->assertTrue($parsed['available']);
        $this->assertSame(10, $parsed['critical_warning']);
    }

    public function testParseSmartctlOutputUsesGenericNoOutputReason(): void
    {
        $parsed = NvmeCollector::parseSmartctlOutput(
            '',
            '/dev/nvme0n1',
            'Main OS SSD',
            '2026-04-18T00:00:00+00:00',
        );

        $this->assertFalse($parsed['available']);
        $this->assertSame('No smartctl output', $parsed['error']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
                @rmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
