<?php

if (!function_exists('server_dashboard_is_ipv6_local_or_private')) {
    function server_dashboard_is_ipv6_local_or_private($ip)
    {
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return true;
        }
        if ($bin === str_repeat("\x00", 15) . "\x01") {
            return true;
        }
        if ((ord($bin[0]) & 0xFE) === 0xFC) {
            return true;
        }
        if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
            return true;
        }
        if (ord($bin[0]) === 0x00) {
            return true;
        }
        return false;
    }
}

if (!function_exists('server_dashboard_is_private_or_reserved_ip')) {
    function server_dashboard_is_private_or_reserved_ip($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ok = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $ok === false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return server_dashboard_is_ipv6_local_or_private($ip);
        }
        return true;
    }
}

if (!function_exists('server_dashboard_host_allowed')) {
    function server_dashboard_host_allowed($host, $allowList)
    {
        if (!is_array($allowList) || !count($allowList)) {
            return true;
        }
        foreach ($allowList as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $entry = strtolower(trim($entry));
            if ($entry === '') {
                continue;
            }
            if ($host === $entry) {
                return true;
            }
            if ($entry[0] === '.' && substr($host, -strlen($entry)) === $entry) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('server_dashboard_resolve_host_ips')) {
    function server_dashboard_resolve_host_ips($host)
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            foreach ($a as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $row) {
                    $ip = $row['ipv6'] ?? '';
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $ip;
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }
}

if (!function_exists('server_dashboard_favicon_cache_file')) {
    function server_dashboard_favicon_cache_file($host)
    {
        $dataDir = __DIR__ . '/../data/favicons';
        @mkdir($dataDir, 0775, true);
        return $dataDir . '/' . preg_replace('/[^a-z0-9\.-]/i', '_', $host ?: 'default') . '.ico';
    }
}

if (!function_exists('server_dashboard_cache_favicon')) {
    function server_dashboard_cache_favicon($host)
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim($host, '.');
        if ($host === '' || !preg_match('/^[a-z0-9][a-z0-9\.-]*$/i', $host)) {
            return null;
        }

        $maxBytes = max(4096, (int) cfg_local('security.favicon_max_bytes', 262144));
        $timeout = max(1, min(10, (int) cfg_local('security.favicon_timeout_sec', 4)));
        $allowHttp = (bool) cfg_local('security.favicon_allow_http', false);
        $allowedHosts = cfg_local('security.favicon_allowed_hosts', []);
        if (!is_array($allowedHosts)) {
            $allowedHosts = [];
        }
        if (!server_dashboard_host_allowed($host, $allowedHosts)) {
            return null;
        }

        $cacheFile = server_dashboard_favicon_cache_file($host);
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            return $cacheFile;
        }

        $ips = server_dashboard_resolve_host_ips($host);
        if (!count($ips)) {
            return null;
        }
        foreach ($ips as $ip) {
            if (server_dashboard_is_private_or_reserved_ip($ip)) {
                return null;
            }
        }

        $srcs = ['https://' . $host . '/favicon.ico'];
        if ($allowHttp) {
            $srcs[] = 'http://' . $host . '/favicon.ico';
        }

        foreach ($srcs as $src) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'method' => 'GET',
                    'header' => "User-Agent: ServerDashboard-Favicon/1\r\nAccept: image/*,*/*;q=0.5\r\nConnection: close\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                ],
            ]);
            $fp = @fopen($src, 'rb', false, $ctx);
            if (!$fp) {
                continue;
            }
            $data = @stream_get_contents($fp, $maxBytes + 1);
            @fclose($fp);
            if (!is_string($data) || $data === '' || strlen($data) > $maxBytes) {
                continue;
            }
            if (@file_put_contents($cacheFile, $data) === false) {
                return null;
            }
            @chmod($cacheFile, 0644);
            return $cacheFile;
        }

        return null;
    }
}
