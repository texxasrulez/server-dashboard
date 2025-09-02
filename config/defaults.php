<?php
// config/defaults.php
// Authoritative default settings for the entire app.
// Keep this file in VCS. Do not edit in production; use config/local.json for overrides.
return [
  // Site & Branding
  'site' => [
    'name' => 'Domain',
    'base_url' => 'https://example.com',
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
];
