<?php

use PHPUnit\Framework\TestCase;

final class ConfigMutationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        $this->resetConfigState();
        \App\Config::init($this->repoRoot);
    }

    public function testSetManyWritesLocalOverridesAndLegacySecuritySync(): void
    {
        $root = $this->createTempProject();

        try {
            $this->resetConfigState();
            \App\Config::init($root);

            \App\Config::setMany([
                "mail" => [
                    "mail_transport" => "smtp",
                    "mail_from" => "ops@example.test",
                    "mail_envelope_from" => "bounces@example.test",
                    "smtp_host" => "smtp.example.test",
                    "smtp_port" => 2525,
                ],
                "alerts" => [
                    "cron_token" => "0123456789abcdef0123456789abcdef",
                ],
            ]);

            $local = json_decode(
                (string) file_get_contents($root . "/config/local.json"),
                true,
            );
            $this->assertIsArray($local);
            $this->assertSame(
                "smtp.example.test",
                $local["mail"]["smtp_host"] ?? null,
            );
            $this->assertSame(
                "bounces@example.test",
                $local["mail"]["mail_envelope_from"] ?? null,
            );
            $this->assertSame(
                "0123456789abcdef0123456789abcdef",
                $local["alerts"]["cron_token"] ?? null,
            );

            $legacy = json_decode(
                (string) file_get_contents(
                    $root . "/data/security_config.json",
                ),
                true,
            );
            $this->assertIsArray($legacy);
            $this->assertSame("smtp", $legacy["mail_transport"] ?? null);
            $this->assertSame(
                "bounces@example.test",
                $legacy["mail_envelope_from"] ?? null,
            );
            $this->assertSame(
                "smtp.example.test",
                $legacy["smtp_host"] ?? null,
            );
            $this->assertSame(
                "0123456789abcdef0123456789abcdef",
                $legacy["cron_token"] ?? null,
            );

            \App\Config::delete("alerts.cron_token");
            $legacyAfterDelete = json_decode(
                (string) file_get_contents(
                    $root . "/data/security_config.json",
                ),
                true,
            );
            $this->assertIsArray($legacyAfterDelete);
            $this->assertArrayNotHasKey("cron_token", $legacyAfterDelete);
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testFeatureFlagsUseExpandedRegistryKeys(): void
    {
        $root = $this->createTempProject();

        try {
            $this->resetConfigState();
            \App\Config::init($root);

            \App\Config::setMany([
                "features" => [
                    "services" => false,
                    "backups" => false,
                ],
            ]);

            $local = json_decode(
                (string) file_get_contents($root . "/config/local.json"),
                true,
            );
            $this->assertIsArray($local);
            $this->assertFalse($local["features"]["services"] ?? true);
            $this->assertFalse($local["features"]["backups"] ?? true);
            $this->assertFalse(\App\Config::featureEnabled("services"));
            $this->assertFalse(\App\Config::featureEnabled("backups"));
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testSetManyRejectsInvalidConfiguredUrl(): void
    {
        $root = $this->createTempProject();

        try {
            $this->resetConfigState();
            \App\Config::init($root);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("Base URL must be a valid URL");

            \App\Config::setMany([
                "site" => [
                    "base_url" => "not-a-url",
                ],
            ]);
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testHiddenFieldBlankPreservesExistingValue(): void
    {
        $root = $this->createTempProject();

        try {
            $this->resetConfigState();
            \App\Config::init($root);

            \App\Config::setMany([
                "site" => [
                    "backup_keep" => 12,
                ],
            ]);

            $saved = \App\Config::setMany([
                "site" => [
                    "backup_keep" => "",
                ],
            ]);

            $this->assertSame(12, $saved["site"]["backup_keep"] ?? null);
        } finally {
            $this->deleteTree($root);
        }
    }

    public function testLegacyFeatureKeysStillLoadFromLocalConfig(): void
    {
        $root = $this->createTempProject();

        try {
            file_put_contents(
                $root . "/config/local.json",
                json_encode(
                    [
                        "features" => [
                            "enable_server_tests" => false,
                            "enable_bookmarks" => false,
                        ],
                    ],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            );

            $this->resetConfigState();
            \App\Config::init($root);

            $this->assertFalse(\App\Config::featureEnabled("server_tests"));
            $this->assertFalse(\App\Config::featureEnabled("bookmarks"));
        } finally {
            $this->deleteTree($root);
        }
    }

    private function createTempProject(): string
    {
        $root =
            sys_get_temp_dir() .
            "/server-dashboard-config-" .
            bin2hex(random_bytes(4));
        mkdir($root . "/config", 0775, true);
        mkdir($root . "/data", 0775, true);
        mkdir($root . "/assets/css/themes", 0775, true);

        copy(
            $this->repoRoot . "/config/defaults.php",
            $root . "/config/defaults.php",
        );
        copy(
            $this->repoRoot . "/config/schema.php",
            $root . "/config/schema.php",
        );
        file_put_contents(
            $root . "/assets/css/themes/nord.css",
            "/* test theme */\n",
        );

        return $root;
    }

    private function resetConfigState(): void
    {
        $ref = new ReflectionClass(\App\Config::class);
        foreach (["cache", "path", "schema"] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $child = $path . "/" . $item;
            if (is_dir($child)) {
                $this->deleteTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
