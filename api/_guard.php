<?php

// api/_guard.php — lightweight API guard: IP allowlist, bearer token, rate limiting
// Usage: require __DIR__.'/_guard.php'; guard_api(['key'=>'metrics_prom','require_token'=>true,'type'=>'text']);
//
// Reads from config/local.json -> security: { api_tokens:[], api_rate_limit_ms:int, ip_allowlist:[] }

if (!function_exists('guard_read_cfg')) {
    function guard_read_cfg($dot, $default = null)
    {
        $file = dirname(__DIR__) . '/config/local.json';
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return $default;
        }
        $cur = $j;
        foreach (explode('.', $dot) as $k) {
            if (is_array($cur) && array_key_exists($k, $cur)) {
                $cur = $cur[$k];
            } else {
                return $default;
            }
        }
        return $cur;
    }
}

if (!function_exists('guard_client_ip')) {
    function guard_ip_in_cidr($ip, $cidr)
    {
        $ip = trim((string)$ip);
        $cidr = trim((string)$cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            return strcasecmp($ip, $cidr) === 0;
        }
        list($base, $mask) = explode('/', $cidr, 2);
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
    function guard_ip_in_any($ip, $list)
    {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $cidr) {
            if (!is_string($cidr)) {
                continue;
            }
            if (guard_ip_in_cidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }
    function guard_client_ip()
    {
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $xff = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        $trusted = guard_read_cfg('security.trusted_proxies', []);
        if ($xff !== '' && $remote !== '' && guard_ip_in_any($remote, is_array($trusted) ? $trusted : [])) {
            $parts = array_map('trim', explode(',', $xff));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }
        return '0.0.0.0';
    }
}

if (!function_exists('guard_api')) {
    function guard_api($opts = [])
    {
        $ct = isset($opts['type']) && $opts['type'] === 'text' ? 'text/plain' : 'application/json';
        header('X-Robots-Tag: noindex');
        // IP allowlist (applies if list not empty)
        $allow = guard_read_cfg('security.ip_allowlist', []);
        if (is_array($allow) && count($allow)) {
            $ip = guard_client_ip();
            if (!guard_ip_in_any($ip, $allow)) {
                http_response_code(403);
                if ($ct !== 'text/plain') {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo ($ct === 'text/plain') ? "forbidden\n" : json_encode(['ok' => false,'error' => 'forbidden ip']);
                exit;
            }
        }
        // Rate limit per endpoint+ip
        $rate_ms = (int)guard_read_cfg('security.api_rate_limit_ms', 100);
        $key = isset($opts['key']) ? preg_replace('/[^a-z0-9_\-]+/i', '', $opts['key']) : 'api';
        if ($rate_ms > 0) {
            $dir = dirname(__DIR__) . '/state/ratelimit';
            @mkdir($dir, 0775, true);
            $ip = guard_client_ip();
            $f = $dir . '/' . $key . '_' . preg_replace('/[^a-z0-9:._-]+/i', '_', $ip) . '.txt';
            $now = microtime(true);
            $last = 0.0;
            if (is_file($f)) {
                $raw = @file_get_contents($f);
                if ($raw !== false) {
                    $last = floatval($raw);
                }
            }
            if (($now - $last) * 1000.0 < $rate_ms) {
                http_response_code(429);
                if ($ct !== 'text/plain') {
                    header('Content-Type: application/json; charset=utf-8');
                }
                $retry = max(0, $rate_ms - intval(($now - $last) * 1000.0));
                echo ($ct === 'text/plain') ? "rate_limited\n" : json_encode(['ok' => false,'error' => 'rate limited','retry_ms' => $retry]);
                exit;
            }
            @file_put_contents($f, sprintf('%.6f', $now), LOCK_EX);
        }
        // Optional admin requirement (session-backed)
        if (!empty($opts['require_admin'])) {
            require_once dirname(__DIR__) . '/includes/init.php';
            require_once dirname(__DIR__) . '/includes/auth.php';
            $isAdmin = function_exists('user_is_admin') ? user_is_admin() : false;
            if (!$isAdmin) {
                http_response_code(403);
                if ($ct !== 'text/plain') {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo ($ct === 'text/plain') ? "forbidden\n" : json_encode(['ok' => false,'error' => 'admin required']);
                exit;
            }
        }
        // Token (only if require_token AND tokens exist)
        $need = !empty($opts['require_token']);
        $tokens = guard_read_cfg('security.api_tokens', []);
        if ($need) {
            if (!is_array($tokens) || !count($tokens)) {
                http_response_code(503);
                if ($ct !== 'text/plain') {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo ($ct === 'text/plain') ? "misconfigured\n" : json_encode(['ok' => false,'error' => 'token auth misconfigured']);
                exit;
            }
            $got = '';
            if (!empty($_GET['token'])) {
                $got = (string)$_GET['token'];
            }
            if (!$got && !empty($_POST['token'])) {
                $got = (string)$_POST['token'];
            }
            if (!$got && !empty($_SERVER['HTTP_X_API_TOKEN'])) {
                $got = (string)$_SERVER['HTTP_X_API_TOKEN'];
            }
            if (!$got && !empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
                $got = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
            }
            $ok = false;
            if ($got) {
                foreach ($tokens as $tok) {
                    if (is_string($tok) && $tok !== '' && hash_equals($tok, (string)$got)) {
                        $ok = true;
                        break;
                    }
                }
            }
            if (!$ok) {
                http_response_code(401);
                if ($ct !== 'text/plain') {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo ($ct === 'text/plain') ? "unauthorized\n" : json_encode(['ok' => false,'error' => 'unauthorized']);
                exit;
            }
        }
    }
}
