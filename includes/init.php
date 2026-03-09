<?php

// includes/init.php — canonical bootstrap (no UI includes).
// Safe to include multiple times.
require_once __DIR__ . '/config.inc.php';
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
          || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params([
          'lifetime' => 0,
          'path' => '/',
          'secure' => $https,
          'httponly' => true,
          'samesite' => 'Lax',
        ]);
    }
    session_start();
}

if (!defined('BUILD')) {
    define('BUILD', date('Ymd.His'));
}
if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('project_url')) {
    function project_url(string $p = ''): string
    {
        $p = (string)$p;
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(dirname($script), '/');
        if ($p === '' || $p === '/') {
            return $base . '/';
        }
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }
        if ($p[0] === '/') {
            return $base . $p;
        }
        return $base . '/' . $p;
    }
}

if (!function_exists('asset_href')) {
    function asset_href(string $rel): string
    {
        $rel = ltrim((string)$rel, '/');
        return project_url('/assets/' . $rel);
    }
}

if (!function_exists('request_ip_in_cidr')) {
    function request_ip_in_cidr(string $ip, string $cidr): bool
    {
        $ip = trim($ip);
        $cidr = trim($cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            return strcasecmp($ip, $cidr) === 0;
        }
        [$base, $mask] = explode('/', $cidr, 2);
        $maskBits = (int)$mask;
        $ipBin = @inet_pton($ip);
        $baseBin = @inet_pton($base);
        if ($ipBin === false || $baseBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($baseBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        if ($maskBits < 0) {
            $maskBits = 0;
        }
        if ($maskBits > $maxBits) {
            $maskBits = $maxBits;
        }
        $fullBytes = intdiv($maskBits, 8);
        $remainBits = $maskBits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($baseBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainBits === 0) {
            return true;
        }
        $maskByte = (0xFF << (8 - $remainBits)) & 0xFF;
        return ((ord($ipBin[$fullBytes]) & $maskByte) === (ord($baseBin[$fullBytes]) & $maskByte));
    }
}

if (!function_exists('request_ip_in_any')) {
    function request_ip_in_any(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (!is_string($range)) {
                continue;
            }
            if (request_ip_in_cidr($ip, trim($range))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('request_trusted_proxies')) {
    function request_trusted_proxies(): array
    {
        $list = [];
        if (function_exists('cfg_local')) {
            $raw = cfg_local('security.trusted_proxies', []);
            if (is_array($raw)) {
                foreach ($raw as $entry) {
                    if (!is_string($entry)) {
                        continue;
                    }
                    $entry = trim($entry);
                    if ($entry !== '') {
                        $list[] = $entry;
                    }
                }
            }
        }
        return array_values(array_unique($list));
    }
}

if (!function_exists('request_client_ip')) {
    function request_client_ip(): string
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($xff !== '' && $remote !== '') {
            $trusted = request_trusted_proxies();
            if ($trusted && request_ip_in_any($remote, $trusted)) {
                $parts = array_map('trim', explode(',', $xff));
                foreach ($parts as $part) {
                    if (filter_var($part, FILTER_VALIDATE_IP)) {
                        return $part;
                    }
                }
            }
        }
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        return '0.0.0.0';
    }
}

// === Themes: support BOTH legacy assets/css/themes/*.css and assets/css/themes/* ===
if (!function_exists('theme_list')) {
    function theme_list(): array
    {
        $names = [];
        $dir = __DIR__ . '/../assets/css/themes';
        if (is_dir($dir)) {
            foreach (@scandir($dir) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $fs = $dir . '/' . $f;
                if (is_dir($fs)) {
                    // accept folder-based theme only if it contains theme.css
                    if (is_file($fs . '/theme.css')) {
                        $names[] = $f;
                    }
                } elseif (substr($f, -4) === '.css') {
                    $name = basename($f, '.css');
                    // skip mobile variants
                    if (preg_match('/\.mobile$/i', $name)) {
                        continue;
                    }
                    $names[] = $name;
                }
            }
        }
        if (!$names) {
            $names = ['nord'];
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }
}

// Determine current theme (session → cookie → THEME_DEFAULT → 'default')
if (!function_exists('theme_current')) {
    function theme_current(): string
    {
        $t = null;
        if (!empty($_SESSION['theme'])) {
            $t = (string) $_SESSION['theme'];
        } elseif (!empty($_COOKIE['dash_theme'])) {
            $t = preg_replace('/[^a-z0-9_\-]/i', '', (string)$_COOKIE['dash_theme']);
        } elseif (defined('THEME_DEFAULT')) {
            $t = (string) THEME_DEFAULT;
        } else {
            $t = 'default';
        }
        $list = theme_list();
        if (!in_array($t, $list, true)) {
            $t = 'default';
        }
        return $t;
    }
}

$GLOBALS['THEME'] = theme_current();

if (!function_exists('theme_href')) {
    function theme_href(string $rel = ''): string
    {
        if ($rel !== '') {
            $rel = ltrim($rel, '/');
            return project_url('/assets/css/' . $rel);
        }
        $theme = $GLOBALS['THEME'] ?? 'default';
        // Legacy single-file theme wins if present
        $legacy = __DIR__ . '/../assets/css/themes/' . $theme . '.css';
        if (is_file($legacy)) {
            return project_url('/assets/css/themes/' . $theme . '.css');
        }
        // Modern candidates
        $candidates = [
          ['fs' => __DIR__ . '/../assets/css/themes/' . $theme . '.css',        'web' => '/assets/css/themes/' . $theme . '.css'],
          ['fs' => __DIR__ . '/../assets/css/themes/' . $theme . '/theme.css',  'web' => '/assets/css/themes/' . $theme . '/theme.css'],
          ['fs' => __DIR__ . '/../assets/css/themes/default.css',               'web' => '/assets/css/themes/default.css'],
          ['fs' => __DIR__ . '/../assets/css/theme.css',                        'web' => '/assets/css/theme.css'],
        ];
        foreach ($candidates as $c) {
            if (is_file($c['fs'])) {
                return project_url($c['web']);
            }
        }
        return project_url('/assets/css/theme.css');
    }
}

if (!function_exists('cron_token_candidates')) {
    function cron_token_candidates(): array
    {
        $tokens = [];
        if (defined('CRON_TOKEN')) {
            $tokens[] = trim((string) CRON_TOKEN);
        }
        $env = getenv('DASH_CRON_TOKEN');
        if ($env !== false && $env !== '') {
            $tokens[] = trim($env);
        }
        if (function_exists('cfg_local')) {
            $apiTokens = cfg_local('security.api_tokens', []);
            if (is_array($apiTokens)) {
                foreach ($apiTokens as $apiTok) {
                    if (is_string($apiTok) && $apiTok !== '') {
                        $tokens[] = trim($apiTok);
                    }
                }
            }
        }
        if (defined('PROJECT_ROOT')) {
            $files = [
              PROJECT_ROOT . '/data/cron_token.txt',
              PROJECT_ROOT . '/state/cron_token.txt',
            ];
            foreach ($files as $file) {
                if (is_file($file)) {
                    $txt = trim((string) @file_get_contents($file));
                    if ($txt !== '') {
                        $tokens[] = $txt;
                    }
                }
            }
        }
        $tokens = array_values(array_unique(array_filter($tokens, function ($v) {
            return trim((string)$v) !== '';
        })));
        return $tokens;
    }
}

if (!function_exists('cron_request_token')) {
    function cron_request_token(): string
    {
        $candidates = [
          $_GET['token'] ?? null,
          $_POST['token'] ?? null,
          $_SERVER['HTTP_X_CRON_TOKEN'] ?? null,
        ];
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) {
            $candidates[] = trim(substr($auth, 7));
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return trim((string)$candidate);
            }
        }
        return '';
    }
}

if (!function_exists('cron_token_is_valid')) {
    function cron_token_is_valid(string $token): bool
    {
        $token = (string)$token;
        if ($token === '') {
            return false;
        }
        foreach (cron_token_candidates() as $expected) {
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }
        return false;
    }
}
