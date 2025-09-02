<?php
// ─────────────────────────────────────────────────────────────────────────────
// Root config.php — central configuration UI (uses project header/footer)
// ─────────────────────────────────────────────────────────────────────────────

// Bootstrap + auth (same as the rest of the app)
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
$REQUIRE_ADMIN = true;   // head.php will enforce require_admin() for us

// Central config engine
require_once __DIR__ . '/lib/Config.php';
\App\Config::init(__DIR__);
$cfg = \App\Config::all();

// CSRF (session is already started in includes/init.php)
if (empty($_SESSION['csrf'])) {
  if(function_exists('random_bytes')){$_SESSION['csrf'] = bin2hex(random_bytes(16));}else{$_SESSION['csrf']=bin2hex(openssl_random_pseudo_bytes(16));}
}

// Handle JSON save
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'POST') {
  header('Content-Type: application/json');
  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  if (!isset($payload['_csrf']) || $payload['_csrf'] !== $_SESSION['csrf']) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
  }
  try {
    $saved = \App\Config::setMany($payload['settings'] ?? []);
    
    // Auto-create data/cron_token.txt for cron/probe endpoints if a token is present
    try {
      $cron = '';
      $sources = [];
      // Prefer values from saved merged config when available
      if (isset($saved) && is_array($saved)) {
        if (isset($saved['alerts']['cron_token']))  $sources[] = (string)$saved['alerts']['cron_token'];
        if (isset($saved['security']['cron_token']))$sources[] = (string)$saved['security']['cron_token'];
        if (isset($saved['site']['cron_token']))    $sources[] = (string)$saved['site']['cron_token'];
        if (isset($saved['cron']['token']))         $sources[] = (string)$saved['cron']['token'];
        if (isset($saved['api']['cron_token']))     $sources[] = (string)$saved['api']['cron_token'];
        if (isset($saved['history']['token']))      $sources[] = (string)$saved['history']['token'];
      }
      // Also consider raw payload if present
      if (isset($payload['settings']) && is_array($payload['settings'])) {
        $ps = $payload['settings'];
        if (isset($ps['alerts']['cron_token']))     $sources[] = (string)$ps['alerts']['cron_token'];
        if (isset($ps['security']['cron_token']))   $sources[] = (string)$ps['security']['cron_token'];
        if (isset($ps['site']['cron_token']))       $sources[] = (string)$ps['site']['cron_token'];
        if (isset($ps['cron']['token']))            $sources[] = (string)$ps['cron']['token'];
        if (isset($ps['api']['cron_token']))        $sources[] = (string)$ps['api']['cron_token'];
        if (isset($ps['history']['token']))         $sources[] = (string)$ps['history']['token'];
        if (isset($payload['CRON_TOKEN']))   $sources[] = (string)$payload['CRON_TOKEN'];
      }
      foreach ($sources as $val) { if (is_string($val) && $val !== '') { $cron = $val; break; } }
      if ($cron !== '') {
        $dataDir = __DIR__ . '/data';
        if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
        $file = $dataDir . '/cron_token.txt';
        $tmp  = $file . '.tmp';
        @file_put_contents($tmp, $cron . PHP_EOL);
        @chmod($tmp, 0640);
        @rename($tmp, $file);
      }
    } catch (\Throwable $e) {
      // Non-fatal: do not block save on token file failures
    }
echo json_encode(['ok'=>true,'config'=>$saved]); exit;
  } catch (Exception $e) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
  }
}

// Page-scoped variables for the standard layout
$PAGE_TITLE = 'Configuration';
$PAGE_JS    = 'assets/js/pages/config.page.js'; // head.php will include this ONE

// Pull the schema for initial render
$schema_path = __DIR__ . '/config/schema.php';
if (!is_file($schema_path)) {
  http_response_code(500);
  die("Missing schema at {$schema_path}. Expected /config/schema.php under project root.");
}
$schema = require $schema_path;

// Include standard header (nav, header, content wrapper)
include __DIR__ . '/includes/head.php';
?>

<!-- Page content inside the app’s normal layout -->
<div class="card">
  <div class="row between">
    <strong><div class="section-title">Configuration: </div></strong>
    <p><span class="muted small">Edits save to <code>config/local.json</code></span></p>
  </div>

  
<!-- UI polish: gentle vertical rhythm between rows under all tabs -->
<style id="config-row-spacing">
  /* Keep it scoped and non-destructive */
  #configTabs { margin-bottom: 8px; }
  /* Common case: rows rendered as .field blocks */
  #configPane .field + .field { margin-top: 8px !important; }
  #configPane fieldset .field + .field { margin-top: 8px !important; }
  /* Fallback: if some rows lack .field, add spacing between adjacent block children */
  #configPane > * + * { margin-top: 6px; }
  #configPane fieldset > * + * { margin-top: 6px; }
  /* Keep fieldsets from crowding */
  #configPane fieldset { margin: 10px 0 12px; padding-top: 2px; }
</style>

  <div id="configTabs" class="tabs"></div>
  <div id="configPane"></div>
  <!-- Ensure email.accounts is always included in saves (even if Email tab didn't run) -->
  <input type="hidden"
         id="persist_email_accounts"
         data-path='["email","accounts"]'
         value="<?= htmlspecialchars(\App\Config::get('email.accounts',''), ENT_QUOTES) ?>">


  <div class="row gap-sm" style="margin-top:12px">
    <button id="btnSave" class="btn primary">Save</button>
    <button id="btnReset" class="btn">Reset</button>
  </div>
</div>

<script>
  // Boot data for the page script
  window.__CONFIG_CSRF__  = <?= json_encode($_SESSION['csrf']) ?>;
  window.__CONFIG_SCHEMA__= <?= json_encode($schema) ?>;
  window.__CONFIG_DATA__  = <?= json_encode($cfg) ?>;
</script>
<?php
// Standard footer (footer text, toast auto-init, scripts, etc.)
include __DIR__ . '/includes/foot.php';
?>
