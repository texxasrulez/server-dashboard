<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

function microtime_ms() { return (int)floor(microtime(true)*1000); }
function check_tcp($host,$port,$timeout=2){ $start=microtime_ms(); $fp=@fsockopen($host,(int)$port,$errno,$errstr,$timeout); $lat=microtime_ms()-$start; if(!$fp) return ['status'=>'down','latency_ms'=>$lat,'error'=>"$errno $errstr"]; fclose($fp); return ['status'=>'up','latency_ms'=>$lat]; }
function check_http($host,$port,$path='/',$timeout=3,$tls=false){
  $scheme=$tls?'https':'http'; $url=$scheme.'://'.$host.':'.$port.($path?:'/');
  $start=microtime_ms();
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"Connection: close\r\nUser-Agent: Dashboard\r\n"]]);
  $data=@file_get_contents($url,false,$ctx);
  $lat=microtime_ms()-$start;
  $code=null;
  if(isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#',$http_response_header[0],$m)) $code=(int)$m[1];
  $status = ($code && $code<500) ? 'up' : 'down';
  return ['status'=>$status,'latency_ms'=>$lat,'http_code'=>$code];
}
function check_ping($host,$timeout=1){ $cmd=sprintf('ping -c 1 -W %d %s 2>/dev/null',$timeout,escapeshellarg($host)); $start=microtime_ms(); @exec($cmd,$o,$code); $lat=microtime_ms()-$start; return ['status'=>$code===0?'up':'down','latency_ms'=>$lat]; }

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON body']); exit; }

$host = trim($payload['host'] ?? '');
$port = (int)($payload['port'] ?? 0);
$check= strtolower($payload['check'] ?? 'tcp');
$path = trim($payload['path'] ?? '/');
$timeout_ms = (int)($payload['timeout_ms'] ?? 800);
if ($timeout_ms<100) $timeout_ms = 100;
if ($timeout_ms>60000) $timeout_ms = 60000;

if ($host==='' || $port<=0) { http_response_code(422); echo json_encode(['error'=>'host and port required']); exit; }
if (!in_array($check,['tcp','http','ping'])) $check='tcp';
if ($check!=='http') $path='/';

$secs = max(1, (int)ceil($timeout_ms/1000));

if ($check==='http') $res=check_http($host,$port,$path,$secs);
else if ($check==='ping') $res=check_ping($host,$secs);
else $res=check_tcp($host,$port,$secs);

if (($res['status']??'')==='up' && ($res['latency_ms']??0) > $timeout_ms) $res['status']='warn';
echo json_encode($res);
