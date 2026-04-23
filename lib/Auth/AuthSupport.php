<?php

namespace App\Auth;

final class AuthSupport
{
    public static function config($path, $default = null)
    {
        if (function_exists("cfg_local")) {
            return cfg_local((string) $path, $default);
        }
        return $default;
    }

    public static function clientIp(): string
    {
        if (function_exists("request_client_ip")) {
            return (string) request_client_ip();
        }
        $ip = isset($_SERVER["REMOTE_ADDR"]) ? (string) $_SERVER["REMOTE_ADDR"] : "";
        return $ip !== "" ? $ip : "0.0.0.0";
    }

    public static function projectUrl($path = ""): string
    {
        $base = defined("BASE_URL")
            ? BASE_URL
            : rtrim(dirname($_SERVER["SCRIPT_NAME"] ?? ""), "/");
        if ($base === "") {
            $base = "/";
        }
        $p = "/" . ltrim((string) $path, "/");
        return rtrim($base, "/") . $p;
    }
}
