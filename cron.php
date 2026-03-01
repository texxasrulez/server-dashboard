<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

require_once __DIR__ . '/lib/Config.php';
\App\Config::init(__DIR__);

function cron_token_lookup(): string {
  $paths = [
    'alerts.cron_token',
    'security.cron_token',
    'site.cron_token',
    'cron.token',
    'api.cron_token',
    'history.token',
  ];
  foreach ($paths as $path) {
    $val = (string)\App\Config::get($path, '');
    if ($val !== '') return $val;
  }
  if (defined('CRON_TOKEN')) return (string) CRON_TOKEN;
  foreach (cron_token_candidates() as $cand) {
    if ($cand !== '') return $cand;
  }
  return '';
}

$cronToken = cron_token_lookup();
$baseUrl = rtrim((string)\App\Config::get('site.base_url', project_url('/')), '/');
if ($baseUrl === '') { $baseUrl = project_url('/'); }
$alertUrl = $baseUrl . '/api/cron_mark.php?what=alerts';
$historyUrl = $baseUrl . '/api/cron_mark.php?what=history';
$heartbeatApi = $baseUrl . '/api/cron_heartbeat.php';
$alertsEval = $baseUrl . '/api/alerts_eval.php';

$PAGE_TITLE = 'Cron Health';
$PAGE_CSS   = 'assets/css/pages/cron.css';
$REQUIRE_ADMIN = true;
include __DIR__ . '/includes/head.php';
?>

<div id="cronRoot"
     data-token="<?= h($cronToken) ?>"
     data-alert-url="<?= h($alertUrl) ?>"
     data-history-url="<?= h($historyUrl) ?>"
     data-heartbeat-url="<?= h($heartbeatApi) ?>"
     data-alerts-eval="<?= h($alertsEval) ?>"
     data-history-eval="<?= h($alertsEval) ?>"
     data-base-url="<?= h($baseUrl) ?>">

  <div class="card">
    <div class="row between align-center">
      <div>
        <div class="section-title">Cron Health</div>
        <p class="muted" id="cronStatusNote">Waiting for first refresh…</p>
      </div>
      <div class="row gap">
        <button class="btn secondary" id="cronCopyAll">Copy all cURL</button>
        <button class="btn" id="cronRefresh">Refresh</button>
      </div>
    </div>
    <div class="cron-grid" id="cronCore">
      <div class="cron-card skeleton" data-core="alerts">
        <div class="muted small">Loading alerts…</div>
      </div>
      <div class="cron-card skeleton" data-core="history">
        <div class="muted small">Loading history…</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="row between align-center">
      <div class="section-title">Custom jobs & heartbeats</div>
      <p class="muted" id="cronJobsHint">Define JSON under Config → History (`cron.jobs`).</p>
    </div>
    <div id="cronJobsList" class="cron-jobs empty">
      <div class="muted small">No custom jobs defined yet.</div>
    </div>
  </div>

  <div class="card" id="cronWizard"
       data-base-url="<?= h($baseUrl) ?>"
       data-alerts-eval="<?= h($alertsEval) ?>"
       data-history-eval="<?= h($alertsEval) ?>"
       data-heartbeat-api="<?= h($heartbeatApi) ?>"
       data-token="<?= h($cronToken) ?>">
    <div class="section-title">Crontab helper</div>
    <p class="muted small">Adjust intervals to generate sample `curl` commands for alerts, history, and remote heartbeats.</p>
    <div class="wizard-grid">
      <label>Alerts interval (min)
        <input type="number" id="wizardAlertsEvery" min="1" value="<?= (int)\App\Config::get('alerts.cron_interval_min', 10) ?>">
      </label>
      <label>History interval (min)
        <input type="number" id="wizardHistoryEvery" min="1" value="<?= (int)\App\Config::get('history.append_interval_min', 5) ?>">
      </label>
      <label>Alerts limit
        <input type="number" id="wizardLimit" min="100" step="100" value="5000">
      </label>
      <label>Job ID / heartbeat tag
        <input type="text" id="wizardJobId" value="custom_job">
      </label>
      <label>Job interval (min)
        <input type="number" id="wizardJobEvery" min="1" value="5">
      </label>
    </div>
    <div class="wizard-output">
      <div>
        <div class="muted small">Alerts evaluator</div>
        <code id="wizardAlertsLine" class="code muted">—</code>
        <button class="btn secondary" data-copy-wizard="alerts">Copy line</button>
      </div>
      <div>
        <div class="muted small">History sampler</div>
        <code id="wizardHistoryLine" class="code muted">—</code>
        <button class="btn secondary" data-copy-wizard="history">Copy line</button>
      </div>
      <div>
        <div class="muted small">Custom job heartbeat</div>
        <code id="wizardJobLine" class="code muted">—</code>
        <button class="btn secondary" data-copy-wizard="job">Copy line</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Token & snippets</div>
    <div class="token-block">
      <label class="muted">Active cron token</label>
      <div class="token-row">
        <input type="<?= $cronToken ? 'password' : 'text' ?>" value="<?= h($cronToken ?: 'Not set') ?>" readonly id="cronTokenField" />
        <button class="btn secondary" id="cronTokenToggle" <?= $cronToken ? '' : 'disabled' ?>><?= $cronToken ? 'Show' : 'No token' ?></button>
        <button class="btn secondary" id="cronTokenCopy" <?= $cronToken ? '' : 'disabled' ?>>Copy token</button>
      </div>
    </div>
    <div class="snip-grid">
      <div class="snip" data-type="alerts">
        <div class="muted small">Alerts evaluator</div>
        <code id="cronCurlAlerts" class="code muted">curl …</code>
        <button class="btn secondary" data-copy="alerts">Copy cURL</button>
        <button class="btn ghost" data-ping="alerts">Ping now</button>
      </div>
      <div class="snip" data-type="history">
        <div class="muted small">History sampler</div>
        <code id="cronCurlHistory" class="code muted">curl …</code>
        <button class="btn secondary" data-copy="history">Copy cURL</button>
        <button class="btn ghost" data-ping="history">Ping now</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="row between align-center">
      <div class="section-title">Server crontab</div>
      <button class="btn secondary" id="crontabRefresh">Refresh</button>
    </div>
    <p class="muted small" id="crontabNote">Reading crontab for current user.</p>
    <div id="crontabList" class="crontab-list">
      <div class="muted small">Loading…</div>
    </div>
  </div>
</div>

<script defer src="assets/js/pages/cron.js?v=<?= h(BUILD) ?>"></script>

<?php include __DIR__ . '/includes/foot.php'; ?>
