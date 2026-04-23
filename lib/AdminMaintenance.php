<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/logger.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/AuditLog.php";
require_once __DIR__ . "/Config.php";

final class AdminMaintenance
{
    public const TOKEN_REVEAL_TTL = 300;

    public static function configInit(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        \App\Config::init(dirname(__DIR__));
        $ready = true;
    }

    public static function cronTokenPaths(): array
    {
        $root = dirname(__DIR__);
        return [
            $root . "/data/cron_token.txt",
            $root . "/state/cron_token.txt",
        ];
    }

    public static function currentCronToken(): string
    {
        self::configInit();
        $candidates = [];

        $fromConfig = \App\Config::get("alerts.cron_token", "");
        if (is_string($fromConfig) && trim($fromConfig) !== "") {
            $candidates[] = trim($fromConfig);
        }

        if (defined("CRON_TOKEN")) {
            $candidates[] = trim((string) CRON_TOKEN);
        }

        $env = getenv("DASH_CRON_TOKEN");
        if (is_string($env) && trim($env) !== "") {
            $candidates[] = trim($env);
        }

        foreach (self::cronTokenPaths() as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = trim((string) @file_get_contents($path));
            if ($raw !== "") {
                $candidates[] = $raw;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== "") {
                return $candidate;
            }
        }

        return "";
    }

    public static function maskedToken(string $token): string
    {
        $token = trim($token);
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat("*", $len);
        }
        return substr($token, 0, 4) .
            str_repeat("*", max(4, $len - 8)) .
            substr($token, -4);
    }

    public static function fileMode(?string $path): ?string
    {
        if (!is_string($path) || $path === "" || !file_exists($path)) {
            return null;
        }
        $perms = @fileperms($path);
        if ($perms === false) {
            return null;
        }
        return substr(sprintf("%o", $perms), -4);
    }

    public static function isPermissionTooOpen(?string $mode): bool
    {
        if (!is_string($mode) || $mode === "") {
            return false;
        }
        $last = substr($mode, -1);
        return in_array($last, ["2", "3", "6", "7"], true);
    }

    public static function cronTokenStatus(): array
    {
        $token = self::currentCronToken();
        $paths = [];
        foreach (self::cronTokenPaths() as $path) {
            $paths[] = [
                "path" => $path,
                "exists" => is_file($path),
                "writable" => is_file($path)
                    ? is_writable($path)
                    : is_writable(dirname($path)),
                "mode" => self::fileMode($path),
            ];
        }

        $audit = self::latestTokenAuditEvent();

        return [
            "present" => $token !== "",
            "masked" => $token !== "" ? self::maskedToken($token) : "",
            "length" => strlen($token),
            "paths" => $paths,
            "last_rotation_at" => $audit["time"] ?? null,
            "last_rotation_user" => $audit["user"] ?? "",
            "last_rotation_ip" => $audit["ip"] ?? "",
            "reveal_authorized" => self::canRevealCronToken(),
            "reveal_expires_at" => self::revealExpiry(),
        ];
    }

    public static function generateCronToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    public static function rotateCronToken(?string $actor = null): array
    {
        self::configInit();
        $token = self::generateCronToken();

        $patch = [
            "alerts" => [
                "cron_token" => $token,
            ],
        ];
        \App\Config::setMany($patch);

        foreach (self::cronTokenPaths() as $path) {
            try {
                write_text_atomic($path, $token . PHP_EOL);
            } catch (\Throwable $e) {
            }
        }

        self::clearRevealAuthorization();
        self::logSecurityEvent("cron_token_rotated", [
            "actor" => $actor ?: dashboard_log_current_user(),
            "length" => strlen($token),
        ]);

        return [
            "token" => $token,
            "masked" => self::maskedToken($token),
            "rotated_at" => date("c"),
        ];
    }

    public static function verifyCurrentPassword(string $password): bool
    {
        $user = current_user();
        $username = is_array($user)
            ? trim((string) ($user["username"] ?? ""))
            : "";
        if ($username === "" || $password === "") {
            return false;
        }
        $record = user_find($username);
        if (!is_array($record) || empty($record["password_hash"])) {
            return false;
        }
        return password_verify($password, (string) $record["password_hash"]);
    }

    public static function authorizeTokenReveal(): int
    {
        $expires = time() + self::TOKEN_REVEAL_TTL;
        $_SESSION["cron_token_reveal_until"] = $expires;
        self::logSecurityEvent("cron_token_reveal_authorized", [
            "ttl_sec" => self::TOKEN_REVEAL_TTL,
        ]);
        return $expires;
    }

    public static function clearRevealAuthorization(): void
    {
        unset($_SESSION["cron_token_reveal_until"]);
    }

    public static function revealExpiry(): int
    {
        $expires = (int) ($_SESSION["cron_token_reveal_until"] ?? 0);
        return max(0, $expires);
    }

    public static function canRevealCronToken(): bool
    {
        $expires = self::revealExpiry();
        if ($expires <= time()) {
            self::clearRevealAuthorization();
            return false;
        }
        return true;
    }

    public static function revealedCronToken(): string
    {
        if (!self::canRevealCronToken()) {
            return "";
        }
        return self::currentCronToken();
    }

    public static function logSecurityEvent(
        string $message,
        array $ctx = [],
    ): void {
        app_log("security", $message, $ctx, LOG_KIND_APP);
        AuditLog::record(
            "security." . $message,
            "security",
            true,
            $ctx,
            $message,
            "security",
        );
    }

    public static function latestTokenAuditEvent(): array
    {
        $path = DATA_DIR . "/logs/security.log";
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = (string) $lines[$i];
            if (
                strpos($line, '"cron_token_rotated"') === false &&
                strpos($line, "cron_token_rotated") === false
            ) {
                continue;
            }
            return [
                "raw" => $line,
                "time" => self::extractLogField($line, "Time"),
                "user" => self::extractLogField($line, "User"),
                "ip" => self::extractLogField($line, "IP"),
            ];
        }
        return [];
    }

    private static function extractLogField(string $line, string $field): string
    {
        if (
            !preg_match(
                "/\b" . preg_quote($field, "/") . '=("([^"\\\\]|\\\\.)*"|\S+)/',
                $line,
                $m,
            )
        ) {
            return "";
        }
        $value = (string) $m[1];
        $decoded = json_decode($value, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        return trim($value, '"');
    }
}
