<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/AdminAudit.php";
require_login();

if (!user_is_admin()) {
    http_response_code(403);
    echo "Admin only.";
    exit();
}

$PAGE_TITLE = "Admin Audit";
$BASE_HREF = rtrim((string) (defined("BASE_URL") ? BASE_URL : "/"), "/") . "/";
$docLang = defined("DASH_LOCALE") ? (string) DASH_LOCALE : "en";
$report = AdminAudit::buildReport();
$helpMap = [
    "admin" => [
        "heading" =>
            "This consolidated audit log tracks sensitive actions across the dashboard.",
        "items" => [
            "Config saves, service changes, privileged log reads, backup jobs, and support bundle generation are recorded here.",
            "Sensitive values are redacted before audit metadata is persisted.",
            "Incident timelines and service detail pages reuse this same audit stream.",
        ],
        "actions" => [
            ["label" => "Open Config", "href" => project_url("/config.php")],
            ["label" => "Open Backups", "href" => project_url("/backups.php")],
        ],
    ],
    "security" => [
        "heading" => "This log is created after security actions occur.",
        "items" => [
            "Authorize cron-token reveal in Config > Security.",
            "Rotate the cron token from the admin token controls.",
            "Future security events will appear here automatically once written.",
        ],
        "actions" => [
            ["label" => "Open Config", "href" => project_url("/config.php")],
        ],
    ],
    "diagnostics" => [
        "heading" => "This log is created after diagnostic actions occur.",
        "items" => [
            "Run Quick Scan or other actions from Server Tests.",
            "Diagnostic audit entries are written by the server tests API layer.",
            "Once a test action runs, recent events will appear here automatically.",
        ],
        "actions" => [
            [
                "label" => "Open Server Tests",
                "href" => project_url("/server_tests.php"),
            ],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= h($docLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Audit</title>
  <base href="<?= h($BASE_HREF) ?>">
  <link rel="stylesheet" href="<?= h(
      project_url("/assets/css/app.css"),
  ) ?>?v=<?= h(BUILD) ?>">
  <link rel="stylesheet" href="<?= h(
      project_url("/assets/css/themes/core.css"),
  ) ?>?v=<?= h(BUILD) ?>">
  <script defer src="<?= h(
      project_url("/assets/js/utils/sortable-table.js"),
  ) ?>?v=<?= h(BUILD) ?>"></script>
</head>
<body>
<main class="container" style="padding:20px 16px 28px">
<div class="card">
  <div class="row between wrap gap">
    <div>
      <div class="section-title">Admin Audit</div>
      <div class="muted small">Recent security and diagnostic events already recorded by the dashboard.</div>
    </div>
    <div class="muted small">
      <div>Generated <?= h($report["generated_at"]) ?></div>
      <div>Total events <?= (int) ($report["summary"]["total_events"] ??
          0) ?></div>
    </div>
  </div>
</div>

<?php foreach ($report["sources"] as $source): ?>
  <div class="card">
    <div class="row between wrap gap">
      <div>
        <div class="section-title"><?= h($source["label"]) ?></div>
        <div class="muted small"><?= h($source["description"]) ?></div>
        <div class="muted small"><code><?= h($source["path"]) ?></code></div>
      </div>
      <div class="muted small">
        <div><?= $source["exists"] ? "Log present" : "Log missing" ?></div>
        <div><?= (int) ($source["event_count"] ??
            0) ?> recent events shown</div>
      </div>
    </div>

    <?php if (!$source["exists"]): ?>
      <?php $help = $helpMap[$source["key"]] ?? null; ?>
      <div class="muted" style="display:grid;gap:10px">
        <p><?= h(
            $help["heading"] ?? "This log has not been created yet.",
        ) ?></p>
        <?php if (!empty($help["items"])): ?>
          <ul style="margin:0;padding-left:18px">
            <?php foreach ($help["items"] as $item): ?>
              <li><?= h($item) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($help["actions"])): ?>
          <div class="row wrap gap">
            <?php foreach ($help["actions"] as $action): ?>
              <a class="btn secondary" href="<?= h($action["href"]) ?>"><?= h(
    $action["label"],
) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table js-sortable">
          <thead>
            <tr>
              <th data-sort="str">Time</th>
              <th data-sort="str">Message</th>
              <th data-sort="str">User</th>
              <th data-sort="str">IP</th>
              <th data-sort="str">Context</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($source["events"] as $event): ?>
              <tr>
                <td><?= h($event["time"] ?: "—") ?></td>
                <td><?= h($event["message"] ?: "—") ?></td>
                <td><?= h($event["user"] ?: "—") ?></td>
                <td><?= h($event["ip"] ?: "—") ?></td>
                <td><pre class="small" style="margin:0;white-space:pre-wrap"><?= h(
                    $event["context"] ?: "—",
                ) ?></pre></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row wrap gap small muted" style="margin-top:12px">
        <?php if (!$source["message_counts"]): ?>
          <span>No event summary available.</span>
        <?php else: ?>
          <?php foreach (
              array_slice($source["message_counts"], 0, 6, true)
              as $message => $count
          ): ?>
            <span class="badge"><?= h($message) ?>: <?= (int) $count ?></span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</main>
</body>
</html>
