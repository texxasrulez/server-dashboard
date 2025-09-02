<?php
// config/schema.php — uses \App\Config::listThemes() for theme values
return [
  'site' => [
    '_label' => 'Site',
    'name' => ['type'=>'string','label'=>'Site Name','min'=>1,'max'=>80],
    'base_url' => ['type'=>'url','label'=>'Base URL','required'=>true],
    'timezone' => ['type'=>'timezone','label'=>'Timezone','required'=>true],
    'theme' => ['type'=>'enum','label'=>'Theme','values'=>\App\Config::listThemes()],
    'backup_keep' => ['type'=>'int','label'=>'Backups — keep last (managed by UI)','min'=>5,'max'=>200] + ['hidden' => true],
  ],

  'features' => [
    '_label' => 'Features',
    'enable_server_tests' => ['type'=>'bool','label'=>'Server Tests'],
    'enable_bookmarks'    => ['type'=>'bool','label'=>'Bookmarks'],
    'enable_diagnostics'  => ['type'=>'bool','label'=>'Diagnostics'],
  ],

  'mail' => [
    '_label' => 'Mail',
    'mail_transport' => ['type'=>'enum','label'=>'Transport','values'=>['phpmail','sendmail','smtp']],
    'mail_from'      => ['type'=>'email','label'=>'From Email'],
    'mail_replyto'   => ['type'=>'email','label'=>'Reply-To Email'],
    'sendmail_path'  => ['type'=>'string','label'=>'sendmail Path'],
    // SMTP sub-settings (used when transport=smtp). These mirror legacy IDs.
    'smtp_host'   => ['type'=>'string','label'=>'SMTP Host'],
    'smtp_port'   => ['type'=>'int','label'=>'SMTP Port','min'=>1,'max'=>65535],
    'smtp_secure' => ['type'=>'enum','label'=>'SMTP Security','values'=>['tls','ssl','none']],
    'smtp_user'   => ['type'=>'string','label'=>'SMTP User'],
    'smtp_pass'   => ['type'=>'secret','label'=>'SMTP Password'],
    // Optional: recipients for test/alerts UI
    'sec_email'   => ['type'=>'list','label'=>'Alert recipients (comma-separated)','item'=>'email'],
  ],

  'integrations' => [
    '_label' => 'Integrations',
    'smtp' => [
      '_label' => 'SMTP',
      'host'=>['type'=>'string','label'=>'Host','required'=>true],
      'port'=>['type'=>'int','label'=>'Port','min'=>1,'max'=>65535],
      'username'=>['type'=>'string','label'=>'Username'],
      'password'=>['type'=>'secret','label'=>'Password'],
      'encryption'=>['type'=>'enum','label'=>'Encryption','values'=>['STARTTLS','SSL','NONE']],
    ],
    'mysql' => [
      '_label' => 'MySQL',
      'host'=>['type'=>'string','label'=>'Host','required'=>true],
      'port'=>['type'=>'int','label'=>'Port','min'=>1,'max'=>65535],
      'database'=>['type'=>'string','label'=>'Database','required'=>true],
      'username'=>['type'=>'string','label'=>'Username','required'=>true],
      'password'=>['type'=>'secret','label'=>'Password'],
    ],
    'redis' => [
      '_label' => 'Redis',
      'host'=>['type'=>'string','label'=>'Host','required'=>true],
      'port'=>['type'=>'int','label'=>'Port','min'=>1,'max'=>65535],
      'db'=>['type'=>'int','label'=>'DB Index','min'=>0,'max'=>64],
    ],
  ],

  'security' => [
    '_label' => 'Security',
    'admin_emails' => ['type'=>'list','label'=>'Admin Emails','item'=>'email'],
    'csrf_secret'  => ['type'=>'secret','label'=>'CSRF Secret'],
    'allowed_origins' => ['type'=>'list','label'=>'Allowed Origins','item'=>'string'],

    // API / IP Hardening
    'api_tokens'        => ['type'=>'list','label'=>'API tokens (Bearer)','item'=>'string'],
    'api_rate_limit_ms' => ['type'=>'int','label'=>'API rate limit per endpoint (ms)','min'=>0,'max'=>60000],
    'ip_allowlist'      => ['type'=>'list','label'=>'IP allowlist for this app (optional)','item'=>'string'],
  ],

  'ui' => [
    '_label' => 'UI',
    'toast_position' => ['type'=>'enum','label'=>'Toast Position','values'=>['bottom-center','bottom-right','bottom-left','top-right','top-left','top-center','center']],
    'items_per_page' => ['type'=>'int','label'=>'Items/Page','min'=>5,'max'=>500],
    'high_contrast' => ['type'=>'bool','label'=>'High contrast mode'],
  ],

  'server_tests' => [
    '_label' => 'Server Tests',
    'rate_limit_ms' => ['type'=>'int','label'=>'UI rate limit (ms)','min'=>0,'max'=>10000],

    // Services
    'service_targets'   => ['type'=>'list','label'=>'Service targets — extra (auto uses enabled Services)','item'=>'string'],
    'service_timeout_ms'=> ['type'=>'int','label'=>'Service connect timeout (ms)','min'=>100,'max'=>20000],

    // TLS
    'tls_warn_days' => ['type'=>'int','label'=>'TLS warn at (days)','min'=>1,'max'=>365],
    'tls_fail_days' => ['type'=>'int','label'=>'TLS fail at (days)','min'=>1,'max'=>365],

    // Inodes
    'inode_ok_free_pct'   => ['type'=>'int','label'=>'Inodes OK at ≥ (%)','min'=>1,'max'=>100],
    'inode_warn_free_pct' => ['type'=>'int','label'=>'Inodes WARN at ≥ (%)','min'=>0,'max'=>100],

    // Disk
    'disk_warn_free_pct' => ['type'=>'int','label'=>'Disk WARN at ≥ (%)','min'=>0,'max'=>100],
    'disk_fail_free_pct' => ['type'=>'int','label'=>'Disk FAIL at < (%)','min'=>0,'max'=>100],

    // Load (per core)
    'load_warn_per_core' => ['type'=>'int','label'=>'Load WARN per core (1m)','hint'=>'decimal ok, e.g. 1.0','min'=>0,'max'=>32, 'step'=>'any'],
    'load_fail_per_core' => ['type'=>'int','label'=>'Load FAIL per core (1m)','hint'=>'decimal ok, e.g. 2.0','min'=>0,'max'=>64, 'step'=>'any'],

    // Security updates
    'updates_warn_count' => ['type'=>'int','label'=>'Security updates WARN at ≥','min'=>0,'max'=>1000],
    'updates_fail_count' => ['type'=>'int','label'=>'Security updates FAIL at ≥','min'=>0,'max'=>1000],

    'audit_log'=>['type'=>'bool','label'=>'Log diagnostics runs'],
  ],

  'alerts' => [
    '_label' => 'Alerts',
    'enabled'     => ['type'=>'bool','label'=>'Enable alerts'],
    'webhook_url' => ['type'=>'url','label'=>'Webhook URL (optional)'],

    // Cron expectations
    'cron_token'        => ['type'=>'string','label'=>'Cron token (required for HTTP endpoint)'],
    'quiet_hours'       => ['type'=>'string','label'=>'Quiet hours (HH:MM-HH:MM, optional)'],
    'cron_interval_min' => ['type'=>'int','label'=>'Expected alert cron interval (min)','min'=>1,'max'=>1440],

    // Noise control
    'debounce_hours' => ['type'=>'int','label'=>'Suppress repeats for (hours)','min'=>0,'max'=>168],
    'daily_digest'   => ['type'=>'bool','label'=>'Send daily summary (if issues persist)'],
    'digest_hour'    => ['type'=>'string','label'=>'Digest send time (HH:MM)','hint'=>'24h time, e.g. 08:00'],
  ],

  'cron' => [
    '_label' => 'Cron',
    'ui_stub' => ['type'=>'string','label'=>'(managed by UI)','hint'=>'Use controls below'],
    // Hidden storage for the manager UI (JSON string)
    'jobs'   => ['type'=>'string','label'=>'(managed by UI)','hidden'=>true],
  ],

  'history' => [
    '_label' => 'History',
    'enabled' => ['type'=>'bool','label'=>'Enable history logging'],
    'retain_days' => ['type'=>'int','label'=>'Retention (days)','min'=>1,'max'=>3650],
    'sample_limit' => ['type'=>'int','label'=>'Max samples to return','min'=>50,'max'=>5000],

    // History expectations
    'append_interval_min' => ['type'=>'int','label'=>'Expected history append every (min)','min'=>1,'max'=>1440],
  ],

  // Log Viewer
  'logs' => [
    '_label' => 'Logs',
    'allow' => ['type'=>'list','label'=>'Allowlisted logs (Label|/path/to/file)','item'=>'string'],
    'tail_bytes' => ['type'=>'int','label'=>'Tail bytes (per request)','min'=>1024,'max'=>10485760],
    'poll_ms' => ['type'=>'int','label'=>'Follow refresh (ms)','min'=>250,'max'=>10000],
    'grep_max_lines' => ['type'=>'int','label'=>'Max lines returned','min'=>50,'max'=>10000],
  ],

  'email' => [
    '_label' => 'Email',
    'enabled' => ['type'=>'bool','label'=>'Enable Email polling'],
    'indicator_mode'=> ['type'=>'enum','label'=>'Indicator Mode','values'=>['single','multiple'],'default'=>'single'],

    // Quick-add inputs (user-friendly)
    'new_email'        => ['type'=>'email','label'=>'Email'],
    'new_password'     => ['type'=>'secret','label'=>'Password'],

    // Internal storage: JSON string managed by the JS (hidden from UI)
    'accounts'         => ['type'=>'string','label'=>'(managed by UI)','hidden'=>true],

    // OAuth fields here …

  'oauth_google_client_id'     => ['type'=>'string','label'=>'Google Client ID'],
  'oauth_google_client_secret' => ['type'=>'secret','label'=>'Google Client Secret'],
  'oauth_ms_client_id'         => ['type'=>'string','label'=>'Microsoft Client ID'],
  'oauth_ms_client_secret'     => ['type'=>'secret','label'=>'Microsoft Client Secret'],
  'oauth_ms_tenant'            => ['type'=>'string','label'=>'Microsoft Tenant (common or your-tenant-id)','default'=>'common'],
  'oauth_yahoo_client_id'      => ['type'=>'string','label'=>'Yahoo Client ID'],
  'oauth_yahoo_client_secret'  => ['type'=>'secret','label'=>'Yahoo Client Secret']
]
];
