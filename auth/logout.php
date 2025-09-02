<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
$theme = $_SESSION['theme'] ?? null;
auth_logout();
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if ($theme) $_SESSION['theme'] = $theme;
header('Location: login.php');
exit;
