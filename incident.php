<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/init.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/lib/IncidentManager.php";
require_admin();

$id = trim((string) ($_GET["id"] ?? ""));
$incident = $id !== "" ? IncidentManager::get($id) : null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json; charset=utf-8");
    if (!csrf_check_request()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "CSRF failed"]);
        exit();
    }
    $state = trim((string) ($_POST["state"] ?? ""));
    if ($id === "" || !IncidentManager::setState($id, $state)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Invalid incident state"]);
        exit();
    }
    echo json_encode(["ok" => true]);
    exit();
}

$PAGE_TITLE = $incident ? "Incident" : "Incident Not Found";
$PAGE_JS = ["assets/js/utils/sortable-table.js"];
include __DIR__ . "/includes/head.php";
?>
<div class="card">
  <div class="row between wrap gap">
    <div>
      <div class="section-title"><?= h($incident["title"] ?? "Incident not found") ?></div>
      <div class="muted small">
        <?php if ($incident): ?>
          Host <?= h((string) ($incident["host"] ?? "host")) ?> · root cause <?= h((string) ($incident["root_service_name"] ?? "unknown")) ?>
        <?php else: ?>
          No correlated incident matched this id.
        <?php endif; ?>
      </div>
    </div>
    <?php if ($incident): ?>
      <form method="post" class="row gap-sm middle">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <label class="row gap-xs middle">State
          <select name="state">
            <?php foreach (["open", "acknowledged", "resolved", "suppressed"] as $state): ?>
              <option value="<?= h($state) ?>" <?= (($incident["status"] ?? "open") === $state) ? "selected" : "" ?>><?= h(ucfirst($state)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn" type="submit">Update</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($incident): ?>
  <div class="card">
    <div class="row gap wrap">
      <span class="chip <?= h(strtolower((string) ($incident["severity"] ?? "warn"))) ?>"><?= h(strtoupper((string) ($incident["severity"] ?? "warn"))) ?></span>
      <span class="chip neutral">Events <?= (int) ($incident["event_count"] ?? 0) ?></span>
      <span class="chip neutral">Downstream <?= (int) ($incident["suppressed_count"] ?? 0) ?></span>
      <a class="chip neutral" href="<?= h(project_url("/service_detail.php?id=" . rawurlencode((string) ($incident["root_service_id"] ?? "")))) ?>">Root Service</a>
      <span class="chip neutral">First seen <?= h(date("Y-m-d H:i:s", (int) ($incident["first_ts"] ?? time()))) ?></span>
      <span class="chip neutral">Last seen <?= h(date("Y-m-d H:i:s", (int) ($incident["last_ts"] ?? time()))) ?></span>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Timeline</div>
    <div class="muted small" style="margin-bottom:.75rem;">Alerts are correlated with service state changes, audit events, backup actions, and speedtest anomalies in one chronological view.</div>
    <div class="row gap wrap small" id="incidentTimelineFilters" style="margin-bottom:.75rem;">
      <?php foreach (["alert" => "Alerts", "service_state" => "Service States", "audit" => "Audit", "backup" => "Backups", "speedtest" => "Speedtest"] as $filterKey => $filterLabel): ?>
        <label class="chip neutral" style="cursor:pointer;">
          <input type="checkbox" data-filter-type="<?= h($filterKey) ?>" checked style="margin-right:.35rem;">
          <?= h($filterLabel) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap">
      <table class="table js-sortable" id="incidentTimelineTable">
        <thead>
          <tr><th data-sort="str">Time</th><th data-sort="str">Type</th><th data-sort="str">Summary</th><th data-sort="str">Details</th></tr>
        </thead>
        <tbody>
          <?php foreach ($incident["timeline"] as $row): ?>
            <?php
              $type = (string) ($row["type"] ?? "event");
              $summary = "";
              $details = "";
              if ($type === "alert") {
                  $summary = (($row["status"] ?? "") === "downstream" ? "[downstream] " : "") . (($row["service_name"] ?? "") . " " . ($row["alert_name"] ?? ""));
                  $details = trim((string) ($row["metric"] ?? "") . " value=" . (string) ($row["value"] ?? "") . " threshold=" . (string) ($row["threshold"] ?? ""));
              } elseif ($type === "service_state") {
                  $summary = (string) (($row["service_name"] ?? "") . " status " . ($row["status"] ?? ""));
                  $details = trim("latency=" . (string) ($row["latency_ms"] ?? "n/a") . " http=" . (string) ($row["http_code"] ?? "n/a"));
              } elseif ($type === "audit") {
                  $summary = (string) ($row["message"] ?? "");
                  $details = trim((string) (($row["category"] ?? "") . " " . ($row["context"] ?? "")));
              } elseif ($type === "backup") {
                  $summary = (string) (($row["action"] ?? "") . " " . (!empty($row["ok"]) ? "ok" : "failed"));
                  $details = (string) ($row["message"] ?? "");
              } elseif ($type === "speedtest") {
                  $summary = "Speedtest anomaly";
                  $details = trim("status=" . (string) ($row["status"] ?? "") . " dl=" . (string) ($row["download_mbps"] ?? "") . " packet_loss=" . (string) ($row["packet_loss"] ?? ""));
              }
            ?>
            <tr data-timeline-type="<?= h($type) ?>">
              <td><?= h(date("Y-m-d H:i:s", (int) ($row["ts"] ?? time()))) ?></td>
              <td><?= h($type) ?></td>
              <td><?= h($summary) ?></td>
              <td><pre class="small" style="margin:0;white-space:pre-wrap;"><?= h($details) ?></pre></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
    (function () {
      var wrap = document.getElementById("incidentTimelineFilters");
      var table = document.getElementById("incidentTimelineTable");
      if (!wrap || !table) return;
      function applyFilters() {
        var active = {};
        wrap.querySelectorAll("input[data-filter-type]").forEach(function (input) {
          active[input.getAttribute("data-filter-type") || ""] = !!input.checked;
        });
        table.querySelectorAll("tbody tr[data-timeline-type]").forEach(function (row) {
          var type = row.getAttribute("data-timeline-type") || "";
          row.hidden = !active[type];
        });
      }
      wrap.addEventListener("change", applyFilters);
      applyFilters();
    })();
  </script>
<?php endif; ?>

<?php include __DIR__ . "/includes/foot.php"; ?>
