<?php

use PHPUnit\Framework\TestCase;

final class ConfigAndDiagTest extends TestCase
{
    public function testConfigLoadsExpectedSections(): void
    {
        $config = \App\Config::all();
        $this->assertIsArray($config);
        $this->assertArrayHasKey("site", $config);
        $this->assertArrayHasKey("security", $config);
        $this->assertArrayHasKey("alerts", $config);
    }

    public function testFeatureRegistryCoversAllFeatureTabs(): void
    {
        $defs = \App\Config::featureDefinitions();
        $this->assertGreaterThan(3, count($defs));
        $this->assertArrayHasKey("services", $defs);
        $this->assertArrayHasKey("backups", $defs);
        $this->assertArrayHasKey("diagnostics", $defs);
        $this->assertSame(
            "services",
            \App\Config::featureKeyForPage("services.php"),
        );
        $this->assertSame(
            "alerts",
            \App\Config::featureKeyForPage("alerts_admin.php"),
        );
    }

    public function testBytesFromIniHandlesCommonSuffixes(): void
    {
        $this->assertSame(134217728, \App\ServerDiag::bytes_from_ini("128M"));
        $this->assertSame(1024, \App\ServerDiag::bytes_from_ini("1K"));
        $this->assertSame(-1, \App\ServerDiag::bytes_from_ini("-1"));
    }

    public function testFullDiagReportContainsSummary(): void
    {
        $report = \App\ServerDiag::fullReport();
        $this->assertIsArray($report);
        $this->assertArrayHasKey("summary", $report);
        $this->assertArrayHasKey("checks", $report);
        $this->assertArrayHasKey("groups", $report);
        $this->assertNotEmpty($report["checks"]);
    }
}
