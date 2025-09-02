<?php
// api/email_status.php — multi-account status with Gmail OAuth + IMAP fallback (robust accounts parsing)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($no,$str){ /* swallow non-fatal */ });
set_exception_handler(function(Throwable $e){
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES); exit;
});

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../lib/Config.php';
\App\Config::init(dirname(__DIR__));

function cfg($key, $default=null){
  try { $v = \App\Config::get($key); return ($v===null ? $default : $v); }
  catch(Throwable $e){ return $default; }
}
function json_out($arr){ echo json_encode($arr, JSON_UNESCAPED_SLASHES); exit; }
function now(){ return time(); }

function provider_guess(string $address): string {
  $host = strtolower(substr(strrchr($address, '@') ?: '', 1));
  if ($host === 'gmail.com' || $host === 'googlemail.com') return 'google';
  if (preg_match('~(^|\\.)yahoo\\.~i',$host)) return 'yahoo';
  if (preg_match('~(outlook|live|hotmail|msn)\\.com$~i',$host)) return 'microsoft';
  return 'other';
}

function accounts_from_config(): array {
  $acc = [];
  $raw = cfg('email.accounts', null);

  if (is_string($raw)) {
    $raw = trim($raw);
    if ($raw !== '') {
      $j = json_decode($raw, true);
      if (is_array($j)) $acc = $j;
    }
  } elseif (is_array($raw)) {
    // Some setups may store a real array already
    $acc = $raw;
  }

  // legacy single fields (only if no array-based accounts)
  $singleAddr = (string) cfg('email.address', '');
  $singlePass = (string) cfg('email.password', '');
  if (empty($acc) && $singleAddr !== '') {
    $acc[] = ['address'=>$singleAddr,'password'=>$singlePass,'provider'=>'auto','poll_seconds'=> (int)cfg('email.poll_seconds', 300)];
  }

  // Filter out disabled accounts (enabled=false)
  $acc = array_values(array_filter($acc, function($a){ return !is_array($a) || !array_key_exists('enabled',$a) || $a['enabled']; }));

  // Normalize + dedupe by address
  $out = []; $seen = [];
  foreach ($acc as $a) {
    if (empty($a['address'])) continue;
    $addr = trim((string)$a['address']);
    if ($addr === '' || isset($seen[strtolower($addr)])) continue;
    $seen[strtolower($addr)] = true;
    $p = $a['provider'] ?? 'auto';
    if ($p==='auto') $p = provider_guess($addr);
    $out[] = [
      'address' => $addr,
      'password'=> (string)($a['password'] ?? ''),
      'provider'=> $p,
      'poll_seconds'=> isset($a['poll_seconds']) ? (int)$a['poll_seconds'] : (int)cfg('email.poll_seconds', 300)
    ];
  }
  return $out;
}

function gmail_token_file(string $address): string {
  $safe = preg_replace('~[^a-z0-9_.@-]+~i','_', $address);
  return dirname(__DIR__) . '/data/oauth/google-' . $safe . '.json';
}
function gmail_get_access_token(string $address): ?array {
  $file = gmail_token_file($address);
  if (!is_file($file)) return null;
  $data = json_decode((string)@file_get_contents($file), true);
  if (!is_array($data) || empty($data['token'])) return null;
  $tok = $data['token'];
  $obt = isset($data['obtained']) ? strtotime($data['obtained']) : 0;
  $exp = (int)($tok['expires_in'] ?? 0);
  $access = $tok['access_token'] ?? '';
  $refresh = $tok['refresh_token'] ?? '';
  $client_id = (string) cfg('email.oauth_google_client_id', '');
  $client_secret = (string) cfg('email.oauth_google_client_secret', '');
  $needsRefresh = ($exp>0 && $obt>0 && (now() >= ($obt + $exp - 60)));
  if ($needsRefresh && $refresh && $client_id && $client_secret) {
    $payload = http_build_query([
      'grant_type'=>'refresh_token',
      'refresh_token'=>$refresh,
      'client_id'=>$client_id,
      'client_secret'=>$client_secret,
    ], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
      CURLOPT_TIMEOUT => 12,
    ]);
    $res = curl_exec($ch);
    $http= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res !== false && $http < 400) {
      $new = json_decode($res, true);
      if (is_array($new) && !empty($new['access_token'])) {
        $tok['access_token'] = $new['access_token'];
        if (!empty($new['expires_in'])) $tok['expires_in'] = $new['expires_in'];
        @file_put_contents($file, json_encode(['provider'=>'google','address'=>$address,'obtained'=>gmdate('c'),'token'=>$tok], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $access = $tok['access_token'];
      }
    }
  }
  return $access ? ['access_token'=>$access] : null;
}

