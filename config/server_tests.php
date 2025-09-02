<?php
// Thresholds for server_tests; tweak per environment.
// Return an associative array.
return [
  'tls' => ['warn_days' => 21, 'fail_days' => 5],
  'network' => [
    'http_warn_ms' => 800, 'http_fail_ms' => 2000,
    'dns_warn_ms' => 150, 'dns_fail_ms' => 600,
    'tcp_warn_ms' => 300, 'tcp_fail_ms' => 1000
  ],
  'filesystem' => ['disk_warn_pct' => 80, 'disk_fail_pct' => 95,
                   'inode_warn_pct' => 80, 'inode_fail_pct' => 95],
  'php' => [
    'opcache_required' => true,
    'required_extensions' => ['curl','json','mbstring','pdo_mysql','intl','zip','openssl','xml']
  ],
  'smtp' => ['timeout_sec' => 2, 'host' => '127.0.0.1', 'port' => 25],
];
