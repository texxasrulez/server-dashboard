<?php

// Upsert alert (create/update) — admin only
require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/_alerts_store.php";
require_admin();
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

// Read JSON body; fall back to form-encoded
$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body) || empty($body)) {
    $body = $_POST;
    // If still not an array, bail clearly
    if (!is_array($body)) {
        $body = [];
    }
}
if (!csrf_check_request((string) ($body["_csrf"] ?? ($body["csrf"] ?? "")))) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}

// Helper: nested getter by dotted path (e.g., "notify.email")
function arr_get($arr, $path, $default = null)
{
    if (!is_array($arr)) {
        return $default;
    }
    $parts = explode(".", $path);
    $cur = $arr;
    foreach ($parts as $p) {
        if (is_array($cur) && array_key_exists($p, $cur)) {
            $cur = $cur[$p];
        } else {
            return $default;
        }
    }
    return $cur;
}
function v($key, $default = null)
{
    global $body;
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

// Coerce/normalize input
$alert = [
    "id" => (string) v("id", ""),
    "name" => trim((string) v("name", "")),
    "service_id" => trim((string) v("service_id", "")),
    "service_name" => trim((string) v("service_name", "")),
    "metric" => trim((string) v("metric", "status")),
    "op" => trim((string) v("op", ">")),
    "threshold" => (float) v("threshold", 1),
    "consecutive" => max(1, (int) v("consecutive", 3)),
    "cooldown_min" => max(1, (int) v("cooldown_min", 30)),
    "severity" => trim((string) v("severity", "warn")),
    "notify" => [
        "email" => trim(
            (string) arr_get($body, "notify.email", v("email", "")),
        ),
        "webhook_url" => trim(
            (string) arr_get($body, "notify.webhook_url", v("webhook_url", "")),
        ),
    ],
    "enabled" => (bool) v("enabled", true),
];

// Validate
$allowed_metrics = ["status", "latency_ms", "http_code", "packet_loss_pct"];
$allowed_ops = [">", ">=", "==", "<=", "<", "!="];
$allowed_sev = ["info", "warn", "crit"];

if ($alert["name"] === "" || $alert["service_id"] === "") {
    http_response_code(422);
    echo json_encode(["error" => "name and service_id are required"]);
    exit();
}
if (!in_array($alert["metric"], $allowed_metrics, true)) {
    http_response_code(422);
    echo json_encode(["error" => "invalid metric"]);
    exit();
}
if (!in_array($alert["op"], $allowed_ops, true)) {
    http_response_code(422);
    echo json_encode(["error" => "invalid operator"]);
    exit();
}
if (!in_array($alert["severity"], $allowed_sev, true)) {
    http_response_code(422);
    echo json_encode(["error" => "invalid severity"]);
    exit();
}

// File paths
$dataDir = __DIR__ . "/../data";
$stateDir = __DIR__ . "/../state";
@mkdir($dataDir, 0775, true);
@mkdir($stateDir, 0775, true);
$dataPath = $dataDir . "/alerts.json";
$statePath = $stateDir . "/alerts.json";

if (!file_exists($dataPath) && file_exists($statePath)) {
    @copy($statePath, $dataPath);
}
if (!file_exists($dataPath)) {
    write_json_atomic($dataPath, ["items" => []]);
}

// Load, normalize, upsert
$store = alerts_store_read($dataPath);
$payload = $store["payload"];
$items = $store["items"];

if ($alert["id"] === "") {
    $alert["id"] = "alert_" . bin2hex(random_bytes(6));
}
$metaKeys = [
    "last_triggered" => null,
    "times_triggered" => 0,
    "consecutive_count" => 0,
    "silenced_until" => null,
];
foreach ($metaKeys as $metaKey => $defaultVal) {
    if (!array_key_exists($metaKey, $alert)) {
        $alert[$metaKey] = $defaultVal;
    }
}

$found = false;
for ($i = 0; $i < count($items); $i++) {
    if (isset($items[$i]["id"]) && $items[$i]["id"] === $alert["id"]) {
        foreach (array_keys($metaKeys) as $metaKey) {
            if (isset($items[$i][$metaKey])) {
                $alert[$metaKey] = $items[$i][$metaKey];
            }
        }
        $items[$i] = $alert;
        $found = true;
        break;
    }
}
if (!$found) {
    $items[] = $alert;
}

alerts_store_write($dataPath, $payload, $items);

echo json_encode(["ok" => true, "item" => $alert], JSON_UNESCAPED_SLASHES);
