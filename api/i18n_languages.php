<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$root = dirname(__DIR__);
$dir = $root . '/assets/i18n';
$out = [];
if (is_dir($dir)) {
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    if (substr($f, -5) === '.json') {
      $code = substr($f, 0, -5);
      if ($code !== '') $out[] = ['code'=>$code, 'name'=>strtoupper($code)];
    }
  }
}
usort($out, function($a,$b){ return strcmp($a['code'],$b['code']); });
echo json_encode(['ok'=>true, 'languages'=>$out]);