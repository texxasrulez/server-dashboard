<?php

namespace App\Auth;

final class AuthUsers
{
    public static function load(): array
    {
        $file = USERS_FILE;
        if (!file_exists($file)) {
            return ["users" => []];
        }
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data["users"])) {
            $data = ["users" => []];
        }
        return $data;
    }

    public static function save($data): void
    {
        $dir = dirname(USERS_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            USERS_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function find($username)
    {
        $data = self::load();
        foreach ($data["users"] as $u) {
            if (
                isset($u["username"]) &&
                strtolower($u["username"]) === strtolower((string) $username)
            ) {
                return $u;
            }
        }
        return null;
    }

    public static function ensureDefaultAdmin(bool $force = false): ?string
    {
        $data = self::load();
        if (!empty($data["users"])) {
            return null;
        }
        $allowWeb = (bool) AuthSupport::config(
            "security.allow_web_bootstrap_admin",
            false,
        );
        if (!$force && PHP_SAPI !== "cli" && !$allowWeb) {
            return null;
        }
        $defaultPass = bin2hex(random_bytes(4));
        $data["users"][] = [
            "username" => "admin",
            "password_hash" => password_hash($defaultPass, PASSWORD_DEFAULT),
            "role" => "admin",
            "created" => date("c"),
        ];
        self::save($data);
        return $defaultPass;
    }

    public static function add($username, $password, $role = "user"): array
    {
        $username = trim((string) $username);
        if ($username === "") {
            return [false, "Username required"];
        }
        if ($role !== "admin") {
            $role = "user";
        }
        $data = self::load();
        foreach ($data["users"] as $u) {
            if (strtolower($u["username"]) === strtolower($username)) {
                return [false, "User already exists"];
            }
        }
        $data["users"][] = [
            "username" => $username,
            "password_hash" => password_hash((string) $password, PASSWORD_DEFAULT),
            "role" => $role,
            "created" => date("c"),
        ];
        self::save($data);
        return [true, "User created"];
    }

    public static function delete($username, ?array $currentUser): array
    {
        if (
            strtolower($currentUser["username"] ?? "") ===
            strtolower((string) $username)
        ) {
            return [false, "Cannot delete the currently signed-in user"];
        }
        $data = self::load();
        $before = count($data["users"]);
        $data["users"] = array_values(
            array_filter($data["users"], static function ($u) use ($username) {
                return strtolower($u["username"]) !== strtolower((string) $username);
            }),
        );
        if (count($data["users"]) === $before) {
            return [false, "User not found"];
        }
        self::save($data);
        return [true, "User deleted"];
    }

    public static function setRole($username, $role): array
    {
        $data = self::load();
        $ok = false;
        foreach ($data["users"] as &$u) {
            if (strtolower($u["username"]) === strtolower((string) $username)) {
                $u["role"] = $role === "admin" ? "admin" : "user";
                $ok = true;
                break;
            }
        }
        unset($u);
        if (!$ok) {
            return [false, "User not found"];
        }
        self::save($data);
        return [true, "Role updated"];
    }

    public static function profileOf($username)
    {
        $rec = self::find($username);
        return $rec["profile"] ?? null;
    }
}
