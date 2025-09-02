<?php
// api/_guard.php — lightweight API guard: IP allowlist, bearer token, rate limiting
// Usage: require __DIR__.'/_guard.php'; guard_api(['key'=>'metrics_prom','require_token'=>true,'type'=>'text']);
//
// Reads from config/local.json -> security: { api_tokens:[], api_rate_limit_ms:int, ip_allowlist:[] }

if (!function_exists('guard_read_cfg')) {
  function guard_read_cfg($dot, $default=null){
    $file = dirname(__DIR__) . '/config/local.json';
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false) return $default;
    $j = json_decode($raw, true);
    if (!is_array($j)) return $default;
    $cur = $j;
    foreach (explode('.', $dot) as $k) {
      if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; } else { return $default; }
    }
    return $cur;
  }
}

if (!function_exists('guard_client_ip')) {
  function guard_client_ip(){
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
    return $ip ?: '0.0.0.0';
  }
}

if (!function_exists('guard_api')) {
  function guard_api($opts=[]){
    $ct = isset($opts['type']) && $opts['type']==='text' ? 'text/plain' : 'application/json';
    header('X-Robots-Tag: noindex');
    // IP allowlist (applies if list not empty)
    $allow = guard_read_cfg('security.ip_allowlist', []);
    if (is_array($allow) && count($allow)) {
      $ip = guard_client_ip();
      if (!in_array($ip, $allow, true)) {
        http_response_code(403);
        if ($ct!=='text/plain') header('Content-Type: application/json; charset=utf-8');
        echo ($ct==='text/plain') ? "forbidden\n" : json_encode(['ok'=>false,'error'=>'forbidden ip']);
        exit;
      }
    }
    // Rate limit per endpoint+ip
    $rate_ms = (int)guard_read_cfg('security.api_rate_limit_ms', 100);
    $key = isset($opts['key']) ? preg_replace('/[^a-z0-9_\-]+/i','',$opts['key']) : 'api';
    if ($rate_ms > 0) {
      $dir = dirname(__DIR__) . '/state/ratelimit';
      @mkdir($dir, 0775, true);
      $ip = guard_client_ip();
      $f = $dir . '/' . $key . '_' . preg_replace('/[^a-z0-9:._-]+/i','_',$ip) . '.txt';
      $now = microtime(true);
      $last = 0.0;
      if (is_file($f)) { $raw = @file_get_contents($f); if ($raw !== false) $last = floatval($raw); }
      if (($now - $last) * 1000.0 < $rate_ms) {
        http_response_code(429);
        if ($ct!=='text/plain') header('Content-Type: application/json; charset=utf-8');
        $retry = max(0, $rate_ms - intval(($now - $last)*1000.0));
        echo ($ct==='text/plain') ? "rate_limited\n" : json_encode(['ok'=>false,'error'=>'rate limited','retry_ms'=>$retry]);
        exit;
      }
      @file_put_contents($f, sprintf('%.6f', $now), LOCK_EX);
    }
    // Token (only if require_token AND tokens exist)
    $need = !empty($opts['require_token']);
    $tokens = guard_read_cfg('security.api_tokens', []);
    if ($need && is_array($tokens) && count($tokens)) {
      $got = '';
      if (!empty($_GET['token'])) $got = (string)$_GET['token'];
      if (!$got && !empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'],'Bearer ')===0) {
        $got = trim(substr($_SERVER['HTTP_AUTHORIZATION'],7));
      }
      if (!$got || !in_array($got, $tokens, true)) {
        http_response_code(401);
        if ($ct!=='text/plain') header('Content-Type: application/json; charset=utf-8');
        echo ($ct==='text/plain') ? "unauthorized\n" : json_encode(['ok'=>false,'error'=>'unauthorized']);
        exit;
      }
    }
  }
}
