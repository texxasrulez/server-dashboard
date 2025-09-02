<?php
// api/email_oauth_callback.php — exchanges code for tokens; stores per account
declare(strict_types=1);
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../lib/Config.php';
\App\Config::init(dirname(__DIR__));

function base_url_from_request(): string {
    $https = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
          || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
function absolute_callback(): string {
    $base = base_url_from_request();
    $dir  = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? 'api/email_oauth_callback.php')), '/');
    return $base . $dir . '/email_oauth_callback.php';
}
function b64url_decode(string $s): string {
    $p = strtr($s, '-_', '+/');
    $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
    return base64_decode($p) ?: '';
}
function html($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$err   = $_GET['error'] ?? '';
$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if ($err) {
  http_response_code(400);
  echo "<h1>OAuth Error</h1><pre>" . html($err) . "</pre>";
  exit;
}

if (!$code) {
  http_response_code(400);
  echo "<h1>Missing code</h1>"; exit;
}

$stateData = json_decode(b64url_decode($state), true) ?: [];
$provider  = $stateData['p'] ?? 'google';
$address   = $stateData['a'] ?? '';

if ($provider !== 'google') {
  http_response_code(400);
  echo "<h1>Unsupported provider</h1>"; exit;
}

// Exchange code
$client_id     = \App\Config::get('email.oauth_google_client_id');
$client_secret = \App\Config::get('email.oauth_google_client_secret');
if (!$client_id || !$client_secret) { http_response_code(500); echo "Missing Google OAuth client in Config › Email."; exit; }

$redirect_uri  = absolute_callback();

$payload = http_build_query([
  'grant_type'    => 'authorization_code',
  'code'          => $code,
  'client_id'     => $client_id,
  'client_secret' => $client_secret,
  'redirect_uri'  => $redirect_uri,
], '', '&', PHP_QUERY_RFC3986);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
  CURLOPT_TIMEOUT => 15,
]);
$res = curl_exec($ch);
$http= curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($res === false || $http >= 400) {
  http_response_code(500);
  echo "<h1>Token exchange failed</h1><pre>" . html($err ?: $res) . "</pre>";
  exit;
}

$tok = json_decode($res, true);
if (!is_array($tok) || empty($tok['access_token'])) {
  http_response_code(500);
  echo "<h1>Bad token payload</h1><pre>" . html($res) . "</pre>";
  exit;
}

// Persist tokens per-address (simple file store)
$storeDir = dirname(__DIR__) . '/data/oauth';
if (!is_dir($storeDir)) @mkdir($storeDir, 0775, true);
$fname = $storeDir . '/google-' . preg_replace('~[^a-z0-9_.@-]+~i','_', $address ?: 'unknown') . '.json';
file_put_contents($fname, json_encode([
  'provider' => 'google',
  'address'  => $address,
  'obtained' => gmdate('c'),
  'token'    => $tok,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

?><!doctype html>
<meta charset="utf-8">
<title>Google mail connected</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px;background:#111;color:#eee}
  .card{max-width:720px;margin:auto;background:#1b1b1b;border-radius:12px;padding:20px;border:1px solid rgba(255,255,255,.06)}
  .ok{color:#7dff7d}
  .muted{opacity:.7}
  a{color:#7dc3ff}
</style>
<div class="card">
  <h2 class="ok">✅ Google account connected</h2>
  <p>Tokens were saved for <strong><?=html($address?:'(no address)')?></strong>.</p>
  <p class="muted">You can close this tab and return to the dashboard.</p>
</div>
