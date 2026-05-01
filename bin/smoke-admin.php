<?php

declare(strict_types=1);

$root = dirname(__DIR__);

if (($argv[1] ?? "") === "--worker") {
    $path = (string) ($argv[2] ?? "");
    $query = (string) ($argv[3] ?? "");
    $role = (string) ($argv[4] ?? "user");

    if ($path === "") {
        fwrite(STDERR, "missing path\n");
        exit(2);
    }

    ini_set("session.save_path", sys_get_temp_dir());
    session_id(
        "smoke-" . substr(md5($path . "|" . $query . "|" . $role), 0, 24),
    );
    session_start();
    $_SESSION["user"] = ["username" => "smoke", "role" => $role];
    $_SESSION["csrf"] = "smoke-csrf-token";

    $_SERVER["DOCUMENT_ROOT"] = $root;
    $_SERVER["SCRIPT_NAME"] = "/" . ltrim($path, "/");
    $_SERVER["REQUEST_URI"] =
        "/" . ltrim($path, "/") . ($query !== "" ? "?" . $query : "");
    $_SERVER["HTTP_HOST"] = "localhost";
    $_SERVER["SERVER_NAME"] = "localhost";
    $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    $_SERVER["REQUEST_METHOD"] = "GET";

    parse_str($query, $_GET);
    $_POST = [];

    ob_start();
    require $root . "/" . ltrim($path, "/");
    $output = ob_get_clean();
    fwrite(STDOUT, (string) $output);
    exit(0);
}

function smokeRun(
    string $path,
    string $query = "",
    string $role = "user",
): string {
    global $argv;
    $cmd =
        escapeshellarg(PHP_BINARY) .
        " " .
        escapeshellarg(__FILE__) .
        " --worker " .
        escapeshellarg($path) .
        " " .
        escapeshellarg($query) .
        " " .
        escapeshellarg($role);

    $output = shell_exec($cmd);
    return is_string($output) ? $output : "";
}

function smokeJson(
    string $path,
    string $query = "",
    string $role = "user",
): array {
    $output = smokeRun($path, $query, $role);
    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            "Invalid JSON from " . $path . ": " . $output,
        );
    }
    return $decoded;
}

$checks = [];
$month = date("Y-m");

try {
    $health = smokeJson("api/health.php");
    $checks[] = ["label" => "api/health.php", "ok" => !empty($health["ok"])];

    $token = smokeJson("api/cron_token_admin.php", "action=status", "admin");
    $checks[] = [
        "label" => "api/cron_token_admin.php?action=status",
        "ok" => !empty($token["ok"]) && isset($token["status"]["masked"]),
    ];

    $report = smokeJson(
        "api/report_uptime.php",
        "month=" . rawurlencode($month),
        "admin",
    );
    $checks[] = [
        "label" => "api/report_uptime.php?month=" . $month,
        "ok" => !empty($report["ok"]) && array_key_exists("overall", $report),
    ];

    $diagHtml = smokeRun("diag.php", "", "admin");
    $checks[] = [
        "label" => "diag.php",
        "ok" => strpos($diagHtml, "Environment Doctor") !== false,
    ];

    $assetsHtml = smokeRun("tools/assets_audit.php", "", "admin");
    $checks[] = [
        "label" => "tools/assets_audit.php",
        "ok" => strpos($assetsHtml, "Assets Audit") !== false,
    ];

    $auditHtml = smokeRun("tools/admin_audit.php", "", "admin");
    $checks[] = [
        "label" => "tools/admin_audit.php",
        "ok" => strpos($auditHtml, "Admin Audit") !== false,
    ];
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$failed = false;
foreach ($checks as $check) {
    $line = sprintf("[%s] %s", $check["ok"] ? "OK" : "FAIL", $check["label"]);
    fwrite(STDOUT, $line . PHP_EOL);
    if (!$check["ok"]) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
