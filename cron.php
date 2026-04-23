<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

require_once __DIR__ . '/lib/Config.php';
\App\Config::init(__DIR__);
$baseUrl = rtrim((string)\App\Config::get('site.base_url', project_url('/')), '/');
if ($baseUrl === '') {
    $baseUrl = project_url('/');
}
$alertsEvalBin = realpath(__DIR__ . '/bin/alerts-eval.php') ?: (__DIR__ . '/bin/alerts-eval.php');
$cronHeartbeatBin = realpath(__DIR__ . '/bin/cron-heartbeat.php') ?: (__DIR__ . '/bin/cron-heartbeat.php');
$heartbeatApi = $baseUrl . '/api/cron_heartbeat.php';
$cronPageJsVersion = is_file(__DIR__ . '/assets/js/pages/cron.js')
    ? (string)filemtime(__DIR__ . '/assets/js/pages/cron.js')
    : (string)BUILD;

$PAGE_TITLE = 'Cron Health';
$PAGE_CSS   = 'assets/css/pages/cron.css';
$REQUIRE_ADMIN = true;
include __DIR__ . '/includes/head.php';
?>

<div id="cronRoot"
     data-heartbeat-url="<?= h($heartbeatApi) ?>"
     data-base-url="<?= h($baseUrl) ?>"
     data-alerts-eval-bin="<?= h($alertsEvalBin) ?>"
     data-cron-heartbeat-bin="<?= h($cronHeartbeatBin) ?>">

  <div class="card">
    <div class="row between align-center">
      <div>
        <div class="section-title">Cron Health</div>
        <p class="muted" id="cronStatusNote">Waiting for first refresh…</p>
      </div>
      <div class="row gap">
        <button class="btn secondary" id="cronCopyAll">Copy all commands</button>
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
       data-heartbeat-api="<?= h($heartbeatApi) ?>">
    <div class="section-title">Crontab helper</div>
    <p class="muted small">Adjust intervals to generate sample wrapper commands for alerts, history, and remote heartbeats.</p>
    <div class="wizard-grid">
      <label>Alerts interval (min)
        <input type="number" id="wizardAlertsEvery" min="1" value="<?= (int)\App\Config::get('alerts.cron_interval_min', 10) ?>">
      </label>
      <label>History interval (min)
        <input type="number" id="wizardHistoryEvery" min="1" value="<?= (int)\App\Config::get('history.append_interval_min', 5) ?>">
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
        <code id="wizardAlertsLine" class="code muted">*/10 * * * * php <?= h(escapeshellarg($alertsEvalBin)) ?></code>
        <button class="btn secondary" data-copy-wizard="alerts">Copy command</button>
      </div>
      <div>
        <div class="muted small">History sampler</div>
        <code id="wizardHistoryLine" class="code muted">*/5 * * * * php <?= h(escapeshellarg($alertsEvalBin)) ?> --probe=1</code>
        <button class="btn secondary" data-copy-wizard="history">Copy command</button>
      </div>
      <div>
        <div class="muted small">Custom job heartbeat</div>
        <code id="wizardJobLine" class="code muted">*/5 * * * * php <?= h(escapeshellarg($cronHeartbeatBin)) ?> --id=custom_job</code>
        <button class="btn secondary" data-copy-wizard="job">Copy command</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Snippets</div>
    <p class="muted small">Copy the current wrapper commands as generated below.</p>
    <div class="snip-grid">
      <div class="snip" data-type="alerts">
        <div class="muted small">Alerts evaluator</div>
        <code id="cronCurlAlerts" class="code muted">php <?= h(escapeshellarg($alertsEvalBin)) ?></code>
        <button class="btn secondary" data-copy="alerts">Copy command</button>
        <button class="btn ghost" data-ping="alerts">Ping now</button>
      </div>
      <div class="snip" data-type="history">
        <div class="muted small">History sampler</div>
        <code id="cronCurlHistory" class="code muted">php <?= h(escapeshellarg($alertsEvalBin)) ?> --probe=1</code>
        <button class="btn secondary" data-copy="history">Copy command</button>
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

<script defer src="assets/js/pages/cron.js?v=<?= h($cronPageJsVersion) ?>"></script>

<?php include __DIR__ . '/includes/foot.php'; ?>
