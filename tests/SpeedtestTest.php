<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . "/lib/Speedtest.php";

final class SpeedtestTest extends TestCase
{
    public function testValidatePatchRejectsEqualQuietHoursWhenEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Speedtest::validatePatch([
            "speedtest" => [
                "quiet_hours" => [
                    "enabled" => true,
                    "start_hour" => 2,
                    "end_hour" => 2,
                ],
            ],
        ]);
    }

    public function testNormalizeProcessExitCodeUsesRecordedExitStatusWhenProcCloseReturnsMinusOne(): void
    {
        $ref = new ReflectionClass(\App\Speedtest::class);
        $method = $ref->getMethod("normalizeProcessExitCode");
        $method->setAccessible(true);

        $code = $method->invoke(null, -1, ["exitcode" => 7]);

        $this->assertSame(7, $code);
    }

    public function testNormalizeProcessExitCodeKeepsKnownExitCode(): void
    {
        $ref = new ReflectionClass(\App\Speedtest::class);
        $method = $ref->getMethod("normalizeProcessExitCode");
        $method->setAccessible(true);

        $code = $method->invoke(null, 3, ["exitcode" => 7]);

        $this->assertSame(3, $code);
    }

    public function testSettingsNormalizesLegacyPreferredBackend(): void
    {
        $settings = \App\Speedtest::settings();

        $this->assertArrayHasKey("preferred_backend", $settings);
        $this->assertContains($settings["preferred_backend"], ["auto", "ookla", "librespeed"]);
    }
}
