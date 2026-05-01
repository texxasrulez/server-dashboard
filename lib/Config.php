<?php

// lib/Config.php — FULL implementation with dynamic theme discovery

namespace App;

require_once dirname(__DIR__) . "/includes/paths.php";
require_once __DIR__ . "/Config/ConfigData.php";
require_once __DIR__ . "/Config/ConfigLegacy.php";
require_once __DIR__ . "/Config/ConfigPaths.php";
require_once __DIR__ . "/Config/ConfigValidator.php";

use App\Config\ConfigData;
use App\Config\ConfigLegacy;
use App\Config\ConfigPaths;
use App\Config\ConfigValidator;

final class Config
{
    private static $cache;
    private static $path; // <project-root>/config
    private static $schema;

    public static function init(string $rootDir): void
    {
        self::$path = ConfigPaths::initConfigPath($rootDir);
        self::$schema = require self::$path . "/schema.php";
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $defaults = require self::$path . "/defaults.php";
        $local = ConfigData::readLocal(self::$path);
        $merged = ConfigData::merge($defaults, $local);
        $merged = ConfigData::applyEnvOverrides($merged);
        if (empty($merged["security"]["csrf_secret"])) {
            $merged["security"]["csrf_secret"] = ConfigData::ensureSecret(
                self::$path,
                "csrf_secret",
            );
        }
        return self::$cache = $merged;
    }

    public static function get(string $path, $default = null)
    {
        $cfg = self::all();
        foreach (explode(".", $path) as $seg) {
            if (!is_array($cfg) || !array_key_exists($seg, $cfg)) {
                return $default;
            }
            $cfg = $cfg[$seg];
        }
        return $cfg;
    }

    public static function featureDefinitions(): array
    {
        $alertsHref = ConfigPaths::resolveFirstExistingPage(self::$path, [
            "alerts_admin.php",
            "alerts.php",
        ]);

        return [
            "history" => [
                "label" => "History",
                "i18n_key" => "header.nav.history",
                "href" => "history.php",
                "pages" => ["history.php", "incident.php"],
            ],
            "logs" => [
                "label" => "Logs",
                "i18n_key" => "logs.title",
                "href" => "logs.php",
                "pages" => ["logs.php", "log_viewer.php"],
            ],
            "drive_health" => [
                "label" => "Drive Health",
                "i18n_key" => "header.nav.drive_health",
                "href" => "drive_health.php",
                "pages" => ["drive_health.php"],
            ],
            "cron_health" => [
                "label" => "Cron Health",
                "i18n_key" => "header.nav.cron_health",
                "href" => "cron.php",
                "pages" => ["cron.php"],
            ],
            "processes" => [
                "label" => "Processes",
                "i18n_key" => "header.nav.processes",
                "href" => "processes.php",
                "pages" => ["processes.php"],
            ],
            "services" => [
                "label" => "Services",
                "i18n_key" => "services.title",
                "href" => "services.php",
                "pages" => [
                    "services.php",
                    "service_detail.php",
                    "services_admin.php",
                ],
            ],
            "databases" => [
                "label" => "Databases",
                "i18n_key" => "header.nav.databases",
                "href" => "database.php",
                "pages" => ["database.php"],
            ],
            "alerts" => [
                "label" => "Alerts",
                "i18n_key" => "alerts.title",
                "href" => $alertsHref,
                "pages" => array_values(
                    array_filter([
                        $alertsHref,
                        "alerts_admin.php",
                        "alerts.php",
                    ]),
                ),
            ],
            "server_tests" => [
                "label" => "Server Tests",
                "i18n_key" => "tests.title",
                "href" => "server_tests.php",
                "pages" => ["server_tests.php"],
            ],
            "speedtest" => [
                "label" => "Speedtest",
                "i18n_key" => "header.nav.speedtest",
                "href" => "speedtest.php",
                "pages" => ["speedtest.php"],
            ],
            "bookmarks" => [
                "label" => "Bookmarks",
                "i18n_key" => "bookmarks_page.title",
                "href" => "bookmarks.php",
                "pages" => ["bookmarks.php"],
            ],
            "diagnostics" => [
                "label" => "Diagnostics",
                "i18n_key" => "header.nav.diagnostics",
                "href" => "diag.php",
                "pages" => ["diag.php"],
            ],
            "backups" => [
                "label" => "Backups",
                "i18n_key" => "header.nav.backups",
                "href" => "backups.php",
                "pages" => ["backups.php"],
            ],
        ];
    }

    public static function featureSchema(): array
    {
        $schema = [];
        foreach (self::featureDefinitions() as $key => $definition) {
            $schema[$key] = [
                "type" => "bool",
                "label" => (string) ($definition["label"] ?? $key),
            ];
        }
        return $schema;
    }

    public static function featureDefaults(): array
    {
        $defaults = [];
        foreach (array_keys(self::featureDefinitions()) as $key) {
            $defaults[$key] = true;
        }
        return $defaults;
    }

    public static function featureKeyForPage(string $page): ?string
    {
        $target = basename($page);
        foreach (self::featureDefinitions() as $key => $definition) {
            $pages = is_array($definition["pages"] ?? null)
                ? $definition["pages"]
                : [];
            foreach ($pages as $candidate) {
                if (basename((string) $candidate) === $target) {
                    return $key;
                }
            }
        }
        return null;
    }

    public static function featureEnabled(string $key): bool
    {
        $defaults = self::featureDefaults();
        return (bool) self::get("features." . $key, $defaults[$key] ?? true);
    }

    public static function setMany(array $patch): array
    {
        $current = self::all();
        $normalized = ConfigValidator::validateTree(
            self::$schema,
            $patch,
            $current,
        );
        $next = ConfigData::merge($current, $normalized);
        $next = ConfigData::pruneUnchangedEmpty($next, $normalized);
        $defaults = require self::$path . "/defaults.php";
        $overrides = ConfigData::diff($next, $defaults);
        ConfigData::writeLocal(self::$path, $overrides);
        ConfigLegacy::syncSecurityConfig(self::$path, $next);
        self::$cache = $next;
        return $next;
    }

    public static function delete(string $path): bool
    {
        $current = self::all();
        $segments = array_values(array_filter(explode(".", trim($path))));
        if (!$segments) {
            return false;
        }
        if (!ConfigData::removePath($current, $segments)) {
            return false;
        }
        $defaults = require self::$path . "/defaults.php";
        $overrides = ConfigData::diff($current, $defaults);
        ConfigData::writeLocal(self::$path, $overrides);
        ConfigLegacy::syncSecurityConfig(self::$path, $current);
        self::$cache = $current;
        return true;
    }

    /**
     * Discover available themes by scanning /assets/css/themes.
     * Supports both file themes (themes/foo.css) and folder themes (themes/foo/theme.css).
     * Filters out non-themes like core.css and *.mobile.css.
     */
    public static function listThemes(): array
    {
        return ConfigPaths::listThemes(self::$path);
    }
}
