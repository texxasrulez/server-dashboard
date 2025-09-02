</div>
<?php
  // Safer footer label (shows actual role if present)
  $footer_user = $_SESSION['user']['username'] ?? 'guest';
  $footer_role = $_SESSION['user']['role']     ?? 'user';
?>
<footer class="footer muted">
  Logged in as<strong>: <?= h($footer_user) ?></strong> (<?= h($footer_role) ?>) <strong>&#9679;</strong> <?= h(BUILD) ?> <strong>&#9679;</strong> Theme<strong>: <?= h($THEME) ?></strong>
</footer>

<!-- Keep a single autoprobe include -->

<script defer src="<?= h(project_url('/assets/js/utils/theme-hot-swap.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/autoprobe.js')) ?>?v=<?= h(BUILD) ?>"></script>

<!-- Larry-style toasts + logging -->
<script defer src="<?= h(project_url('/assets/js/components/logger.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/app_alert_bridge.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/app_errors.js')) ?>?v=<?= h(BUILD) ?>"></script>


<!-- Core bundles -->
<link rel="stylesheet" href="<?= h(project_url('/assets/build_css.php?b=core')) ?>&v=<?= h(BUILD) ?>" />
<script defer src="<?= h(project_url('/assets/build_js.php?b=core')) ?>&v=<?= h(BUILD) ?>"></script>

<!-- Header modules (theme-safe, global) -->
<link rel="stylesheet" href="<?= h(project_url('/assets/css/header/status.css')) ?>?v=<?= h(BUILD) ?>" />
<link rel="stylesheet" href="<?= h(project_url('/assets/css/header/userbox.css')) ?>?v=<?= h(BUILD) ?>" />
<link rel="stylesheet" href="<?= h(project_url('/assets/css/header/debug.css')) ?>?v=<?= h(BUILD) ?>" />
<link rel="stylesheet" href="<?= h(project_url('/assets/css/header/email-indicator.css')) ?>?v=<?= h(BUILD) ?>" />
<script defer src="<?= h(project_url('/assets/js/header/status.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/header/debug.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/header/userbox.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="<?= h(project_url('/assets/js/header/email-indicator.js')) ?>?v=<?= h(BUILD) ?>"></script>

<?php
  // Page-scoped assets: set $PAGE_CSS and/or $PAGE_JS inside the page.
  // Example:
  //   $PAGE_CSS = 'assets/css/pages/users.css';
  //   $PAGE_JS  = 'assets/js/pages/users.profile.js';
  if (!empty($PAGE_CSS)) {
    foreach ((array)$PAGE_CSS as $css) {
      $href = project_url('/' . ltrim($css, '/'));
      echo '<link rel="stylesheet" href="' . h($href) . '?v=' . h(BUILD) . '" />' . PHP_EOL;
    }
  }
  if (!empty($PAGE_JS)) {
    foreach ((array)$PAGE_JS as $js) {
      $src = project_url('/' . ltrim($js, '/'));
      echo '<script defer src="' . h($src) . '?v=' . h(BUILD) . '"></script>' . PHP_EOL;
    }
  }
?>

<!-- toast dynamic loader (footer, drop-anywhere) -->
<script>
(function() {
  if (window.__toastLoaderInjected) return; window.__toastLoaderInjected = true;
  var prefixes=["","../","../../","../../../","../../../../"];
  function loadCSS(href){return new Promise(function(res,rej){var l=document.createElement("link");l.rel="stylesheet";l.href=href;l.onload=function(){res(href)};l.onerror=function(){l.remove();rej(href)};document.head.appendChild(l);});}
  function loadJS(src){return new Promise(function(res,rej){var s=document.createElement("script");s.src=src;s.defer=true;s.onload=function(){res(src)};s.onerror=function(){s.remove();rej(src)};document.head.appendChild(s);});}
  function boot(){
    var i=0;
    (function next(){
      if(i>=prefixes.length){console.error("[toast] notify assets not found under any relative path");return;}
      var pre=prefixes[i++];
      var css=pre+"assets/css/notify.css";
      var js =pre+"assets/js/utils/notify.js";
      loadCSS(css).then(function(){return loadJS(js)}).then(function(){
        if(!window.__toastBasePath) window.__toastBasePath=pre;
        if(window.fetch && !window.fetch.__toast_log_patch){
          var _f=window.fetch.bind(window);
          window.fetch=function(url,opts){
            try{ if(typeof url==="string" && url.indexOf("/api/client_log.php")===0){ url=(window.__toastBasePath||"")+"api/client_log.php"; } }catch(e){}
            return _f(url,opts);
          };
          window.fetch.__toast_log_patch=true;
        }
        if(window.ToastAuto && window.ToastAuto.init){ window.ToastAuto.init(); }
      }).catch(next);
    })();
  }
  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot,{once:true});} else {boot();}
})();
</script>
<!-- /toast dynamic loader -->
<!-- <script defer src="<?= h(project_url('/assets/js/debug/toast-debug.js')) ?>?v=<?= h(BUILD) ?>"></script>
<script defer src="/assets/js/utils/toast-position-selector-driver.js"></script>
<script defer src="/assets/js/debug/toast-calibrator.js"></script> -->
</body></html>
