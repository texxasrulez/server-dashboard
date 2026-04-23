<?php

require_once __DIR__ . "/includes/init.php";
require_once __DIR__ . "/includes/auth.php";
require_once __DIR__ . "/lib/ServerDiag.php";
require_login();

if (!user_is_admin()) {
    http_response_code(403);
    echo "Admin only.";
    exit();
}

$PAGE_TITLE = "Diagnostics";
$PAGE_CSS = "assets/css/page_diag.css";
$PAGE_JS = "assets/js/pages/diag.js";

$report = \App\ServerDiag::fullReport();
$summary = $report["summary"];
$groups = $report["groups"];

function diag_badge_class(string $status): string
{
    if ($status === "fail") {
        return "diag-badge fail";
    }
    if ($status === "warn") {
        return "diag-badge warn";
    }
    return "diag-badge ok";
}

function diag_status_label(string $status): string
{
    if ($status === "fail") {
        return "FAIL";
    }
    if ($status === "warn") {
        return "WARN";
    }
    return "PASS";
}

include __DIR__ . "/includes/head.php";
?>
<div class="card">
  <div class="diag-shell">
    <div class="diag-hero">
      <div>
        <div class="section-title">Environment Doctor</div>
        <p class="muted">Actionable preflight checks for runtime health, writable paths, token state, mail transport, and configuration sanity.</p>
      </div>
      <div class="diag-scorecard">
        <div class="diag-score">
          <strong><?= (int) $summary["score"] ?>%</strong>
          <span>Readiness score</span>
        </div>
        <div class="diag-counts">
          <span class="diag-badge ok">PASS <?= (int) $summary["pass"] ?></span>
          <span class="diag-badge warn">WARN <?= (int) $summary[
              "warn"
          ] ?></span>
          <span class="diag-badge fail">FAIL <?= (int) $summary[
              "fail"
          ] ?></span>
        </div>
      </div>
    </div>

    <div class="diag-meta muted small">
      <span>Generated <?= h($report["generated_at"]) ?></span>
      <span>Build <?= h(BUILD) ?></span>
      <span>User <?= h($_SESSION["user"]["username"] ?? "guest") ?></span>
      <span>Theme <?= h($THEME ?? "default") ?></span>
    </div>

    <div class="diag-grid">
      <section class="card diag-card">
        <div class="card-title">Failures</div>
        <div class="card-body">
          <?php if (!$groups["fail"]): ?>
            <p class="muted">No blocking failures detected.</p>
          <?php else: ?>
            <div class="diag-list">
              <?php foreach ($groups["fail"] as $item): ?>
                <article class="diag-item">
                  <div class="diag-item-head">
                    <span class="<?= h(
                        diag_badge_class($item["status"]),
                    ) ?>"><?= h(diag_status_label($item["status"])) ?></span>
                    <strong><?= h($item["name"]) ?></strong>
                  </div>
                  <div class="diag-item-body">
                    <p><code><?= h($item["details"]) ?></code></p>
                    <p class="muted"><?= h($item["action"]) ?></p>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card diag-card">
        <div class="card-title">Warnings</div>
        <div class="card-body">
          <?php if (!$groups["warn"]): ?>
            <p class="muted">No warnings at the moment.</p>
          <?php else: ?>
            <div class="diag-list">
              <?php foreach ($groups["warn"] as $item): ?>
                <article class="diag-item">
                  <div class="diag-item-head">
                    <span class="<?= h(
                        diag_badge_class($item["status"]),
                    ) ?>"><?= h(diag_status_label($item["status"])) ?></span>
                    <strong><?= h($item["name"]) ?></strong>
                  </div>
                  <div class="diag-item-body">
                    <p><code><?= h($item["details"]) ?></code></p>
                    <p class="muted"><?= h($item["action"]) ?></p>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="card diag-card">
      <div class="card-title">Passing Checks</div>
      <div class="card-body">
        <div class="diag-list diag-list-compact">
          <?php foreach ($groups["ok"] as $item): ?>
            <article class="diag-item compact">
              <div class="diag-item-head">
                <span class="<?= h(diag_badge_class($item["status"])) ?>"><?= h(
    diag_status_label($item["status"]),
) ?></span>
                <strong><?= h($item["name"]) ?></strong>
              </div>
              <div class="diag-item-body">
                <p><code><?= h($item["details"]) ?></code></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card diag-card">
      <div class="card-title">Admin Shortcuts</div>
      <div class="card-body">
        <div class="diag-actions">
          <a class="btn secondary" href="<?= h(
              project_url("/config.php"),
          ) ?>">Open Config</a>
          <a class="btn secondary" href="<?= h(
              project_url("/history.php"),
          ) ?>">Open History</a>
          <a class="btn secondary" href="<?= h(
              project_url("/api/health.php"),
          ) ?>" data-modal-url="<?= h(
    project_url("/api/health.php"),
) ?>" data-modal-kind="json">Open `api/health.php`</a>
          <a class="btn secondary" href="<?= h(
              project_url("/tools/assets_audit.php"),
          ) ?>" data-modal-url="<?= h(
    project_url("/tools/assets_audit.php"),
) ?>" data-modal-kind="iframe">Assets Audit</a>
          <a class="btn secondary" href="<?= h(
              project_url("/tools/admin_audit.php"),
          ) ?>" data-modal-url="<?= h(
    project_url("/tools/admin_audit.php"),
) ?>" data-modal-kind="iframe">Admin Audit</a>
        </div>
      </div>
    </section>
  </div>
</div>
<?php include __DIR__ . "/includes/foot.php"; ?>
