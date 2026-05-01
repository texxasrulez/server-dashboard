<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$uri = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$path = realpath($root . $uri);

ini_set("session.save_path", sys_get_temp_dir());

if (!empty($_GET["__smoke_role"]) || !empty($_COOKIE[session_name()])) {
    session_start();
    if (!empty($_GET["__smoke_role"])) {
        $_SESSION["user"] = [
            "username" => "browser-smoke",
            "role" => (string) $_GET["__smoke_role"],
        ];
        $_SESSION["csrf"] = "browser-smoke-csrf";
    }
    if (array_key_exists("__smoke_fixture", $_GET)) {
        $_SESSION["__smoke_fixture"] = (string) $_GET["__smoke_fixture"];
    }
}

if (($_SESSION["__smoke_fixture"] ?? "") === "ops") {
    @mkdir($root . "/data", 0775, true);
    @mkdir($root . "/state", 0775, true);
    @mkdir($root . "/state/logs", 0775, true);

    @file_put_contents(
        $root . "/data/services.json",
        json_encode(
            [
                "items" => [
                    [
                        "id" => "svc_smoke_web",
                        "name" => "Smoke Web",
                        "type" => "app",
                        "host" => "127.0.0.1",
                        "port" => 8080,
                        "check" => "http",
                        "path" => "/health",
                        "timeout_ms" => 800,
                        "enabled" => true,
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ),
    );

    $servicesStatus = json_encode(
        [
            "results" => [
                [
                    "id" => "svc_smoke_web",
                    "status" => "warn",
                    "latency_ms" => 1200,
                    "http_code" => 502,
                    "ts" => time() - 120,
                ],
            ],
            "ts" => time() - 120,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    );
    @file_put_contents($root . "/state/services_status.json", $servicesStatus);
    @file_put_contents($root . "/data/services_status.json", $servicesStatus);

    $historyJsonl =
        json_encode(
            [
                "id" => "svc_smoke_web",
                "ts" => time() - 600,
                "status" => "up",
                "latency_ms" => 120,
                "http_code" => 200,
            ],
            JSON_UNESCAPED_SLASHES,
        ) .
        "\n" .
        json_encode(
            [
                "id" => "svc_smoke_web",
                "ts" => time() - 180,
                "status" => "down",
                "latency_ms" => 1600,
                "http_code" => 502,
            ],
            JSON_UNESCAPED_SLASHES,
        ) .
        "\n" .
        json_encode(
            [
                "id" => "svc_smoke_web",
                "ts" => time() - 120,
                "status" => "warn",
                "latency_ms" => 1200,
                "http_code" => 502,
            ],
            JSON_UNESCAPED_SLASHES,
        ) .
        "\n";
    @file_put_contents(
        $root . "/state/services_status_history.jsonl",
        $historyJsonl,
    );
    @file_put_contents(
        $root . "/data/services_status_history.jsonl",
        $historyJsonl,
    );

    $alertsJsonl =
        json_encode(
            [
                "ts" => time() - 150,
                "alert_id" => "alert_smoke",
                "alert_name" => "Smoke Web latency high",
                "service_id" => "svc_smoke_web",
                "service_name" => "Smoke Web",
                "metric" => "latency_ms",
                "op" => ">=",
                "threshold" => 1000,
                "value" => 1200,
                "severity" => "warn",
            ],
            JSON_UNESCAPED_SLASHES,
        ) . "\n";
    @file_put_contents($root . "/state/alerts_events.jsonl", $alertsJsonl);
    @file_put_contents($root . "/data/alerts_events.jsonl", $alertsJsonl);

    @file_put_contents(
        $root . "/state/backup_restore_verification.json",
        json_encode(
            [
                "history" => [
                    [
                        "ts" => date("c", time() - 300),
                        "method" => "integrity-verified",
                        "result" => "pass",
                        "checks" => [
                            [
                                "label" => "snapshots",
                                "result" => "pass",
                                "reason" => "latest artifact looks sane",
                            ],
                        ],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ),
    );
}

if (
    $uri === "/api/speedtest_history.php" &&
    ($_SESSION["__smoke_fixture"] ?? "") === "speedtest"
) {
    header("Content-Type: application/json; charset=utf-8");
    $rows = [];
    $download = [];
    $upload = [];
    $ping = [];
    $jitter = [];
    $packetLoss = [];
    $baseTs = strtotime("2026-04-08 12:00:00 UTC");

    for ($i = 0; $i < 60; $i++) {
        $ts = $baseTs - $i * 1800;
        $row = [
            "timestamp" => gmdate("c", $ts),
            "timestamp_ts" => $ts,
            "status" => "success",
            "backend" => "cli",
            "server_label" => "Test Node " . (($i % 3) + 1),
            "server_name" => "Fixture Server " . (($i % 3) + 1),
            "server_location" => "Lab " . (($i % 3) + 1),
            "server_id" => "srv-" . (($i % 3) + 1),
            "ping_ms" => 8 + ($i % 7),
            "jitter_ms" => 1 + ($i % 4),
            "download_mbps" => 600 - $i,
            "upload_mbps" => 120 - $i / 4,
            "packet_loss" => 0,
            "duration_ms" => 15000 + $i * 20,
            "raw_tool_version" => "fixture-1.0.0",
            "error_message" => "",
        ];
        $rows[] = $row;
        $download[] = [
            "ts" => $ts,
            "value" => $row["download_mbps"],
            "timestamp" => $row["timestamp"],
            "status" => $row["status"],
        ];
        $upload[] = [
            "ts" => $ts,
            "value" => $row["upload_mbps"],
            "timestamp" => $row["timestamp"],
            "status" => $row["status"],
        ];
        $ping[] = [
            "ts" => $ts,
            "value" => $row["ping_ms"],
            "timestamp" => $row["timestamp"],
            "status" => $row["status"],
        ];
        $jitter[] = [
            "ts" => $ts,
            "value" => $row["jitter_ms"],
            "timestamp" => $row["timestamp"],
            "status" => $row["status"],
        ];
        $packetLoss[] = [
            "ts" => $ts,
            "value" => 0,
            "timestamp" => $row["timestamp"],
            "status" => $row["status"],
        ];
    }

    echo json_encode(
        [
            "ok" => true,
            "filters" => [
                "range" => "24h",
                "server" => "",
                "include_failed" => false,
            ],
            "summary" => [
                "latest_result" => [
                    "status" => "success",
                    "timestamp" => gmdate("c", $baseTs),
                ],
                "latest_download_mbps" => 600,
                "latest_upload_mbps" => 120,
                "latest_ping_ms" => 8,
                "average_download_mbps" => 570,
                "average_upload_mbps" => 112,
                "best_result" => [
                    "download_mbps" => 600,
                    "timestamp" => gmdate("c", $baseTs),
                ],
                "worst_result" => [
                    "download_mbps" => 541,
                    "timestamp" => gmdate("c", $baseTs - 59 * 1800),
                ],
                "success_count" => 60,
                "failure_count" => 0,
                "last_successful_test" => gmdate("c", $baseTs),
            ],
            "charts" => [
                "download" => $download,
                "upload" => $upload,
                "ping" => $ping,
                "jitter" => $jitter,
                "packet_loss" => $packetLoss,
            ],
            "rows" => $rows,
            "servers" => [
                ["value" => "srv-1", "label" => "Fixture Server 1"],
                ["value" => "srv-2", "label" => "Fixture Server 2"],
                ["value" => "srv-3", "label" => "Fixture Server 3"],
            ],
            "invalid_lines" => 0,
        ],
        JSON_UNESCAPED_SLASHES,
    );
    return;
}

if (
    $path &&
    is_file($path) &&
    str_starts_with($path, $root . DIRECTORY_SEPARATOR)
) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === "php") {
        $_SERVER["DOCUMENT_ROOT"] = $root;
        $_SERVER["SCRIPT_FILENAME"] = $path;
        $_SERVER["SCRIPT_NAME"] = $uri;
        $_SERVER["PHP_SELF"] = $uri;
        chdir(dirname($path));
        require $path;
        return;
    }

    $types = [
        "css" => "text/css; charset=utf-8",
        "js" => "application/javascript; charset=utf-8",
        "json" => "application/json; charset=utf-8",
        "svg" => "image/svg+xml",
        "png" => "image/png",
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "gif" => "image/gif",
        "webp" => "image/webp",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
    ];
    if (isset($types[$ext])) {
        header("Content-Type: " . $types[$ext]);
    }
    readfile($path);
    return;
}

http_response_code(404);
echo "Not found";
