<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../api/_state_path.php';
require_once __DIR__ . '/../api/_alerts_store.php';
require_once __DIR__ . '/../lib/AuditLog.php';

if (!function_exists('dashboard_is_cli')) {
    function dashboard_is_cli(): bool
    {
        return PHP_SAPI === 'cli';
    }
}

if (!function_exists('dashboard_cli_bool_arg')) {
    function dashboard_cli_bool_arg(array $args, string $name): bool
    {
        return isset($args[$name]) && (string)$args[$name] === '1';
    }
}

if (!function_exists('dashboard_cli_parse_args')) {
    function dashboard_cli_parse_args(array $argv): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg) || strpos($arg, '--') !== 0) {
                continue;
            }
            $eq = strpos($arg, '=');
            if ($eq === false) {
                $out[substr($arg, 2)] = '1';
                continue;
            }
            $out[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
        return $out;
    }
}

if (!function_exists('dashboard_cron_mark_run')) {
    function dashboard_cron_mark_run(string $what): array
    {
        $what = strtolower(trim($what));
        if (!in_array($what, ['alerts', 'history'], true)) {
            return ['ok' => false, 'error' => 'invalid what', 'status' => 400];
        }

        $map = ['alerts' => 'cron_last_alert.txt', 'history' => 'cron_last_history.txt'];
        $primary = dashboard_state_path($map[$what]);
        $legacyState = dirname(__DIR__) . '/state';
        @mkdir($legacyState, 0775, true);
        $legacy = $legacyState . '/' . $map[$what];
        $ts = time();
        $ok1 = @file_put_contents($primary, (string)$ts) !== false;
        $ok2 = @file_put_contents($legacy, (string)$ts) !== false;

        $ok = $ok1 || $ok2;
        return ['ok' => $ok, 'what' => $what, 'ts' => $ts, 'status' => $ok ? 200 : 500];
    }
}

if (!function_exists('dashboard_slugify_job')) {
    function dashboard_slugify_job(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim((string)$value, '-') ?: ('job_' . substr(sha1($value . microtime(true)), 0, 8));
    }
}

if (!function_exists('dashboard_resolve_heartbeat_path')) {
    function dashboard_resolve_heartbeat_path(string $root, string $heartbeatDir, string $id, string $custom): string
    {
        $paths = $custom !== '' ? [$custom] : [$heartbeatDir . '/' . $id . '.txt'];
        foreach ($paths as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate)) {
                $candidate = $root . '/' . ltrim($candidate, '/');
            }
            $dir = dirname($candidate);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $realDir = realpath($dir);
            if ($realDir === false) {
                continue;
            }
            if (strpos($realDir, $root) !== 0) {
                continue;
            }
            return rtrim($realDir, '/') . '/' . basename($candidate);
        }

        return $heartbeatDir . '/' . $id . '.txt';
    }
}

if (!function_exists('dashboard_cron_heartbeat_run')) {
    function dashboard_cron_heartbeat_run(string $id, string $heartbeatParam = '', ?int $tsParam = null): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'error' => 'Missing id parameter', 'status' => 400];
        }

        $root = realpath(dirname(__DIR__));
        $heartbeatDir = dirname(dashboard_state_path('heartbeats/.keep'));
        @mkdir($heartbeatDir, 0775, true);
        $slug = dashboard_slugify_job($id);
        $path = dashboard_resolve_heartbeat_path((string)$root, $heartbeatDir, $slug, trim($heartbeatParam));
        $ts = ($tsParam !== null && $tsParam > 0) ? $tsParam : time();

        if (@file_put_contents($path, $ts . PHP_EOL, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Failed to write heartbeat', 'status' => 500];
        }
        @chmod($path, 0640);

        return ['ok' => true, 'id' => $slug, 'ts' => $ts, 'heartbeat' => $path, 'status' => 200];
    }
}

if (!function_exists('dashboard_alerts_eval_is_authorized')) {
    function dashboard_alerts_eval_is_authorized(): bool
    {
        if (dashboard_is_cli()) {
            return true;
        }
        if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) {
            return true;
        }
        return cron_token_is_valid(cron_request_token());
    }
}

