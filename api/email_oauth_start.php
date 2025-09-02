<?php
// api/email_oauth_start.php — builds a correct absolute redirect_uri (no double /api)
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
    // Always resolve relative to this /api/ directory to avoid "/api/api" mistakes
    $base = base_url_from_request();
    $dir  = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? 'api/email_oauth_start.php')), '/');
    return $base . $dir . '/email_oauth_callback.php';
}

function b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

$provider = strtolower(trim($_GET['provider'] ?? ''));
$address  = trim($_GET['address'] ?? '');

if (!in_array($provider, ['google','microsoft','yahoo','outlook'], true)) {
    http_response_code(400);
    echo "Unsupported provider."; exit;
}

$cb = absolute_callback();

// Pull OAuth client creds from config (under 'email' group, per schema)
$cid = $sec = null;
switch ($provider) {
  case 'google':
    $cid = \App\Config::get('email.oauth_google_client_id');
    $sec = \App\Config::get('email.oauth_google_client_secret');
    if (!$cid || !$sec) { http_response_code(400); echo "Missing Google OAuth client in Config › Email."; exit; }
    $auth = 'https://accounts.google.com/o/oauth2/v2/auth';
    $params = [
      'response_type' => 'code',
      'client_id'     => $cid,
      'redirect_uri'  => $cb,                          // <-- absolute; must match console exactly
      'scope'         => 'https://mail.google.com/',
      'access_type'   => 'offline',
      'include_granted_scopes' => 'true',
      'prompt'        => 'consent',
      'login_hint'    => $address,
      // carry provider + address so callback can file tokens per-account
      'state'         => b64url(json_encode(['p'=>'google','a'=>$address], JSON_UNESCAPED_SLASHES)),
    ];
    $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $auth . '?' . $qs, true, 302);
    exit;
  default:
    http_response_code(501);
    echo "Provider not implemented yet.";
    exit;
}
