<?php

// includes/config.inc.php — single canonical config bootstrap.
// Unified config (single-source)
// DO NOT include any UI files here.

if (defined("APP_CONFIG_INC_LOADED")) {
    return;
}
define("APP_CONFIG_INC_LOADED", 1);

/* ---------- helpers ---------- */
if (!function_exists("cfg_env")) {
    function cfg_env(string $key, $default = null)
    {
        $dash = "DASH_" . $key;
        $v = getenv($dash);
        if ($v !== false && $v !== "") {
            return $v;
        }
        $v = getenv($key);
        if ($v !== false && $v !== "") {
            return $v;
        }
        return $default;
    }
}
if (!function_exists("cfg_define")) {
    function cfg_define(string $key, $value): void
    {
        if (!defined($key)) {
            define($key, $value);
        }
    }
}

$__PROJECT_ROOT = realpath(__DIR__ . "/..") ?: __DIR__ . "/..";
$__cfg_local = [];
$__cfg_local_path = $__PROJECT_ROOT . "/config/local.json";
if (is_file($__cfg_local_path)) {
    $raw = @file_get_contents($__cfg_local_path);
    $json = @json_decode($raw, true);
    if (is_array($json)) {
        $__cfg_local = $json;
    }
}
if (!function_exists("cfg_local")) {
    function cfg_local(string $path, $default = null)
    {
        global $__cfg_local;
        if (!is_array($__cfg_local) || !$__cfg_local) {
            return $default;
        }
        $node = $__cfg_local;
        foreach (explode(".", $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node === null ? $default : $node;
    }
}
if (!function_exists("cfg_first_local")) {
    function cfg_first_local(array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = cfg_local($path, null);
            if ($value !== null && $value !== "") {
                return $value;
            }
        }
        return $default;
    }
}
if (!function_exists("cfg_mail_extract_email")) {
    function cfg_mail_extract_email($value): string
    {
        $value = preg_replace("/[\r\n]+/", " ", trim((string) $value));
        if ($value === "") {
            return "";
        }
        if (preg_match("/<([^>]+)>/", $value, $m)) {
            $value = trim((string) $m[1]);
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : "";
    }
}
if (!function_exists("cfg_mail_default_sender")) {
    function cfg_mail_default_sender(): string
    {
        return "server-dashboard@localhost.invalid";
    }
}

// Define Build Version
if (!defined("BUILD")) {
    define("BUILD", "Server Dashboard-v0.1.0");
} // or any string you want

/* ---------- Core / Security ---------- */
$cronToken = (string) cfg_env("CRON_TOKEN", "");
if ($cronToken === "") {
    foreach (
        [
            "alerts.cron_token",
            "security.cron_token",
            "cron.token",
            "api.cron_token",
            "history.token",
            "site.cron_token",
        ]
        as $path
    ) {
        $candidate = cfg_local($path, null);
        if (is_string($candidate) && $candidate !== "") {
            $cronToken = (string) $candidate;
            break;
        }
    }
}
if ($cronToken === "") {
    foreach (
        [
            $__PROJECT_ROOT . "/data/cron_token.txt",
            $__PROJECT_ROOT . "/state/cron_token.txt",
        ]
        as $tf
    ) {
        if (is_file($tf)) {
            $candidate = trim((string) @file_get_contents($tf));
            if ($candidate !== "") {
                $cronToken = $candidate;
                break;
            }
        }
    }
}
if ($cronToken === "") {
    $cronToken = bin2hex(random_bytes(16));
    $tokenFile = $__PROJECT_ROOT . "/data/cron_token.txt";
    if (!is_dir(dirname($tokenFile))) {
        @mkdir(dirname($tokenFile), 0775, true);
    }
    @file_put_contents($tokenFile, $cronToken . PHP_EOL);
    @chmod($tokenFile, 0640);
}
cfg_define("CRON_TOKEN", $cronToken);

/* ---------- Mailer ---------- */
$transport = cfg_env("MAIL_TRANSPORT", null);
if ($transport === null || $transport === "") {
    $transport = cfg_local("mail.mail_transport", "phpmail");
}
cfg_define("MAIL_TRANSPORT", strtolower((string) $transport));
$from_fallback = (string) cfg_first_local(
    ["mail.mail_envelope_from"],
    cfg_mail_default_sender(),
);
$mailFrom = cfg_env("MAIL_FROM", null);
if ($mailFrom === null || $mailFrom === "") {
    $mailFrom = cfg_local("mail.mail_from", $from_fallback);
}
$mailFrom = preg_replace("/[\r\n]+/", " ", trim((string) $mailFrom));
if ($mailFrom === "") {
    $mailFrom = $from_fallback;
}
cfg_define("MAIL_FROM", (string) $mailFrom);
$mailEnvelope = cfg_env("MAIL_ENVELOPE_FROM", null);
if ($mailEnvelope === null || $mailEnvelope === "") {
    $mailEnvelope = cfg_first_local(
        ["mail.mail_envelope_from"],
        cfg_mail_extract_email($mailFrom),
    );
}
$mailEnvelope = cfg_mail_extract_email($mailEnvelope);
if ($mailEnvelope === "") {
    $mailEnvelope = cfg_mail_extract_email($mailFrom);
}
if ($mailEnvelope === "") {
    $mailEnvelope = cfg_mail_default_sender();
}
cfg_define("MAIL_ENVELOPE_FROM", (string) $mailEnvelope);
$mailReply = cfg_env("MAIL_REPLYTO", null);
if ($mailReply === null || $mailReply === "") {
    $mailReply = cfg_local("mail.mail_replyto", "");
}
cfg_define("MAIL_REPLYTO", (string) $mailReply);
$sendmail = cfg_env("SENDMAIL_PATH", null);
if ($sendmail === null || $sendmail === "") {
    $sendmail = cfg_local("mail.sendmail_path", "/usr/sbin/sendmail");
}
cfg_define("SENDMAIL_PATH", (string) $sendmail);
$smtpHost = cfg_env("SMTP_HOST", null);
if ($smtpHost === null) {
    $smtpHost = cfg_local("mail.smtp_host", "");
}
cfg_define("SMTP_HOST", (string) $smtpHost);
$smtpPort = cfg_env("SMTP_PORT", null);
if ($smtpPort === null || $smtpPort === "") {
    $smtpPort = cfg_local("mail.smtp_port", 587);
}
cfg_define("SMTP_PORT", (int) $smtpPort);
$smtpSecure = cfg_env("SMTP_SECURE", null);
if ($smtpSecure === null || $smtpSecure === "") {
    $smtpSecure = cfg_local("mail.smtp_secure", "tls");
}
cfg_define("SMTP_SECURE", strtolower((string) $smtpSecure));
$smtpUser = cfg_env("SMTP_USER", null);
if ($smtpUser === null || $smtpUser === "") {
    $smtpUser = cfg_local("mail.smtp_user", "");
}
cfg_define("SMTP_USER", (string) $smtpUser);
$smtpPass = cfg_env("SMTP_PASS", null);
if ($smtpPass === null || $smtpPass === "") {
    $smtpPass = cfg_local("mail.smtp_pass", "");
}
cfg_define("SMTP_PASS", (string) $smtpPass);
$smtpTimeout = cfg_env("SMTP_TIMEOUT", null);
if ($smtpTimeout === null || $smtpTimeout === "") {
    $smtpTimeout = cfg_local("mail.smtp_timeout", 12);
}
cfg_define("SMTP_TIMEOUT", (int) $smtpTimeout);

/* ---------- Alerts / History ---------- */
cfg_define(
    "ALLOW_HISTORY_EXPORT_WITH_TOKEN",
    (bool) (int) cfg_env("ALLOW_HISTORY_EXPORT_WITH_TOKEN", 1),
);
$alertEmails = cfg_env("ALERT_EMAILS", null);
if ($alertEmails === null || $alertEmails === "") {
    $alertEmails = cfg_local("mail.sec_email", null);
    if ($alertEmails === null || $alertEmails === "") {
        $alertEmails = cfg_local("alerts.email", "");
    }
}
if (is_array($alertEmails)) {
    $alertEmails = implode(",", array_filter(array_map("trim", $alertEmails)));
}
cfg_define("ALERT_EMAILS", (string) $alertEmails);

/* ---------- Theming / Client ---------- */
$disk = cfg_env("DISK_METRICS_PATH", null);
if ($disk !== null && $disk !== "") {
    cfg_define("DISK_METRICS_PATH", $disk);
}
$theme = cfg_env("THEME_DEFAULT", null);
if ($theme === null || $theme === "") {
    $theme = cfg_local("site.theme", null);
}
if ($theme !== null && $theme !== "") {
    cfg_define("THEME_DEFAULT", $theme);
}
$client = cfg_env("CLIENT_DEBUG_LOG", null);
if ($client !== null && $client !== "") {
    cfg_define("CLIENT_DEBUG_LOG", (int) $client);
}

/* ---------- BUILD (cache-busting) ---------- */
if (!defined("BUILD")) {
    $build = cfg_env("BUILD", "");
    if ($build === "") {
        $f = __DIR__ . "/../state/build.txt";
        if (is_file($f)) {
            $build = trim(@file_get_contents($f));
        }
    }
    if ($build === "") {
        $build = date("Ymd.His");
    }
    define("BUILD", $build);
}

/* ---------- Optional local overrides ---------- */
/* ---------- Snapshot helper (no secrets) ---------- */
if (!function_exists("app_config_snapshot")) {
    function app_config_snapshot(): array
    {
        $keys = [
            "CRON_TOKEN",
            "MAIL_TRANSPORT",
            "MAIL_FROM",
            "MAIL_ENVELOPE_FROM",
            "MAIL_REPLYTO",
            "SENDMAIL_PATH",
            "SMTP_HOST",
            "SMTP_PORT",
            "SMTP_SECURE",
            "SMTP_USER",
            "SMTP_PASS",
            "SMTP_TIMEOUT",
            "ALLOW_HISTORY_EXPORT_WITH_TOKEN",
            "ALERT_EMAILS",
            "DISK_METRICS_PATH",
            "THEME_DEFAULT",
            "CLIENT_DEBUG_LOG",
            "BUILD",
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = defined($k) ? constant($k) : null;
        }
        foreach (["CRON_TOKEN", "SMTP_USER", "SMTP_PASS"] as $mask) {
            if (!empty($out[$mask])) {
                $out[$mask] = "set";
            }
        }
        return $out;
    }
}

// --- canonical guards appended ---
if (!defined("PROJECT_ROOT")) {
    define("PROJECT_ROOT", realpath(__DIR__ . "/.."));
}
// Load JSON overrides early (if present)
$__cfg_json = [];
$__cfg_state = defined("DATA_DIR")
    ? DATA_DIR
    : realpath(__DIR__ . "/..") . "/data";
$__cfg_file = $__cfg_state . "/config.json";
if (is_file($__cfg_file)) {
    $t = @file_get_contents($__cfg_file);
    $d = @json_decode($t, true);
    if (is_array($d)) {
        $__cfg_json = $d;
    }
}
function __cfg_ovr($k, $default)
{
    global $__cfg_json;
    if (!is_array($__cfg_json)) {
        $__cfg_json = [];
    }
    return array_key_exists($k, $__cfg_json) ? $__cfg_json[$k] : $default;
}
/* JSON overrides from data/config.json */
$__auto_base = (function () {
    $doc = rtrim($_SERVER["DOCUMENT_ROOT"] ?? "", "/");
    $root = realpath(__DIR__ . "/..");
    if ($doc && $root && strpos($root, $doc) === 0) {
        $p = substr($root, strlen($doc));
        return $p === "" ? "/" : $p;
    }
    $script = $_SERVER["SCRIPT_NAME"] ?? "";
    $dir = rtrim(dirname($script), "/");
    return $dir === "" ? "/" : $dir;
})();
if (!defined("BASE_URL")) {
    $baseLocal = cfg_local("site.base_url", null);
    if (is_string($baseLocal) && $baseLocal !== "") {
        define("BASE_URL", rtrim($baseLocal, "/"));
    }
}
if (!defined("BASE_URL")) {
    define("BASE_URL", __cfg_ovr("BASE_URL", $__auto_base));
}
if (!defined("STATE_DIR")) {
    define("STATE_DIR", PROJECT_ROOT . "/state");
}
if (!defined("DATA_DIR")) {
    define("DATA_DIR", PROJECT_ROOT . "/data");
}
if (!defined("USERS_FILE")) {
    define("USERS_FILE", DATA_DIR . "/users.json");
}
if (!defined("THEME_DEFAULT")) {
    define("THEME_DEFAULT", __cfg_ovr("THEME_DEFAULT", "nord"));
}
if (!defined("MAIL_FROM")) {
    define("MAIL_FROM", __cfg_ovr("MAIL_FROM", cfg_mail_default_sender()));
}
if (!defined("MAIL_ENVELOPE_FROM")) {
    define(
        "MAIL_ENVELOPE_FROM",
        __cfg_ovr(
            "MAIL_ENVELOPE_FROM",
            cfg_mail_extract_email(defined("MAIL_FROM") ? MAIL_FROM : "") ?:
            cfg_mail_default_sender(),
        ),
    );
}
// if (!defined('CRON_TOKEN')) define('CRON_TOKEN', __cfg_ovr('CRON_TOKEN', 'change-me'));
