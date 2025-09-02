<?php
require_once __DIR__ . '/../includes/init.php';
$url = $_GET['url'] ?? '';
$host = $_GET['host'] ?? '';

if ($url && !$host) {
  $u = @parse_url($url);
  $host = $u && isset($u['host']) ? $u['host'] : '';
}
$host = strtolower(trim($host));

$dataDir = __DIR__ . '/../data/favicons';
@mkdir($dataDir, 0775, true);
$cacheFile = $dataDir . '/' . preg_replace('/[^a-z0-9\.-]/i','_', $host?:'default') . '.ico';
$default = __DIR__ . '/../assets/img/default_favicon.png';

function out_png($file){
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=86400');
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
  if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    out_ico($cacheFile);
  }
  $srcs = [
    'https://'.$host.'/favicon.ico',
    'http://'.$host.'/favicon.ico'
  ];
  foreach ($srcs as $src) {
    $ctx = stream_context_create(['http'=>['timeout'=>4, 'follow_location'=>1, 'ignore_errors'=>true]]);
    $data = @file_get_contents($src, false, $ctx);
    if ($data && strlen($data) > 0) {
      @file_put_contents($cacheFile, $data);
      out_ico($cacheFile);
    }
  }
}

out_png($default);