if (!function_exists('dashboard_alerts_noise_cfg')) {
    function dashboard_alerts_noise_cfg(): array
    {
        $file = dirname(__DIR__) . '/config/local.json';
        $json = @json_decode(@file_get_contents($file), true);
        $alerts = isset($json['alerts']) ? $json['alerts'] : [];
        return [
            'debounce_hours' => intval($alerts['debounce_hours'] ?? 0),
            'daily_digest' => !empty($alerts['daily_digest']),
            'digest_hour' => (string)($alerts['digest_hour'] ?? '08:00'),
            'email' => (string)($alerts['email'] ?? ''),
        ];
    }
}

if (!function_exists('dashboard_alerts_noise_debounce_should_suppress')) {
    function dashboard_alerts_noise_debounce_should_suppress(string $key, int $hours): bool
    {
        if ($hours <= 0) {
            return false;
        }
        $file = dashboard_state_path('alerts_debounce.json');
        $map = @json_decode(@file_get_contents($file), true);
        if (!is_array($map)) {
            $map = [];
        }
        $last = isset($map[$key]) ? intval($map[$key]) : 0;
        return $last > 0 && (time() - $last) < ($hours * 3600);
    }
}

if (!function_exists('dashboard_alerts_noise_debounce_mark')) {
    function dashboard_alerts_noise_debounce_mark(string $key): void
    {
        $file = dashboard_state_path('alerts_debounce.json');
        $map = @json_decode(@file_get_contents($file), true);
        if (!is_array($map)) {
            $map = [];
        }
        $map[$key] = time();
        @file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('dashboard_alerts_noise_digest_append')) {
    function dashboard_alerts_noise_digest_append(array $items): void
    {
        $file = dirname(__DIR__) . '/state/alerts_digest_queue.jsonl';
        $fh = @fopen($file, 'ab');
        if ($fh) {
            $row = ['ts' => time(), 'items' => array_values($items)];
            @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
            @fclose($fh);
        }
    }
}

if (!function_exists('dashboard_alerts_noise_maybe_send_daily_digest')) {
    function dashboard_alerts_noise_maybe_send_daily_digest(): void
    {
        $cfg = dashboard_alerts_noise_cfg();
        if (empty($cfg['daily_digest']) || $cfg['email'] === '') {
            return;
        }

        $stateDir = dirname(__DIR__) . '/state';
        @mkdir($stateDir, 0775, true);
        $lastFile = $stateDir . '/alerts_last_digest.txt';
        $last = intval(@file_get_contents($lastFile));
        $hhmm = $cfg['digest_hour'];
        if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $match)) {
            $match = [0, '08', '00'];
        }
        $now = time();
        $dt = getdate($now);
        $target = mktime(intval($match[1]), intval($match[2]), 0, $dt['mon'], $dt['mday'], $dt['year']);
        if ($now < $target || $last >= $target) {
            return;
        }

        $queueFile = $stateDir . '/alerts_digest_queue.jsonl';
        if (!is_file($queueFile)) {
            return;
        }

        $since = $now - 24 * 3600;
        $agg = [];
        $fh = @fopen($queueFile, 'rb');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $row = @json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $ts = intval($row['ts'] ?? 0);
                if ($ts < $since) {
                    continue;
                }
                $items = isset($row['items']) && is_array($row['items']) ? $row['items'] : [];
                foreach ($items as $item) {
                    $key = strtolower(($item['name'] ?? 'unknown') . '|' . ($item['status'] ?? ''));
                    if (!isset($agg[$key])) {
                        $agg[$key] = ['name' => $item['name'] ?? 'unknown', 'status' => $item['status'] ?? '', 'count' => 0];
                    }
                    $agg[$key]['count']++;
                }
            }
            fclose($fh);
        }
        if (!count($agg)) {
            @file_put_contents($lastFile, (string)time());
            return;
        }

        $lines = ['Daily alert digest for last 24h', 'Time: ' . date('c'), ''];
        foreach ($agg as $row) {
            $lines[] = sprintf('- %s - %s (%d hits)', $row['name'], strtoupper($row['status']), $row['count']);
        }
        $res = Mailer::send($cfg['email'], '[ServerDiag] Daily alert digest', implode("\n", $lines), []);
        if (!empty($res['ok'])) {
            @file_put_contents($lastFile, (string)time());
            return;
        }

        dashboard_log_append($stateDir . '/mail_failures.log', 'alerts_mail', 'daily digest send failed', [
            'error' => (string)($res['error'] ?? 'fail'),
            'recipient' => (string)$cfg['email'],
        ]);
    }
}

