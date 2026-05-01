<?php

declare(strict_types=1);

final class Redaction
{
    private const PLACEHOLDER = "REDACTED";

    public static function isSensitiveKey(?string $key): bool
    {
        if (!is_string($key) || $key === "") {
            return false;
        }

        $key = strtolower($key);
        if (
            in_array(
                $key,
                [
                    "api_tokens",
                    "authorization",
                    "csrf_secret",
                    "cron_token",
                    "password",
                    "pass",
                    "token",
                    "secret",
                ],
                true,
            )
        ) {
            return true;
        }

        foreach (["password", "secret", "token", "bearer", "apikey", "api_key"] as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function redactTree($value, ?string $key = null)
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return self::redactedShape($value);
        }

        if (!is_array($value)) {
            return self::redactScalar($value);
        }

        $out = [];
        foreach ($value as $childKey => $childValue) {
            $out[$childKey] = self::redactTree(
                $childValue,
                is_string($childKey) ? $childKey : $key,
            );
        }
        return $out;
    }

    public static function redactText(string $text): string
    {
        if ($text === "") {
            return "";
        }

        $patterns = [
            "/([?&](?:token|access_token|refresh_token|api_key|apikey|password|pass|secret)=)[^&\\s]+/i" => '$1' . self::PLACEHOLDER,
            "/(Authorization:\\s*Bearer\\s+)[^\\s]+/i" => '$1' . self::PLACEHOLDER,
            "/(X-CRON-TOKEN:\\s*)[^\\s]+/i" => '$1' . self::PLACEHOLDER,
            "/(X-API-TOKEN:\\s*)[^\\s]+/i" => '$1' . self::PLACEHOLDER,
            "/(--(?:token|password|secret|pass)(?:=|\\s+))([^\\s]+)/i" => '$1' . self::PLACEHOLDER,
        ];

        return (string) preg_replace(
            array_keys($patterns),
            array_values($patterns),
            $text,
        );
    }

    public static function sanitizeForPersisted($value)
    {
        if (is_array($value)) {
            return self::redactTree($value);
        }
        if (is_string($value)) {
            return self::redactText($value);
        }
        return self::redactScalar($value);
    }

    public static function redactedLabel(): string
    {
        return self::PLACEHOLDER;
    }

    private static function redactedShape($value)
    {
        if (!is_array($value)) {
            return self::PLACEHOLDER;
        }

        $out = [];
        foreach ($value as $childKey => $_childValue) {
            $out[$childKey] = self::PLACEHOLDER;
        }
        return $out;
    }

    private static function redactScalar($value)
    {
        if (is_string($value)) {
            return self::redactText($value);
        }
        if (is_array($value)) {
            return self::redactTree($value);
        }
        return $value;
    }
}
