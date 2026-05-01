<?php

namespace App\Config;

final class ConfigValidator
{
    public static function validateTree(
        array $schema,
        array $patch,
        array $current,
    ): array {
        $out = [];
        foreach ($schema as $key => $rule) {
            if ($key === "_label") {
                continue;
            }

            if (is_array($rule) && isset($rule["type"])) {
                if (!array_key_exists($key, $patch)) {
                    continue;
                }
                $old = $current[$key] ?? null;
                $out[$key] = self::validateLeaf($rule, $patch[$key], $old);
                continue;
            }

            if (is_array($rule)) {
                if (
                    !isset($patch[$key]) ||
                    !is_array($patch[$key]) ||
                    $patch[$key] === []
                ) {
                    continue;
                }
                $childPatch = $patch[$key];
                $curChild = isset($current[$key]) && is_array($current[$key])
                    ? $current[$key]
                    : [];
                $child = self::validateTree($rule, $childPatch, $curChild);
                if ($child !== []) {
                    $out[$key] = $child;
                }
            }
        }
        return $out;
    }

    public static function validateLeaf(array $rule, $val, $old)
    {
        $type = $rule["type"];
        $label = $rule["label"] ?? "Value";
        $req = $rule["required"] ?? false;

        if (
            !empty($rule["hidden"]) &&
            ($val === "" || $val === null) &&
            $old !== null
        ) {
            return $old;
        }
        if ($val === "" || $val === null) {
            if ($req) {
                throw new \InvalidArgumentException("$label is required");
            }
            return $type === "int"
                ? 0
                : ($type === "bool"
                    ? false
                    : ($type === "list"
                        ? []
                        : $val));
        }

        switch ($type) {
            case "text":
            case "string":
                $s = (string) $val;
                $min = $rule["min"] ?? 0;
                $max = $rule["max"] ?? 1000;
                if (mb_strlen($s) < $min || mb_strlen($s) > $max) {
                    throw new \InvalidArgumentException(
                        "$label length must be $min..$max",
                    );
                }
                return $s;
            case "secret":
                return (string) $val;
            case "int":
                $n = (int) $val;
                if (isset($rule["min"]) && $n < $rule["min"]) {
                    throw new \InvalidArgumentException(
                        "$label must be >= {$rule["min"]}",
                    );
                }
                if (isset($rule["max"]) && $n > $rule["max"]) {
                    throw new \InvalidArgumentException(
                        "$label must be <= {$rule["max"]}",
                    );
                }
                return $n;
            case "bool":
                return (bool) $val;
            case "url":
                if (!filter_var($val, FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException(
                        "$label must be a valid URL",
                    );
                }
                return (string) $val;
            case "email":
                if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException(
                        "$label must be a valid email",
                    );
                }
                return (string) $val;
            case "list":
                $item = $rule["item"] ?? "string";
                $arr = is_array($val)
                    ? $val
                    : preg_split(
                        "/\s*,\s*/",
                        (string) $val,
                        -1,
                        PREG_SPLIT_NO_EMPTY,
                    );
                $out = [];
                foreach ($arr as $x) {
                    $out[] = self::validateLeaf(
                        ["type" => $item, "label" => $label . " item"],
                        $x,
                        null,
                    );
                }
                return $out;
            case "timezone":
                try {
                    new \DateTimeZone((string) $val);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException(
                        "$label must be a valid timezone",
                    );
                }
                return (string) $val;
            case "enum":
                $vals = $rule["values"] ?? [];
                if (!in_array($val, $vals, true)) {
                    throw new \InvalidArgumentException(
                        "$label must be one of: " . implode(", ", $vals),
                    );
                }
                return $val;
            default:
                throw new \InvalidArgumentException(
                    "Unsupported type $type for $label",
                );
        }
    }
}
