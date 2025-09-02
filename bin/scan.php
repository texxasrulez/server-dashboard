#!/usr/bin/env php
<?php
// bin/scan.php — CLI quick scan + optional alert send via alerts config
// Usage: php bin/scan.php [--no-send]

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/ServerDiag.php';

\App\Config::init(dirname(__DIR__));

$cfg = \App\Config::all();
$alerts = $cfg['alerts'] ?? [];
$enabled = !empty($alerts['enabled']);
$noSend = in_array('--no-send', $argv, true);

$items = \App\ServerDiag::scan_quick();
$score = \App\ServerDiag::score($items);

$rank = function($s){ $s=strtolower((string)$s); return $s==='fail'?2:($s==='warn'?1:0); };
$min = strtolower((string)($alerts['min_severity'] ?? 'warn'));
$minRank = ($min==='fail')?2:1;
$bad = array_values(array_filter($items, function($r) use ($rank, $minRank){ return $rank($r['status'] ?? 'warn') >= $minRank; }));

echo json_encode(['ok'=>true,'score'=>$score,'bad'=>count($bad),'items'=>$items], JSON_PRETTY_PRINT), PHP_EOL;

if ($noSend || !$enabled || count($bad) === 0) exit(0);

// Quiet hours skip
$quiet = trim((string)($alerts['quiet_hours'] ?? ''));
if ($quiet !== '') {
  $parts = explode('-', $quiet);
  if (count($parts) === 2) {
    $now = new DateTime('now');
    $start = DateTime::createFromFormat('H:i', $parts[0]) ?: null;
    $end   = DateTime::createFromFormat('H:i', $parts[1]) ?: null;
    if ($start && $end) {
      $s = (int)$start->format('Hi'); $e = (int)$end->format('Hi'); $n = (int)$now->format('Hi');
      $in = ($s <= $e) ? ($n >= $s && $n <= $e) : ($n >= $s || $n <= $e);
      if ($in) { fwrite(STDERR, "suppressed within quiet hours\n"); exit(0); }
    }
  }
}

// Send webhook if set
$sent = false;
$wh = trim((string)($alerts['webhook_url'] ?? ''));
if ($wh !== '') {
  $payload = json_encode(['time'=>date('c'),'score'=>$score,'bad_count'=>count($bad),'min_severity'=>$min,'items'=>$bad]);
  $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>5]]);
  @file_get_contents($wh, false, $ctx);
  $sent = true;
}

// Send email if set
$em = trim((string)($alerts['email'] ?? ''));
if ($em !== '') {
  $subj = '[ServerDiag] ' . count($bad) . ' issue(s)';
  $lines = [];
  foreach ($bad as $r) { $lines[] = sprintf('- %s: %s (%s)', $r['name'], $r['status'], $r['details'] ?? ''); }
  $body = "Time: " . date('c') . "\nScore: " . round($score*100) . "%\nMin severity: ".$min."\n\n" . implode("\n", $lines) . "\n";
  @mail($em, $subj, $body);
  $sent = true;
}

exit($sent ? 0 : 0);
