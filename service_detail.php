<?php

declare(strict_types=1);

require_once __DIR__ . "/includes/init.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/lib/ServiceDetails.php";
require_once __DIR__ . "/lib/IncidentManager.php";
require_admin();

$id = trim((string) ($_GET["id"] ?? ""));
$detail = $id !== "" ? ServiceDetails::get($id) : null;
$incidents = [];
if ($detail) {
    foreach (IncidentManager::recent(50) as $incident) {
        if (in_array($id, $incident["services"] ?? [], true)) {
            $incidents[] = $incident;
        }
    }
}

$PAGE_TITLE = $detail ? (($detail["service"]["name"] ?? "Service") . " Detail") : "Service Not Found";
$PAGE_JS = ["assets/js/utils/sortable-table.js"];
include __DIR__ . "/includes/head.php";
?>
<div class="card">
  <div class="row between wrap gap">
    <div>
      <div class="section-title"><?= h($detail["service"]["name"] ?? "Service not found") ?></div>
      <div class="muted small">
        <?php if ($detail): ?>
          <?= h((string) ($detail["service"]["host"] ?? "")) ?>:<?= h((string) ($detail["service"]["port"] ?? "")) ?> · <?= h((string) ($detail["service"]["check"] ?? "tcp")) ?>
        <?php else: ?>
          Unknown service id.
        <?php endif; ?>
      </div>
    </div>
    <?php if ($detail): ?>
      <?php $meta = $detail["service"]["status_meta"] ?? []; ?>
      <div class="row gap wrap">
        <span class="chip <?= h((string) (($meta["status"] ?? "neutral") === "up" ? "ok" : (($meta["status"] ?? "") === "warn" ? "warn" : "down"))) ?>"><?= h(strtoupper((string) ($meta["status"] ?? "unknown"))) ?></span>
        <span class="chip neutral">Latency <?= h((string) ($meta["latency_ms"] ?? "n/a")) ?> ms</span>
        <span class="chip neutral">HTTP <?= h((string) ($meta["http_code"] ?? "n/a")) ?></span>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($detail): ?>
  <div class="card">
    <div class="section-title">Related Incidents</div>
    <?php if (!$incidents): ?>
      <div class="muted small">No recent correlated incidents for this service.</div>
    <?php else: ?>
      <div class="row wrap gap">
        <?php foreach ($incidents as $incident): ?>
          <a class="chip warn" href="<?= h(project_url("/incident.php?id=" . rawurlencode((string) $incident["id"]))) ?>"><?= h((string) $incident["title"]) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="section-title">Recent Failures</div>
    <div class="table-wrap">
      <table class="table js-sortable">
        <thead><tr><th data-sort="str">Time</th><th data-sort="str">Status</th><th data-sort="num">Latency</th><th data-sort="num">HTTP</th></tr></thead>
        <tbody>
          <?php if (!$detail["failures"]): ?>
            <tr><td colspan="4" class="muted">No recent failures recorded.</td></tr>
          <?php else: ?>
            <?php foreach ($detail["failures"] as $row): ?>
              <tr>
                <td><?= h(date("Y-m-d H:i:s", (int) ($row["ts"] ?? time()))) ?></td>
                <td><?= h((string) ($row["status"] ?? "unknown")) ?></td>
                <td><?= h((string) ($row["latency_ms"] ?? "n/a")) ?></td>
                <td><?= h((string) ($row["http_code"] ?? "n/a")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Restart / Recovery History</div>
    <div class="table-wrap">
      <table class="table js-sortable">
        <thead><tr><th data-sort="str">Time</th><th data-sort="str">Event</th></tr></thead>
        <tbody>
          <?php if (!$detail["restarts"]): ?>
            <tr><td colspan="2" class="muted">No restart or recovery transitions found in the retained history window.</td></tr>
          <?php else: ?>
            <?php foreach ($detail["restarts"] as $row): ?>
              <tr>
                <td><?= h(date("Y-m-d H:i:s", (int) ($row["ts"] ?? time()))) ?></td>
                <td><?= h((string) ($row["label"] ?? "Recovery")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="section-title">Recent Alert Events</div>
    <div class="table-wrap">
      <table class="table js-sortable">
        <thead><tr><th data-sort="str">Time</th><th data-sort="str">Name</th><th data-sort="str">Condition</th><th data-sort="num">Value</th><th data-sort="str">Severity</th></tr></thead>
        <tbody>
          <?php if (!$detail["alerts"]): ?>
            <tr><td colspan="5" class="muted">No recent alert events for this service.</td></tr>
          <?php else: ?>
            <?php foreach ($detail["alerts"] as $row): ?>
              <tr>
                <td><?= h(date("Y-m-d H:i:s", (int) ($row["ts"] ?? time()))) ?></td>
                <td><?= h((string) ($row["alert_name"] ?? "alert")) ?></td>
                <td><?= h(trim((string) ($row["metric"] ?? "") . " " . (string) ($row["op"] ?? "") . " " . (string) ($row["threshold"] ?? ""))) ?></td>
                <td><?= h((string) ($row["value"] ?? "n/a")) ?></td>
                <td><?= h((string) ($row["severity"] ?? "warn")) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="row between wrap gap">
      <div class="section-title">Recent Admin Actions</div>
      <div class="muted small">Sensitive metadata is redacted before persistence.</div>
    </div>
    <div class="table-wrap">
      <table class="table js-sortable">
        <thead><tr><th data-sort="str">Time</th><th data-sort="str">Message</th><th data-sort="str">User</th><th data-sort="str">Context</th></tr></thead>
        <tbody>
          <?php if (!$detail["audit"]): ?>
            <tr><td colspan="4" class="muted">No recent audited admin actions matched this service.</td></tr>
          <?php else: ?>
            <?php foreach ($detail["audit"] as $row): ?>
              <tr>
                <td><?= h((string) ($row["time"] ?? "—")) ?></td>
                <td><?= h((string) ($row["message"] ?? "—")) ?></td>
                <td><?= h((string) ($row["user"] ?? "—")) ?></td>
                <td><pre class="small" style="margin:0;white-space:pre-wrap;"><?= h((string) ($row["context"] ?? "—")) ?></pre></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($detail["privileged_logs"]): ?>
    <div class="card">
      <div class="section-title">Relevant Logs</div>
      <div class="row wrap gap">
        <?php foreach ($detail["privileged_logs"] as $log): ?>
          <a class="chip neutral" href="<?= h(project_url("/logs.php?mode=live&live_log_key=" . rawurlencode((string) $log["key"]))) ?>"><?= h((string) $log["label"]) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . "/includes/foot.php"; ?>
