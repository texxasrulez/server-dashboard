<?php

date_default_timezone_set("UTC");

$_SERVER["DOCUMENT_ROOT"] = $_SERVER["DOCUMENT_ROOT"] ?? dirname(__DIR__);
$_SERVER["SCRIPT_NAME"] = $_SERVER["SCRIPT_NAME"] ?? "/index.php";
$_SERVER["REQUEST_URI"] = $_SERVER["REQUEST_URI"] ?? "/";
$_SERVER["HTTP_HOST"] = $_SERVER["HTTP_HOST"] ?? "localhost";
$_SERVER["SERVER_NAME"] = $_SERVER["SERVER_NAME"] ?? "localhost";
$_SERVER["REMOTE_ADDR"] = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";

require_once dirname(__DIR__) . "/includes/init.php";
require_once dirname(__DIR__) . "/includes/auth.php";
require_once dirname(__DIR__) . "/lib/AdminMaintenance.php";
require_once dirname(__DIR__) . "/lib/ServerDiag.php";
require_once dirname(__DIR__) . "/lib/UptimeReport.php";

\App\Config::init(dirname(__DIR__));
