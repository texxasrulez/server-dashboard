<?php
// api/email_token_info.php — presence & mtime of OAuth token for an address (Google)
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
function resp($ok, $extra = []) { echo json_encode(array_merge(['ok'=>$ok], $extra)); exit; }
try {
  $provider = strtolower((string)($_GET['provider'] ?? 'google'));
  $address  = trim((string)($_GET['address'] ?? ''));
  if ($provider !== 'google') { resp(false, ['error'=>'unsupported provider']); }
  if ($address === '') { resp(false, ['error'=>'missing address']); }
  $root = dirname(__DIR__);
  $safe = preg_replace('~[^a-z0-9_.@-]+~i','_', $address);
  $candidates = [
    $root . '/data/oauth/google-' . $safe . '.json',
    $root . '/config/oauth/google-' . $safe . '.json',
    $root . '/oauth/google-' . $safe . '.json',
    dirname($root) . '/data/oauth/google-' . $safe . '.json',
    dirname($root) . '/config/oauth/google-' . $safe . '.json',
  ];
  $path = null; foreach ($candidates as $p) { if (is_readable($p)) { $path = $p; break; } }
  if (!$path) { resp(true, ['exists'=>false]); }
  $stat = @stat($path);
  resp(true, [
    'exists'=>true,
    'mtime'=>$stat ? ($stat['mtime'] ?? null) : null,
    'mtime_iso'=>$stat ? gmdate('c', (int)$stat['mtime']) : null,
    'size'=>$stat ? ($stat['size'] ?? null) : null,
    'file'=>basename($path),
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  resp(false, ['error'=>$e->getMessage()]);
}