if (!function_exists('dashboard_mark_cron')) {
    function dashboard_mark_cron(string $which): void
    {
        $result = dashboard_cron_mark_run($which);
        unset($result);
    }
}

if (!function_exists('dashboard_service_probe_microtime_ms')) {
    function dashboard_service_probe_microtime_ms(): int
    {
        return (int)floor(microtime(true) * 1000);
    }
}

if (!function_exists('dashboard_service_probe_check_tcp')) {
    function dashboard_service_probe_check_tcp(string $host, int $port, int $timeout = 2): array
    {
        $start = dashboard_service_probe_microtime_ms();
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $lat = dashboard_service_probe_microtime_ms() - $start;
        if (!$fp) {
            return ['status' => 'down', 'latency_ms' => $lat, 'error' => "$errno $errstr"];
        }
        fclose($fp);
        return ['status' => 'up', 'latency_ms' => $lat];
    }
}

if (!function_exists('dashboard_service_probe_check_http')) {
    function dashboard_service_probe_check_http(string $host, int $port, string $path = '/', int $timeout = 3, bool $tls = false): array
    {
        $scheme = $tls ? 'https' : 'http';
        $url = $scheme . '://' . $host . ':' . $port . ($path ?: '/');
        $start = dashboard_service_probe_microtime_ms();
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true, 'header' => "Connection: close\r\nUser-Agent: Dashboard\r\n"]]);
        @file_get_contents($url, false, $ctx);
        $lat = dashboard_service_probe_microtime_ms() - $start;
        $code = null;
        if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $match)) {
            $code = (int)$match[1];
        }
        return ['status' => ($code && $code < 500) ? 'up' : 'down', 'latency_ms' => $lat, 'http_code' => $code];
    }
}

if (!function_exists('dashboard_service_probe_check_ping')) {
    function dashboard_service_probe_check_ping(string $host, int $timeout = 1): array
    {
        $cmd = sprintf('ping -c 1 -W %d %s 2>/dev/null', $timeout, escapeshellarg($host));
        $start = dashboard_service_probe_microtime_ms();
        @exec($cmd, $out, $code);
        $lat = dashboard_service_probe_microtime_ms() - $start;
        return ['status' => $code === 0 ? 'up' : 'down', 'latency_ms' => $lat];
    }
}

