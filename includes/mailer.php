<?php
/**
 * includes/mailer.php — compatibility-first mailer (PHP 5.3+ safe)
 * Transports: phpmail, sendmail, smtp
 * Config sources (priority order):
 *   1) config/local.json -> mail.* and smtp_* (also mail.smtp_* if present)
 *   2) Environment variables (MAIL_*, SMTP_*)
 *   3) Legacy: integrations.smtp.* in local.json (fallback only)
 *
 * No null-coalesce, no typed signatures, no null-safe. Pure old-school PHP.
 */

// -------- tiny helpers --------

if (!function_exists('mailer_env')) {
  function mailer_env($key, $default) {
    if (defined($key)) return constant($key);
    $dash = 'DASH_' . $key;
    if (defined($dash)) return constant($dash);
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    $v = getenv($dash);
    if ($v !== false && $v !== '') return $v;
    return $default;
  }
}

if (!function_exists('mailer_cfg_local')) {
  function mailer_cfg_local($dot, $default) {
    static $cacheLoaded = false;
    static $cache = array();
    if (!$cacheLoaded) {
      $file = dirname(__DIR__) . '/config/local.json';
      if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
          $j = json_decode($raw, true);
          if (is_array($j)) $cache = $j;
        }
      }
      $cacheLoaded = true;
    }
    $cur = $cache;
    $parts = explode('.', $dot);
    for ($i=0; $i<count($parts); $i++) {
      $k = $parts[$i];
      if (is_array($cur) && array_key_exists($k, $cur)) {
        $cur = $cur[$k];
      } else {
        return $default;
      }
    }
    return $cur;
  }
}

if (!function_exists('mailer_log')) {
  function mailer_log($data) {
    // Keep quiet in production but log enough to debug
    if (!is_array($data)) return;
    $line = '[Mailer] ' . json_encode($data);
    error_log($line);
  }
}

// -------- Mailer --------

