<?php
require __DIR__.'/_guard.php';
guard_api(['key'=>'cron_list','require_token'=>false,'type'=>'json']);
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

function fn_enabled(string $name): bool {
  $disabled = array_filter(array_map('trim', explode(',', (string)@ini_get('disable_functions'))));
  return function_exists($name) && !in_array($name, $disabled, true);
}

if (!fn_enabled('exec')) {
  http_response_code(501);
  echo json_encode([
    'ok'=>false,
    'error'=>'exec unavailable on this PHP runtime',
  ]);
  exit;
}

$output = [];
$code = 0;
@exec('crontab -l 2>&1', $output, $code);
if ($code !== 0) {
  echo json_encode([
    'ok'=>false,
    'error'=>'Unable to read crontab (exit '.$code.')',
    'output'=>$output,
    'exit'=>$code,
  ]);
  exit;
}

$items = [];
foreach ($output as $raw) {
  $line = rtrim($raw, "\r\n");
  $trim = ltrim($line);
  if ($trim === '') {
    $items[] = ['raw'=>$line, 'type'=>'blank'];
    continue;
  }
  if ($trim[0] === '#') {
    $items[] = ['raw'=>$line, 'type'=>'comment'];
    continue;
  }
  if (strpos($trim, '=') !== false && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $trim)) {
    $items[] = ['raw'=>$line, 'type'=>'env'];
    continue;
  }
  $parts = preg_split('/\s+/', $trim, 6);
  if (count($parts) >= 6) {
    $schedule = implode(' ', array_slice($parts, 0, 5));
    $command  = $parts[5];
    $items[] = [
      'raw'=>$line,
      'type'=>'entry',
      'schedule'=>$schedule,
      'command'=>$command,
    ];
  } else {
    $items[] = ['raw'=>$line, 'type'=>'entry', 'schedule'=>null, 'command'=>$trim];
  }
}

echo json_encode([
  'ok'=>true,
  'items'=>$items,
  'count'=>count($items),
  'exit'=>$code,
  'user'=>get_current_user(),
]);
