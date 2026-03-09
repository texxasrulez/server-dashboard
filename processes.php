<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$PAGE_TITLE = 'Processes';
$PAGE_CSS = 'assets/css/pages/processes.css';
$PAGE_JS = 'assets/js/pages/processes.js';

include __DIR__ . '/includes/head.php';
?>

<div class="card">
  <div class="row between" style="gap:.75rem; flex-wrap:wrap;">
    <div>
      <div class="section-title">Processes</div>
      <div class="muted small">Read-only process viewer from <code>/proc</code> (auto-refresh every 2s).</div>
    </div>
    <div class="proc-controls" id="procControls">
      <label>Sort
        <select id="procSort">
          <option value="cpu">CPU%</option>
          <option value="mem">RSS</option>
          <option value="pid">PID</option>
          <option value="user">User</option>
          <option value="cmd">Command</option>
        </select>
      </label>
      <label>Limit
        <select id="procLimit">
          <option value="25">25</option>
          <option value="50" selected>50</option>
          <option value="100">100</option>
          <option value="200">200</option>
        </select>
      </label>
      <label>Filter
        <input id="procFilter" type="text" maxlength="80" placeholder="cmd/user contains...">
      </label>
    </div>
  </div>
  <div id="procMeta" class="muted small" style="margin-top:.5rem"></div>

  <div class="table-scroll" style="margin-top:.65rem;">
    <table class="table proc-table" id="procTable">
      <thead>
        <tr>
          <th>PID</th>
          <th>USER</th>
          <th>CPU%</th>
          <th>RSS</th>
          <th>STATE</th>
          <th>CMD</th>
        </tr>
      </thead>
      <tbody id="procBody"></tbody>
    </table>
  </div>
</div>

<div id="procDetails" class="modal" hidden>
  <div class="modal-dialog proc-modal" role="dialog" aria-modal="true" aria-label="Process details">
    <div class="modal-head">
      <div class="modal-title">Process Details</div>
      <button type="button" class="modal-close" id="procDetailsClose" aria-label="Close">×</button>
    </div>
    <div class="modal-body">
      <div class="proc-detail-grid" id="procDetailGrid"></div>
      <div style="margin-top:.6rem">
        <div class="muted small">Command line</div>
        <pre id="procDetailCmdline" class="proc-cmdline"></pre>
      </div>
    </div>
  </div>
</div>

<style id="proc-modal-fix">
  /* Keep this in-page so it applies even if page CSS is cached */
  #procDetails.modal {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 1rem !important;
  }
  #procDetails.modal[hidden] {
    display: none !important;
  }
  #procDetails .modal-dialog.proc-modal {
    position: relative !important;
    left: auto !important;
    top: auto !important;
    transform: none !important;
    width: min(780px, 96vw) !important;
    max-height: min(88vh, 900px) !important;
    overflow: auto !important;
    border-radius: 16px !important;
  }
</style>

<?php include __DIR__ . '/includes/foot.php'; ?>
