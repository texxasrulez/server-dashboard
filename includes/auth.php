<?php

// includes/auth.php — session auth helpers (JSON-backed user store)

require_once __DIR__ . "/../lib/Auth/AuthSupport.php";
require_once __DIR__ . "/../lib/Auth/AuthRateLimiter.php";
require_once __DIR__ . "/../lib/Auth/AuthUsers.php";
require_once __DIR__ . "/../lib/Auth/AuthSession.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!defined("USERS_FILE")) {
    define("USERS_FILE", __DIR__ . "/../data/users.json");
}

if (!function_exists("project_url")) {
    function project_url($path = "")
    {
        return \App\Auth\AuthSupport::projectUrl($path);
    }
}

if (!function_exists("auth_cfg")) {
    function auth_cfg($path, $default = null)
    {
        return \App\Auth\AuthSupport::config($path, $default);
    }
}

if (!function_exists("auth_client_ip")) {
    function auth_client_ip()
    {
        return \App\Auth\AuthSupport::clientIp();
    }
}

if (!function_exists("auth_rate_limit_config")) {
    function auth_rate_limit_config()
    {
        return \App\Auth\AuthRateLimiter::config();
    }
}

if (!function_exists("auth_login_rate_file")) {
    function auth_login_rate_file()
    {
        return \App\Auth\AuthRateLimiter::filePath();
    }
}

if (!function_exists("auth_login_rate_load")) {
    function auth_login_rate_load()
    {
        return \App\Auth\AuthRateLimiter::load();
    }
}

if (!function_exists("auth_login_rate_save")) {
    function auth_login_rate_save($data)
    {
        \App\Auth\AuthRateLimiter::save($data);
    }
}

if (!function_exists("auth_login_rate_key")) {
    function auth_login_rate_key($username, $ip)
    {
        return \App\Auth\AuthRateLimiter::key($username, $ip);
    }
}

if (!function_exists("auth_set_last_login_error")) {
    function auth_set_last_login_error($msg)
    {
        \App\Auth\AuthSession::setLastLoginError($msg);
    }
}

if (!function_exists("auth_last_login_error")) {
    function auth_last_login_error()
    {
        return \App\Auth\AuthSession::lastLoginError();
    }
}

if (!function_exists("auth_login_rate_prune")) {
    function auth_login_rate_prune($all, $windowSec, $now)
    {
        return \App\Auth\AuthRateLimiter::prune($all, $windowSec, $now);
    }
}

if (!function_exists("auth_login_rate_block_seconds")) {
    function auth_login_rate_block_seconds($username, $ip)
    {
        return \App\Auth\AuthRateLimiter::blockSeconds($username, $ip);
    }
}

if (!function_exists("auth_login_rate_register_failure")) {
    function auth_login_rate_register_failure($username, $ip)
    {
        \App\Auth\AuthRateLimiter::registerFailure($username, $ip);
    }
}

if (!function_exists("auth_login_rate_clear")) {
    function auth_login_rate_clear($username, $ip)
    {
        \App\Auth\AuthRateLimiter::clear($username, $ip);
    }
}

function csrf_token()
{
    return \App\Auth\AuthSession::csrfToken();
}

function csrf_check($token)
{
    return \App\Auth\AuthSession::csrfCheck($token);
}

function csrf_request_token($fallback = "")
{
    return \App\Auth\AuthSession::csrfRequestToken($fallback);
}

function csrf_check_request($fallback = "")
{
    return csrf_check(csrf_request_token($fallback));
}

function users_load()
{
    return \App\Auth\AuthUsers::load();
}

function users_save($data)
{
    \App\Auth\AuthUsers::save($data);
}

function user_find($username)
{
    return \App\Auth\AuthUsers::find($username);
}

function ensure_default_admin($force = false)
{
    return \App\Auth\AuthUsers::ensureDefaultAdmin((bool) $force);
}

function auth_login($username, $password)
{
    return \App\Auth\AuthSession::login($username, $password);
}

function auth_logout()
{
    \App\Auth\AuthSession::logout();
}

function current_user()
{
    return \App\Auth\AuthSession::currentUser();
}

function user_profile_of($username)
{
    return \App\Auth\AuthUsers::profileOf($username);
}

function gravatar_url_from($email, $size = 48)
{
    return \App\Auth\AuthSession::gravatarUrlFrom($email, $size);
}

function user_avatar_url($user_or_name = null, $size = 48)
{
    return \App\Auth\AuthSession::userAvatarUrl($user_or_name, $size);
}

function user_display_name($user_or_name = null)
{
    return \App\Auth\AuthSession::userDisplayName($user_or_name);
}

function is_logged_in()
{
    return \App\Auth\AuthSession::isLoggedIn();
}

function require_login()
{
    \App\Auth\AuthSession::requireLogin();
}

function user_is_admin($u = null)
{
    return \App\Auth\AuthSession::userIsAdmin($u);
}

function require_admin()
{
    \App\Auth\AuthSession::requireAdmin();
}

function user_add($username, $password, $role = "user")
{
    return \App\Auth\AuthUsers::add($username, $password, $role);
}

function user_delete($username)
{
    return \App\Auth\AuthUsers::delete($username, current_user());
}

function user_set_role($username, $role)
{
    return \App\Auth\AuthUsers::setRole($username, $role);
}
