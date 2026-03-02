<?php
// config/defaults.php
// Authoritative default settings for the entire app.
// Keep this file in VCS. Do not edit in production; use config/local.json for overrides.
return [
  // Site & Branding
  'site' => [
    'name' => 'GeneMail',
    'base_url' => 'https://www.genesworld.net/web-admin/',
    'timezone' => 'UTC',
    'theme' => 'dark',
  ],
  // Feature toggles
  'features' => [
    'enable_server_tests' => true,
    'enable_bookmarks'    => true,
    'enable_diagnostics'  => true,
  ],
  // Integrations
  'integrations' => [
    'smtp' => [
      'host' => 'localhost',
      'port' => 587,
      'username' => '',
      'password' => '',
      'encryption' => 'STARTTLS'
    ],
    'mysql' => [
      'host' => '127.0.0.1',
      'port' => 3306,
      'database' => 'appdb',
      'username' => 'appuser',
      'password' => '',
    ],
    'redis' => [
      'host' => '127.0.0.1',
      'port' => 6379,
      'db'   => 0,
    ]
  ],

// Server Tests thresholds
'server_tests' => [
  'rate_limit_ms' => 1500,
  'service_targets' => [],
  'service_timeout_ms' => 1500,
  'tls_warn_days' => 21,
  'tls_fail_days' => 7,
  'inode_ok_free_pct' => 15,
  'inode_warn_free_pct' => 8,
  'disk_warn_free_pct' => 10,
  'disk_fail_free_pct' => 5,
  'load_warn_per_core' => 1.0,
  'load_fail_per_core' => 2.0,
  'updates_warn_count' => 1,
  'updates_fail_count' => 5,
  'audit_log' => false
],
  // Security
'security' => [
    'admin_emails' => ['admin@example.com'],
    'csrf_secret'  => null, // if null, a random secret will be generated into local.json
    'allowed_origins' => ['*'],
  
// Hardening defaults
'api_tokens' => [],
'api_rate_limit_ms' => 300,
'ip_allowlist' => [],
'trusted_proxies' => [],
'allow_web_bootstrap_admin' => false,
'favicon_allowed_hosts' => [],
'favicon_allow_http' => false,
'favicon_require_admin' => true,
'favicon_timeout_sec' => 4,
'favicon_max_bytes' => 262144,
'login_rate_limit' => [
  'enabled' => true,
  'max_attempts' => 5,
  'window_sec' => 900,
  'base_delay_sec' => 30,
  'max_delay_sec' => 900,
],
],
// Alerts
'alerts' => [
  'enabled' => false,
  'webhook_url' => '',
  'email' => '',
  'min_severity' => 'warn', // 'warn' or 'fail'
  'cron_token' => '',
  'quiet_hours' => '', // e.g. '22:00-07:00' (local time) or '' for none

// Cron default expectations
'cron_interval_min' => 10,
// Noise control defaults
'debounce_hours' => 6,
'daily_digest' => false,
'digest_hour' => '08:00',
  'mute_presets' => '30,60,120',
  'service_defaults' => [
    'latency_warn_ms' => 1500,
    'latency_fail_ms' => 5000,
  ],
],
// History
'history' => [
  'enabled' => true,
  'retain_days' => 90,
  'sample_limit' => 500,

// History default expectations
'append_interval_min' => 5,
],
  // UI Defaults
  'ui' => [
        'high_contrast' => false,
    'toast_position' => 'bottom-center',
    'items_per_page' => 25,
  ],

// Logs
'logs' => [
  'allow' => [],
  'tail_bytes' => 65536,
  'poll_ms' => 2000,
  'grep_max_lines' => 1000,
],

// Backups + log watcher helpers
'backups' => [
  'fs_root' => '/mnt/backupz',
  'fs_dirs' => "hestia\nmicro\nsnapshots",
  'exclude_dirs' => '',
  'suspend' => false,
  'disable_on_mount_fail' => false,
  'debug' => false,
  'log_dest' => '/var/log-export',
  'log_owner' => 'root:root',
  'log_mode' => '0640',
  'log_service_name' => 'log-watcher',
  'log_initial_copy' => true,
  'log_enable_now' => true,
],
];
