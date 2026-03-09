<?php
require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_login();

// per-session CSRF token for this page
$csrf_token = csrf_token();

// FS-level backup presence check under configured root/subdirs
$HAS_ANY_BACKUP_FS = false;
$backup_root = (string) cfg_local('backups.fs_root', '/mnt/backupz');
if ($backup_root === '') {
    $backup_root = '/mnt/backupz';
}
$hestia_source_dir = (string) cfg_local('backups.hestia_source_dir', '/backup');
if ($hestia_source_dir === '') {
    $hestia_source_dir = '/backup';
}
$hestia_bind_source = (string) cfg_local('backups.hestia_bind_source', '');
$hestia_bind_target = (string) cfg_local('backups.hestia_bind_target', $hestia_source_dir);
if ($hestia_bind_target === '') {
    $hestia_bind_target = $hestia_source_dir;
}
$hestia_bind_options = (string) cfg_local('backups.hestia_bind_options', 'bind,nofail');
if ($hestia_bind_options === '') {
    $hestia_bind_options = 'bind,nofail';
}
$hestia_user = (string) cfg_local('backups.hestia_user', 'user');
if ($hestia_user === '') {
    $hestia_user = 'user';
}
$backup_script_path = (string) cfg_local('backups.script_path', __DIR__ . '/scripts');
if ($backup_script_path === '') {
    $backup_script_path = __DIR__ . '/scripts';
}
$dirs_raw = (string) cfg_local('backups.fs_dirs', "hestia\nmicro\nsnapshots");
$backup_dirs = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $dirs_raw) ?: [])));
if (!$backup_dirs) {
    $backup_dirs = ['hestia','micro','snapshots'];
}

$backup_orchestrator_defaults = [
    'backup_root'     => $backup_root,
    'script_path'     => $backup_script_path,
    'snap_script'     => (string) cfg_local('backups.snap_script', rtrim($backup_script_path, '/') . '/make-snapshots.sh'),
    'micro_script'    => (string) cfg_local('backups.micro_script', rtrim($backup_script_path, '/') . '/make-micro-backups.sh'),
    'hestia_cmd'      => (string) cfg_local('backups.hestia_cmd', '/usr/local/hestia/bin/v-backup-user'),
    'hestia_user'     => $hestia_user,
    'hestia_source_dir' => $hestia_source_dir,
    'hestia_bind_source' => $hestia_bind_source,
    'hestia_bind_target' => $hestia_bind_target,
    'hestia_bind_options' => $hestia_bind_options,
    'exclude_dirs'    => (string) cfg_local('backups.exclude_dirs', ''),
    'backupctl'       => (string) cfg_local('backups.backupctl', rtrim($backup_script_path, '/') . '/backupctl'),
    'pipeline_script' => (string) cfg_local('backups.pipeline_script', '/usr/local/bin/backup-nightly.sh'),
    'log_file'        => (string) cfg_local('backups.log_file', '/var/log/backup-nightly.log'),
    'cron_time'       => (string) cfg_local('backups.cron_time', '02:00'),
    'service_name'    => (string) cfg_local('backups.service_name', 'backup-nightly'),
    'system_user'     => (string) cfg_local('backups.system_user', 'root'),
    'include_health'     => cfg_local('backups.include_health', true),
    'include_integrity' => cfg_local('backups.include_integrity', true),
    'include_prune'      => cfg_local('backups.include_prune', true),
    'suspend'            => cfg_local('backups.suspend', false),
    'disable_on_mount_fail' => cfg_local('backups.disable_on_mount_fail', cfg_local('backups.require_dedicated_mount', false)),
];
$backup_orchestrator_json = json_encode($backup_orchestrator_defaults, JSON_UNESCAPED_SLASHES);

foreach ($backup_dirs as $sub) {
    $p = $backup_root . '/' . $sub;
    if (is_dir($p)) {
        try {
            $it = new FilesystemIterator($p, FilesystemIterator::SKIP_DOTS);
            if ($it->valid()) {
                $HAS_ANY_BACKUP_FS = true;
                break;
            }
        } catch (Throwable $e) {
            // If we can't iterate, treat as no backups in that dir and move on
        }
    }
}

$initial_status = 'UNKNOWN';
$initial_status_class = 'status-crit';
$initial_disk_usage = '--%';
$initial_disk_status = 'Unknown';
try {
    $status_file = __DIR__ . '/state/backup_status.json';
    if (is_readable($status_file)) {
        $raw = @file_get_contents($status_file);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j)) {
            $raw_status = strtoupper(trim((string)($j['status'] ?? '')));
            $mount_ok = !array_key_exists('backup_mount_ok', $j) || ($j['backup_mount_ok'] !== false);
            $warnings = (isset($j['warnings']) && is_array($j['warnings'])) ? $j['warnings'] : [];
            $errors = (isset($j['errors']) && is_array($j['errors'])) ? $j['errors'] : [];
            $usage = null;
            if (isset($j['disk']) && is_array($j['disk']) && isset($j['disk']['usage_percent']) && is_numeric($j['disk']['usage_percent'])) {
                $usage = (int)$j['disk']['usage_percent'];
            }
            if ($usage !== null) {
                $initial_disk_usage = $usage . '%';
            }

            if (in_array($raw_status, ['OK','HEALTHY','PASS'], true)) {
                $initial_status = 'OK';
            } elseif (in_array($raw_status, ['WARN','WARNING','DEGRADED'], true)) {
                $initial_status = 'WARN';
            } elseif (in_array($raw_status, ['CRIT','CRITICAL','FAIL','ERROR'], true)) {
                $initial_status = 'CRIT';
            } else {
                if ($mount_ok === false || count($errors) > 0 || ($usage !== null && $usage >= 95)) {
                    $initial_status = 'CRIT';
                } elseif (count($warnings) > 0 || ($usage !== null && $usage >= 80)) {
                    $initial_status = 'WARN';
                } else {
                    $initial_status = 'OK';
                }
            }

            if ($mount_ok === false) {
                $initial_disk_status = 'UNMOUNTED';
            } elseif ($usage !== null) {
                if ($usage >= 95) {
                    $initial_disk_status = 'CRITICAL';
                } elseif ($usage >= 90) {
                    $initial_disk_status = 'HIGH';
                } elseif ($usage >= 80) {
                    $initial_disk_status = 'ELEVATED';
                } else {
                    $initial_disk_status = 'HEALTHY';
                }
            }
        }
    }
} catch (Throwable $e) {
    // Keep UNKNOWN fallback.
}
if ($initial_status === 'OK') {
    $initial_status_class = 'status-ok';
} elseif ($initial_status === 'WARN') {
    $initial_status_class = 'status-warn';
}

$PAGE_TITLE = 'Backups';
$PAGE_CSS   = null; // Using inline styles for this page

include __DIR__.'/includes/head.php';
?>

<!-- CSRF token for JS -->
<meta name="csrf-token" content="<?= h($csrf_token) ?>">
<!-- FS-level "any backup present?" flag -->
<meta name="backup-any-fs" content="<?= $HAS_ANY_BACKUP_FS ? '1' : '0' ?>">

<style>
/* Backups page: sit flush under the main app header */
.content {
  padding-top: 0 !important;
}