if (!function_exists('dashboard_services_probe_all_run')) {
    function dashboard_services_probe_all_run(): array
    {
        $dataPath = dirname(__DIR__) . '/data/services.json';
        $statePath = dashboard_state_path('services_status.json');
        @mkdir(dirname($statePath), 0775, true);
        $store = json_decode(@file_get_contents($dataPath), true) ?: ['items' => []];
        $items = $store['items'] ?? [];
        $results = [];

        foreach ($items as $item) {
            if (empty($item['enabled'])) {
                continue;
            }
            $check = $item['check'] ?? 'tcp';
            $timeout = max(1, (int)ceil(($item['timeout_ms'] ?? 800) / 1000));
            if ($check === 'http') {
                $row = dashboard_service_probe_check_http($item['host'] ?? '', (int)($item['port'] ?? 0), $item['path'] ?? '/', $timeout);
            } elseif ($check === 'ping') {
                $row = dashboard_service_probe_check_ping($item['host'] ?? '', $timeout);
            } else {
                $row = dashboard_service_probe_check_tcp($item['host'] ?? '', (int)($item['port'] ?? 0), $timeout);
            }
            if (($row['status'] ?? '') === 'up' && ($row['latency_ms'] ?? 0) > ($item['timeout_ms'] ?? 800)) {
                $row['status'] = 'warn';
            }
            $row['id'] = $item['id'];
            $row['ts'] = time();
            $results[] = $row;
        }

        try {
            write_json_atomic($statePath, ['results' => $results, 'ts' => time()]);
        } catch (Throwable $e) {
        }

        $historyFile = dashboard_state_path('services_status_history.jsonl');
        foreach ($results as $row) {
            if (!isset($row['ts'])) {
                $row['ts'] = time();
            }
            try {
                append_line_locked($historyFile, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
            } catch (Throwable $e) {
                break;
            }
        }

        return ['results' => $results, 'ts' => time()];
    }
}

if (!function_exists('dashboard_alerts_cmp')) {
    function dashboard_alerts_cmp(string $op, $a, $b): bool
    {
        switch ($op) {
            case '>':
                return $a > $b;
            case '>=':
                return $a >= $b;
            case '==':
                return $a == $b;
            case '<=':
                return $a <= $b;
            case '<':
                return $a < $b;
            case '!=':
                return $a != $b;
            default:
                return false;
        }
    }
}

if (!function_exists('dashboard_alerts_eval_run')) {
    function dashboard_alerts_eval_run(bool $probe = false, bool $dry = false): array
    {
        if ($probe) {
            dashboard_services_probe_all_run();
        }

        $dataDir = dirname(__DIR__) . '/data';
        $stateDir = dirname(__DIR__) . '/state';
        @mkdir($dataDir, 0775, true);
        @mkdir($stateDir, 0775, true);

        $alertsPath = $dataDir . '/alerts.json';
        if (!file_exists($alertsPath)) {
            file_put_contents($alertsPath, json_encode(['items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $alertsStore = alerts_store_read($alertsPath);
        $alerts = $alertsStore['payload'];
        $rules = $alertsStore['items'];

        $statusPath = $stateDir . '/services_status.json';
        $statusPayload = json_decode(@file_get_contents($statusPath), true) ?: [];
        $svcLatest = isset($statusPayload['items']) && is_array($statusPayload['items']) ? $statusPayload['items'] : [];
        if (!$svcLatest && isset($statusPayload['results']) && is_array($statusPayload['results'])) {
            $svcLatest = $statusPayload['results'];
        }

        $svcMap = [];
        foreach ($svcLatest as $item) {
            if (!empty($item['id'])) {
                $svcMap[$item['id']] = $item;
            }
        }

        $now = time();
        $fired = [];

        foreach ($rules as &$rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $silencedUntil = isset($rule['silenced_until']) ? intval($rule['silenced_until']) : 0;
            if ($silencedUntil > 1000000000000) {
                $silencedUntil = (int)round($silencedUntil / 1000);
            }
            if ($silencedUntil > 0) {
                if ($silencedUntil > $now) {
                    continue;
                }
                unset($rule['silenced_until']);
            }

            $serviceId = $rule['service_id'] ?? '';
            $metric = $rule['metric'] ?? 'status';
            $op = $rule['op'] ?? '>';
            $threshold = $rule['threshold'] ?? 1;
            $consecutive = max(1, intval($rule['consecutive'] ?? 3));
            $cooldown = max(1, intval($rule['cooldown_min'] ?? 5)) * 60;

            $service = $svcMap[$serviceId] ?? null;
            if (!$service) {
                continue;
            }

            $value = null;
            if ($metric === 'status') {
                $value = !empty($service['ok']) ? 1 : 0;
            } elseif ($metric === 'latency_ms') {
                $value = isset($service['latency_ms']) ? floatval($service['latency_ms']) : null;
            } elseif ($metric === 'http_code') {
                $value = isset($service['http_code']) ? intval($service['http_code']) : null;
            } elseif ($metric === 'packet_loss_pct') {
                $value = isset($service['packet_loss_pct']) ? floatval($service['packet_loss_pct']) : null;
            }
            if ($value === null) {
                continue;
            }

            $pass = dashboard_alerts_cmp($op, $value, $threshold);
            if (!isset($rule['consecutive_count'])) {
                $rule['consecutive_count'] = 0;
            }
            $rule['consecutive_count'] = $pass ? ($rule['consecutive_count'] + 1) : 0;

            $last = isset($rule['last_triggered']) ? intval($rule['last_triggered']) : 0;
            $coolOk = ($now - $last) >= $cooldown;

            if ($rule['consecutive_count'] < $consecutive || !$coolOk || !$pass) {
                continue;
            }

            $event = [
                'ts' => $now,
                'alert_id' => $rule['id'] ?? '',
                'alert_name' => $rule['name'] ?? '',
                'service_id' => $serviceId,
                'service_name' => $rule['service_name'] ?? '',
                'metric' => $metric,
                'op' => $op,
                'threshold' => $threshold,
                'value' => $value,
                'severity' => $rule['severity'] ?? 'warn',
            ];
            $fired[] = $event;

            $rule['last_triggered'] = $now;
            $rule['times_triggered'] = intval($rule['times_triggered'] ?? 0) + 1;
            $rule['consecutive_count'] = 0;

            if ($dry) {
                continue;
            }

            $email = $rule['notify']['email'] ?? '';
            $noise = dashboard_alerts_noise_cfg();
            $debounceHours = intval($noise['debounce_hours']);
            $key = md5('eval:' . ($rule['id'] ?? '') . '|' . ($rule['name'] ?? '') . '|' . $serviceId . '|' . $metric . '|' . $op . '|' . $threshold);
            $suppress = dashboard_alerts_noise_debounce_should_suppress($key, $debounceHours);

            if ($email) {
                $subject = '[Alert] ' . (($rule['name'] ?? '') ?: 'Rule fired');
                $lines = [];
                $lines[] = 'Alert: ' . ($rule['name'] ?? '');
                $lines[] = 'Service: ' . (($rule['service_name'] ?? '') ?: $serviceId);
                $lines[] = 'Metric: ' . $metric . ' ' . $op . ' ' . $threshold;
                $lines[] = 'Value: ' . $value;
                $lines[] = 'Severity: ' . ($rule['severity'] ?? 'warn');
                $lines[] = 'Time: ' . date('c', $now);
                $body = implode("\n", $lines) . "\n\nJSON:\n" . json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $res = $suppress ? ['ok' => false, 'error' => 'suppressed'] : Mailer::send($email, $subject, $body, []);
                if (!$res['ok']) {
                    dashboard_log_append($stateDir . '/mail_failures.log', 'alerts_mail', 'alert delivery failed', [
                        'recipient' => (string)$email,
                        'subject' => (string)$subject,
                        'error' => (string)($res['error'] ?? 'fail'),
                        'rule' => (string)($rule['name'] ?? ''),
                        'service' => (string)(($rule['service_name'] ?? '') ?: $serviceId),
                    ]);
                }
            }
            if (!$suppress) {
                dashboard_alerts_noise_debounce_mark($key);
            }
            dashboard_alerts_noise_digest_append([$event]);
            $webhook = $rule['notify']['webhook_url'] ?? '';
            if ($webhook && !$suppress) {
                $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($event, JSON_UNESCAPED_SLASHES), 'timeout' => 5]]);
                @file_get_contents($webhook, false, $ctx);
            }
        }
        unset($rule);

        if (!$dry) {
            alerts_store_write($alertsPath, $alerts, $rules);
        }

        if (!$dry && count($fired)) {
            $eventsFile = dashboard_state_path('alerts_events.jsonl');
            $fh = @fopen($eventsFile, 'ab');
            if ($fh) {
                foreach ($fired as $event) {
                    fwrite($fh, json_encode($event, JSON_UNESCAPED_SLASHES) . "\n");
                }
                fclose($fh);
            }
        }

        dashboard_alerts_noise_maybe_send_daily_digest();
        dashboard_mark_cron('alerts');
        if ($probe) {
            dashboard_mark_cron('history');
        }

        return ['ok' => true, 'now' => $now, 'fired_count' => count($fired), 'fired' => $fired, 'status' => 200];
    }
}
