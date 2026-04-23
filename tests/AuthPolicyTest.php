<?php

use PHPUnit\Framework\TestCase;

final class AuthPolicyTest extends TestCase
{
    private string $usersFile;
    private string $usersBackup;
    private string $rateFile;
    private string $rateBackup;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $this->usersFile = USERS_FILE;
        $this->usersBackup = is_file($this->usersFile)
            ? (string) file_get_contents($this->usersFile)
            : "";
        $this->rateFile = auth_login_rate_file();
        $this->rateBackup = is_file($this->rateFile)
            ? (string) file_get_contents($this->rateFile)
            : "";

        $_SESSION = [];
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        users_save(["users" => []]);
        auth_login_rate_save([]);
        auth_set_last_login_error("");
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        if ($this->usersBackup !== "") {
            file_put_contents($this->usersFile, $this->usersBackup);
        } elseif (is_file($this->usersFile)) {
            @unlink($this->usersFile);
        }

        if ($this->rateBackup !== "") {
            file_put_contents($this->rateFile, $this->rateBackup);
        } elseif (is_file($this->rateFile)) {
            @unlink($this->rateFile);
        }
    }

    public function testRateLimitClearRemovesExistingBlockState(): void
    {
        $key = auth_login_rate_key("admin", "127.0.0.1");
        auth_login_rate_save([
            $key => [
                "count" => 5,
                "first" => time() - 60,
                "last" => time() - 5,
                "next_allowed" => time() + 60,
            ],
        ]);

        $this->assertGreaterThan(0, auth_login_rate_block_seconds("admin", "127.0.0.1"));
        auth_login_rate_clear("admin", "127.0.0.1");
        $this->assertSame(0, auth_login_rate_block_seconds("admin", "127.0.0.1"));
    }

    public function testLoginRateLimitBlocksAndSetsErrorMessage(): void
    {
        $now = time();
        auth_login_rate_save([
            auth_login_rate_key("blocked", "127.0.0.1") => [
                "count" => 9,
                "first" => $now - 30,
                "last" => $now - 5,
                "next_allowed" => $now + 45,
            ],
        ]);

        $this->assertFalse(auth_login("blocked", "wrong"));
        $this->assertStringContainsString(
            "Too many login attempts.",
            auth_last_login_error(),
        );
    }

    public function testDeletingCurrentUserIsRejected(): void
    {
        $_SESSION["user"] = ["username" => "admin", "role" => "admin"];
        users_save([
            "users" => [
                [
                    "username" => "admin",
                    "password_hash" => password_hash("secret", PASSWORD_DEFAULT),
                    "role" => "admin",
                ],
            ],
        ]);

        [$ok, $message] = user_delete("admin");

        $this->assertFalse($ok);
        $this->assertSame("Cannot delete the currently signed-in user", $message);
    }

    public function testUserIsAdminReflectsCurrentSessionRole(): void
    {
        $_SESSION["user"] = ["username" => "operator", "role" => "admin"];
        $this->assertTrue(user_is_admin());

        $_SESSION["user"] = ["username" => "viewer", "role" => "user"];
        $this->assertFalse(user_is_admin());
    }

    public function testCsrfRequestTokenChecksFallbackPostGetAndHeader(): void
    {
        $_POST = ["csrf" => "post-token"];
        $_GET = ["_csrf" => "get-token"];
        $_SERVER["HTTP_X_CSRF_TOKEN"] = "header-token";

        $this->assertSame("fallback-token", csrf_request_token("fallback-token"));
        $this->assertSame("post-token", csrf_request_token());

        $_POST = [];
        $this->assertSame("get-token", csrf_request_token());

        $_GET = [];
        $this->assertSame("header-token", csrf_request_token());
    }
}
