<?php
// bookmarks.php
$PAGE_TITLE = 'Bookmarks';
$PAGE_CSS = 'assets/css/pages/bookmarks.css';
$PAGE_JS  = 'assets/js/pages/bookmarks.js';
require_once __DIR__ . '/includes/head.php';
?>
<main class="wrap" id="bookmarks-root" data-api-base="<?= h(project_url('/api/')) ?>">
 <div class="card">
  <div class="card">
    <div class="left">
      <h1>Bookmarks</h1>
      <div class="tabs">
        <?php
  $scheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO']
           : (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $target = $scheme . '://' . $host . project_url('/bookmarks.php');
  $bm_js  = "(function(){"
          . "var u=encodeURIComponent(location.href);"
          . "var t=encodeURIComponent(document.title);"
          . "var tg=prompt('Tags (comma,separated):','');"
          . "window.open('".$target."#add?u='+u+'&t='+t+'&tags='+encodeURIComponent(tg),'_blank','noopener');"
          . "})();";
?>
<a class="btn ghost"
   id="bookmarklet"
   href="javascript:<?= htmlspecialchars($bm_js, ENT_QUOTES) ?>"
   title="Drag this to your bookmarks bar to quickly add the current page">Add to Browser</a>
        <button class="btn" id="openManage" type="button">Manage Bookmarks</button>
      </div>
      <br />
    </div>
    <div class="right">
      <div class="controls">
        <label class="inline">
          <span>Category</span>
          <select id="bmCatFilter">
            <option value="">All</option>
          </select>
        </label>
        <label class="inline">
          <span>Sort</span>
          <select id="bmSort">
            <option value="updated_desc">Updated (newest)</option>
            <option value="title_asc">Title (A→Z)</option>
            <option value="host_asc">Host (A→Z)</option>
          </select>
        </label>
        <button id="bmRefresh" class="btn"><span data-i18n="alerts.refresh">Refresh</span></button>
      </div>
    </div>
   </div>
  </header>

  <section class="grid-2">
    <!-- Left: Add/Edit bookmark -->
    <section class="card add-form">
      <form id="bmForm">
        <div class="row">
          <label>Title
            <input type="text" id="bmTitle" placeholder="Optional title">
          </label>
          <label>URL
            <input type="url" id="bmUrl" placeholder="https://example.com" required>
          </label>
          <label>Tags
            <input type="text" id="bmTags" placeholder="comma,separated,tags">
          </label>
          <label>Category
            <select id="bmCategory"></select>
          </label>
          <div class="actions">
            <button id="bmSave" class="btn"><span data-i18n="common.save">Save</span></button>
            <button id="bmCancel" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button>
          </div>
        </div>
      </form>
    </section>

    
    <!-- Right: Add Category -->
    <section class="card cats">
      <h2>Add Category</h2>
      <form id="catFormMain" class="cat-form">
        <input type="hidden" id="catIdMain">
        <label>Name
          <input type="text" id="catNameMain" placeholder="New category name">
        </label>
        <div class="actions">
          <button id="catSaveMain" class="btn"><span data-i18n="common.save">Save</span></button>
          <button id="catCancelMain" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button>
        </div>
      </form>
    </section>
  <section class="card list">
    <div class="list-header">
      <span>Title</span><span>URL</span><span>Tags</span><span>Updated</span><span>Actions</span>
    </div>
    <div id="bmList" class="list-body" aria-live="polite"></div>
    <div id="bmEmpty" class="empty">No bookmarks yet.</div>
  </section>

  <!-- Manage Bookmarks Modal -->
  <div id="bmModal" class="modal" hidden aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-dialog">
      <button class="modal-close" aria-label="Close">×</button>
      <h2>Bookmarks Managment</h2>
      <div class="modal-grid">
        <section class="card add-form">
          <h3>Add / Edit Bookmark</h3>
          <form id="bmFormModal" class="row">
            <input type="hidden" id="bmId">
            <label>Title <input type="text" id="bmTitle" placeholder="Optional title"></label>
            <label>URL <input type="url" id="bmUrl" placeholder="https://example.com"></label>
            <label>Tags <input type="text" id="bmTags" placeholder="comma,separated,tags"></label>
            <label>Category <select id="bmCategory"></select></label>
            <div class="actions"><button id="bmSave" class="btn"><span data-i18n="common.save">Save</span></button><button id="bmCancel" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button></div>
          </form>
          <div class="hint">Display Bookmarks here with collapsible categories. Click a bookmark to display in textbox above to edit or delete.</div>
          <div id="bmListModal" class="list-body scroll-y"></div>
        </section>
        <section class="card cats">
          <h3>Add Categories</h3>
          <form id="catForm" class="cat-form">
            <input type="hidden" id="catId">
            <label>Name <input type="text" id="catName" placeholder="New category name"></label>
            <div class="actions"><button id="catSave" class="btn"><span data-i18n="common.save">Save</span></button><button id="catCancel" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button><button id="catDelete" class="btn danger" type="button"><span data-i18n="common.delete">Delete</span></button></div>
          </form>
          <div class="hint">Categories displayed here. Click a category to display in textbox above to edit or delete category</div>
          <div id="catList" class="cat-list scroll-y" aria-live="polite"></div>
          <div id="catEmpty" class="empty">No categories yet.</div>
        </section>
      </div>
    </div>
  </div>
</div>
</div>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      var c = document.querySelector('.content');
      if (c) c.classList.add('content-locked');
    });
  </script>

</main>
<?php require_once __DIR__ . '/includes/foot.php'; ?>
