<?php
declare(strict_types=1);

// Writes a redacted copy of config/local.json for safe sharing/commits.
// Usage: php bin/redact-local-config.php [output-path]

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$in = $root . '/config/local.json';
$out = $argv[1] ?? ($root . '/config/local.redacted.json');

if (!is_file($in)) {
  fwrite(STDERR, "Missing config/local.json\n");
  exit(1);
}

$cfg = json_decode((string)file_get_contents($in), true);
if (!is_array($cfg)) {
  fwrite(STDERR, "Invalid JSON in config/local.json\n");
  exit(1);
}

$secretKeyRegex = '/(password|pass|secret|token|api[_-]?key|client_secret)/i';

$walk = function (&$node, $path = '') use (&$walk, $secretKeyRegex) {
  if (!is_array($node)) return;
  foreach ($node as $k => &$v) {
    $p = $path === '' ? (string)$k : ($path . '.' . $k);
    if (is_array($v)) {
      $walk($v, $p);
      continue;
    }
    if (preg_match($secretKeyRegex, (string)$k)) {
      $v = 'REDACTED';
      continue;
    }
    if (is_string($v) && preg_match('/^([A-Za-z0-9+\/=_-]{24,}|GOCSPX-|AIza|ya29\.)/', $v)) {
      $v = 'REDACTED';
    }
  }
};

$walk($cfg);
$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
  fwrite(STDERR, "Failed to encode redacted JSON\n");
  exit(1);
}
if (@file_put_contents($out, $json . PHP_EOL) === false) {
  fwrite(STDERR, "Failed to write {$out}\n");
  exit(1);
}
echo "Wrote redacted config: {$out}\n";
