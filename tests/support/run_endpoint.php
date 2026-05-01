<?php

$root = dirname(__DIR__, 2);
$path = (string) ($argv[1] ?? "");
$query = (string) ($argv[2] ?? "");
$role = (string) ($argv[3] ?? "user");
$method = strtoupper((string) ($argv[4] ?? "GET"));
$bodyJson = (string) ($argv[5] ?? "");

if ($path === "") {
    fwrite(STDERR, "missing path\n");
    exit(2);
}

ini_set("session.save_path", sys_get_temp_dir());
session_id("test-" . substr(md5($path . "|" . $query . "|" . $role), 0, 24));
session_start();
$_SESSION["user"] = ["username" => "tester", "role" => $role];
$_SESSION["csrf"] = "test-csrf-token";

$_SERVER["DOCUMENT_ROOT"] = $root;
$_SERVER["SCRIPT_NAME"] = "/" . ltrim($path, "/");
$_SERVER["REQUEST_URI"] =
    "/" . ltrim($path, "/") . ($query !== "" ? "?" . $query : "");
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["SERVER_NAME"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = $method;

parse_str($query, $_GET);
$body = [];
if ($bodyJson !== "") {
    $decoded = json_decode($bodyJson, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
$_POST = $method === "POST" ? $body : [];

ob_start();
require $root . "/" . ltrim($path, "/");
$output = ob_get_clean();
fwrite(STDOUT, (string) $output);
