<?php

// config/schema.php — uses \App\Config::listThemes() for theme values
return [
  'site' => [
    '_label' => 'Site',
    'name' => ['type' => 'string','label' => 'Site Name','min' => 1,'max' => 80],
    'base_url' => ['type' => 'url','label' => 'Base URL','required' => true],
    'timezone' => ['type' => 'timezone','label' => 'Timezone','required' => true],
    'theme' => ['type' => 'enum','label' => 'Theme','values' => \App\Config::listThemes()],
    'backup_keep' => ['type' => 'int','label' => 'Backups — keep last (managed by UI)','min' => 5,'max' => 200] + ['hidden' => true],
  ],

  'features' => [
    '_label' => 'Features',
    'enable_server_tests' => ['type' => 'bool','label' => 'Server Tests'],
    'enable_bookmarks'    => ['type' => 'bool','label' => 'Bookmarks'],
    'enable_diagnostics'  => ['type' => 'bool','label' => 'Diagnostics'],
  ],

  'capabilities' => [
    '_label' => 'Capabilities',
    'panel' => ['type' => 'enum','label' => 'Panel hint','values' => ['auto','hestia','none']],
    'web' => ['type' => 'enum','label' => 'Web stack hint','values' => ['auto','nginx','apache','both','none']],
    'mta' => ['type' => 'enum','label' => 'MTA hint','values' => ['auto','exim','postfix','none']],
    'db' => ['type' => 'enum','label' => 'DB hint','values' => ['auto','mariadb','postgres','none']],
  ],

  'processes' => [
    '_label' => 'Processes',
    'default_limit' => ['type' => 'int','label' => 'Processes default limit','min' => 10,'max' => 200],
    'cache_ttl_sec' => ['type' => 'int','label' => 'Processes cache TTL (seconds)','min' => 1,'max' => 10],
  ],

  'mail' => [
    '_label' => 'Mail',
    'mail_transport' => ['type' => 'enum','label' => 'Transport','values' => ['phpmail','sendmail','smtp']],
    'mail_from'      => ['type' => 'email','label' => 'From Email'],
    'mail_replyto'   => ['type' => 'email','label' => 'Reply-To Email'],
    'sendmail_path'  => ['type' => 'string','label' => 'sendmail Path'],
    // SMTP sub-settings (used when transport=smtp). These mirror legacy IDs.
    'smtp_host'   => ['type' => 'string','label' => 'SMTP Host'],
    'smtp_port'   => ['type' => 'int','label' => 'SMTP Port','min' => 1,'max' => 65535],
    'smtp_secure' => ['type' => 'enum','label' => 'SMTP Security','values' => ['tls','ssl','none']],
    'smtp_user'   => ['type' => 'string','label' => 'SMTP User'],
    'smtp_pass'   => ['type' => 'secret','label' => 'SMTP Password'],
    // Optional: recipients for test/alerts UI
    'sec_email'   => ['type' => 'list','label' => 'Alert recipients (comma-separated)','item' => 'email'],
  ],

  'integrations' => [
    '_label' => 'Integrations',
    'smtp' => [
      '_label' => 'SMTP',
      'host' => ['type' => 'string','label' => 'Host','required' => true],
      'port' => ['type' => 'int','label' => 'Port','min' => 1,'max' => 65535],
      'username' => ['type' => 'string','label' => 'Username'],
      'password' => ['type' => 'secret','label' => 'Password'],
      'encryption' => ['type' => 'enum','label' => 'Encryption','values' => ['STARTTLS','SSL','NONE']],
    ],
    'mysql' => [
      '_label' => 'MySQL',
      'host' => ['type' => 'string','label' => 'Host','required' => true],
      'port' => ['type' => 'int','label' => 'Port','min' => 1,'max' => 65535],
      'database' => ['type' => 'string','label' => 'Database','required' => true],
      'username' => ['type' => 'string','label' => 'Username','required' => true],
      'password' => ['type' => 'secret','label' => 'Password'],
    ],
    'redis' => [
      '_label' => 'Redis',
      'host' => ['type' => 'string','label' => 'Host','required' => true],
      'port' => ['type' => 'int','label' => 'Port','min' => 1,'max' => 65535],
      'db' => ['type' => 'int','label' => 'DB Index','min' => 0,'max' => 64],
    ],
  ],

  'security' => [
    '_label' => 'Security',
    'admin_emails' => ['type' => 'list','label' => 'Admin Emails','item' => 'email'],
    'csrf_secret'  => ['type' => 'secret','label' => 'CSRF Secret'],
    'allowed_origins' => ['type' => 'list','label' => 'Allowed Origins','item' => 'string'],

    // API / IP Hardening
    'api_tokens'        => ['type' => 'list','label' => 'API tokens (Bearer)','item' => 'string'],
    'api_rate_limit_ms' => ['type' => 'int','label' => 'API rate limit per endpoint (ms)','min' => 0,'max' => 60000],
    'ip_allowlist'      => ['type' => 'list','label' => 'IP allowlist for this app (optional)','item' => 'string'],
    'trusted_proxies'   => ['type' => 'list','label' => 'Trusted reverse proxies (IP/CIDR)','item' => 'string'],
    'allow_web_bootstrap_admin' => ['type' => 'bool','label' => 'Allow first admin bootstrap via login page'],
    'favicon_allowed_hosts' => ['type' => 'list','label' => 'Favicon host allowlist (optional)','item' => 'string'],
    'favicon_allow_http' => ['type' => 'bool','label' => 'Allow HTTP for favicon fetches'],
    'favicon_require_admin' => ['type' => 'bool','label' => 'Require admin for favicon proxy'],
    'favicon_timeout_sec' => ['type' => 'int','label' => 'Favicon fetch timeout (sec)','min' => 1,'max' => 10],
    'favicon_max_bytes' => ['type' => 'int','label' => 'Favicon max bytes','min' => 4096,'max' => 1048576],
    'login_rate_limit' => [
      '_label' => 'Login rate limit',
      'enabled' => ['type' => 'bool','label' => 'Enable login rate limiting'],
      'max_attempts' => ['type' => 'int','label' => 'Attempts per window','min' => 1,'max' => 50],
      'window_sec' => ['type' => 'int','label' => 'Window seconds','min' => 30,'max' => 86400],
      'base_delay_sec' => ['type' => 'int','label' => 'Base delay seconds','min' => 1,'max' => 3600],
      'max_delay_sec' => ['type' => 'int','label' => 'Max delay seconds','min' => 5,'max' => 86400],
    ],
  ],

  'ui' => [
    '_label' => 'UI',
    'toast_position' => ['type' => 'enum','label' => 'Toast Position','values' => ['bottom-center','bottom-right','bottom-left','top-right','top-left','top-center','center']],
    'items_per_page' => ['type' => 'int','label' => 'Items/Page','min' => 5,'max' => 500],
    'header_navigation_mode' => ['type' => 'enum','label' => 'Header navigation','values' => ['buttons','dropdown'],'value_labels' => ['buttons' => 'Header buttons','dropdown' => 'Dropdown menu']],
    'high_contrast' => ['type' => 'bool','label' => 'High visibility mode (Experimental)'],
  ],

  'server_tests' => [
    '_label' => 'Server Tests',
    'rate_limit_ms' => ['type' => 'int','label' => 'UI rate limit (ms)','min' => 0,'max' => 10000],

    // Services
    'service_targets'   => ['type' => 'list','label' => 'Service targets — extra (auto uses enabled Services)','item' => 'string'],
    'service_timeout_ms' => ['type' => 'int','label' => 'Service connect timeout (ms)','min' => 100,'max' => 20000],

    // TLS
    'tls_warn_days' => ['type' => 'int','label' => 'TLS warn at (days)','min' => 1,'max' => 365],
    'tls_fail_days' => ['type' => 'int','label' => 'TLS fail at (days)','min' => 1,'max' => 365],

    // Inodes
    'inode_ok_free_pct'   => ['type' => 'int','label' => 'Inodes OK at ≥ (%)','min' => 1,'max' => 100],
    'inode_warn_free_pct' => ['type' => 'int','label' => 'Inodes WARN at ≥ (%)','min' => 0,'max' => 100],

    // Disk
    'disk_warn_free_pct' => ['type' => 'int','label' => 'Disk WARN at ≥ (%)','min' => 0,'max' => 100],
    'disk_fail_free_pct' => ['type' => 'int','label' => 'Disk FAIL at < (%)','min' => 0,'max' => 100],

    // Load (per core)
    'load_warn_per_core' => ['type' => 'int','label' => 'Load WARN per core (1m)','hint' => 'decimal ok, e.g. 1.0','min' => 0,'max' => 32, 'step' => 'any'],
    'load_fail_per_core' => ['type' => 'int','label' => 'Load FAIL per core (1m)','hint' => 'decimal ok, e.g. 2.0','min' => 0,'max' => 64, 'step' => 'any'],

    // Security updates
    'updates_warn_count' => ['type' => 'int','label' => 'Security updates WARN at ≥','min' => 0,'max' => 1000],
    'updates_fail_count' => ['type' => 'int','label' => 'Security updates FAIL at ≥','min' => 0,'max' => 1000],

    'audit_log' => ['type' => 'bool','label' => 'Log diagnostics runs'],
  ],

  'alerts' => [
    '_label' => 'Alerts',
    'enabled'     => ['type' => 'bool','label' => 'Enable alerts'],
    'webhook_url' => ['type' => 'url','label' => 'Webhook URL (optional)'],
    'email'       => ['type' => 'string','label' => 'Legacy alert email(s)','hidden' => true],
    'min_severity' => ['type' => 'enum','label' => 'Minimum severity to alert','values' => ['warn','fail']],

    // Cron expectations
    'cron_token'        => ['type' => 'string','label' => 'Cron token (required for HTTP endpoint)'],
    'quiet_hours'       => ['type' => 'string','label' => 'Quiet hours (HH:MM-HH:MM, optional)'],
    'cron_interval_min' => ['type' => 'int','label' => 'Expected alert cron interval (min)','min' => 1,'max' => 1440],

    // Noise control
    'debounce_hours' => ['type' => 'int','label' => 'Suppress repeats for (hours)','min' => 0,'max' => 168],
    'daily_digest'   => ['type' => 'bool','label' => 'Send daily summary (if issues persist)'],
    'digest_hour'    => ['type' => 'string','label' => 'Digest send time (HH:MM)','hint' => '24h time, e.g. 08:00'],
    'mute_presets'   => ['type' => 'string','label' => 'Mute presets (minutes, comma-separated)','help' => 'Show quick-silence buttons with these durations. Default: 30,60,120'],
    'service_defaults' => [
      '_label' => 'Service defaults',
      'latency_warn_ms' => ['type' => 'int','label' => 'Warn if latency > (ms)','min' => 0,'max' => 60000],
      'latency_fail_ms' => ['type' => 'int','label' => 'Fail if latency > (ms)','min' => 0,'max' => 60000],
    ],
  ],

  'cron' => [
    '_label' => 'Cron',
    'ui_stub' => ['type' => 'string','label' => '(managed by UI)','hint' => 'Use controls below'],
    // Hidden storage for the manager UI (JSON string)
    'jobs'   => ['type' => 'string','label' => '(managed by UI)','hidden' => true],
  ],

  'history' => [
    '_label' => 'History',
    'enabled' => ['type' => 'bool','label' => 'Enable history logging'],
    'retain_days' => ['type' => 'int','label' => 'Retention (days)','min' => 1,'max' => 3650],
    'sample_limit' => ['type' => 'int','label' => 'Max samples to return','min' => 50,'max' => 5000],

    // History expectations
    'append_interval_min' => ['type' => 'int','label' => 'Expected history append every (min)','min' => 1,'max' => 1440],
  ],

  'speedtest' => [
    '_label' => 'Speedtest Settings',
    'enabled' => ['type' => 'bool','label' => 'Enable scheduled speedtests','help' => 'Scheduled speedtests run on the dashboard host, not in the browser.'],
    'interval_minutes' => ['type' => 'int','label' => 'Interval (minutes)','min' => 1,'max' => 10080,'help' => 'Set any interval from 1 minute up to 7 days. Speedtests consume bandwidth and can skew graphs if run too frequently.'],
    'timeout_seconds' => ['type' => 'int','label' => 'Timeout (seconds)','min' => 5,'max' => 300],
    'preferred_backend' => ['type' => 'enum','label' => 'Preferred backend','values' => ['auto','ookla','librespeed'],'value_labels' => ['auto' => 'Auto-detect','ookla' => 'Ookla speedtest','librespeed' => 'librespeed-cli']],
    'preferred_server_ookla' => ['type' => 'string','label' => 'Preferred server ID for Speedtest','max' => 200,'help' => 'Optional. Use a numeric server ID for Ookla speedtest or speedtest-cli.'],
    'preferred_server_librespeed' => ['type' => 'string','label' => 'Preferred server host for Librespeed','max' => 200,'help' => 'Optional. Use a safe host or server identifier for librespeed-cli.'],
    'retention_days' => ['type' => 'int','label' => 'Retention days','min' => 1,'max' => 3650],
    'max_history_entries' => ['type' => 'int','label' => 'Max history entries','min' => 10,'max' => 50000],
    'log_failed_tests' => ['type' => 'bool','label' => 'Log failed tests'],
    'randomize_schedule_window' => ['type' => 'bool','label' => 'Randomize schedule window','help' => 'When enabled, the actual next run is delayed by up to the randomize window in addition to the base interval.'],
    'randomize_window_minutes' => ['type' => 'int','label' => 'Randomize window minutes','min' => 0,'max' => 240,'help' => 'Extra delay added on top of the base interval when randomization is enabled.'],
    'quiet_hours' => [
      '_label' => 'Quiet hours',
      'enabled' => ['type' => 'bool','label' => 'Enable quiet hours'],
      'start_hour' => ['type' => 'int','label' => 'Start hour','min' => 0,'max' => 23],
      'end_hour' => ['type' => 'int','label' => 'End hour','min' => 0,'max' => 23],
    ],
  ],

  // Log Viewer
  'logs' => [
    '_label' => 'Logs',
    'allow' => ['type' => 'list','label' => 'Allowlisted logs (Label|/path/to/file)','item' => 'string'],
    'tail_bytes' => ['type' => 'int','label' => 'Tail bytes (per request)','min' => 1024,'max' => 10485760],
    'poll_ms' => ['type' => 'int','label' => 'Follow refresh (ms)','min' => 250,'max' => 10000],
    'grep_max_lines' => ['type' => 'int','label' => 'Max lines returned','min' => 50,'max' => 10000],
  ],

  'backups' => [
    '_label' => 'Backups',
    'fs_root' => ['type' => 'string','label' => 'Filesystem root path','placeholder' => '/mnt/backupz','help' => 'Root directory that holds your backup folders.','required' => true],
    'script_path' => ['type' => 'string','label' => 'Backup Script path','placeholder' => '/path/to/scripts','help' => 'Absolute directory path containing make-snapshots.sh, make-micro-backups.sh, backup_health_check.sh, backup_integrity_watch.sh, prune_snapshots.sh, prune-micro-backups.sh, and prune_hestia_backups.sh.'],
    'backupctl' => ['type' => 'string','label' => 'backupctl path','placeholder' => '/path/to/backupctl','help' => 'Absolute path to backupctl wrapper binary/script.'],
    'hestia_cmd' => ['type' => 'string','label' => 'Hestia backup command path','placeholder' => '/usr/local/hestia/bin/v-backup-user','help' => 'Absolute path to Hestia v-backup-user command.'],
    'hestia_user' => ['type' => 'string','label' => 'Hestia backup user','placeholder' => 'user','help' => 'Default Hestia user for backup actions and generated commands.'],
    'pipeline_script' => ['type' => 'string','label' => 'Nightly pipeline script path','placeholder' => '/usr/local/bin/backup-nightly.sh','help' => 'Where to install/run the generated nightly script.'],
    'log_file' => ['type' => 'string','label' => 'Nightly log file path','placeholder' => '/var/log/backup-nightly.log','help' => 'Log file path for generated nightly jobs.'],
    'cron_time' => ['type' => 'string','label' => 'Nightly cron time','placeholder' => '02:00','help' => 'HH:MM local time used in generated cron/systemd timer examples.'],
    'service_name' => ['type' => 'string','label' => 'Systemd service name','placeholder' => 'backup-nightly','help' => 'Base name for generated systemd unit/timer.'],
    'system_user' => ['type' => 'string','label' => 'System user','placeholder' => 'root','help' => 'User account to run generated cron/service commands.'],
    'include_health' => ['type' => 'bool','label' => 'Include health check stage','help' => 'Include backup health check in generated nightly pipeline.'],
    'include_integrity' => ['type' => 'bool','label' => 'Include integrity check stage','help' => 'Include backup integrity check in generated nightly pipeline.'],
    'include_prune' => ['type' => 'bool','label' => 'Include prune stage','help' => 'Include prune scripts in generated nightly pipeline.'],
    'hestia_source_dir' => ['type' => 'string','label' => 'Hestia backup source path','placeholder' => '/backup','help' => 'Actual directory where Hestia writes tarballs (usually /backup).'],
    'hestia_bind_source' => ['type' => 'string','label' => 'Hestia bind source (optional)','placeholder' => '/mnt/backupz/hestia','help' => 'Optional source directory to bind-mount into Hestia target path. Leave empty to disable bind mount guidance.'],
    'hestia_bind_target' => ['type' => 'string','label' => 'Hestia bind target path','placeholder' => '/backup','help' => 'Target path Hestia writes to. Usually /backup.'],
    'hestia_bind_options' => ['type' => 'string','label' => 'Hestia bind mount options','placeholder' => 'bind,nofail','help' => 'Options segment for fstab bind entry.'],
    'fs_dirs' => ['type' => 'text','label' => 'Subdirectories','placeholder' => "hestia\nmicro\nsnapshots",'help' => 'Comma or newline-separated list of directories under the root to monitor.'],
    'exclude_dirs' => ['type' => 'text','label' => 'Exclude paths','placeholder' => "/backup\n/mnt/backupz",'help' => 'Comma or newline-separated paths/patterns for backup scripts that honor BACKUP_EXCLUDES.'],
    'suspend' => ['type' => 'bool','label' => 'Suspend backups','help' => 'When enabled, backup actions and generated scripts will skip running backups.'],
    'require_dedicated_mount' => ['type' => 'bool','label' => 'Require dedicated mount','help' => 'Legacy compatibility key. When enabled, backup scripts require the backup mount to be present.'],
    'disable_on_mount_fail' => ['type' => 'bool','label' => 'Disable backups if mount fails','help' => 'When enabled, backup actions and generated scripts stop if the backup mount is missing.'],
    'debug' => ['type' => 'bool','label' => 'Debug backup scripts','help' => 'When enabled, backup scripts emit resolved BACKUP_ROOT and BACKUP_EXCLUDES for troubleshooting.'],
    'log_dest' => ['type' => 'string','label' => 'Log mirror destination','placeholder' => '/var/log-export','help' => 'Destination path used by the log watcher scripts.','required' => true],
    'log_owner' => ['type' => 'string','label' => 'Log files owner (user:group)','placeholder' => 'root:root','required' => true],
    'log_mode' => ['type' => 'string','label' => 'Log files mode','placeholder' => '0640','required' => true],
    'log_service_name' => ['type' => 'string','label' => 'Service name','placeholder' => 'log-watcher','help' => 'Name for the systemd unit created by install.sh'],
    'log_initial_copy' => ['type' => 'bool','label' => 'Run initial copy on install','default' => true],
    'log_enable_now' => ['type' => 'bool','label' => 'Enable + start service after install','default' => true],
  ],

  'email' => [
    '_label' => 'Email',
    'enabled' => ['type' => 'bool','label' => 'Enable Email polling'],
    'indicator_mode' => ['type' => 'enum','label' => 'Indicator Mode','values' => ['single','multiple'],'default' => 'single'],

    // Quick-add inputs (user-friendly)
    'new_email'        => ['type' => 'email','label' => 'Email'],
    'new_password'     => ['type' => 'secret','label' => 'Password'],

    // Internal storage: JSON string managed by the JS (hidden from UI)
    'accounts'         => ['type' => 'string','label' => '(managed by UI)','hidden' => true],

    // OAuth fields here …

  'notification_link'        => ['type' => 'string','label' => 'Notification link','placeholder' => 'https://mail.example.com','help' => 'If set, email notification icons link here. Leave blank to use provider defaults.'],
  'oauth_google_client_id'     => ['type' => 'string','label' => 'Google Client ID'],
  'oauth_google_client_secret' => ['type' => 'secret','label' => 'Google Client Secret'],
  'oauth_ms_client_id'         => ['type' => 'string','label' => 'Microsoft Client ID'],
  'oauth_ms_client_secret'     => ['type' => 'secret','label' => 'Microsoft Client Secret'],
  'oauth_ms_tenant'            => ['type' => 'string','label' => 'Microsoft Tenant (common or your-tenant-id)','default' => 'common'],
  'oauth_yahoo_client_id'      => ['type' => 'string','label' => 'Yahoo Client ID'],
  'oauth_yahoo_client_secret'  => ['type' => 'secret','label' => 'Yahoo Client Secret']
]
];
