<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();
if ((bool)cfg_local('security.favicon_require_admin', false)) require_admin();

$maxBytes = max(4096, (int)cfg_local('security.favicon_max_bytes', 262144));
$timeout = max(1, min(10, (int)cfg_local('security.favicon_timeout_sec', 4)));
$allowHttp = (bool)cfg_local('security.favicon_allow_http', false);
$allowedHosts = cfg_local('security.favicon_allowed_hosts', []);
if (!is_array($allowedHosts)) $allowedHosts = [];

$url = $_GET['url'] ?? '';
$host = $_GET['host'] ?? '';

if ($url && !$host) {
  $u = @parse_url($url);
  $scheme = is_array($u) ? strtolower((string)($u['scheme'] ?? '')) : '';
  if ($scheme === 'http' && !$allowHttp) $u = null;
  if ($u && isset($u['host'])) $host = $u['host'];
}
$host = strtolower(trim($host));
$host = rtrim($host, '.');

function is_ipv6_local_or_private($ip) {
  $bin = @inet_pton($ip);
  if ($bin === false || strlen($bin) !== 16) return true;
  if ($bin === str_repeat("\x00", 15) . "\x01") return true; // ::1
  if ((ord($bin[0]) & 0xFE) === 0xFC) return true; // fc00::/7
  if (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) return true; // fe80::/10
  if (ord($bin[0]) === 0x00) return true; // ::/8 includes unspecified
  return false;
}

function is_private_or_reserved_ip($ip) {
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $ok = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return $ok === false;
  }
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    return is_ipv6_local_or_private($ip);
  }
  return true;
}

function host_allowed($host, $allowList) {
  if (!is_array($allowList) || !count($allowList)) return true;
  foreach ($allowList as $entry) {
    if (!is_string($entry)) continue;
    $entry = strtolower(trim($entry));
    if ($entry === '') continue;
    if ($host === $entry) return true;
    if ($entry[0] === '.' && substr($host, -strlen($entry)) === $entry) return true;
  }
  return false;
}

function resolve_host_ips($host) {
  $ips = [];
  if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
  $a = @gethostbynamel($host);
  if (is_array($a)) {
    foreach ($a as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = $ip;
    }
  }
  if (function_exists('dns_get_record')) {
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
      foreach ($aaaa as $row) {
        $ip = $row['ipv6'] ?? '';
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $ips[] = $ip;
      }
    }
  }
  return array_values(array_unique($ips));
}

$dataDir = __DIR__ . '/../data/favicons';
@mkdir($dataDir, 0775, true);
$cacheFile = $dataDir . '/' . preg_replace('/[^a-z0-9\.-]/i','_', $host?:'default') . '.ico';
$default = __DIR__ . '/../assets/img/default_favicon.png';

function out_png($file){
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=300');
  readfile($file);
  exit;
}
function out_ico($file){
  header('Content-Type: image/x-icon');
  header('Cache-Control: public, max-age=86400');
  readfile($file);
  exit;
}

if ($host) {
  if (!preg_match('/^[a-z0-9][a-z0-9\.-]*$/i', $host)) out_png($default);
  if (!host_allowed($host, $allowedHosts)) out_png($default);
  // Serve cached favicon first; this avoids DNS/private-IP guard false negatives
  // for hosts that were already cached (including manually seeded cache files).
  if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    out_ico($cacheFile);
  }
  $ips = resolve_host_ips($host);
  if (!count($ips)) out_png($default);
  foreach ($ips as $ip) {
    if (is_private_or_reserved_ip($ip)) out_png($default);
  }
  $srcs = ['https://'.$host.'/favicon.ico'];
  if ($allowHttp) $srcs[] = 'http://'.$host.'/favicon.ico';
  foreach ($srcs as $src) {
    $ctx = stream_context_create([
      'http'=>[
        'timeout'=>$timeout,
        'follow_location'=>0,
        'ignore_errors'=>true,
        'method'=>'GET',
        'header'=>"User-Agent: ServerDashboard-Favicon/1\r\nAccept: image/*,*/*;q=0.5\r\nConnection: close\r\n"
      ],
      'ssl'=>[
        'verify_peer'=>true,
        'verify_peer_name'=>true,
        'SNI_enabled'=>true,
      ]
    ]);
    $fp = @fopen($src, 'rb', false, $ctx);
    if (!$fp) continue;
    $data = @stream_get_contents($fp, $maxBytes + 1);
    @fclose($fp);
    if (is_string($data) && strlen($data) > 0 && strlen($data) <= $maxBytes) {
      @file_put_contents($cacheFile, $data);
      @chmod($cacheFile, 0644);
      out_ico($cacheFile);
    }
  }
}

out_png($default);
