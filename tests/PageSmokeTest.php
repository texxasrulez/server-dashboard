<?php

use PHPUnit\Framework\TestCase;

final class PageSmokeTest extends TestCase
{
    public function testDiagnosticsPageRendersDoctorSummary(): void
    {
        $html = $this->runPage("diag.php");
        $this->assertStringContainsString("Environment Doctor", $html);
        $this->assertStringContainsString("Failures", $html);
        $this->assertStringContainsString("Passing Checks", $html);
    }

    public function testHistoryPageRendersMonthlyReportSection(): void
    {
        $html = $this->runPage("history.php");
        $this->assertStringContainsString("Monthly Uptime Summary", $html);
        $this->assertStringContainsString("reportPreviewBtn", $html);
    }

    public function testAssetsAuditPageRendersInventorySections(): void
    {
        $html = $this->runPage("tools/assets_audit.php");
        $this->assertStringContainsString("Assets Audit", $html);
        $this->assertStringContainsString("Missing Asset References", $html);
        $this->assertStringContainsString("Largest Assets", $html);
        $this->assertStringNotContainsString("/tools/assets/", $html);
    }

    public function testAdminAuditPageRendersEventSections(): void
    {
        $html = $this->runPage("tools/admin_audit.php");
        $this->assertStringContainsString("Admin Audit", $html);
        $this->assertStringContainsString("Structured Admin Audit", $html);
        $this->assertStringContainsString("Security Events", $html);
        $this->assertStringContainsString("Diagnostic Actions", $html);
        $this->assertStringNotContainsString("/tools/assets/", $html);
    }

    public function testLogsPageRendersLivePrivilegedSwitcher(): void
    {
        $html = $this->runPage("logs.php");
        $this->assertStringContainsString("Copied Logs", $html);
        $this->assertStringContainsString("Live Privileged Logs", $html);
    }

    public function testBackupsPageRendersVerificationAndBundlePanel(): void
    {
        $html = $this->runPage("backups.php");
        $this->assertStringContainsString(
            "Restore Verification & Support",
            $html,
        );
        $this->assertStringContainsString("Build Support Bundle", $html);
    }

    public function testHistoryPageRendersIncidentsSection(): void
    {
        $html = $this->runPage("history.php");
        $this->assertStringContainsString("Recent Incidents", $html);
        $this->assertStringContainsString("Monthly Uptime Summary", $html);
    }

    public function testServiceDetailPageRendersAlertsAndRecoverySections(): void
    {
        $this->seedOpsFixture();
        $html = $this->runPage("service_detail.php", "id=svc_smoke_web");
        $this->assertStringContainsString("Smoke Web", $html);
        $this->assertStringContainsString("Restart / Recovery History", $html);
        $this->assertStringContainsString("Recent Alert Events", $html);
    }

    public function testIncidentPageRendersTimelineFilters(): void
    {
        $this->seedOpsFixture();
        $id = trim(
            (string) shell_exec(
                escapeshellarg(PHP_BINARY) .
                    " -r " .
                    escapeshellarg(
                        'require "includes/init.php"; require "lib/IncidentManager.php"; $items=IncidentManager::recent(5); echo $items[0]["id"] ?? "";',
                    ),
            ),
        );
        $this->assertNotSame("", $id, "Expected incident fixture to correlate");
        $html = $this->runPage("incident.php", "id=" . rawurlencode($id));
        $this->assertStringContainsString("Timeline", $html);
        $this->assertStringContainsString("Service States", $html);
        $this->assertStringContainsString("Speedtest", $html);
    }

    private function runPage(string $path, string $query = ""): string
    {
        $cmd =
            escapeshellarg(PHP_BINARY) .
            " " .
            escapeshellarg(
                dirname(__DIR__) . "/tests/support/run_endpoint.php",
            ) .
            " " .
            escapeshellarg($path) .
            " " .
            escapeshellarg($query) .
            " " .
            escapeshellarg("admin");

        $output = shell_exec($cmd);
        $this->assertIsString($output, "Page runner returned no output");
        return (string) $output;
    }

    private function seedOpsFixture(): void
    {
        $root = dirname(__DIR__);
        @mkdir($root . "/data", 0775, true);
        @mkdir($root . "/state", 0775, true);

        file_put_contents(
            $root . "/data/services.json",
            json_encode(
                [
                    "items" => [
                        [
                            "id" => "svc_smoke_web",
                            "name" => "Smoke Web",
                            "type" => "app",
                            "host" => "127.0.0.1",
                            "port" => 8080,
                            "check" => "http",
                            "path" => "/health",
                            "timeout_ms" => 800,
                            "enabled" => true,
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ),
        );

        file_put_contents(
            $root . "/data/services_status_history.jsonl",
            json_encode(
                [
                    "id" => "svc_smoke_web",
                    "ts" => time() - 360,
                    "status" => "down",
                    "latency_ms" => 1400,
                    "http_code" => 502,
                ],
                JSON_UNESCAPED_SLASHES,
            ) .
                "\n" .
                json_encode(
                    [
                        "id" => "svc_smoke_web",
                        "ts" => time() - 180,
                        "status" => "up",
                        "latency_ms" => 110,
                        "http_code" => 200,
                    ],
                    JSON_UNESCAPED_SLASHES,
                ) .
                "\n",
        );

        file_put_contents(
            $root . "/data/alerts_events.jsonl",
            json_encode(
                [
                    "ts" => time() - 150,
                    "alert_id" => "alert_smoke",
                    "alert_name" => "Smoke Web latency high",
                    "service_id" => "svc_smoke_web",
                    "service_name" => "Smoke Web",
                    "metric" => "latency_ms",
                    "op" => ">=",
                    "threshold" => 1000,
                    "value" => 1200,
                    "severity" => "warn",
                ],
                JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
    }
}
