<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . "/lib/Backups/BackupsConfig.php";
require_once dirname(__DIR__) . "/lib/Logs/LogsPageSupport.php";
require_once dirname(__DIR__) . "/lib/Api/ProcessesApi.php";

final class BackupsAndLogsSupportTest extends TestCase
{
    public function testBackupsPageDataProvidesExpectedKeys(): void
    {
        $data = \App\Backups\BackupsConfig::pageData(dirname(__DIR__));

        $this->assertArrayHasKey("csrf_token", $data);
        $this->assertArrayHasKey("has_any_backup_fs", $data);
        $this->assertArrayHasKey("backup_orchestrator_json", $data);
        $this->assertArrayHasKey("initial_status", $data);
        $this->assertArrayHasKey("status", $data["initial_status"]);
    }

    public function testResolveCandidatePathAcceptsAllowedLogFile(): void
    {
        $dir = sys_get_temp_dir() . "/dashboard-log-test-" . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $path = $dir . "/sample.log";
        file_put_contents($path, "test\n");

        [$real, $root] = \App\Logs\LogsPageSupport::resolveCandidatePath($path, [$dir]);

        $this->assertSame(realpath($path), $real);
        $this->assertSame(realpath($dir), $root);

        @unlink($path);
        @rmdir($dir);
    }

    public function testResolveCandidatePathRejectsNonLogExtension(): void
    {
        $dir = sys_get_temp_dir() . "/dashboard-log-test-" . bin2hex(random_bytes(4));
        @mkdir($dir, 0775, true);
        $path = $dir . "/sample.txt";
        file_put_contents($path, "test\n");

        [$real, $root] = \App\Logs\LogsPageSupport::resolveCandidatePath($path, [$dir]);

        $this->assertNull($real);
        $this->assertNull($root);

        @unlink($path);
        @rmdir($dir);
    }

    public function testProcessesUserFilterRejectsUnsafeInput(): void
    {
        $this->assertSame("", proc_api_clean_user("../root"));
        $this->assertSame("www-data", proc_api_clean_user("www-data"));
    }

    public function testProcessesResponseReturnsExpectedTopLevelKeys(): void
    {
        $response = proc_api_response(["limit" => 10, "sort" => "cpu"]);

        $this->assertSame(200, $response["status"]);
        $this->assertTrue($response["payload"]["ok"]);
        $this->assertArrayHasKey("processes", $response["payload"]);
        $this->assertArrayHasKey("host", $response["payload"]);
        $this->assertLessThanOrEqual(10, count($response["payload"]["processes"]));
    }
}