.backup-root {
  --accent-ok: var(--ok, #4caf50);
  --accent-warn: var(--warn, #ff9800);
  --accent-crit: var(--error, #f44336);
  --accent-info: var(--info, #3b82f6);
  --text-main: var(--fg, #e6eef2);
  --text-muted: var(--muted, #9aa3ad);
  --border-soft: var(--border, #2a2f36);
}

h1, h2, h3 {
  margin: 0 0 8px 0;
  font-weight: 600;
}
h1 {
  font-size: 20px;
}
.subtitle {
  color: var(--text-muted);
  font-size: 13px;
  margin-bottom: 8px;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.status-ok {
  background: color-mix(in srgb, var(--accent-ok) 10%, transparent);
  color: var(--accent-ok);
  border: 1px solid color-mix(in srgb, var(--accent-ok) 55%, transparent);
}
.status-warn {
  background: color-mix(in srgb, var(--accent-warn) 10%, transparent);
  color: var(--accent-warn);
  border: 1px solid color-mix(in srgb, var(--accent-warn) 55%, transparent);
}
.status-crit {
  background: color-mix(in srgb, var(--accent-crit) 10%, transparent);
  color: var(--accent-crit);
  border: 1px solid color-mix(in srgb, var(--accent-crit) 55%, transparent);
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.card-title {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
}
.card-main {
  font-size: 24px;
  font-weight: 600;
}
.card-sub {
  font-size: 12px;
  color: var(--text-muted);
}

.pill-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}

.kpi-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-top: 6px;
}
@media (max-width: 900px) {
  .kpi-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
.kpi {
  padding: 4px 6px;
  border-radius: 10px;
  background: color-mix(in srgb, var(--card, #171a21) 80%, transparent);
  border: 1px solid var(--border-soft);
}
.kpi-size {
  width: 100%;
}
.kpi-ages {
  width: 100%;
  height: 75px;
}
.kpi-label {
  font-weight: bold;
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 3px;
}
.kpi-value {
  font-size: 15px;
  font-weight: 600;
}
.kpi-sub {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 2px;
}

.kpi-ok {
  color: var(--accent-ok);
}
.kpi-warn {
  color: var(--accent-warn);
}
.kpi-crit {
  color: var(--accent-crit);
}

.flex {
  display: flex;
  align-items: center;
  gap: 8px;
}
.flex-space {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

canvas {
  max-width: 100%;
}

/* Scrollable list blocks and JSON panes */
.list-block {
  max-height: 150px;
  overflow-y: auto;
  font-size: 12px;
  background: color-mix(in srgb, var(--card, #171a21) 90%, transparent);
  border-radius: 10px;
  padding: 8px;
  border: 1px solid var(--border-soft);
}
.list-block ul {
  margin: 0;
  padding-left: 16px;
}
.list-block li {
  margin-bottom: 3px;
}

.tag {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  border: 1px solid var(--border-soft);
  color: var(--text-muted);
}

.timestamp {
  font-size: 11px;
  color: var(--text-muted);
}

.badge-disk-ok { color: var(--accent-ok); }
.badge-disk-warn { color: var(--accent-warn); }
.badge-disk-crit { color: var(--accent-crit); }

.backup-actions {
  margin-top: 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.btn-backup {
  border-radius: 999px;
  border: 1px solid var(--border, rgba(255,255,255,0.12));
  background: var(--card, rgba(255,255,255,0.03));
  color: var(--fg, #f5f5f5);
  font-size: 12px;
  padding: 6px 12px;
  cursor: pointer;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.btn-backup:hover {
  background: color-mix(in srgb, var(--card, #171a21) 85%, transparent);
}
.btn-backup:disabled {
  opacity: 0.6;
  cursor: default;
}
.backup-status-msg {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
}

/* Sticky local header that matches the global background */
.backup-root > .backup-header-shell {
  margin-top: 0;
}
.backup-header-shell {
  position: sticky;
  top: 0;
  z-index: 20;
  margin: 0 0 12px;
  padding: 0;
  background: var(--bg, #0f1115);
  border-bottom: 0;
}

/* Recent actions list */
#recent-actions-list {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 12px;
}
#recent-actions-list li {
  padding: 4px 0;
  border-bottom: 1px solid rgba(255,255,255,0.04);
}
#recent-actions-list li:last-child {
  border-bottom: none;
}
.action-ts {
  color: var(--text-muted);
  font-size: 11px;
  margin-right: 6px;
}
.action-ok {
  color: var(--accent-ok);
}
.action-fail {
  color: var(--accent-crit);
}
.action-meta {
  color: var(--text-muted);
  font-size: 11px;
}

/* Disk legend under donut */
.disk-legend {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
}
.disk-legend-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 2px;
}
.disk-legend-left {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.disk-legend-color {
  width: 10px;
  height: 10px;
  border-radius: 2px;
  flex: 0 0 auto;
}
.disk-legend-label {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.disk-legend-size {
  white-space: nowrap;
  text-align: right;
  flex: 0 0 auto;
}

#backupOrchestrator .orch-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:12px;
  margin-top:12px;
}
#backupOrchestrator label{
  display:flex;
  flex-direction:column;
  gap:4px;
  font-size:12px;
  color:var(--text-muted);
}
#backupOrchestrator input,
#backupOrchestrator textarea{
  border:1px solid var(--border-soft);
  border-radius:10px;
  padding:.45rem .6rem;
  background:color-mix(in srgb,var(--card, #171a21) 92%, transparent);
  color:var(--fg);
}
#backupOrchestrator .orch-flags{
  display:flex;
  flex-wrap:wrap;
  gap:12px;
  margin-top:12px;
  font-size:12px;
  color:var(--text-muted);
}
#backupOrchestrator .orch-flags input[type=checkbox]{
  width:16px;
  height:16px;
  accent-color:var(--accent-info, #3b82f6);
}
#backupOrchestrator .orch-output{
  margin-top:16px;
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:12px;
}
#backupOrchestrator .orch-block{
  border:1px solid var(--border-soft);
  border-radius:12px;
  padding:10px;
  background:color-mix(in srgb,var(--card, #171a21) 94%, transparent);
}
#backupOrchestrator pre{
  background:rgba(0,0,0,0.25);
  border-radius:8px;
  padding:10px;
  font-size:12px;
  min-height:60px;
  white-space:pre-wrap;
  word-break:break-all;
}

.collapsible-toggle{
  border:1px solid var(--border-soft);
  background:color-mix(in srgb,var(--card, #171a21) 92%, transparent);
  color:var(--fg);
  border-radius:8px;
  min-width:32px;
  height:28px;
  padding:0 8px;
  cursor:pointer;
  font-size:13px;
  line-height:1;
}
.collapsible-card.is-collapsed .collapsible-body{
  display:none;
}
</style>

<div class="backup-root">
  <div class="backup-header-shell">
    <div class="h-card">
      <header>
        <div class="flex-space">
          <div>
            <h1 data-i18n="backups.page.title">Backup Control Center</h1>
            <div class="subtitle">
              <span data-i18n="backups.page.subtitle_prefix">Live status driven by</span> <code>backup_status.json</code> <span data-i18n="backups.page.subtitle_suffix">on your backup disk.</span>
            </div>
            <div class="subtitle" id="freshness-summary">
              <span data-i18n="backups.page.freshness_prefix">Freshness:</span> --
            </div>

            <!-- Backup action buttons -->
            <div class="backup-actions">
              <button class="btn-backup" data-action="os_snapshot" data-i18n="backups.actions.run_os_snapshot">Run OS Snapshot</button>
              <button class="btn-backup" data-action="micro_backup" data-i18n="backups.actions.run_micro_backup">Run Micro Backup</button>
              <button class="btn-backup" data-action="hestia_user" data-i18n="backups.actions.run_hestia_backup_user">Run Hestia Backup (user)</button>
              <button class="btn-backup" data-action="all_backups" data-i18n="backups.actions.run_all_backups">Run ALL Backups</button>
              <button class="btn-backup" data-action="health_check" data-i18n="backups.actions.run_health_check">Run Health Check</button>
            </div>

            <!-- Log maintenance buttons -->
            <div style="margin-top:8px; font-size:11px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.08em;">
              <span data-i18n="backups.actions.log_maintenance">Log maintenance</span>
            </div>
            <div class="backup-actions">
              <button class="btn-backup" data-action="clear_backup_logs" data-i18n="backups.actions.clear_backup_logs">Clear backup-health logs</button>
              <button class="btn-backup" data-action="clear_prune_logs" data-i18n="backups.actions.clear_prune_logs">Clear prune logs</button>
              <button class="btn-backup" data-action="clear_integrity_log" data-i18n="backups.actions.clear_integrity_log">Clear integrity log</button>
            </div>

            <div id="backup-action-status" class="backup-status-msg"></div>
          </div>
          <div class="flex" style="gap: 12px;">
            <div class="timestamp" id="timestamp"><span data-i18n="backups.common.timestamp">Timestamp:</span> --</div>
            <div id="status-pill" class="status-pill <?= h($initial_status_class) ?>"><span data-i18n="backups.common.status">Status:</span> <span><?= h($initial_status) ?></span></div>
          </div>
        </div>
      </header>
    </div>
  </div>

<div class="card">
<section class="grid" style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">
  <div class="card" style="flex:0 0 39%; min-width:260px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.disk.title">Disk Utilization</div>
        <div class="card-sub"><?= h($backup_root) ?> capacity &amp; health</div>
      </div>
      <span class="tag" id="disk-tag"><span data-i18n="backups.disk.tag">Disk:</span> --</span>
    </div>
    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
      <div style="flex:0 0 200px;">
        <canvas id="diskChart" width="175" height="175"></canvas>
      </div>
      <div style="flex:1 1 0;">
        <div class="kpi-row">
          <div class="kpi kpi-size">
            <div class="kpi-label" data-i18n="backups.disk.usage">Usage</div>
            <div class="kpi-value" id="disk-usage"><?= h($initial_disk_usage) ?></div>
          </div>
          <div class="kpi kpi-size">
            <div class="kpi-label" data-i18n="backups.disk.mount">Mount</div>
            <div class="kpi-value" style="font-size:13px;"><?= h($backup_root) ?></div>
          </div>
          <div class="kpi kpi-size">
            <div class="kpi-label" data-i18n="backups.common.status">Status</div>
            <div class="kpi-value" id="disk-status-label"><?= h($initial_disk_status) ?></div>
          </div>
        </div>
        <div style="margin-top:6px;">
          <pre id="disk-df" style="margin:0; font-size:10px; max-height:70px; overflow:auto;"></pre>
        </div>
        <div class="kpi-sub" id="disk-sizes-updated"><span data-i18n="backups.disk.sizes_updated">Sizes updated:</span> --</div>
        <div id="disk-legend" class="disk-legend"></div>
      </div>
    </div>
  </div>

  <div class="card" style="flex:0 0 60%; min-width:200px%;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.ages.title">Backup Ages</div>
        <div class="card-sub" data-i18n="backups.ages.subtitle">How fresh your latest backups are</div>
      </div>
    </div>
    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
      <!-- Narrow KPI strip -->
      <div style="flex:0 0 200px; max-width:400px;">
        <div class="kpi-row" style="grid-template-columns:1fr; gap:6px;">
          <div class="kpi kpi-ages">
            <div class="kpi-label" data-i18n="backups.ages.snapshot_daily0">Snapshot daily.0</div>
            <div class="kpi-value" id="age-snap">-- d</div>
            <div class="kpi-sub" id="snap-count"></div>
          </div>
          <div class="kpi kpi-ages">
            <div class="kpi-label" data-i18n="backups.ages.latest_hestia">Latest Hestia</div>
            <div class="kpi-value" id="age-hestia">-- d</div>
            <div class="kpi-sub" id="hestia-count"></div>
          </div>
          <div class="kpi kpi-ages">
            <div class="kpi-label" data-i18n="backups.ages.latest_micro">Latest Micro</div>
            <div class="kpi-value" id="age-micro">-- d</div>
            <div class="kpi-sub" id="micro-count"></div>
          </div>
        </div>
      </div>

      <!-- Chart takes the rest -->
      <div style="flex:1 1 0; min-width:200px;">
        <canvas id="ageChart" width="200" height="75"></canvas>
      </div>
    </div>
  </div>
</section>

<section class="grid" style="display:flex; gap:16px; margin-bottom:16px; flex-wrap:wrap;">

  <!-- Warnings & Errors ~40% -->
  <div class="card" style="flex:0 0 39%; min-width:280px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.warnings_errors.title">Warnings &amp; Errors</div>
        <div class="card-sub" data-i18n="backups.warnings_errors.subtitle">Summary from the health check</div>
      </div>
    </div>

    <div style="display:flex; flex-direction:column; gap:10px;">
      <div>
        <h3 style="font-size:13px; color:var(--accent-warn); margin-bottom:4px;" data-i18n="backups.common.warnings">Warnings</h3>
        <div class="list-block" id="warn-list">-</div>
      </div>

      <div>
        <h3 style="font-size:13px; color:var(--accent-crit); margin-bottom:4px;" data-i18n="backups.common.errors">Errors</h3>
        <div class="list-block" id="err-list">-</div>
      </div>
    </div>
  </div>

  <!-- Raw Snapshot ~60% -->
  <div class="card" style="flex:0 0 60%; min-width:320px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.raw_snapshot.title">Raw Snapshot</div>
        <div class="card-sub" data-i18n="backups.raw_snapshot.subtitle">Key values directly from the last JSON status</div>
      </div>
    </div>

    <pre id="raw-json" style="font-size:11px; max-height:260px; overflow:auto; background: var(--card); border-radius:8px; border:1px solid var(--border-soft); padding:8px;"></pre>
  </div>

</section>

  <!-- Recent Actions -->
  <div class="card" style="margin-top:16px; margin-bottom:40px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.recent_actions.title">Recent Actions</div>
        <div class="card-sub" data-i18n="backups.recent_actions.subtitle">History of actions from this dashboard</div>
      </div>
    </div>
    <div id="recent-actions-container" class="list-block" style="max-height:180px;">
      <div id="recent-actions-empty" style="font-size:12px; color:var(--text-muted);">
        <span data-i18n="backups.recent_actions.none">No actions recorded yet.</span>
      </div>
      <ul id="recent-actions-list" style="display:none;"></ul>
    </div>
  </div>

  <div class="card collapsible-card" id="backupOrchestrator" data-config='<?= h($backup_orchestrator_json) ?>' data-collapse-key="backup-orchestrator" style="margin-bottom:18px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.orch.title">Backup Orchestration Wizard</div>
        <div class="card-sub" data-i18n="backups.orch.subtitle">Customize paths once, then copy the generated script/cron/systemd units.</div>
      </div>
      <button type="button" class="collapsible-toggle" data-collapse-toggle aria-expanded="true" title="Collapse/Expand">▾</button>
    </div>
    <div class="collapsible-body">
    <div class="orch-grid">
      <label><span data-i18n="backups.orch.backup_root">Backup root</span>
        <input type="text" data-orch="backup_root">
      </label>
      <label>Exclude paths
        <textarea data-orch="exclude_dirs" rows="3" placeholder="/backup&#10;/mnt/backupz"></textarea>
      </label>
      <label>Backup Script path
        <input type="text" data-orch="script_path">
      </label>
      <label>Hestia backup command
        <input type="text" data-orch="hestia_cmd">
      </label>
      <label>Hestia user
        <input type="text" data-orch="hestia_user">
      </label>
      <label>backupctl path
        <input type="text" data-orch="backupctl">
      </label>
      <label>Pipeline script path
        <input type="text" data-orch="pipeline_script">
      </label>
      <label>Log file
        <input type="text" data-orch="log_file">
      </label>
      <label>Service name
        <input type="text" data-orch="service_name">
      </label>
      <label>Run as user
        <input type="text" data-orch="system_user">
      </label>
      <label>Cron time (HH:MM)
        <input type="text" data-orch="cron_time" placeholder="02:00">
      </label>
    </div>
    <div class="orch-flags">
      <label><input type="checkbox" data-orch-flag="include_health" checked> <span data-i18n="backups.orch.include_health">Include health check</span></label>
      <label><input type="checkbox" data-orch-flag="include_integrity" checked> <span data-i18n="backups.orch.include_integrity">Include integrity check</span></label>
      <label><input type="checkbox" data-orch-flag="include_prune" checked> <span data-i18n="backups.orch.include_prune">Include prune stage</span></label>
      <label><input type="checkbox" data-orch-flag="suspend"> <span data-i18n="backups.orch.suspend">Suspend backups</span></label>
      <label><input type="checkbox" data-orch-flag="disable_on_mount_fail"> <span data-i18n="backups.orch.disable_on_mount_fail">Disable backups if mount fails</span></label>
    </div>
    <div class="orch-output">
      <div class="orch-block">
        <div class="card-title" data-i18n="backups.orch.nightly_script">Nightly script</div>
        <pre id="orchScript">Loading…</pre>
        <button class="btn-backup" type="button" onclick="copyRestoreCmd('orchScript');" data-i18n="backups.orch.copy_script">Copy script</button>
      </div>
      <div class="orch-block">
        <div class="card-title" data-i18n="backups.orch.cron_entry">Cron entry</div>
        <pre id="orchCron">Loading…</pre>
        <button class="btn-backup" type="button" onclick="copyRestoreCmd('orchCron');" data-i18n="backups.orch.copy_cron_line">Copy cron line</button>
      </div>
      <div class="orch-block">
        <div class="card-title" data-i18n="backups.orch.systemd_service">Systemd service</div>
        <pre id="orchService">Loading…</pre>
        <button class="btn-backup" type="button" onclick="copyRestoreCmd('orchService');" data-i18n="backups.orch.copy_unit">Copy unit</button>
      </div>
      <div class="orch-block">
        <div class="card-title" data-i18n="backups.orch.systemd_timer">Systemd timer</div>
        <pre id="orchTimer">Loading…</pre>
        <button class="btn-backup" type="button" onclick="copyRestoreCmd('orchTimer');" data-i18n="backups.orch.copy_timer">Copy timer</button>
      </div>
    </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Read CSRF token from meta tag once
    const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')
      ?.getAttribute('content') || '';
    const DEFAULT_BACKUP_ROOT = <?= json_encode($backup_root, JSON_UNESCAPED_SLASHES) ?>;
    const DEFAULT_HESTIA_SOURCE = <?= json_encode($hestia_source_dir, JSON_UNESCAPED_SLASHES) ?>;
    const DEFAULT_HESTIA_USER = <?= json_encode($hestia_user, JSON_UNESCAPED_SLASHES) ?>;
    const DEFAULT_MICRO_PATH = <?= json_encode(rtrim($backup_root, '/') . '/micro/LATEST_SNAPSHOT/', JSON_UNESCAPED_SLASHES) ?>;
    const orchestratorRoot = document.getElementById('backupOrchestrator');
    const T = (key, fallback) => {
      try {
        if (window.I18N && typeof window.I18N.t === 'function') return window.I18N.t(key, fallback);
      } catch(_) {}
      return fallback != null ? fallback : key;
    };

    // FS-level "any backup?" flag from PHP (1 = some backups exist, 0 = none)
    const HAS_BACKUP_FS = (document.querySelector('meta[name="backup-any-fs"]')
      ?.getAttribute('content') === '1');

    async function loadStatus() {
      try {
        const fetchStatusJson = async () => {
          const res = await fetch('state/backup_status.json?_=' + Date.now());
          if (!res.ok) throw new Error('HTTP ' + res.status);
          const raw = await res.text();
          if (!raw || !raw.trim()) throw new Error('Empty status JSON');
          return JSON.parse(raw);
        };

        let data;
        try {
          data = await fetchStatusJson();
        } catch (firstErr) {
          await new Promise(resolve => setTimeout(resolve, 250));
          data = await fetchStatusJson();
        }
        renderStatus(data);
      } catch (e) {
        console.error('Failed to load backup_status.json:', e);
        const pill = document.getElementById('status-pill');
        pill.className = 'status-pill status-crit';
        pill.textContent = T('backups.common.status', 'Status:') + ' ' + T('backups.status.error_loading_json', 'ERROR LOADING JSON');
      }
    }

    async function loadActions() {
    const listEl = document.getElementById('recent-actions-list');
      const emptyEl = document.getElementById('recent-actions-empty');
      if (!listEl || !emptyEl) return;

      try {
        const res = await fetch('state/backup_actions.json?_=' + Date.now());
        if (!res.ok) {
          // no history file yet, don’t spam errors
          emptyEl.style.display = 'block';
          listEl.style.display = 'none';
          return;
        }
        const data = await res.json();
        if (!Array.isArray(data) || data.length === 0) {
          emptyEl.style.display = 'block';
          listEl.style.display = 'none';
          listEl.innerHTML = '';
          return;
        }

        // newest last in file, so reverse to show newest first
        const entries = data.slice().reverse();

        listEl.innerHTML = '';
        entries.forEach(entry => {
          const li = document.createElement('li');

          const ts = document.createElement('span');
          ts.className = 'action-ts';
          ts.textContent = entry.ts ? '[' + entry.ts + ']' : '[unknown]';

          const label = document.createElement('span');
          const ok = (entry.ok !== false);
          label.className = ok ? 'action-ok' : 'action-fail';
          label.textContent = (entry.action || 'action') + (ok ? ' OK' : ' FAILED');

          const msg = document.createElement('span');
          msg.style.marginLeft = '6px';
          msg.textContent = entry.message ? '– ' + entry.message : '';

          const meta = document.createElement('div');
          meta.className = 'action-meta';

          const detailParts = [];

          if (entry.job_id) {
            detailParts.push('job ' + entry.job_id);
          }
          if (entry.log) {
            detailParts.push('log: ' + entry.log);
          }
          if (entry.script) {
            detailParts.push('cmd: ' + entry.script);
          }

          if (detailParts.length) {
            meta.textContent = detailParts.join(' · ');
          }

          li.appendChild(ts);
          li.appendChild(label);
          li.appendChild(msg);
          if (detailParts.length) {
            li.appendChild(document.createElement('br'));
            li.appendChild(meta);
          }

          listEl.appendChild(li);
        });

        emptyEl.style.display = 'none';
        listEl.style.display = 'block';

      } catch (e) {
        console.error('Failed to load backup_actions.json:', e);
        // leave whatever was there
      }
    }

    let diskChart, ageChart;

    function ageToLevel(age) {
      if (age === null || age === undefined) return null;
      if (age <= 1) return 'ok';
      if (age <= 3) return 'warn';
      return 'crit';
    }

    function applyStatusColor(el, level) {
      if (!el) return;
      el.className = 'kpi-value';
      if (!level) return;
      if (level === 'ok') el.classList.add('kpi-ok');
      else if (level === 'warn') el.classList.add('kpi-warn');
      else if (level === 'crit') el.classList.add('kpi-crit');
    }

    function normalizeOverallStatus(rawStatus, mountOk, warnings, errors, diskUsage) {
      const s = String(rawStatus || '').trim().toUpperCase();
      if (s === 'OK' || s === 'HEALTHY' || s === 'PASS') return 'OK';
      if (s === 'WARN' || s === 'WARNING' || s === 'DEGRADED') return 'WARN';
      if (s === 'CRIT' || s === 'CRITICAL' || s === 'FAIL' || s === 'ERROR') return 'CRIT';

      // Derive status if source omitted/legacy field is missing.
      if (mountOk === false) return 'CRIT';
      if (Array.isArray(errors) && errors.length > 0) return 'CRIT';
      if (Array.isArray(warnings) && warnings.length > 0) return 'WARN';
      if (Number.isFinite(diskUsage)) {
        if (diskUsage >= 95) return 'CRIT';
        if (diskUsage >= 80) return 'WARN';
      }
      return 'OK';
    }

    function renderStatus(data) {
      // Timestamp
      document.getElementById('timestamp').textContent =
        T('backups.common.timestamp', 'Timestamp:') + ' ' + (data.timestamp || '--');

      // Mount health from JSON (default true if missing)
      const mountOk = (data.backup_mount_ok !== false);

      // Disk block
      const usage = typeof data.disk?.usage_percent === 'number'
        ? data.disk.usage_percent
        : parseInt(data.disk?.usage_percent || '0', 10);

      const diskUsageEl = document.getElementById('disk-usage');
      diskUsageEl.textContent = usage + '%';
      document.getElementById('disk-df').textContent = data.disk?.df || '';
      const sizeUpdatedEl = document.getElementById('disk-sizes-updated');

      const diskTag = document.getElementById('disk-tag');
      const diskStatusLabel = document.getElementById('disk-status-label');
      diskTag.className = 'tag';

      let diskStatusText = T('backups.status.ok', 'OK');
      let diskLevel = 'ok';

      if (!mountOk) {
        // Disk not mounted: override everything
        diskTag.classList.add('badge-disk-crit');
        diskTag.textContent = T('backups.disk.unmounted', 'Disk UNMOUNTED');
        diskStatusText = T('backups.status.unmounted', 'UNMOUNTED') + '  ·  💀';
        diskLevel = 'crit';
      } else {
        if (usage >= 95) {
          diskTag.classList.add('badge-disk-crit');
          diskStatusText = T('backups.status.critical', 'CRITICAL') + '  ·  😡';
          diskLevel = 'crit';
        } else if (usage >= 90) {
          diskTag.classList.add('badge-disk-warn');
          diskStatusText = T('backups.status.high', 'HIGH') + '  ·  😤';
          diskLevel = 'warn';
        } else if (usage >= 80) {
          diskTag.classList.add('badge-disk-warn');
          diskStatusText = T('backups.status.elevated', 'ELEVATED') + '  ·  😩';
          diskLevel = 'warn';
        } else {
          diskTag.classList.add('badge-disk-ok');
          diskStatusText = T('backups.status.healthy', 'HEALTHY') + '  ·  😎';
          diskLevel = 'ok';
        }

        diskTag.textContent = T('backups.disk.tag', 'Disk:') + ' ' + usage + '%';
      }

      diskStatusLabel.textContent = diskStatusText;

      // Color disk KPIs
      applyStatusColor(diskUsageEl, diskLevel);
      applyStatusColor(diskStatusLabel, diskLevel);

      if (sizeUpdatedEl) {
        const sizeUpdatedIso = data.disk?.sizes_updated || null;
        const sizeUpdatedTs = Number(data.disk?.sizes_updated_ts || 0);
        if (sizeUpdatedIso) {
          let ageText = '';
          if (sizeUpdatedTs > 0) {
            const ageMin = Math.max(0, Math.floor((Date.now() / 1000 - sizeUpdatedTs) / 60));
            ageText = ' (' + ageMin + 'm ago)';
          }
          sizeUpdatedEl.textContent = T('backups.disk.sizes_updated', 'Sizes updated:') + ' ' + sizeUpdatedIso + ageText;
        } else {
          sizeUpdatedEl.textContent = T('backups.disk.sizes_updated', 'Sizes updated:') + ' --';
        }
      }

      // Snapshot ages
      const snapAge   = data.snapshots?.daily0_age_days ?? null;
      const hestiaAge = data.hestia?.latest_age_days   ?? null;
      const microAge  = data.micro?.latest_age_days    ?? null;

      const ageSnapEl   = document.getElementById('age-snap');
      const ageHestiaEl = document.getElementById('age-hestia');
      const ageMicroEl  = document.getElementById('age-micro');

      ageSnapEl.textContent   = (snapAge   !== null ? snapAge   : '--') + ' d';
      ageHestiaEl.textContent = (hestiaAge !== null ? hestiaAge : '--') + ' d';
      ageMicroEl.textContent  = (microAge  !== null ? microAge  : '--') + ' d';

      // Freshness summary under header
      const freshnessEl = document.getElementById('freshness-summary');
      if (freshnessEl) {
        const parts = [];
        parts.push('snap '   + (snapAge   !== null && snapAge   !== undefined ? snapAge   + 'd' : '--'));
        parts.push('hestia ' + (hestiaAge !== null && hestiaAge !== undefined ? hestiaAge + 'd' : '--'));
        parts.push('micro '  + (microAge  !== null && microAge  !== undefined ? microAge  + 'd' : '--'));
        freshnessEl.textContent = T('backups.page.freshness_prefix', 'Freshness:') + ' ' + parts.join(' · ');
      }

      // Backup counts (if JSON exposes them)
      const snapCount = data.snapshots?.entries_count ?? null;
      const hestiaCount = data.hestia?.entries_count ?? null;
      const microCount = data.micro?.entries_count ?? null;
      const snapCountEl = document.getElementById('snap-count');
      const hestiaCountEl = document.getElementById('hestia-count');
      const microCountEl = document.getElementById('micro-count');
      if (snapCountEl) {
        if (snapCount === null || snapCount === undefined) {
          snapCountEl.textContent = '';
        } else if (snapCount === 0) {
          snapCountEl.textContent = T('backups.ages.no_snapshots_on_disk', 'No snapshots found on disk');
        } else if (snapCount === 1) {
          snapCountEl.textContent = T('backups.ages.one_snapshot_present', '1 snapshot present');
        } else {
          snapCountEl.textContent = snapCount + ' ' + T('backups.ages.snapshots_present_suffix', 'snapshots present');
        }
      }
      if (hestiaCountEl) {
        if (hestiaCount === null || hestiaCount === undefined) {
          hestiaCountEl.textContent = '';
        } else if (hestiaCount === 0) {
          hestiaCountEl.textContent = T('backups.ages.no_panel_backups_on_disk', 'No panel backups found on disk');
        } else if (hestiaCount === 1) {
          hestiaCountEl.textContent = T('backups.ages.one_panel_backup_present', '1 panel backup present');
        } else {
          hestiaCountEl.textContent = hestiaCount + ' ' + T('backups.ages.panel_backups_present_suffix', 'panel backups present');
        }
      }
      if (microCountEl) {
        if (microCount === null || microCount === undefined) {
          microCountEl.textContent = '';
        } else if (microCount === 0) {
          microCountEl.textContent = T('backups.ages.no_micro_backups_on_disk', 'No micro backups found on disk');
        } else if (microCount === 1) {
          microCountEl.textContent = T('backups.ages.one_micro_backup_present', '1 micro backup present');
        } else {
          microCountEl.textContent = microCount + ' ' + T('backups.ages.micro_backups_present_suffix', 'micro backups present');
        }
      }

      // Base JSON warnings/errors
      let warnings = Array.isArray(data.warnings) ? [...data.warnings] : [];
      let errors   = Array.isArray(data.errors)   ? [...data.errors]   : [];

      // Do we see *any* backup info in JSON?
      const hasAnyBackupJSON =
        (snapAge   !== null && snapAge   !== undefined) ||
        (hestiaAge !== null && hestiaAge !== undefined) ||
        (microAge  !== null && microAge  !== undefined);

      // Combined view: JSON OR filesystem
      const hasAnyBackupEffective = hasAnyBackupJSON || HAS_BACKUP_FS;

      // Start with backend status, but normalize/derive for legacy or partial payloads.
      let overallStatus = normalizeOverallStatus(data.status, mountOk, warnings, errors, usage);

      // If NOTHING on disk and JSON has no backup ages, force CRITICAL
      if (!hasAnyBackupEffective) {
        overallStatus = 'CRIT';
        errors.push(T('backups.errors.no_backups_detected', 'No backups detected for snapshots, Hestia, or micro sets.'));
      }

      // If mount is bad, always CRIT regardless of other conditions
      if (!mountOk) {
        overallStatus = 'CRIT';
      }

      // Color age KPIs based on JSON ages first
      applyStatusColor(ageSnapEl,   ageToLevel(snapAge));
      applyStatusColor(ageHestiaEl, ageToLevel(hestiaAge));
      applyStatusColor(ageMicroEl,  ageToLevel(microAge));

      // If absolutely no backups exist, make the ages scream too
      if (!hasAnyBackupEffective) {
        applyStatusColor(ageSnapEl,   'crit');
        applyStatusColor(ageHestiaEl, 'crit');
        applyStatusColor(ageMicroEl,  'crit');
      }

      // Overall status pill driven by our stricter decision
      const pill = document.getElementById('status-pill');
      pill.className = 'status-pill';
      if (overallStatus === 'OK') {
        pill.classList.add('status-ok');
      } else if (overallStatus === 'WARN') {
        pill.classList.add('status-warn');
      } else {
        pill.classList.add('status-crit');
      }
      pill.textContent = T('backups.common.status', 'Status:') + ' ' + overallStatus;

      // Warnings
      const warnContainer = document.getElementById('warn-list');
      warnContainer.innerHTML = '';

      if (!warnings.length) {
        warnContainer.textContent = T('backups.common.none_dash', '-');
      } else {
        // Backend sometimes gives word-by-word arrays; merge them
        const mergedWarn = warnings.join(' ').replace(/\s+/g, ' ').trim();

        // Allow explicit newlines in the string to make multiple bullets
        const lines = mergedWarn.split(/\n+/).map(s => s.trim()).filter(Boolean);

        const ul = document.createElement('ul');
        lines.forEach(line => {
          const li = document.createElement('li');
          li.textContent = line;
          ul.appendChild(li);
        });
        warnContainer.appendChild(ul);
      }

      // Errors
      const errContainer = document.getElementById('err-list');
      errContainer.innerHTML = '';

      if (!errors.length) {
        errContainer.textContent = T('backups.common.none_dash', '-');
      } else {
        // Merge word-split arrays into a readable message
        const mergedErr = errors.join(' ').replace(/\s+/g, ' ').trim();

        // Respect explicit newlines as separate bullets
        const lines = mergedErr.split(/\n+/).map(s => s.trim()).filter(Boolean);

        const ul = document.createElement('ul');
        lines.forEach(line => {
          const li = document.createElement('li');
          li.textContent = line;
          ul.appendChild(li);
        });
        errContainer.appendChild(ul);
      }

      // Raw JSON pane
      document.getElementById('raw-json').textContent = JSON.stringify(data, null, 2);

      // Populate "Quick Restore Helpers" panel (best-effort)
      const hestiaLatestName =
        (data.hestia && data.hestia.latest_backup_name)
          ? data.hestia.latest_backup_name
          : (DEFAULT_HESTIA_USER + '.YYYYMMDD-HHMM.tar');

      const restoreHestiaCmd =
        'sudo /usr/local/hestia/bin/v-restore-user ' + DEFAULT_HESTIA_USER + ' ' + hestiaLatestName;

      const restoreHestiaPre = document.getElementById('restore-hestia-cmd');
      const restoreHestiaNote = document.getElementById('restore-hestia-note');
      if (restoreHestiaPre) {
        restoreHestiaPre.textContent = restoreHestiaCmd;
      }
      if (restoreHestiaNote) {
        if (data.hestia && data.hestia.latest_backup_name) {
          restoreHestiaNote.textContent =
            T('backups.restore.using_latest_backup_prefix', 'Using latest backup reported in JSON: ') + hestiaLatestName;
        } else {
          restoreHestiaNote.textContent =
            T('backups.restore.adjust_backup_tar', 'Adjust BACKUP_TAR to a real file from \"v-list-backups USER\" before running.');
        }
      }

      const microPath =
        (data.micro && data.micro.latest_path)
          ? data.micro.latest_path
          : DEFAULT_MICRO_PATH;

      const microPathNormalized = microPath.replace(/\/?$/, '/');

      const restoreMicroCmd =
        'sudo rsync -aHAXv ' + microPathNormalized + ' /';

      const restoreMicroPre = document.getElementById('restore-micro-cmd');
      const restoreMicroNote = document.getElementById('restore-micro-note');
      if (restoreMicroPre) {
        restoreMicroPre.textContent = restoreMicroCmd;
      }
      if (restoreMicroNote) {
        if (data.micro && data.micro.latest_path) {
          restoreMicroNote.textContent =
            T('backups.restore.micro_restore_prefix', 'Restore base from latest micro snapshot: ') + data.micro.latest_path +
            T('backups.restore.micro_restore_suffix', ' (review paths and exclusions before running).');
        } else {
          restoreMicroNote.textContent =
            T('backups.restore.set_correct_snapshot_path', 'Set the correct snapshot path before using this command.');
        }
      }

      // Folder breakdown for donut + legend
      const diskFolders = data.disk?.folders || null;
      const diskTotalGb = data.disk?.total_gb || null;

      // Charts
      renderCharts(usage, snapAge, hestiaAge, microAge, diskFolders, diskTotalGb);
    }

    function renderCharts(
      diskUsage,
      snapAge,
      hestiaAge,
      microAge,
      diskFolders,
      diskTotalGb
    ) {
      const diskCtx = document.getElementById('diskChart').getContext('2d');
      const ageCtx  = document.getElementById('ageChart').getContext('2d');

      const toGb = (val) => {
        if (val == null) return 0;
        const n = Number(val);
        if (!Number.isNaN(n)) return n;
        const cleaned = String(val).replace(/[^0-9.]/g, '');
        const parsed = parseFloat(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
      };

      let diskLabels = [];
      let diskData   = [];
      let diskColors = [];

      const inFolderMode =
        Array.isArray(diskFolders) &&
        diskFolders.length > 0 &&
        diskTotalGb;

      if (inFolderMode) {
        const folderColors = [
          '#42a5f5',
          '#dc0aab',
          '#ffa726',
          '#ab47bc',
          '#ec407a',
          '#26c6da'
        ];

        let usedSumGb = 0;

        diskFolders.forEach((folder, idx) => {
          const sizeGb = toGb(folder.size_gb);
          usedSumGb += sizeGb;

          const label = folder.label || ('Folder ' + (idx + 1));
          diskLabels.push(label);
          diskData.push(sizeGb);

          const color = folderColors[idx % folderColors.length];
          diskColors.push(color);
        });

        const freeGb = Math.max(0, diskTotalGb - usedSumGb);
        if (freeGb > 0.01) {
          diskLabels.push(T('backups.common.free', 'Free'));
          diskData.push(freeGb);
          diskColors.push('rgba(106,168,79,0.7)');
        }

        // Build legend
        const legendEl = document.getElementById('disk-legend');
        if (legendEl) {
          legendEl.innerHTML = '';

          diskFolders.forEach((folder, idx) => {
            const sizeGb = toGb(folder.size_gb);
            const label = folder.label || ('Folder ' + (idx + 1));
            const pct = diskTotalGb ? ((sizeGb / diskTotalGb) * 100) : 0;
            const color = folderColors[idx % folderColors.length];

            const row = document.createElement('div');
            row.className = 'disk-legend-item';

            const left = document.createElement('div');
            left.className = 'disk-legend-left';

            const colorBox = document.createElement('span');
            colorBox.className = 'disk-legend-color';
            colorBox.style.backgroundColor = color;

            const labelEl = document.createElement('span');
            labelEl.className = 'disk-legend-label';
            labelEl.textContent = label;

            left.appendChild(colorBox);
            left.appendChild(labelEl);

            const sizeEl = document.createElement('span');
            sizeEl.className = 'disk-legend-size';
            sizeEl.textContent =
              sizeGb.toFixed(1) + ' GB (' + pct.toFixed(1) + '%)';

            row.appendChild(left);
            row.appendChild(sizeEl);
            legendEl.appendChild(row);
          });

          const freeGb2 = Math.max(0, diskTotalGb - usedSumGb);
          if (freeGb2 > 0.01) {
            const pctFree = (freeGb2 / diskTotalGb) * 100;
            const row = document.createElement('div');
            row.className = 'disk-legend-item';

            const left = document.createElement('div');
            left.className = 'disk-legend-left';

            const colorBox = document.createElement('span');
            colorBox.className = 'disk-legend-color';
            colorBox.style.backgroundColor = 'rgba(106,168,79,0.7)';

            const labelEl = document.createElement('span');
            labelEl.className = 'disk-legend-label';
            labelEl.textContent = T('backups.common.free', 'Free');

            left.appendChild(colorBox);
            left.appendChild(labelEl);

            const sizeEl = document.createElement('span');
            sizeEl.className = 'disk-legend-size';
            sizeEl.textContent =
              freeGb2.toFixed(1) + ' GB (' + pctFree.toFixed(1) + '%)';

            row.appendChild(left);
            row.appendChild(sizeEl);
            legendEl.appendChild(row);
          }
        }
      } else {
        // Fallback: old Used/Free donut, no legend
        const used = diskUsage;
        const free = Math.max(0, 100 - used);
        diskLabels = [T('backups.common.used', 'Used'), T('backups.common.free', 'Free')];
        diskData   = [used, free];
        diskColors = ['#42a5f5', '#4caf50'];

        const legendEl = document.getElementById('disk-legend');
        if (legendEl) {
          legendEl.innerHTML = '';
        }
      }

      if (diskChart) diskChart.destroy();
      diskChart = new Chart(diskCtx, {
        type: 'doughnut',
        data: {
          labels: diskLabels,
          datasets: [{
            data: diskData,
            backgroundColor: diskColors,
            borderWidth: 0
          }]
        },
        options: {
          plugins: {
            legend: {
              labels: { color: '#ddd', font: { size: 11 } }
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const label = context.label || '';
                  const value = context.parsed || 0;

                  if (inFolderMode && diskTotalGb) {
                    const pct = ((value / diskTotalGb) * 100).toFixed(1);
                    return label + ': ' + value.toFixed(1) + ' GB (' + pct + '%)';
                  }

                  return label + ': ' + value + '%';
                }
              },
              padding: 8,
              bodyFont: { size: 12 },
              titleFont: { size: 11 },
              boxWidth: 12,
              boxHeight: 12,
              displayColors: true
            }
          },
          cutout: '65%'
        }
      });

      // ---------- AGE BAR CHART ----------
      if (ageChart) ageChart.destroy();
      ageChart = new Chart(ageCtx, {
        type: 'bar',
        data: {
          labels: [T('backups.ages.snapshot_daily0_chart', 'Snapshot (daily.0)'), T('backups.ages.hestia_latest_chart', 'Hestia latest'), T('backups.ages.micro_latest_chart', 'Micro latest')],
          datasets: [{
            label: T('backups.ages.age_days', 'Age (days)'),
            data: [
              snapAge   != null ? snapAge   : 0,
              hestiaAge != null ? hestiaAge : 0,
              microAge  != null ? microAge  : 0
            ],
            backgroundColor: ['#dc0aab', '#ffa726', '#ab47bc'],
            borderWidth: 0
          }]
        },
        options: {
          plugins: {
            legend: {
              labels: { color: '#ddd', font: { size: 11 } }
            },
            tooltip: {
              padding: 8,
              bodyFont: { size: 11 },
              titleFont: { size: 11 },
              boxWidth: 8,
              boxHeight: 8,
              displayColors: true
            }
          },
          scales: {
            x: {
              ticks: { color: '#ddd', font: { size: 11 } },
              grid:  { color: 'rgba(255,255,255,0.05)' }
            },
            y: {
              ticks: { color: '#ddd', font: { size: 11 } },
              grid:  { color: 'rgba(255,255,255,0.07)' }
            }
          }
        }
      });
    }

    // Initial load and periodic refresh
    loadStatus();
    loadActions();
    // Refresh every 60s so you can leave tab open
    setInterval(() => {
      loadStatus();
      loadActions();
    }, 60000);

    function setActionStatus(text, cssClass) {
      const el = document.getElementById('backup-action-status');
      el.textContent = text;

      // Color mapping for status line
      if (cssClass === 'error') {
        el.style.color = '#f44336';   // red
      } else if (cssClass === 'warn') {
        el.style.color = '#ff9800';   // orange
      } else if (cssClass === 'ok') {
        el.style.color = '#4caf50';   // green (success)
      } else {
        el.style.color = '#9ea4b8';   // muted/info/default
      }
    }

    function setButtonsDisabled(disabled) {
      document.querySelectorAll('.btn-backup').forEach(btn => {
        btn.disabled = disabled;
      });
    }

    async function triggerBackup(action, label, extraBody = '') {
      setButtonsDisabled(true);
      setActionStatus(T('backups.actions.starting_prefix', 'Starting ') + label + '…', 'info');

      try {
        let body = 'action=' + encodeURIComponent(action);
        if (extraBody) {
          body += '&' + extraBody;
        }

        const res = await fetch('backups_action.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN
          },
          body
        });

        const data = await res.json().catch(() => ({}));

        if (!res.ok || !data.ok) {
          setActionStatus(
            T('backups.actions.failed_to_start_prefix', 'FAILED to start ') + label + ': ' + (data.error || ('HTTP ' + res.status)),
            'error'
          );
        } else {
          const jobInfo = data.job_id ? (' (job ' + data.job_id + ')') : '';
          setActionStatus(
            T('backups.actions.started_prefix', 'Started ') + label + jobInfo + T('backups.actions.started_suffix', '. This may take a while; status will update on next health check.'),
            'ok'
          );
          // Give the job a few seconds then refresh status + actions once
          setTimeout(() => {
            loadStatus();
            loadActions();
          }, 5000);
        }
      } catch (e) {
        console.error(e);
        setActionStatus(T('backups.actions.error_talking_endpoint', 'Error talking to backups_action.php'), 'error');
      } finally {
        setButtonsDisabled(false);
      }
    }

    function copyRestoreCmd(preId) {
      const el = document.getElementById(preId);
      if (!el) return;
      const text = el.textContent || '';
      if (!text) return;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => {});
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
      }
    }

    function confirmRestore(action, label) {
      const msg =
        label +
        '\n\n' + T('backups.restore.confirm_body_1', 'This will start a Hestia restore job on the server using backupctl.') +
        '\n' + T('backups.restore.confirm_body_2', 'Services and data for that user may be overwritten.') + '\n\n' + T('backups.restore.confirm_body_3', 'Are you absolutely sure?');
      const sure = window.confirm(msg);
      if (!sure) return;
      triggerBackup(action, label);
    }

    // Wire buttons
    const crontabList = document.getElementById('crontabList');
    const crontabRefresh = document.getElementById('crontabRefresh');
    const crontabNote = document.getElementById('crontabNote');

    function renderCrontab(entries){
      if (!crontabList) return;
      if (!entries || !entries.length){
        crontabList.innerHTML = '<div class="muted small" style="padding:.75rem;">' + T('backups.cron.no_entries', 'No crontab entries detected.') + '</div>';
        return;
      }
      crontabList.innerHTML = '';
      entries.forEach((entry, idx) => {
        const div = document.createElement('div');
        div.className = 'crontab-line ' + (entry.type || 'entry');
        div.dataset.raw = entry.raw || '';
        if (entry.type === 'comment' || entry.type === 'blank'){
          const text = document.createElement('div');
          text.className = 'muted small';
          text.textContent = entry.raw || '';
          div.appendChild(text);
        } else if (entry.type === 'env'){
          const label = document.createElement('div');
          label.innerHTML = '<strong>' + T('backups.cron.env', 'Env') + '</strong>';
          const code = document.createElement('div');
          code.className = 'cron-command';
          code.textContent = entry.raw || '';
          div.appendChild(label);
          div.appendChild(code);
        } else {
          const schedule = document.createElement('div');
          schedule.innerHTML = '<span class="muted">' + T('backups.cron.schedule', 'Schedule') + '</span><strong style="margin-left:6px;">'+(entry.schedule || '')+'</strong>';
          const cmd = document.createElement('div');
          cmd.className = 'cron-command';
          cmd.textContent = entry.command || entry.raw || '';
          const actions = document.createElement('div');
          actions.className = 'line-actions';
          const copyBtn = document.createElement('button');
          copyBtn.type = 'button';
          copyBtn.className = 'btn-backup';
          copyBtn.style.padding = '4px 10px';
          copyBtn.setAttribute('data-copy-cronline', String(idx));
          copyBtn.textContent = T('backups.common.copy', 'Copy');
          actions.appendChild(copyBtn);
          div.appendChild(schedule);
          div.appendChild(cmd);
          div.appendChild(actions);
        }
        crontabList.appendChild(div);
      });
    }

    function loadCrontab(){
      if (!crontabList) return;
      if (crontabRefresh) crontabRefresh.disabled = true;
      fetch('api/cron_list.php?_=' + Date.now(), {credentials:'same-origin'})
        .then(r => r.json())
        .then(data => {
          if (data && data.ok){
            renderCrontab(data.items || []);
            if (crontabNote) crontabNote.textContent = T('backups.cron.entries_for_prefix', 'Crontab entries for ') + (data.user || T('backups.cron.current_user', 'current user')) + '.';
          } else {
            const msg = data && data.error ? data.error : T('backups.cron.unable_to_read', 'Unable to read crontab.');
            crontabList.innerHTML = '<div class="muted small" style="padding:.75rem;">'+msg+'</div>';
            if (crontabNote) crontabNote.textContent = msg;
          }
        })
        .catch(err => {
          crontabList.innerHTML = '<div class="muted small" style="padding:.75rem;">'+(err.message||err)+'</div>';
          if (crontabNote) crontabNote.textContent = T('backups.cron.failed_to_read', 'Failed to read crontab.');
        })
        .finally(() => {
          if (crontabRefresh) crontabRefresh.disabled = false;
        });
    }

    function initBackupOrchestrator(){
      if (!orchestratorRoot) return;
      let defaults = {};
      try { defaults = JSON.parse(orchestratorRoot.dataset.config || '{}'); }
      catch (_) { defaults = {}; }
      const state = Object.assign({
        backup_root: '',
        script_path: '',
        snap_script: '',
        micro_script: '',
        hestia_cmd: '',
        hestia_user: '',
        exclude_dirs: '',
        backupctl: '',
        pipeline_script: '/usr/local/bin/backup-nightly.sh',
        log_file: '/var/log/backup-nightly.log',
        cron_time: '02:00',
        service_name: 'backup-nightly',
        system_user: 'root',
        include_health: true,
        include_integrity: true,
        include_prune: true,
        suspend: false,
        disable_on_mount_fail: false,
      }, defaults || {});

      function resolveScriptPath(fileName, legacyValue){
        const explicit = (legacyValue || '').trim();
        if (explicit) return explicit;
        const base = (state.script_path || '').trim();
        if (!base) return '';
        return base.replace(/\/+$/, '') + '/' + fileName;
      }

      orchestratorRoot.querySelectorAll('[data-orch]').forEach(input => {
        const key = input.getAttribute('data-orch');
        if (key && state[key] != null) {
          input.value = state[key];
        }
        input.addEventListener('input', () => {
          if (!key) return;
          state[key] = input.value;
          render();
        });
      });
      orchestratorRoot.querySelectorAll('[data-orch-flag]').forEach(cb => {
        const key = cb.getAttribute('data-orch-flag');
        if (!key) return;
        cb.checked = state[key] !== false;
        state[key] = !!cb.checked;
        cb.addEventListener('change', () => {
          state[key] = !!cb.checked;
          render();
        });
      });

      function parseCronTime(str){
        let h = 2, m = 0;
        if (typeof str === 'string'){
          const parts = str.split(':');
          if (parts[0] !== undefined && !isNaN(parseInt(parts[0], 10))){
            h = Math.min(23, Math.max(0, parseInt(parts[0], 10)));
          }
          if (parts[1] !== undefined && !isNaN(parseInt(parts[1], 10))){
            m = Math.min(59, Math.max(0, parseInt(parts[1], 10)));
          }
        }
        return { hour: String(h), minute: String(m) };
      }

      function buildScript(){
        const logFile = state.log_file || '/var/log/backup-nightly.log';
        const esc = (val) => (val || '').replace(/"/g, '\\"');
        const snapScript = resolveScriptPath('make-snapshots.sh', state.snap_script);
        const microScript = resolveScriptPath('make-micro-backups.sh', state.micro_script);
        const baseScriptPath = (state.script_path || '').trim();
        const excludeList = (state.exclude_dirs || '')
          .split(/[\n,]+/)
          .map(item => item.trim())
          .filter(Boolean);
        const lines = [
          '#!/bin/bash',
          'set -euo pipefail',
          '',
          'LOG_FILE="'+esc(logFile)+'"',
          'mkdir -p "$(dirname "$LOG_FILE")"',
          'exec >>"$LOG_FILE" 2>&1',
          'echo "=== $(date +"%Y-%m-%d %H:%M:%S") backup start ==="',
          ''
        ];
        if (excludeList.length) {
          lines.push('export BACKUP_EXCLUDES="'+esc(excludeList.join(' '))+'"');
          lines.push('');
        }
        if (baseScriptPath) {
          lines.push('export BACKUP_SCRIPT_PATH="'+esc(baseScriptPath)+'"');
          lines.push('');
        }
        if ((state.backup_root || '').trim()){
          lines.push('export BACKUP_ROOT="'+esc(state.backup_root)+'"');
          lines.push('cd "$BACKUP_ROOT" 2>/dev/null || true');
          lines.push('');
        }
        if (state.suspend) {
          lines.push('echo "[WARN] Backups suspended via config; exiting."');
          lines.push('exit 0');
          lines.push('');
        }
        if (state.disable_on_mount_fail) {
          lines.push('BACKUP_MOUNT_PATH="${BACKUP_ROOT:-/mnt/backupz}"');
          lines.push('mount_ok=0');
          lines.push('if command -v findmnt >/dev/null 2>&1; then');
          lines.push('  if findmnt -rn "$BACKUP_MOUNT_PATH" >/dev/null 2>&1; then mount_ok=1; fi');
          lines.push('elif command -v mountpoint >/dev/null 2>&1; then');
          lines.push('  if mountpoint -q "$BACKUP_MOUNT_PATH"; then mount_ok=1; fi');
          lines.push('else');
          lines.push('  if grep -q " $BACKUP_MOUNT_PATH " /proc/mounts 2>/dev/null; then mount_ok=1; fi');
          lines.push('fi');
          lines.push('if [ "$mount_ok" -ne 1 ]; then');
          lines.push('  echo "[ERROR] Backup mount not present at $BACKUP_MOUNT_PATH; exiting."');
          lines.push('  exit 1');
          lines.push('fi');
          lines.push('');
        }
        function addStep(label, cmd){
          cmd = (cmd || '').trim();
          if (!cmd) return;
          lines.push('echo "[INFO] ' + label.replace(/"/g, '\\"') + '"');
          lines.push(cmd);
          lines.push('');
        }
        addStep('Snapshots', snapScript);
        addStep('Micro backups', microScript);
        if ((state.hestia_cmd || '').trim() && (state.hestia_user || '').trim()){
          addStep('Hestia backup (' + state.hestia_user + ')', state.hestia_cmd + ' ' + state.hestia_user);
        }
        if (state.include_health && (state.backupctl || '').trim()){
          addStep('Health check', state.backupctl + ' health');
        }
        if (state.include_integrity && (state.backupctl || '').trim()){
          addStep('Integrity check', state.backupctl + ' integrity');
        }
        if (state.include_prune && (state.backupctl || '').trim()){
          addStep('Prune', state.backupctl + ' prune');
        }
        lines.push('echo "=== $(date +"%Y-%m-%d %H:%M:%S") backup complete ==="');
        return lines.join('\n');
      }

      function buildCron(){
        const cron = parseCronTime(state.cron_time);
        const script = state.pipeline_script || '/usr/local/bin/backup-nightly.sh';
        const logFile = state.log_file || '/var/log/backup-nightly.log';
        const user = state.system_user || 'root';
        return `${cron.minute} ${cron.hour} * * * ${user} ${script} >> ${logFile} 2>&1`;
      }

      function buildService(){
        const script = state.pipeline_script || '/usr/local/bin/backup-nightly.sh';
        const user = state.system_user || 'root';
        const svc = state.service_name || 'backup-nightly';
        return `[Unit]
		Description=Nightly backup pipeline (${svc})
		After=network.target

		[Service]
		Type=oneshot
		User=${user}
		ExecStart=${script}

		[Install]
		WantedBy=multi-user.target`;
			  }

			  function buildTimer(){
				const cron = parseCronTime(state.cron_time);
				const svc = state.service_name || 'backup-nightly';
				const timeStr = cron.hour.padStart(2,'0') + ':' + cron.minute.padStart(2,'0') + ':00';
				return `[Unit]
		Description=Schedule ${svc}.service nightly

		[Timer]
		OnCalendar=*-*-* ${timeStr}
		Persistent=true
		Unit=${svc}.service

		[Install]
		WantedBy=timers.target`;
      }

      function render(){
        const scriptEl = document.getElementById('orchScript');
        const cronEl = document.getElementById('orchCron');
        const serviceEl = document.getElementById('orchService');
        const timerEl = document.getElementById('orchTimer');
        if (scriptEl) scriptEl.textContent = buildScript();
        if (cronEl) cronEl.textContent = buildCron();
        if (serviceEl) serviceEl.textContent = buildService();
        if (timerEl) timerEl.textContent = buildTimer();
      }

      render();
    }

    function initCollapsibleCards(){
      const cards = document.querySelectorAll('.collapsible-card[data-collapse-key]');
      cards.forEach(card => {
        const key = card.getAttribute('data-collapse-key');
        const btn = card.querySelector('[data-collapse-toggle]');
        if (!key || !btn) return;
        const storageKey = 'backups.card.' + key + '.collapsed';
        const setState = (collapsed) => {
          card.classList.toggle('is-collapsed', !!collapsed);
          btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
          btn.textContent = collapsed ? '▸' : '▾';
        };
        const saved = localStorage.getItem(storageKey);
        setState(saved === '1');
        btn.addEventListener('click', () => {
          const collapsed = !card.classList.contains('is-collapsed');
          setState(collapsed);
          localStorage.setItem(storageKey, collapsed ? '1' : '0');
        });
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.btn-backup').forEach(btn => {
        const action = btn.getAttribute('data-action');
        if (!action) return;
        let label = btn.textContent.trim();
        btn.addEventListener('click', () => triggerBackup(action, label));
      });
      if (crontabRefresh) {
        crontabRefresh.addEventListener('click', (e) => {
          e.preventDefault();
          loadCrontab();
        });
      }
      initBackupOrchestrator();
      initCollapsibleCards();
      loadCrontab();
    });

  </script>

  <!-- Quick Restore Helpers (read-only helpers + optional DANGER button) -->
  <div class="card collapsible-card" data-collapse-key="quick-restore" style="margin-bottom:24px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.quick_restore.title">Quick Restore Helpers</div>
        <div class="card-sub" data-i18n="backups.quick_restore.subtitle">Pre-filled CLI commands for emergencies (manual run or DANGER button)</div>
      </div>
      <button type="button" class="collapsible-toggle" data-collapse-toggle aria-expanded="true" title="Collapse/Expand">▾</button>
    </div>
    <div class="collapsible-body">
    <div style="padding:10px 4px; font-size:13px; line-height:1.55; color:var(--fg); display:flex; flex-wrap:wrap; gap:16px;">
      <div style="flex:1 1 260px; min-width:260px;">
        <h3 style="margin-top:0; font-size:14px;" data-i18n="backups.quick_restore.hestia_latest_title">Restore latest Hestia user backup</h3>
        <pre id="restore-hestia-cmd"
             style="background: var(--card); border:1px solid var(--border); padding:10px; border-radius:8px; overflow-x:auto; font-size:12px;">
	<span data-i18n="backups.common.loading">Loading...</span>
        </pre>
        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:6px;">
          <button type="button"
                  class="btn-backup"
                  style="padding:4px 10px; font-size:11px;"
                  onclick="copyRestoreCmd('restore-hestia-cmd');">
            <span data-i18n="backups.quick_restore.copy_command">Copy command</span>
          </button>
          <button type="button"
                  class="btn-backup"
                  style="padding:4px 10px; font-size:11px; border-color:#f44336; background:rgba(244,67,54,0.12);"
                  onclick="confirmRestore('restore_hestia_latest','Restore Hestia user (<?= h($hestia_user) ?>) from latest backup');">
            <span data-i18n="backups.quick_restore.run_restore_danger">Run restore (latest, DANGER)</span>
          </button>
          <div class="kpi-sub" id="restore-hestia-note" style="flex:1 1 100%; margin-top:4px;"></div>
        </div>
      </div>

      <div style="flex:1 1 260px; min-width:260px;">
        <h3 style="margin-top:0; font-size:14px;" data-i18n="backups.quick_restore.micro_latest_title">Restore from latest micro snapshot (CLI only)</h3>
        <pre id="restore-micro-cmd"
             style="background: var(--card); border:1px solid var(--border); padding:10px; border-radius:8px; overflow-x:auto; font-size:12px;">
	<span data-i18n="backups.common.loading">Loading...</span>
        </pre>
        <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
          <button type="button"
                  class="btn-backup"
                  style="padding:4px 10px; font-size:11px;"
                  onclick="copyRestoreCmd('restore-micro-cmd');">
            <span data-i18n="backups.quick_restore.copy_command">Copy command</span>
          </button>
          <div class="kpi-sub" id="restore-micro-note"></div>
        </div>
      </div>
    </div>
    </div>
  </div>

  <div class="card collapsible-card" data-collapse-key="cli-reference" style="margin-bottom:40px;">
    <div class="card-header">
      <div>
        <div class="card-title" data-i18n="backups.cli.title">Hestia Backup & Restore CLI</div>
        <div class="card-sub" data-i18n="backups.cli.subtitle">Quick reference sheet - copy & paste ready</div>
      </div>
      <button type="button" class="collapsible-toggle" data-collapse-toggle aria-expanded="true" title="Collapse/Expand">▾</button>
    </div>
    <div class="collapsible-body">
    <div style="padding:10px 4px; font-size:13px; line-height:1.55; color:var(--fg);">
      <h3 style="margin-top:0; font-size:15px;" data-i18n="backups.cli.create_backups">Create Backups</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	# Backup a specific user (recommended for you)
	sudo /usr/local/hestia/bin/v-backup-user <?= h($hestia_user) ?>

	# Backup admin account
	sudo /usr/local/hestia/bin/v-backup-user admin

	# Backup every user on the server
	sudo /usr/local/hestia/bin/v-list-users | tail -n +3 | awk '{print $1}' | while read u; do
		sudo /usr/local/hestia/bin/v-backup-user "$u"
	done
	</pre>

      <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.list_delete_backups">List & Delete Backups</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	# List backups for "<?= h($hestia_user) ?>"
	sudo /usr/local/hestia/bin/v-list-backups <?= h($hestia_user) ?>

	# Delete a specific backup
	sudo /usr/local/hestia/bin/v-delete-backup <?= h($hestia_user) ?> USER.YYYYMMDD-HHMM.tar
	</pre>

      <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.full_restore_user">Full Restore (User)</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	# Restore an entire user (web, db, mail, configs)
	sudo /usr/local/hestia/bin/v-restore-user <?= h($hestia_user) ?> USER.YYYYMMDD-HHMM.tar
	</pre>

      <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.selective_restore">Selective Restore (Web / DB / Mail)</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	# Restore only a website for a domain
	sudo /usr/local/hestia/bin/v-restore-web USER example.com USER.YYYYMMDD-HHMM.tar

	# Restore only a database from a backup
	sudo /usr/local/hestia/bin/v-restore-database USER DB_NAME USER.YYYYMMDD-HHMM.tar

	# Restore only mail
	sudo /usr/local/hestia/bin/v-restore-mail USER USER.YYYYMMDD-HHMM.tar
	</pre>

      <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.backup_file_locations">Backup File Locations</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	# All Hestia user backups live here:
	 <?= h($hestia_source_dir) ?>   (configured Hestia backup path)

	# Your snapshot system:
	 <?= h(rtrim($backup_root, '/')) ?>/snapshots

	# Micro backups:
	 <?= h(rtrim($backup_root, '/')) ?>/micro
<?php if (trim($hestia_bind_source) !== ''): ?>

	# Optional bind mount example (fstab):
	 <?= h($hestia_bind_source) ?> <?= h($hestia_bind_target) ?> none <?= h($hestia_bind_options) ?> 0 0
<?php endif; ?>
	</pre>

      <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.backup_tarball_contents">Backup Tarball Contents</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	domains/
	  DOMAIN/
		public_html/
		logs/
	mail/
	db/
	conf/
	cron/
	user.conf
	</pre>

		  <h3 style="margin-top:22px; font-size:15px;" data-i18n="backups.cli.backupctl_help">backupctl help</h3>

	<pre style="background: var(--card); border:1px solid var(--border); padding:12px; border-radius:8px; overflow-x:auto;">
	backupctl - unified backup helper

	Usage:
	  backupctl snap
		  Run OS snapshots via: /usr/local/sbin/make-snapshots.sh

	  backupctl micro
		  Run micro backups via: /usr/local/sbin/make-micro-backups.sh

	  backupctl all [USER]
		  Run snapshots + micro + Hestia backup for USER
		  Default USER: <?= h($hestia_user) ?>

	  backupctl h-backup [USER]
		  Hestia: v-backup-user USER
		  Default USER: <?= h($hestia_user) ?>

	  backupctl h-list [USER]
		  Hestia: v-list-backups USER
		  Default USER: <?= h($hestia_user) ?>

	  backupctl h-delete USER BACKUP_TAR
		  Hestia: v-delete-backup USER BACKUP_TAR

	  backupctl h-restore-user USER BACKUP_TAR
		  Hestia: v-restore-user USER BACKUP_TAR

	  backupctl h-restore-web USER DOMAIN BACKUP_TAR
		  Hestia: v-restore-web USER DOMAIN BACKUP_TAR

	  backupctl h-restore-db USER DB_NAME BACKUP_TAR
		  Hestia: v-restore-database USER DB_NAME BACKUP_TAR

	  backupctl h-restore-mail USER BACKUP_TAR
		  Hestia: v-restore-mail USER BACKUP_TAR

	  backupctl health
		  Run backup_health_check.sh (updates backup_status.json for dashboard)

	  backupctl integrity
		  Run backup_integrity_watch.sh (size regression checks + email alert)

	  backupctl prune
		  Run prune scripts:
			- /usr/local/sbin/prune_snapshots.sh
			- /usr/local/sbin/prune-micro-backups.sh
			- /usr/local/sbin/prune_hestia_backups.sh

	  backupctl nightly
		  Opinionated nightly pipeline:
			snap -> micro -> h-backup <?= h($hestia_user) ?> -> health -> integrity -> prune

	  backupctl help
		  Show this help text

	Notes:
	  - You probably want to run this as root or via sudo.
	  - Paths are hard-coded to Hestia defaults on Debian.

	</pre>

    </div>
    </div>
  </div>
</div>
</div>

<?php include __DIR__.'/includes/foot.php'; ?>
