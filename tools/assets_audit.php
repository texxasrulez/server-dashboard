<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";

header("Content-Type: text/html; charset=utf-8");
require_login();
require_admin();

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$baseHref = rtrim((string) (defined("BASE_URL") ? BASE_URL : "/"), "/") . "/";

function audit_rel(string $root, string $path): string
{
    return ltrim(str_replace($root . DIRECTORY_SEPARATOR, "", $path), "/");
}

function audit_href(string $baseHref, string $path): string
{
    return $baseHref . ltrim($path, "/");
}

function audit_collect_files(string $root, array $extensions): array
{
    $out = [];
    $skip = ["vendor", "node_modules", "state", "data", ".git", "docs"];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $rel = audit_rel($root, $path);
        foreach ($skip as $segment) {
            if (strpos($rel, $segment . "/") === 0) {
                continue 2;
            }
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $out[] = $path;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

function audit_extract_asset_refs(string $contents): array
{
    preg_match_all(
        "~assets/(?:js|css)/[A-Za-z0-9_./-]+\.(?:js|css)~",
        $contents,
        $matches,
    );
    return array_values(array_unique($matches[0] ?? []));
}

$scanFiles = audit_collect_files($root, ["php", "js", "html"]);
$assetFiles = array_merge(
    audit_collect_files($root . "/assets/js", ["js"]),
    audit_collect_files($root . "/assets/css", ["css"]),
);

$assetInventory = [];
foreach ($assetFiles as $file) {
    $rel = audit_rel($root, $file);
    $assetInventory[$rel] = [
        "path" => $rel,
        "size_bytes" => (int) filesize($file),
        "mtime" => (int) filemtime($file),
    ];
}

$referenced = [];
foreach ($scanFiles as $file) {
    $source = audit_rel($root, $file);
    $contents = (string) @file_get_contents($file);
    foreach (audit_extract_asset_refs($contents) as $ref) {
        if ($ref === $source) {
            continue;
        }
        if (!isset($referenced[$ref])) {
            $referenced[$ref] = [];
        }
        $referenced[$ref][] = $source;
    }
}
ksort($referenced, SORT_NATURAL | SORT_FLAG_CASE);

$missingRefs = [];
foreach ($referenced as $ref => $sources) {
    if (!isset($assetInventory[$ref])) {
        $missingRefs[] = [
            "path" => $ref,
            "sources" => array_values(array_unique($sources)),
        ];
    }
}

$pageAssets = array_values(
    array_filter(array_keys($assetInventory), function ($path) {
        return strpos($path, "assets/js/pages/") === 0 ||
            strpos($path, "assets/css/pages/") === 0;
    }),
);

$orphanPageAssets = [];
foreach ($pageAssets as $path) {
    if (!isset($referenced[$path])) {
        $orphanPageAssets[] = [
            "path" => $path,
            "size_bytes" => $assetInventory[$path]["size_bytes"] ?? 0,
        ];
    }
}

$largestAssets = array_values($assetInventory);
usort($largestAssets, function (array $a, array $b): int {
    return $b["size_bytes"] <=> $a["size_bytes"];
});
$largestAssets = array_slice($largestAssets, 0, 15);

$report = [
    "generated_at" => date("c"),
    "build" => BUILD,
    "summary" => [
        "scan_files" => count($scanFiles),
        "asset_files" => count($assetInventory),
        "referenced_assets" => count($referenced),
        "missing_references" => count($missingRefs),
        "orphan_page_assets" => count($orphanPageAssets),
    ],
    "missing_references" => $missingRefs,
    "orphan_page_assets" => $orphanPageAssets,
    "largest_assets" => $largestAssets,
    "referenced_assets" => $referenced,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assets Audit</title>
  <base href="<?= h($baseHref) ?>">
  <link rel="stylesheet" href="<?= h(
      audit_href($baseHref, "assets/css/app.css"),
  ) ?>?v=<?= h(BUILD) ?>">
  <link rel="stylesheet" href="<?= h(
      audit_href($baseHref, "assets/css/themes/core.css"),
  ) ?>?v=<?= h(BUILD) ?>">
  <link rel="stylesheet" href="<?= h(
      audit_href($baseHref, "assets/css/tools/assets_audit.css"),
  ) ?>?v=<?= h(BUILD) ?>">
  <script defer src="<?= h(
      audit_href($baseHref, "assets/js/utils/sortable-table.js"),
  ) ?>?v=<?= h(BUILD) ?>"></script>
  <script>
    window.__ASSETS_AUDIT__ = <?= json_encode(
        $report,
        JSON_UNESCAPED_SLASHES,
    ) ?>;
  </script>
  <script defer src="<?= h(
      audit_href($baseHref, "assets/js/tools/assets_audit.js"),
  ) ?>?v=<?= h(BUILD) ?>"></script>
