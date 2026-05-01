<?php

namespace App\ServerDiag;

final class ServerDiagEnvironmentChecks
{
    public static function build(): array
    {
        $checks = [];
        $checks[] = self::checkConfigFile();
        $checks[] = self::checkGeneratedEnv();
        $checks[] = self::checkCronToken();
        $checks[] = self::checkMailTransport();
        $checks[] = self::checkAdapter();
        foreach (self::checkWritablePaths() as $check) {
            $checks[] = $check;
        }
        foreach (self::checkDangerousConditions() as $check) {
            $checks[] = $check;
        }
        return $checks;
    }

    private static function checkConfigFile(): array
    {
        $path = dirname(__DIR__, 2) . "/config/local.json";
        if (!is_file($path)) {
            return self::item("Config file", "warn", "config/local.json not present", "The app will fall back to defaults. Create/sync local config before production rollout.");
        }
        $raw = @file_get_contents($path);
        $data = @json_decode((string) $raw, true);
        if (!is_array($data)) {
            return self::item("Config file", "fail", $path, "config/local.json exists but does not decode as valid JSON.");
        }
        $writable = is_writable($path);
        $baseUrl = \App\Config::get("site.base_url", "");
        $timezone = \App\Config::get("site.timezone", "");
        $notes = [];
        $status = "ok";
        if (!$writable) {
            $status = "warn";
            $notes[] = "read-only";
        }
        if (!is_string($baseUrl) || trim($baseUrl) === "") {
            $status = "warn";
            $notes[] = "base_url missing";
        }
        if (!is_string($timezone) || trim($timezone) === "") {
            $status = "warn";
            $notes[] = "timezone missing";
        }
        return self::item("Config file", $status, $path . ($notes ? " | " . implode(", ", $notes) : ""), $status === "ok" ? "Primary config file is present and readable." : "Review config completeness and write access.");
    }

    private static function checkGeneratedEnv(): array
    {
        $path = dirname(__DIR__, 2) . "/state/generated/dashboard-scripts.env";
        if (!is_file($path)) {
            return self::item("Generated scripts env", "warn", $path, "Run `php bin/install-scripts.php` so cron/systemd helpers use the current dashboard settings.");
        }
        $mode = \AdminMaintenance::fileMode($path);
        $status = \AdminMaintenance::isPermissionTooOpen($mode) ? "warn" : "ok";
        return self::item(
            "Generated scripts env",
            $status,
            $path . ($mode ? " | mode " . $mode : ""),
            $status === "ok" ? "Generated script environment file exists." : "Tighten permissions if this file contains environment-derived secrets.",
        );
    }

    private static function checkCronToken(): array
    {
        $status = \AdminMaintenance::cronTokenStatus();
        if (empty($status["present"])) {
            return self::item("Cron token", "fail", "missing", "Generate/rotate a cron token before exposing cron-triggered endpoints.");
        }
        $itemStatus = "ok";
        $notes = ["masked " . $status["masked"]];
        if (($status["length"] ?? 0) < 32) {
            $itemStatus = "warn";
            $notes[] = "short token";
        }
        foreach ($status["paths"] ?? [] as $pathInfo) {
            if (!empty($pathInfo["mode"]) && \AdminMaintenance::isPermissionTooOpen($pathInfo["mode"])) {
                $itemStatus = "warn";
                $notes[] = basename((string) $pathInfo["path"]) . " mode " . $pathInfo["mode"];
            }
        }
        return self::item("Cron token", $itemStatus, implode(" | ", $notes), $itemStatus === "ok" ? "Token is present for cron/authenticated automation." : "Use the new token management flow to rotate or tighten token storage.");
    }

    private static function checkMailTransport(): array
    {
        $transport = strtolower((string) (defined("MAIL_TRANSPORT") ? constant("MAIL_TRANSPORT") : \App\Config::get("mail.mail_transport", "phpmail")));
        $status = "ok";
        $details = ["transport=" . $transport];
        if ($transport === "smtp") {
            $host = trim((string) (defined("SMTP_HOST") ? constant("SMTP_HOST") : \App\Config::get("mail.smtp_host", "")));
            $from = trim((string) (defined("MAIL_FROM") ? constant("MAIL_FROM") : \App\Config::get("mail.mail_from", "")));
            $envelope = trim((string) (defined("MAIL_ENVELOPE_FROM") ? constant("MAIL_ENVELOPE_FROM") : \App\Config::get("mail.mail_envelope_from", "")));
            if ($host === "") {
                $status = "fail";
                $details[] = "smtp_host missing";
            }
            if ($from === "") {
                $status = $status === "fail" ? "fail" : "warn";
                $details[] = "mail_from missing";
            }
            if ($envelope === "") {
                $details[] = "mail_envelope_from will fall back to mail_from";
            }
            $smtpPort = (int) (defined("SMTP_PORT") ? constant("SMTP_PORT") : \App\Config::get("mail.smtp_port", 0));
            if ($smtpPort <= 0) {
                $status = "fail";
                $details[] = "smtp_port invalid";
            }
        } elseif ($transport === "sendmail") {
            $path = trim((string) (defined("SENDMAIL_PATH") ? constant("SENDMAIL_PATH") : \App\Config::get("mail.sendmail_path", "/usr/sbin/sendmail")));
            if ($path === "" || (!is_file($path) && !is_executable($path))) {
                $status = "warn";
                $details[] = "sendmail path not found";
            }
        } else {
            $details[] = "using PHP mail() with explicit -f envelope sender";
        }
        return self::item("Mail transport", $status, implode(" | ", $details), $status === "ok" ? "Mail configuration passes a basic sanity check." : "Fix transport settings before relying on alert delivery.");
    }

