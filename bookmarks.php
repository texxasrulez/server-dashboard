<?php
// bookmarks.php
require_once __DIR__ . '/includes/i18n.php';
$PAGE_TITLE = __('bookmarks_page.title', 'Bookmarks');
$PAGE_CSS = 'assets/css/pages/bookmarks.css';
$PAGE_JS  = 'assets/js/pages/bookmarks.js';
$REQUIRE_ADMIN = true;
require_once __DIR__ . '/includes/head.php';
?>
<main class="wrap" id="bookmarks-root" data-api-base="<?= h(project_url('/api/')) ?>">
 <div class="card">
  <div class="card">
    <div class="left">
      <h1 data-i18n="bookmarks_page.title"><?= h(__('bookmarks_page.title', 'Bookmarks')) ?></h1>
      <div class="tabs">
        <?php
  $scheme = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO']
           : (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$target = $scheme . '://' . $host . project_url('/bookmarks.php');
$bm_js  = "(function(){"
        . "var u=encodeURIComponent(location.href);"
        . "var t=encodeURIComponent(document.title);"
        . "var tg=prompt(".json_encode(__('bookmarks_page.prompt_tags', 'Tags (comma,separated):')).",'');"
        . "window.open('".$target."#add?u='+u+'&t='+t+'&tags='+encodeURIComponent(tg),'_blank','noopener');"
        . "})();";
?>
<a class="btn ghost"
   id="bookmarklet"
   href="javascript:<?= htmlspecialchars($bm_js, ENT_QUOTES) ?>"
   title="<?= h(__('bookmarks_page.bookmarklet_title', 'Drag this to your bookmarks bar to quickly add the current page')) ?>"
   data-i18n="bookmarks_page.bookmarklet_title"
   data-i18n-attr="title"><span data-i18n="bookmarks_page.add_to_browser"><?= h(__('bookmarks_page.add_to_browser', 'Add to Browser')) ?></span></a>
        <button class="btn" id="openManage" type="button" data-i18n="bookmarks_page.manage">Manage Bookmarks</button>
      </div>
    </div>
    <div class="right">
      <div class="controls">
        <label class="inline">
          <span data-i18n="bookmarks_page.category">Category</span>
          <select id="bmCatFilter">
            <option value=""><?= h(__('bookmarks_page.all', 'All')) ?></option>
          </select>
        </label>
        <label class="inline">
          <span data-i18n="bookmarks_page.sort">Sort</span>
          <select id="bmSort">
            <option value="updated_desc"><?= h(__('bookmarks_page.sort_updated_desc', 'Updated (newest)')) ?></option>
            <option value="title_asc"><?= h(__('bookmarks_page.sort_title_asc', 'Title (A-Z)')) ?></option>
            <option value="host_asc"><?= h(__('bookmarks_page.sort_host_asc', 'Host (A-Z)')) ?></option>
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
          <label><span data-i18n="bookmarks_page.fields.title">Title</span>
            <input type="text" id="bmTitle" placeholder="<?= h(__('bookmarks_page.placeholders.optional_title', 'Optional title')) ?>" data-i18n="bookmarks_page.placeholders.optional_title" data-i18n-attr="placeholder">
          </label>
          <label><span data-i18n="bookmarks_page.fields.url">URL</span>
            <input type="url" id="bmUrl" placeholder="https://example.com" required>
          </label>
          <label><span data-i18n="bookmarks_page.fields.tags">Tags</span>
            <input type="text" id="bmTags" placeholder="<?= h(__('bookmarks_page.placeholders.tags', 'comma,separated,tags')) ?>" data-i18n="bookmarks_page.placeholders.tags" data-i18n-attr="placeholder">
          </label>
          <label><span data-i18n="bookmarks_page.category">Category</span>
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
      <h2 data-i18n="bookmarks_page.add_category">Add Category</h2>
      <form id="catFormMain" class="cat-form">
        <input type="hidden" id="catIdMain">
        <label><span data-i18n="bookmarks_page.fields.name">Name</span>
          <input type="text" id="catNameMain" placeholder="<?= h(__('bookmarks_page.placeholders.new_category_name', 'New category name')) ?>" data-i18n="bookmarks_page.placeholders.new_category_name" data-i18n-attr="placeholder">
        </label>
        <div class="actions">
          <button id="catSaveMain" class="btn"><span data-i18n="common.save">Save</span></button>
          <button id="catCancelMain" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button>
        </div>
      </form>
    </section>
  <section class="card list">
    <div class="list-header">
      <span data-i18n="bookmarks_page.fields.title">Title</span><span data-i18n="bookmarks_page.fields.url">URL</span><span data-i18n="bookmarks_page.fields.tags">Tags</span><span data-i18n="bookmarks_page.updated">Updated</span><span data-i18n="common.actions">Actions</span>
    </div>
    <div id="bmList" class="list-body" aria-live="polite"></div>
    <div id="bmEmpty" class="empty" data-i18n="bookmarks_page.empty">No bookmarks yet.</div>
  </section>

  <!-- Manage Bookmarks Modal -->
  <div id="bmModal" class="modal" hidden aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal-dialog">
      <button class="modal-close" aria-label="<?= h(__('common.close', 'Close')) ?>">×</button>
      <h2 data-i18n="bookmarks_page.manage">Manage Bookmarks</h2>
      <div class="modal-grid">
        <section class="card add-form">
          <h3 data-i18n="bookmarks_page.modal.add_edit">Add / Edit Bookmark</h3>
          <form id="bmFormModal" class="row">
            <input type="hidden" id="bmId">
            <label><span data-i18n="bookmarks_page.fields.title">Title</span> <input type="text" id="bmTitle" placeholder="<?= h(__('bookmarks_page.placeholders.optional_title', 'Optional title')) ?>" data-i18n="bookmarks_page.placeholders.optional_title" data-i18n-attr="placeholder"></label>
            <label><span data-i18n="bookmarks_page.fields.url">URL</span> <input type="url" id="bmUrl" placeholder="https://example.com"></label>
            <label><span data-i18n="bookmarks_page.fields.tags">Tags</span> <input type="text" id="bmTags" placeholder="<?= h(__('bookmarks_page.placeholders.tags', 'comma,separated,tags')) ?>" data-i18n="bookmarks_page.placeholders.tags" data-i18n-attr="placeholder"></label>
            <label><span data-i18n="bookmarks_page.category">Category</span> <select id="bmCategory"></select></label>
            <div class="actions"><button id="bmSave" class="btn"><span data-i18n="common.save">Save</span></button><button id="bmCancel" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button></div>
          </form>
          <div class="hint" data-i18n="bookmarks_page.hints.bookmark_edit">Display bookmarks here with collapsible categories. Click a bookmark to load it above for editing or deletion.</div>
          <div id="bmListModal" class="list-body scroll-y"></div>
        </section>
        <section class="card cats">
          <h3 data-i18n="bookmarks_page.modal.add_categories">Add Categories</h3>
          <form id="catForm" class="cat-form">
            <input type="hidden" id="catId">
            <label><span data-i18n="bookmarks_page.fields.name">Name</span> <input type="text" id="catName" placeholder="<?= h(__('bookmarks_page.placeholders.new_category_name', 'New category name')) ?>" data-i18n="bookmarks_page.placeholders.new_category_name" data-i18n-attr="placeholder"></label>
            <div class="actions"><button id="catSave" class="btn"><span data-i18n="common.save">Save</span></button><button id="catCancel" class="btn secondary" type="button"><span data-i18n="common.cancel">Cancel</span></button><button id="catDelete" class="btn danger" type="button"><span data-i18n="common.delete">Delete</span></button></div>
          </form>
          <div class="hint" data-i18n="bookmarks_page.hints.category_edit">Categories appear here. Click one to load it above for editing or deletion.</div>
          <div id="catList" class="cat-list scroll-y" aria-live="polite"></div>
          <div id="catEmpty" class="empty" data-i18n="bookmarks_page.empty_categories">No categories yet.</div>
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
