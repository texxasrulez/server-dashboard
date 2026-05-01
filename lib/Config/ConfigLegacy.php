<?php

namespace App\Config;

final class ConfigLegacy
{
    public static function mergeLegacySecurity(
        string $configPath,
        array $local,
    ): array {
        $secFile = dirname($configPath) . "/data/security_config.json";
        if (!is_file($secFile)) {
            return $local;
        }
        $raw = @file_get_contents($secFile);
        if ($raw === false) {
            return $local;
        }
        $legacy = json_decode($raw, true);
        if (!is_array($legacy) || !$legacy) {
            return $local;
        }

        $map = [
            "mail_transport" => ["mail", "mail_transport"],
            "mail_from" => ["mail", "mail_from"],
            "mail_envelope_from" => ["mail", "mail_envelope_from"],
            "mail_replyto" => ["mail", "mail_replyto"],
            "sendmail_path" => ["mail", "sendmail_path"],
            "smtp_host" => ["mail", "smtp_host"],
            "smtp_port" => ["mail", "smtp_port"],
            "smtp_secure" => ["mail", "smtp_secure"],
            "smtp_user" => ["mail", "smtp_user"],
            "smtp_pass" => ["mail", "smtp_pass"],
            "alert_emails" => ["mail", "sec_email"],
            "sec_email" => ["mail", "sec_email"],
            "admin_emails" => ["security", "admin_emails"],
            "csrf_secret" => ["security", "csrf_secret"],
            "cron_token" => ["alerts", "cron_token"],
        ];

        foreach ($map as $legacyKey => $path) {
            if (!array_key_exists($legacyKey, $legacy)) {
                continue;
            }
            if (self::pathHasValue($local, $path)) {
                continue;
            }
            $value = $legacy[$legacyKey];
            if (in_array($legacyKey, ["alert_emails", "sec_email"], true)) {
                $value = self::splitEmails($value);
            }
            if ($legacyKey === "admin_emails" && !is_array($value)) {
                $value = is_string($value) ? self::splitEmails($value) : [];
            }
            if ($legacyKey === "smtp_port") {
                $value = is_numeric($value) ? (int) $value : null;
            }
            if ($legacyKey === "cron_token" && !is_string($value)) {
                $value = null;
            }
            if ($value === null) {
                continue;
            }
            self::setPath($local, $path, $value);
        }

        return $local;
    }

    public static function syncSecurityConfig(
        string $configPath,
        array $cfg,
    ): void {
        try {
            $root = dirname($configPath);
            $secFile = $root . "/data/security_config.json";
            if (!is_dir(dirname($secFile))) {
                @mkdir(dirname($secFile), 0775, true);
            }
            $current = [];
            if (is_file($secFile)) {
                $raw = @file_get_contents($secFile);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            }
            $apply = $current;

            $alertString = self::emailsToString(
                self::getValue($cfg, ["mail", "sec_email"]),
            );
            $fallbackAlert = self::getValue($cfg, ["alerts", "email"]);
            $cronToken = self::pickFirst([
                self::getValue($cfg, ["alerts", "cron_token"]),
                self::getValue($cfg, ["security", "cron_token"]),
                self::getValue($cfg, ["site", "cron_token"]),
                self::getValue($cfg, ["cron", "token"]),
                self::getValue($cfg, ["api", "cron_token"]),
                self::getValue($cfg, ["history", "token"]),
            ]);
            $map = [
                "mail_transport" => self::getValue($cfg, ["mail", "mail_transport"]),
                "mail_from" => self::getValue($cfg, ["mail", "mail_from"]),
                "mail_envelope_from" => self::getValue($cfg, [
                    "mail",
                    "mail_envelope_from",
                ]),
                "mail_replyto" => self::getValue($cfg, ["mail", "mail_replyto"]),
                "sendmail_path" => self::getValue($cfg, ["mail", "sendmail_path"]),
                "smtp_host" => self::getValue($cfg, ["mail", "smtp_host"]),
                "smtp_port" => self::getValue($cfg, ["mail", "smtp_port"]),
                "smtp_secure" => self::getValue($cfg, ["mail", "smtp_secure"]),
                "smtp_user" => self::getValue($cfg, ["mail", "smtp_user"]),
                "smtp_pass" => self::getValue($cfg, ["mail", "smtp_pass"]),
                "alert_emails" => $alertString ?: (is_string($fallbackAlert) ? $fallbackAlert : ""),
                "sec_email" => $alertString ?: (is_string($fallbackAlert) ? $fallbackAlert : ""),
                "admin_emails" => self::normalizeArray(
                    self::getValue($cfg, ["security", "admin_emails"]),
                ),
                "csrf_secret" => self::getValue($cfg, ["security", "csrf_secret"]),
                "cron_token" => $cronToken,
            ];

            foreach ($map as $key => $value) {
                if ($key === "smtp_pass" && ($value === null || $value === "")) {
                    if (isset($apply[$key]) && $apply[$key] !== "") {
                        continue;
                    }
                }
                if ($value === null) {
                    unset($apply[$key]);
                    continue;
                }
                if ($key === "smtp_port") {
                    $value = (int) $value;
                }
                if ($key === "mail_transport" && $value === "") {
                    $value = "phpmail";
                }
                $apply[$key] = $value;
            }

            \write_json_atomic($secFile, $apply);
        } catch (\Throwable $e) {
        }
    }

    public static function getValue($tree, array $segments)
    {
        $node = $tree;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    private static function pathHasValue(array $tree, array $segments): bool
    {
        return self::getValue($tree, $segments) !== null;
    }

    private static function setPath(array &$tree, array $segments, $value): void
    {
        $node = &$tree;
        foreach ($segments as $i => $seg) {
            if ($i === count($segments) - 1) {
                $node[$seg] = $value;
            } else {
                if (!isset($node[$seg]) || !is_array($node[$seg])) {
                    $node[$seg] = [];
                }
                $node = &$node[$seg];
            }
        }
        unset($node);
    }

    private static function splitEmails($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $parts = explode(",", $value);
            $list = [];
            foreach ($parts as $part) {
                $t = trim($part);
                if ($t !== "") {
                    $list[] = $t;
                }
            }
        } else {
            $list = [];
        }
        return $list;
    }

    private static function emailsToString($value): string
    {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : "";
        }
        $clean = [];
        foreach ($value as $item) {
            $s = trim((string) $item);
            if ($s !== "") {
                $clean[] = $s;
            }
        }
        return implode(", ", $clean);
    }

    private static function normalizeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return self::splitEmails($value);
        }
        return [];
    }

    private static function pickFirst(array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== "") {
                return $candidate;
            }
        }
        return null;
    }
}
