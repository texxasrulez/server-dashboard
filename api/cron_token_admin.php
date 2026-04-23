<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/AdminMaintenance.php";

require_admin();
header("Content-Type: application/json; charset=utf-8");

$action = strtolower(
    trim((string) ($_GET["action"] ?? ($_POST["action"] ?? "status"))),
);

function cron_token_admin_status_payload(): array
{
    $status = \AdminMaintenance::cronTokenStatus();
    return [
        "ok" => true,
        "status" => $status,
        "guidance" => [
            "preferred" =>
                "Use the `X-CRON-TOKEN` header or `Authorization: Bearer <token>` for automation.",
            "discouraged" =>
                "Avoid `?token=` in query strings when you can use headers; URLs are easier to leak via logs and browser history.",
        ],
    ];
}

if ($action === "status") {
    echo json_encode(cron_token_admin_status_payload(), JSON_UNESCAPED_SLASHES);
    exit();
}

$body = json_decode((string) file_get_contents("php://input"), true);
if (!is_array($body)) {
    $body = $_POST;
}
$csrf = (string) ($body["_csrf"] ?? ($body["csrf"] ?? ""));
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "CSRF failed"]);
    exit();
}

if ($action === "authorize") {
    $password = (string) ($body["password"] ?? "");
    if (!\AdminMaintenance::verifyCurrentPassword($password)) {
        http_response_code(403);
        echo json_encode([
            "ok" => false,
            "error" => "Current password did not match",
        ]);
        exit();
    }
    $expires = \AdminMaintenance::authorizeTokenReveal();
    echo json_encode(
        [
            "ok" => true,
            "authorized_until" => date("c", $expires),
            "authorized_until_ts" => $expires,
        ],
        JSON_UNESCAPED_SLASHES,
    );
    exit();
}

if ($action === "revoke") {
    \AdminMaintenance::clearRevealAuthorization();
    echo json_encode(["ok" => true]);
    exit();
}

if ($action === "reveal") {
    $token = \AdminMaintenance::revealedCronToken();
    if ($token === "") {
        http_response_code(403);
        echo json_encode([
            "ok" => false,
            "error" => "Authorize token reveal in this session first",
        ]);
        exit();
    }
    echo json_encode(
        [
            "ok" => true,
            "token" => $token,
            "authorized_until_ts" => \AdminMaintenance::revealExpiry(),
        ],
        JSON_UNESCAPED_SLASHES,
    );
    exit();
}

if ($action === "rotate") {
    if (!\AdminMaintenance::canRevealCronToken()) {
        http_response_code(403);
        echo json_encode([
            "ok" => false,
            "error" =>
                "Re-authorize with your current password before rotating the token",
        ]);
        exit();
    }
    $result = \AdminMaintenance::rotateCronToken();
    echo json_encode(
        [
            "ok" => true,
            "rotated_at" => $result["rotated_at"],
            "masked" => $result["masked"],
            "status" => \AdminMaintenance::cronTokenStatus(),
        ],
        JSON_UNESCAPED_SLASHES,
    );
    exit();
}

http_response_code(400);
echo json_encode(["ok" => false, "error" => "Unknown action"]);