function gmail_unread_count(string $address): array {
  $tok = gmail_get_access_token($address);
  if (!$tok) {
    return ['ok'=>false,'unseen'=>null,'needs_auth'=>true,'web'=>'https://mail.google.com/mail/u/0/#inbox'];
  }
  $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages?maxResults=1&q=' . rawurlencode('is:unread'));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['access_token']],
    CURLOPT_TIMEOUT => 10,
  ]);
  $res = curl_exec($ch);
  $http= curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($res === false || $http >= 400) {
    return ['ok'=>false,'unseen'=>null,'web'=>'https://mail.google.com/mail/u/0/#inbox'];
  }
  $j = json_decode($res, true);
  $count = (int)($j['resultSizeEstimate'] ?? 0);
  return ['ok'=>true,'unseen'=>$count,'web'=>'https://mail.google.com/mail/u/0/#inbox'];
}

function imap_unread_count(string $address, string $password): array {
  $host = strtolower(substr(strrchr($address, '@') ?: '', 1));
  $cands = [];
  $cands[] = '{127.0.0.1:993/imap/ssl/novalidate-cert}INBOX';
  $cands[] = '{localhost:993/imap/ssl/novalidate-cert}INBOX';
  if ($host) {
    $cands[] = '{imap.'.$host.':993/imap/ssl/novalidate-cert}INBOX';
    $cands[] = '{mail.'.$host.':993/imap/ssl/novalidate-cert}INBOX';
  }
  foreach ($cands as $mbox) {
    $in = @imap_open($mbox, $address, $password, 0, 1);
    if ($in) {
      $info = @imap_status($in, $mbox, SA_UNSEEN);
      $unseen = $info ? (int)$info->unseen : 0;
      @imap_close($in);
      return ['ok'=>true,'unseen'=>$unseen];
    }
  }
  return ['ok'=>false,'unseen'=>null];
}

$enabled = (bool) cfg('email.enabled', false);
$mode    = (string) cfg('email.indicator_mode', 'single');
$accs    = accounts_from_config();
if (empty($accs)) { $enabled = false; }

$outAccs = [];
$total = 0;
foreach ($accs as $a) {
  $addr = $a['address'];
  $prov = $a['provider'];
  $row = ['address'=>$addr,'provider'=>$prov,'unseen'=>null];
  if ($prov === 'google') {
    $res = gmail_unread_count($addr);
    $row = array_merge($row, $res);
    if (!empty($res['unseen'])) $total += (int)$res['unseen'];
  } else {
    $res = imap_unread_count($addr, (string)($a['password'] ?? ''));
    $row = array_merge($row, $res);
    if (!empty($res['unseen'])) $total += (int)$res['unseen'];
    $domain = strtolower(substr(strrchr($addr, '@') ?: '', 1));
    if (!isset($row['web']) && $domain) $row['web'] = 'https://' . $domain . '/mail/';
  }
  $outAccs[] = $row;
}

json_out(['ok'=>true,'enabled'=>$enabled,'indicatorMode'=>$mode,'accounts'=>$outAccs,'total_unseen'=>$total]);
