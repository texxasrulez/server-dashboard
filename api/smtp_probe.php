<?php
// api/smtp_probe.php — connectivity/TLS/AUTH probe for SMTP
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
function resp($ok, $extra=[]) { echo json_encode(array_merge(['ok'=>$ok], $extra)); exit; }
function read_reply($fp, $timeout=8){
  $lines=[]; $code=null; $end=false; $start=microtime(true);
  stream_set_timeout($fp, $timeout);
  while (!feof($fp)){
    $line = fgets($fp, 4096);
    if ($line===false){ break; }
    $lines[] = rtrim($line, "\r\n");
    if (preg_match('~^(\d{3})\s~', $line, $m)){ $code = (int)$m[1]; $end=true; break; }
    if (preg_match('~^(\d{3})-~', $line, $m)){ $code = (int)$m[1]; }
    if ((microtime(true)-$start) > $timeout) break;
  }
  return [$code, $lines];
}
function send_cmd($fp, $cmd){
  fwrite($fp, $cmd."\r\n");
  return read_reply($fp);
}
try {
  $host = (string)($_GET['host'] ?? '');
  $port = (int)($_GET['port'] ?? 0);
  $secure = strtolower((string)($_GET['secure'] ?? ''));
  $transport = strtolower((string)($_GET['transport'] ?? ''));
  $user = (string)($_GET['user'] ?? '');
  $pass = (string)($_GET['pass'] ?? '');
  if (!$host) resp(false, ['error'=>'missing host']);

  if ($port<=0) $port = ($secure==='ssl' || $transport==='smtps') ? 465 : 25;
  $timeout = 8;
  $ctx = stream_context_create(['ssl'=>['SNI_enabled'=>true,'verify_peer_name'=>false,'verify_peer'=>false,'allow_self_signed'=>true]]);

  $useImplicit = ($secure==='ssl' || $transport==='smtps');
  $scheme = $useImplicit ? 'ssl' : 'tcp';
  $fp = @stream_socket_client($scheme.'://'.$host.':'.$port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp){ resp(false, ['connect'=>['ok'=>false,'error'=>trim($errstr),'code'=>$errno],'server'=>compact('host','port','secure','transport')]); }

  list($codeBanner, $linesBanner) = read_reply($fp);
  $res = ['connect'=>['ok'=>($codeBanner===220),'code'=>$codeBanner,'banner'=>$linesBanner],'server'=>compact('host','port','secure','transport')];
  if ($codeBanner!==220){ fclose($fp); resp(false, $res); }

  // EHLO
  list($codeEhlo, $linesEhlo) = send_cmd($fp, 'EHLO localhost');
  $res['ehlo'] = ['ok'=>($codeEhlo===250),'code'=>$codeEhlo,'lines'=>$linesEhlo];
  $features = implode("\n", $linesEhlo);

  // STARTTLS if requested (secure==='tls' or transport==='smtp' w/ tls)
  $wantStarttls = ($secure==='tls' || $secure==='starttls');
  if (!$useImplicit && $wantStarttls){
    // Issue STARTTLS if advertised
    if (stripos($features, 'STARTTLS')!==false){
      list($codeSt, $linesSt) = send_cmd($fp, 'STARTTLS');
      $okSt = ($codeSt===220);
      $res['starttls'] = ['ok'=>$okSt,'code'=>$codeSt,'lines'=>$linesSt];
      if ($okSt){
        $okTls = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $res['tls'] = ['ok'=> (bool)$okTls, 'error'=> $okTls? null : 'tls negotiate failed'];
        if (!$okTls){ fclose($fp); resp(false, $res); }
        // post-TLS EHLO
        list($codeEhlo2, $linesEhlo2) = send_cmd($fp, 'EHLO localhost');
        $res['ehlo_post_tls'] = ['ok'=>($codeEhlo2===250),'code'=>$codeEhlo2,'lines'=>$linesEhlo2];
      } else {
        fclose($fp); resp(false, $res);
      }
    } else {
      $res['starttls'] = ['ok'=>false, 'error'=>'server did not advertise STARTTLS'];
      fclose($fp); resp(false, $res);
    }
  }

  // AUTH test if credentials provided
  if ($user !== '' && $pass !== ''){
    // Try AUTH LOGIN
    list($codeAuth, $linesAuth) = send_cmd($fp, 'AUTH LOGIN');
    if ($codeAuth===334){
      list($c1,$l1) = send_cmd($fp, base64_encode($user));
      list($c2,$l2) = send_cmd($fp, base64_encode($pass));
      $ok = ($c2===235);
      $res['auth'] = ['ok'=>$ok, 'code'=>$c2, 'lines'=>array_merge($linesAuth,$l1,$l2)];
      // end session
      fwrite($fp, "QUIT\r\n");
      fclose($fp);
      $res['ok'] = $res['connect']['ok'] && ($res['ehlo']['ok'] ?? true) && ($res['starttls']['ok'] ?? true) && ($res['tls']['ok'] ?? true) && $ok;
      resp($res['ok'], $res);
    } else {
      $res['auth'] = ['ok'=>false, 'code'=>$codeAuth, 'lines'=>$linesAuth, 'error'=>'AUTH LOGIN not accepted'];
      fwrite($fp, "QUIT\r\n"); fclose($fp);
      resp(false, $res);
    }
  }

  // No auth, just a connectivity/TLS probe
  fwrite($fp, "QUIT\r\n"); fclose($fp);
  $res['ok'] = $res['connect']['ok'] && ($res['ehlo']['ok'] ?? true) && (($res['starttls']['ok'] ?? true) && ($res['tls']['ok'] ?? true));
  resp($res['ok'], $res);

} catch (Throwable $e){
  http_response_code(500);
  resp(false, ['error'=>$e->getMessage()]);
}