</head>
<body>
  <main class="container audit-shell">
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title">Assets Audit</div>
          <p class="muted small">Static inventory and reference scan for dashboard CSS/JS assets across PHP, JS, and HTML sources.</p>
        </div>
        <div class="audit-toolbar"></div>
      </div>
      <div class="card-body">
        <div class="audit-summary">
          <div class="audit-tile"><strong><?= (int) $report["summary"][
              "asset_files"
          ] ?></strong><span>Asset files</span></div>
          <div class="audit-tile"><strong><?= (int) $report["summary"][
              "referenced_assets"
          ] ?></strong><span>Referenced assets</span></div>
          <div class="audit-tile <?= $report["summary"]["missing_references"]
              ? "warn"
              : "" ?>"><strong><?= (int) $report["summary"][
    "missing_references"
] ?></strong><span>Missing refs</span></div>
          <div class="audit-tile <?= $report["summary"]["orphan_page_assets"]
              ? "warn"
              : "" ?>"><strong><?= (int) $report["summary"][
    "orphan_page_assets"
] ?></strong><span>Unreferenced page assets</span></div>
        </div>
        <div class="audit-meta muted small">
          <span>Generated <?= h($report["generated_at"]) ?></span>
          <span>Build <?= h($report["build"]) ?></span>
          <span>Files scanned <?= (int) $report["summary"][
              "scan_files"
          ] ?></span>
        </div>
      </div>
    </div>

    <div class="audit-grid">
      <section class="card">
        <div class="card-title">Missing Asset References</div>
        <div class="card-body">
          <?php if (!$missingRefs): ?>
            <p class="muted">No missing CSS/JS asset paths were found in scanned source files.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table audit-table js-sortable">
                <thead><tr><th data-sort="str">Asset</th><th data-sort="str">Referenced by</th></tr></thead>
                <tbody>
                  <?php foreach ($missingRefs as $row): ?>
                    <tr>
                      <td><code><?= h($row["path"]) ?></code></td>
                      <td><?= h(implode(", ", $row["sources"])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="card">
        <div class="card-title">Unreferenced Page Assets</div>
        <div class="card-body">
          <?php if (!$orphanPageAssets): ?>
            <p class="muted">Every page-scoped CSS/JS asset found under `assets/.../pages/` has at least one PHP reference.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="table audit-table js-sortable">
                <thead><tr><th data-sort="str">Asset</th><th data-sort="num">Size</th></tr></thead>
                <tbody>
                  <?php foreach ($orphanPageAssets as $row): ?>
                    <tr>
                      <td><code><?= h($row["path"]) ?></code></td>
                      <td><?= number_format((int) $row["size_bytes"]) ?> B</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <section class="card">
      <div class="card-title">Largest Assets</div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="table audit-table js-sortable">
            <thead><tr><th data-sort="str">Asset</th><th data-sort="num">Size</th><th data-sort="str">Updated</th></tr></thead>
            <tbody>
              <?php foreach ($largestAssets as $row): ?>
                <tr>
                  <td><code><?= h($row["path"]) ?></code></td>
                  <td><?= number_format((int) $row["size_bytes"]) ?> B</td>
                  <td><?= h(date("Y-m-d H:i:s", (int) $row["mtime"])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-title">Snapshot JSON</div>
      <div class="card-body audit-body">
        <pre id="aa-output" class="muted"></pre>
      </div>
    </section>
  </main>
</body>
</html>
