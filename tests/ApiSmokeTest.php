<?php

use PHPUnit\Framework\TestCase;

final class ApiSmokeTest extends TestCase
{
    public function testHealthEndpointReturnsJson(): void
    {
        $payload = $this->runEndpoint("api/health.php");
        $this->assertTrue($payload["ok"]);
        $this->assertArrayHasKey("state_writable", $payload);
    }

    public function testCronTokenAdminStatusEndpointReturnsMaskedStatus(): void
    {
        $payload = $this->runEndpoint(
            "api/cron_token_admin.php",
            "action=status",
            true,
        );
        $this->assertTrue($payload["ok"]);
        $this->assertArrayHasKey("status", $payload);
        $this->assertArrayHasKey("masked", $payload["status"]);
    }

    public function testUptimeReportEndpointReturnsJsonEvenWithoutHistory(): void
    {
        $payload = $this->runEndpoint(
            "api/report_uptime.php",
            "month=2026-04",
            true,
        );
        $this->assertTrue($payload["ok"]);
        $this->assertArrayHasKey("overall", $payload);
        $this->assertArrayHasKey("items", $payload);
    }

    public function testCronTokenRevealStaysLockedWithoutFreshAuthorization(): void
    {
        $payload = $this->runEndpoint(
            "api/cron_token_admin.php",
            "action=reveal",
            true,
            "POST",
            ["_csrf" => "test-csrf-token"],
        );
        $this->assertFalse($payload["ok"]);
        $this->assertSame(
            "Authorize token reveal in this session first",
            $payload["error"],
        );
    }

    public function testUptimeReportHtmlExportRendersDocument(): void
    {
        $html = $this->runEndpointRaw(
            "api/report_uptime.php",
            "month=2026-04&format=html",
            true,
        );
        $this->assertStringContainsString("Monthly Uptime Summary", $html);
        $this->assertStringContainsString("<table>", $html);
    }

    private function runEndpoint(
        string $path,
        string $query = "",
        bool $admin = false,
        string $method = "GET",
        array $body = [],
    ): array {
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
            escapeshellarg($admin ? "admin" : "user") .
            " " .
            escapeshellarg($method) .
            " " .
            escapeshellarg(json_encode($body, JSON_UNESCAPED_SLASHES));

        $output = shell_exec($cmd);
        $this->assertIsString($output, "Endpoint runner returned no output");

        $decoded = json_decode((string) $output, true);
        $this->assertIsArray(
            $decoded,
            "Endpoint output was not valid JSON: " . $output,
        );
        return $decoded;
    }

    private function runEndpointRaw(
        string $path,
        string $query = "",
        bool $admin = false,
        string $method = "GET",
        array $body = [],
    ): string {
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
            escapeshellarg($admin ? "admin" : "user") .
            " " .
            escapeshellarg($method) .
            " " .
            escapeshellarg(json_encode($body, JSON_UNESCAPED_SLASHES));

        $output = shell_exec($cmd);
        $this->assertIsString($output, "Endpoint runner returned no output");
        return (string) $output;
    }
}
