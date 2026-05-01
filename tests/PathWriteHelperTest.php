<?php

require_once dirname(__DIR__) . "/includes/paths.php";

use PHPUnit\Framework\TestCase;

final class PathWriteHelperTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir =
            sys_get_temp_dir() .
            "/server-dashboard-paths-" .
            bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testWriteJsonAtomicCreatesParentDirectories(): void
    {
        $path = $this->tmpDir . "/state/nested/config.json";

        write_json_atomic($path, ["ok" => true, "items" => [1, 2, 3]]);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame(["ok" => true, "items" => [1, 2, 3]], $decoded);
    }

    public function testAppendLineLockedAppendsMultipleRecords(): void
    {
        $path = $this->tmpDir . "/state/history.jsonl";

        append_line_locked($path, "{\"id\":1}\n");
        append_line_locked($path, "{\"id\":2}\n");

        $this->assertSame("{\"id\":1}\n{\"id\":2}\n", file_get_contents($path));
    }

    public function testWriteJsonAtomicDoesNotReuseFixedTmpFilename(): void
    {
        $path = $this->tmpDir . "/data/alerts.json";
        ensure_dir(dirname($path));
        file_put_contents($path . ".tmp", "stale temp");

        write_json_atomic($path, ["ok" => true]);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame(["ok" => true], $decoded);
        $this->assertSame("stale temp", file_get_contents($path . ".tmp"));
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
            if ($item === "." || $item === "..") {
                continue;
            }
            $full = $path . "/" . $item;
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
