<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
header('Content-Type: text/html; charset=utf-8');
if (!user_is_admin()) { http_response_code(403); echo "Admin only."; exit; }

$PAGE = 'Diagnostics';

// Build absolute URL to metrics endpoint
$path = project_url('/api/metrics_summary.php') . '?trace=1&debug=1';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$API_URL = (preg_match('~^https?://~i', $path)) ? $path : ($scheme . '://' . $host . $path);

// Multi-mode fetcher: curl -> fopen -> include
function fetch_metrics($url, &$mode=''){
  // 1) curl
  if (function_exists('curl_init')){
    $mode='curl';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 3,
      CURLOPT_TIMEOUT => 5,
      CURLOPT_HEADER => true,
      CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $body = substr($resp ?: '', $hdrSize);
    curl_close($ch);
    if ($status) return [$body, $status];
  }
  // 2) fopen (allow_url_fopen)
  $mode='fopen';
  $ctx = stream_context_create(['http'=>['timeout'=>4,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
  $out = @file_get_contents($url, false, $ctx);
  $code = 0;
  if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
  if ($code) return [$out, $code];
  // 3) include fallback (no HTTP; capture output)
  $mode='include';
  $file = __DIR__ . '/api/metrics_summary.php';
  if (is_file($file)){
    // prevent JSON header from leaking
    ob_start();
    include $file;
    $body = ob_get_clean();
    // Reset to HTML
    header('Content-Type: text/html; charset=utf-8');
    return [$body, 200];
  }
  return [null, 0];
}

$mode = '';
list($out, $status) = fetch_metrics($API_URL, $mode);
$j = $out ? json_decode($out, true) : null;
$ok = is_array($j) && isset($j['memory']) && isset($j['disk']);

include __DIR__ . '/includes/head.php';
?>
<main class="container full diag">
  <h1 class="h2">Diagnostics</h1>
  <p class="muted">Build <strong><?= h(BUILD) ?></strong> &middot; User <strong><?= h($_SESSION['user']['username'] ?? 'guest') ?></strong> &middot; Theme <strong><?= h($THEME) ?></strong></p>

  <div class="grid diag-grid">
    <section class="card">
      <div class="card-title">Metrics API</div>
      <div class="card-body">
        <table class="kv small">
          <tr><th>Server-resolved URL</th><td><code><?= h($API_URL) ?></code></td></tr>
          <tr><th>Fetch mode</th><td><?= h($mode) ?></td></tr>
          <tr><th>HTTP status</th><td><strong><?= (int)$status ?></strong></td></tr>
          <tr><th>JSON decode</th><td><strong><?= $ok ? 'ok' : 'fail' ?></strong></td></tr>
        </table>

        <details class="mt-2 raw" open>
          <summary>Raw output (first 400 chars)</summary>
          <pre class="small raw-preview"><?= h(substr($out ?? '(no output)', 0, 400)) ?></pre>
        </details>
        <div class="btn-row mt-2"><a class="btn secondary" href="#" data-copy-raw="1">Copy raw JSON</a>
		<a class="btn secondary" href="api/metrics_summary.php?trace=1&amp;debug=1" target="_blank" rel="noopener" data-modal="1">Open metrics_summary.php</a></div>
		<p class="mt-2"><a class="btn secondary" href="tools/assets_audit.php" data-modal="1">Open Assets Audit</a></p>
      </div>
    </section>

    <section class="card">
      <div class="card-title">Users storage</div>
      <div class="card-body">
        <?php
          $u_path = defined('USERS_FILE') ? USERS_FILE : (__DIR__ . '/data/users.json');
          $u_real = @realpath($u_path) ?: $u_path;
          $u_exists = file_exists($u_path);
          $u_dir = dirname($u_path);
          $u_dir_w = is_writable($u_dir);
          $u_file_w = $u_exists ? is_writable($u_path) : $u_dir_w;
          $u_size = $u_exists ? filesize($u_path) : 0;
          $u_mtime = $u_exists ? date('Y-m-d H:i:s', filemtime($u_path)) : '—';
        ?>
        <table class="kv small">
          <tr><th>Path</th><td><code><?= h($u_real) ?></code></td></tr>
          <tr><th>Directory writable</th><td><strong><?= $u_dir_w ? 'yes' : 'no' ?></strong></td></tr>
          <tr><th>File</th><td>exists: <strong><?= $u_exists ? 'yes' : 'no' ?></strong>; writable: <strong><?= $u_file_w ? 'yes' : 'no' ?></strong></td></tr>
          <tr><th>Size / Updated</th><td><?= number_format($u_size) ?> bytes; <?= h($u_mtime) ?></td></tr>
        </table>
      </div>
    </section>
  </div>
  <script defer src="assets/js/pages/diag.js"></script>
</main>
<link rel="stylesheet" href="assets/css/page_diag.css" />
<script defer src="assets/js/page_diag.js"></script>
<?php include __DIR__ . '/includes/foot.php'; ?>
