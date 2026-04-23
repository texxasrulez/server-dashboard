<?php

// includes/logger.php — tiny JSON logger
declare(strict_types=1);
require_once __DIR__ . '/paths.php';

const LOG_KIND_APP    = 'app';    // data/logs
const LOG_KIND_SYSTEM = 'system'; // state/logs

if (!function_exists('dashboard_log_current_user')) {
    function dashboard_log_current_user(): string
    {
        if (function_exists('current_user')) {
            $user = current_user();
            if (is_array($user)) {
                foreach (['username', 'name', 'email'] as $key) {
                    $value = trim((string)($user[$key] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser)) {
            foreach (['username', 'name', 'email'] as $key) {
                $value = trim((string)($sessionUser[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}

if (!function_exists('dashboard_log_current_ip')) {
    function dashboard_log_current_ip(): string
    {
        if (function_exists('request_client_ip')) {
            $ip = trim((string)request_client_ip());
            if ($ip !== '') {
                return $ip;
            }
        }

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return $ip;
    }
}

if (!function_exists('dashboard_log_encode_value')) {
    function dashboard_log_encode_value($value): string
    {
        $encoded = json_encode((string)$value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '""';
    }
}

if (!function_exists('dashboard_log_line')) {
    function dashboard_log_line(string $category, string $message, array $ctx = []): string
    {
        $time = trim((string)($ctx['time'] ?? date('c')));
        $user = trim((string)($ctx['user'] ?? dashboard_log_current_user()));
        $ip = trim((string)($ctx['ip'] ?? dashboard_log_current_ip()));
        $pid = $ctx['pid'] ?? (function_exists('getmypid') ? getmypid() : null);

        unset($ctx['time'], $ctx['user'], $ctx['ip'], $ctx['pid']);

        $parts = [
            'Time=' . dashboard_log_encode_value($time),
            'Category=' . dashboard_log_encode_value($category),
            'Message=' . dashboard_log_encode_value($message),
        ];
        if ($user !== '') {
            $parts[] = 'User=' . dashboard_log_encode_value($user);
        }
        if ($ip !== '') {
            $parts[] = 'IP=' . dashboard_log_encode_value($ip);
        }
        if (!empty($ctx)) {
            $context = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($context) && $context !== '') {
                $parts[] = 'Context=' . $context;
            }
        }
        if ($pid !== null && $pid !== '') {
            $parts[] = 'PID=' . (int)$pid;
        }

        return implode(' ', $parts) . PHP_EOL;
    }
}

if (!function_exists('dashboard_log_append')) {
    function dashboard_log_append(string $file, string $category, string $message, array $ctx = []): void
    {
        $dir = dirname($file);
        try {
            ensure_dir($dir);
        } catch (Throwable $e) { /* swallow */
        }
        @file_put_contents($file, dashboard_log_line($category, $message, $ctx), FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('app_log')) {
    function app_log(string $channel, string $message, array $ctx = [], string $kind = LOG_KIND_APP): void
    {
        $root = ($kind === LOG_KIND_SYSTEM) ? STATE_DIR : DATA_DIR;
        $dir  = $root . '/logs';
        try {
            ensure_dir($dir);
        } catch (Throwable $e) { /* swallow */
        }
        $ctx['channel'] = $channel;
        dashboard_log_append($dir . '/' . $channel . '.log', $channel, $message, $ctx);
    }
}
