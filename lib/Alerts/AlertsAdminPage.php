<?php

namespace App\Alerts;

final class AlertsAdminPage
{
    public static function uiConfig(): array
    {
        return [
            "mute_presets" => (string) \App\Config::get("alerts.mute_presets", ""),
            "service_defaults" => [
                "latency_warn_ms" => (int) \App\Config::get("alerts.service_defaults.latency_warn_ms", 0),
                "latency_fail_ms" => (int) \App\Config::get("alerts.service_defaults.latency_fail_ms", 0),
            ],
        ];
    }
}
