<?php

use PHPUnit\Framework\TestCase;

final class UptimeReportTest extends TestCase
{
    public function testSummarizeTimelineCalculatesTimeWeightedDurations(): void
    {
        $summary = UptimeReport::summarizeTimeline(
            [
                ["ts" => 100, "status" => "up"],
                ["ts" => 160, "status" => "down"],
                ["ts" => 220, "status" => "up"],
            ],
            100,
            280,
        );

        $this->assertSame(180, $summary["period_seconds"]);
        $this->assertSame(120, $summary["up_seconds"]);
        $this->assertSame(60, $summary["down_seconds"]);
        $this->assertSame(180, $summary["covered_seconds"]);
        $this->assertSame(0, $summary["unknown_seconds"]);
        $this->assertSame(66.667, $summary["uptime_percent"]);
        $this->assertSame(100.0, $summary["coverage_percent"]);
    }

    public function testSummarizeTimelineLeavesUnknownGapWithoutPriorSample(): void
    {
        $summary = UptimeReport::summarizeTimeline(
            [["ts" => 200, "status" => "up"]],
            100,
            300,
        );

        $this->assertSame(200, $summary["period_seconds"]);
        $this->assertSame(100, $summary["up_seconds"]);
        $this->assertSame(0, $summary["down_seconds"]);
        $this->assertSame(100, $summary["covered_seconds"]);
        $this->assertSame(100, $summary["unknown_seconds"]);
        $this->assertSame(100.0, $summary["uptime_percent"]);
        $this->assertSame(50.0, $summary["coverage_percent"]);
    }
}