    private static function checkAdapter(): array
    {
        $adapter = \DashboardAdapterFactory::make();
        $class = get_class($adapter);
        $label = preg_replace("/^.*\\\\/", "", $class);
        return self::item("Adapter detection", "ok", $label, "Detected adapter drives environment-specific behavior and panel hints.");
    }

    private static function checkWritablePaths(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            ["name" => "Data directory", "path" => $root . "/data", "required" => true],
            ["name" => "State directory", "path" => $root . "/state", "required" => true],
            ["name" => "Config directory", "path" => $root . "/config", "required" => true],
            ["name" => "Users store", "path" => $root . "/data/users.json", "required" => false],
            ["name" => "Service history", "path" => dashboard_state_path("services_status_history.jsonl"), "required" => false],
            ["name" => "Alerts events", "path" => dashboard_state_path("alerts_events.jsonl"), "required" => false],
            ["name" => "Config backups", "path" => $root . "/config/backups", "required" => false],
        ];
        $out = [];
        foreach ($paths as $row) {
            $path = $row["path"];
            $exists = file_exists($path);
            $dir = $exists && is_dir($path) ? $path : dirname($path);
            $writable = is_dir($dir) ? is_writable($dir) : false;
            if (($row["required"] ?? false) && !$exists && !is_dir($path)) {
                $status = $writable ? "warn" : "fail";
                $action = $writable ? "Path can be created, but it does not exist yet." : "Create the path and ensure the PHP user can write to it.";
            } elseif ($exists || is_dir($path)) {
                $targetWritable = is_dir($path) ? is_writable($path) : is_writable($path) || is_writable(dirname($path));
                $status = $targetWritable ? "ok" : "fail";
                $action = $targetWritable ? "Path is available for runtime writes." : "Current PHP user cannot write to this path.";
            } else {
                $status = $writable ? "ok" : "warn";
                $action = $writable ? "Optional path is not present yet." : "Optional path is not present and its parent directory is not writable.";
            }
            $out[] = self::item($row["name"], $status, $path, $action);
        }
        return $out;
    }

    private static function checkDangerousConditions(): array
    {
        $out = [];
        $allowWebBootstrap = (bool) \App\Config::get("security.allow_web_bootstrap_admin", false);
        $out[] = self::item(
            "Web bootstrap admin",
            $allowWebBootstrap ? "warn" : "ok",
            $allowWebBootstrap ? "enabled" : "disabled",
            $allowWebBootstrap ? "Disable this on internet-facing installs after the first admin exists." : "First-admin web bootstrap is disabled.",
        );
        $https = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
            || ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https"
            || ($_SERVER["SERVER_PORT"] ?? "") === "443";
        $out[] = self::item("HTTPS", $https ? "ok" : "warn", $https ? "enabled" : "not detected", $https ? "Request is being served over HTTPS." : "Use HTTPS or set trusted proxy headers correctly before exposing the dashboard.");
        $trusted = \App\Config::get("security.trusted_proxies", []);
        $hasForwardedFor = !empty($_SERVER["HTTP_X_FORWARDED_FOR"]);
        $hasForwardedProto = !empty($_SERVER["HTTP_X_FORWARDED_PROTO"]);
        $ipAllowlist = \App\Config::get("security.ip_allowlist", []);
        $apiRateLimitMs = (int) \App\Config::get("security.api_rate_limit_ms", 0);
        $loginRateLimit = \App\Config::get("security.login_rate_limit", []);
        $loginRateLimitEnabled = is_array($loginRateLimit) ? (bool) ($loginRateLimit["enabled"] ?? false) : false;
        $ipSensitiveControls = (is_array($ipAllowlist) && count($ipAllowlist) > 0) || $apiRateLimitMs > 0 || $loginRateLimitEnabled;
        $proxyWarn = $hasForwardedFor && empty($trusted) && $ipSensitiveControls;
        $details = $proxyWarn
            ? "forwarded client IP headers seen; trusted proxies unset"
            : (($hasForwardedFor || $hasForwardedProto) && empty($trusted)
                ? "forwarded headers seen; IP-sensitive controls not using them"
                : "ok");
        $out[] = self::item("Trusted proxy configuration", $proxyWarn ? "warn" : "ok", $details, $proxyWarn ? "Set `security.trusted_proxies` so IP/security checks use the real client IP." : "Proxy settings are not obviously misconfigured.");
        return $out;
    }

    private static function item(string $name, string $status, string $details, string $action): array
    {
        return ["name" => $name, "status" => $status, "details" => $details, "action" => $action];
    }
}
