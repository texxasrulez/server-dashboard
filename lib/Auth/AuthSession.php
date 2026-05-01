<?php

namespace App\Auth;

final class AuthSession
{
    public static function setLastLoginError($msg): void
    {
        $GLOBALS["AUTH_LOGIN_LAST_ERROR"] = (string) $msg;
    }

    public static function lastLoginError(): string
    {
        return isset($GLOBALS["AUTH_LOGIN_LAST_ERROR"])
            ? (string) $GLOBALS["AUTH_LOGIN_LAST_ERROR"]
            : "";
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION["csrf"])) {
            $_SESSION["csrf"] = bin2hex(random_bytes(32));
        }
        return $_SESSION["csrf"];
    }

    public static function csrfCheck($token): bool
    {
        return isset($_SESSION["csrf"]) &&
            hash_equals($_SESSION["csrf"], $token ?? "");
    }

    public static function csrfRequestToken($fallback = ""): string
    {
        $tok = (string) $fallback;
        if ($tok !== "") {
            return $tok;
        }
        foreach (
            [
                $_POST["_csrf"] ?? null,
                $_POST["csrf"] ?? null,
                $_GET["_csrf"] ?? null,
                $_GET["csrf"] ?? null,
                $_SERVER["HTTP_X_CSRF_TOKEN"] ?? null,
            ]
            as $candidate
        ) {
            if (is_string($candidate) && $candidate !== "") {
                return $candidate;
            }
        }
        return "";
    }

    public static function login($username, $password): bool
    {
        self::setLastLoginError("");
        $ip = AuthSupport::clientIp();
        $wait = AuthRateLimiter::blockSeconds($username, $ip);
        if ($wait > 0) {
            self::setLastLoginError(
                "Too many login attempts. Try again in " . $wait . " seconds.",
            );
            return false;
        }
        $user = AuthUsers::find($username);
        if (!$user || !isset($user["password_hash"])) {
            AuthRateLimiter::registerFailure($username, $ip);
            return false;
        }
        if (!password_verify((string) $password, $user["password_hash"])) {
            AuthRateLimiter::registerFailure($username, $ip);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION["user"] = [
            "username" => $user["username"],
            "role" => $user["role"] ?? "user",
        ];
        AuthRateLimiter::clear($username, $ip);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                "",
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"],
            );
        }
        session_destroy();
    }

    public static function currentUser()
    {
        return $_SESSION["user"] ?? null;
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION["user"]);
    }

    public static function userIsAdmin($user = null): bool
    {
        if ($user === null) {
            $user = self::currentUser();
        }
        return !empty($user) && (($user["role"] ?? "user") === "admin");
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header(
                "Location: " .
                    AuthSupport::projectUrl("/auth/login.php") .
                    "?redirect=" .
                    urlencode($_SERVER["REQUEST_URI"] ?? AuthSupport::projectUrl("/")),
            );
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::userIsAdmin()) {
            http_response_code(403);
            echo "<!DOCTYPE html><meta charset=\"utf-8\"><h1>403 Forbidden</h1><p>Admin privileges required.</p>";
            exit;
        }
    }

    public static function gravatarUrlFrom($email, $size = 48): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === "") {
            return null;
        }
        return "https://www.gravatar.com/avatar/" .
            md5($email) .
            "?s=" .
            intval($size) .
            "&d=identicon";
    }

    public static function userAvatarUrl($userOrName = null, $size = 48): string
    {
        [$username, $profile] = self::resolveUserContext($userOrName);
        $avatar = "";
        $email = "";
        if (is_array($profile)) {
            $avatar = trim((string) ($profile["avatar_url"] ?? ""));
            $email = trim((string) ($profile["email"] ?? ""));
        }
        if ($avatar !== "") {
            return $avatar;
        }
        $gravatar = self::gravatarUrlFrom($email, $size);
        if ($gravatar !== null) {
            return $gravatar;
        }
        return AuthSupport::projectUrl("/assets/images/avatar-default.png");
    }

    public static function userDisplayName($userOrName = null): string
    {
        [$username, $profile] = self::resolveUserContext($userOrName);
        $first = trim((string) ($profile["first_name"] ?? ""));
        $last = trim((string) ($profile["last_name"] ?? ""));
        $name = trim($first . " " . $last);
        return $name !== "" ? $name : ($username ?? "user");
    }

    private static function resolveUserContext($userOrName): array
    {
        $username = null;
        $profile = null;
        if (is_array($userOrName)) {
            $username = $userOrName["username"] ?? null;
            $profile = $userOrName["profile"] ?? null;
        } elseif (is_string($userOrName) && $userOrName !== "") {
            $username = $userOrName;
        } else {
            $current = self::currentUser();
            $username = $current["username"] ?? null;
        }
        if (!$profile && $username) {
            $rec = AuthUsers::find($username);
            if ($rec) {
                $profile = $rec["profile"] ?? null;
            }
        }
        return [$username, $profile];
    }
}
