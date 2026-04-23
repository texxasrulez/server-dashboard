<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Config.php';
require_admin();
header('Content-Type: application/json');

\App\Config::init(dirname(__DIR__));
$mail    = \App\Config::get('mail', []);
$security = \App\Config::get('security', []);
$alerts  = \App\Config::get('alerts', []);

function stringify_emails($value)
{
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $clean[] = $s;
            }
        }
        return implode(', ', $clean);
    }
    return is_string($value) ? trim($value) : '';
}

$alertEmails = stringify_emails($mail['sec_email'] ?? $alerts['email'] ?? '');

$settings = [
  'mail_transport' => $mail['mail_transport'] ?? 'phpmail',
  'mail_from'      => $mail['mail_from'] ?? '',
  'mail_envelope_from' => $mail['mail_envelope_from'] ?? '',
  'mail_replyto'   => $mail['mail_replyto'] ?? '',
  'sendmail_path'  => $mail['sendmail_path'] ?? '/usr/sbin/sendmail',
  'smtp_host'      => $mail['smtp_host'] ?? '',
  'smtp_port'      => $mail['smtp_port'] ?? 587,
  'smtp_secure'    => $mail['smtp_secure'] ?? 'tls',
  'smtp_user'      => $mail['smtp_user'] ?? '',
  'smtp_timeout'   => $mail['smtp_timeout'] ?? 12,
  'alert_emails'   => $alertEmails,
  'sec_email'      => $alertEmails,
  'cron_token'     => $alerts['cron_token'] ?? '',
  'admin_emails'   => $security['admin_emails'] ?? [],
  'csrf_secret'    => $security['csrf_secret'] ?? '',
  'allowed_origins' => $security['allowed_origins'] ?? [],
  'ip_allowlist'   => $security['ip_allowlist'] ?? [],
];

echo json_encode(['ok' => true, 'settings' => $settings], JSON_UNESCAPED_SLASHES);