if (!class_exists('Mailer')) {
  class Mailer {

    public static function transport() {
      // Preferred explicit transport from config
      $t = mailer_cfg_local('mail.mail_transport', '');
      if (!is_string($t)) $t = '';
      $t = strtolower(trim($t));
      if ($t === 'phpmail' || $t === 'sendmail' || $t === 'smtp') return $t;

      // Infer SMTP if a host is configured anywhere
      $host = self::cfg('mail.smtp_host', self::cfg('smtp_host', self::cfg('integrations.smtp.host', '')));
      if (is_string($host) && trim($host) !== '') return 'smtp';

      // Env or default
      $te = strtolower(trim(mailer_env('MAIL_TRANSPORT', 'phpmail')));
      if ($te === 'phpmail' || $te === 'sendmail' || $te === 'smtp') return $te;
      return 'phpmail';
    }

    public static function from() {
      $from = self::cfg('mail.mail_from', '');
      if (!$from) $from = mailer_env('MAIL_FROM', '');
      if ($from) return self::stripNewlines($from);

      // no explicit From: synthesize
      $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
      $host = preg_replace('/^www\./i', '', $host);
      return 'no-reply@' . $host;
    }

    public static function replyTo() {
      $reply = self::cfg('mail.mail_replyto', '');
      if (!$reply) $reply = mailer_env('MAIL_REPLYTO', '');
      return self::stripNewlines($reply);
    }

    public static function send($to, $subject, $body, $opts=array()) {
      // Normalize recipients
      if (is_string($to)) {
        $toList = array();
        foreach (explode(',', $to) as $p) {
          $p = trim($p);
          if ($p !== '') $toList[] = $p;
        }
      } else if (is_array($to)) {
        $toList = array();
        for ($i=0; $i<count($to); $i++) {
          $p = trim((string)$to[$i]);
          if ($p !== '') $toList[] = $p;
        }
      } else {
        $toList = array();
      }
      if (!count($toList)) return array('ok'=>false, 'error'=>'no recipients');

      $subject = self::encodeHeader(self::stripNewlines($subject ? $subject : '(no subject)'));
      $from    = self::stripNewlines(isset($opts['from']) ? $opts['from'] : self::from());
      $reply   = self::stripNewlines(isset($opts['reply_to']) ? $opts['reply_to'] : self::replyTo());

      $headers = array();
      $headers[] = 'From: ' . $from;
      if ($reply) $headers[] = 'Reply-To: ' . $reply;
      $headers[] = 'MIME-Version: 1.0';
      $headers[] = 'Content-Type: text/plain; charset=UTF-8';
      $headers[] = 'Content-Transfer-Encoding: 8bit';
      $headers[] = 'X-Mailer: Dashboard';

      $headerStr = implode("\r\n", $headers);
      $body = self::normalizeEOL((string)$body);

      $t = self::transport();
      $result = array('ok'=>false, 'transport'=>$t);

      if ($t === 'phpmail') {
        $ok = @mail(implode(', ', $toList), $subject, $body, $headerStr);
        $result['ok'] = $ok ? true : false;
        if (!$ok) $result['error'] = 'phpmail send failed';
      } else if ($t === 'sendmail') {
        $path = self::cfg('mail.sendmail_path', mailer_env('SENDMAIL_PATH', '/usr/sbin/sendmail'));
        if (!is_string($path) || trim($path) === '') $path = '/usr/sbin/sendmail';
        $envelopeFrom = self::extractEmail($from);
        $cmd = escapeshellcmd($path) . ' -t -i -f ' . escapeshellarg($envelopeFrom);
        $h = popen($cmd, 'w');
        if (!$h) {
          $result['error'] = 'sendmail popen failed';
        } else {
          fwrite($h, 'To: ' . implode(', ', $toList) . "\r\n");
          fwrite($h, 'Subject: ' . $subject . "\r\n");
          fwrite($h, $headerStr . "\r\n\r\n");
          fwrite($h, $body);
          $code = pclose($h);
          if ($code === 0) { $result['ok'] = true; } else { $result['error'] = 'sendmail exit '.$code; }
        }
      } else { // smtp
        $cfg = self::smtpConfig();
        $host = $cfg['host'];
        if (!$host) { $result['error'] = 'smtp host not configured'; mailer_log($result); return $result; }
        $port = $cfg['port'] ? intval($cfg['port']) : (($cfg['secure']==='ssl') ? 465 : 587);
        $timeout = $cfg['timeout'] ? intval($cfg['timeout']) : 12;
        $remote = ($cfg['secure']==='ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) { $result['error'] = 'connect failed: '.$errno.' '.$errstr; mailer_log($result); return $result; }
        stream_set_timeout($fp, $timeout);

        $code = self::smtpReadCode($fp);
        if ($code !== 220) { fclose($fp); $result['error'] = 'smtp banner '.$code; mailer_log($result); return $result; }

        $ehloHost = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        fwrite($fp, "EHLO ".$ehloHost."\r\n");
        $code = self::smtpReadCode($fp);
        if ($code !== 250) { fclose($fp); $result['error'] = 'ehlo failed '.$code; mailer_log($result); return $result; }

        // STARTTLS if requested
        if ($cfg['secure'] === 'tls') {
          fwrite($fp, "STARTTLS\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 220) { fclose($fp); $result['error'] = 'starttls failed '.$code; mailer_log($result); return $result; }
          if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); $result['error'] = 'tls upgrade failed'; mailer_log($result); return $result;
          }
          fwrite($fp, "EHLO ".$ehloHost."\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 250) { fclose($fp); $result['error'] = 'post-tls ehlo failed '.$code; mailer_log($result); return $result; }
        }

        // AUTH LOGIN if user/pass provided
        if ($cfg['user'] !== '' && $cfg['pass'] !== '') {
          fwrite($fp, "AUTH LOGIN\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 334) { fclose($fp); $result['error'] = 'auth login not accepted '.$code; mailer_log($result); return $result; }
          fwrite($fp, base64_encode($cfg['user'])."\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 334) { fclose($fp); $result['error'] = 'username rejected '.$code; mailer_log($result); return $result; }
          fwrite($fp, base64_encode($cfg['pass'])."\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 235) { fclose($fp); $result['error'] = 'password rejected '.$code; mailer_log($result); return $result; }
        }

        $mailFrom = self::extractEmail($from);
        fwrite($fp, "MAIL FROM:<".$mailFrom.">\r\n");
        $code = self::smtpReadCode($fp);
        if ($code !== 250) { fclose($fp); $result['error'] = 'MAIL FROM failed '.$code; mailer_log($result); return $result; }

        for ($i=0; $i<count($toList); $i++) {
          $rcpt = self::extractEmail($toList[$i]);
          fwrite($fp, "RCPT TO:<".$rcpt.">\r\n");
          $code = self::smtpReadCode($fp);
          if ($code !== 250 && $code !== 251) { fclose($fp); $result['error'] = 'RCPT TO failed '.$code; mailer_log($result); return $result; }
        }

        fwrite($fp, "DATA\r\n");
        $code = self::smtpReadCode($fp);
        if ($code !== 354) { fclose($fp); $result['error'] = 'DATA not accepted '.$code; mailer_log($result); return $result; }

        $msg = self::buildRfc822($toList, $from, $subject, $headers, $body);
        // Dot-stuff and ensure CRLF line endings
        $msg = str_replace("\r\n", "\n", $msg);
        $msg = str_replace("\r", "\n", $msg);
        $msg = str_replace("\n", "\r\n", $msg);
        $msg = preg_replace('/^\./m', '..', $msg);
        if (substr($msg, -2) !== "\r\n") $msg .= "\r\n";
        $msg .= ".\r\n";
        fwrite($fp, $msg);
        $code = self::smtpReadCode($fp);
        if ($code !== 250) { fclose($fp); $result['error'] = 'message not accepted '.$code; mailer_log($result); return $result; }

        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        $result['ok'] = true;
      }

      mailer_log($result);
      return $result;
    }

    // ---- helpers ----

    private static function cfg($prefKey, $fallback) {
      $v = mailer_cfg_local($prefKey, null);
      if ($v === null || $v === '') $v = $fallback;
      return $v;
    }

    private static function smtpConfig() {
      $secure = self::cfg('mail.smtp_secure', self::cfg('smtp_secure', self::cfg('integrations.smtp.encryption', '')));
      $secure = strtolower((string)$secure);
      if ($secure !== 'ssl' && $secure !== 'tls') $secure = '';

      $cfg = array(
        'host'    => (string)self::cfg('mail.smtp_host', self::cfg('smtp_host', self::cfg('integrations.smtp.host', mailer_env('SMTP_HOST', '')))),
        'port'    => (string)self::cfg('mail.smtp_port', self::cfg('smtp_port', self::cfg('integrations.smtp.port', mailer_env('SMTP_PORT', '')))),
        'user'    => (string)self::cfg('mail.smtp_user', self::cfg('smtp_user', self::cfg('integrations.smtp.username', mailer_env('SMTP_USER', '')))),
        'pass'    => (string)self::cfg('mail.smtp_pass', self::cfg('smtp_pass', self::cfg('integrations.smtp.password', mailer_env('SMTP_PASS', '')))),
        'secure'  => $secure,
        'timeout' => (int)mailer_env('SMTP_TIMEOUT', 12),
      );
      // normalize port
      if ($cfg['port'] === '') $cfg['port'] = 0;
      $cfg['port'] = intval($cfg['port']);
      if (!$cfg['port']) $cfg['port'] = ($cfg['secure'] === 'ssl' ? 465 : 587);
      return $cfg;
    }

    private static function smtpReadCode($fp) {
      $line = '';
      while (!feof($fp)) {
        $buf = fgets($fp, 515);
        if ($buf === false) break;
        $line .= $buf;
        if (strlen($buf) >= 4 && $buf[3] === ' ') break;
      }
      $code = (int)substr($line, 0, 3);
      return $code;
    }

    private static function buildRfc822($toList, $from, $subject, $headers, $body) {
      $h = is_array($headers) ? implode("\r\n", $headers) : (string)$headers;
      $msg = $h . "\r\n";
      $msg .= "To: " . implode(', ', $toList) . "\r\n";
      $msg .= "Subject: " . $subject . "\r\n\r\n";
      $msg .= $body;
      if (substr($msg, -2) !== "\r\n") $msg .= "\r\n";
      return $msg;
    }

    public static function normalizeEOL($s){ return str_replace(array("\r\n","\r"), "\n", (string)$s); }
    public static function encodeHeader($s){ return '=?UTF-8?B?'.base64_encode((string)$s).'?='; }
    public static function stripNewlines($s){ return preg_replace('/[\r\n]+/', ' ', (string)$s); }
    public static function extractEmail($s){
      if (preg_match('/<([^>]+)>/', $s, $m)) return $m[1];
      return trim($s);
    }
  }
}
