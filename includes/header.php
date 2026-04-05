<?php require_once __DIR__ . '/init.php'; ?>
<?php
// Pull site name for brand alt from central config if available
if (!isset($SITE_NAME)) {
    try {
        $cfgLib = __DIR__ . '/../lib/Config.php';
        if (is_file($cfgLib)) {
            require_once $cfgLib;
            \App\Config::init(dirname(__DIR__));
            $SITE_NAME = \App\Config::get('site.name', 'Server Dashboard');
        } else {
            $SITE_NAME = 'Server Dashboard';
        }
    } catch (Throwable $e) {
        $SITE_NAME = 'Server Dashboard';
    }
}
?>
<header class="app-header">
  <div class="mobile-bar">
    <button id="mobileToggle" class="hamburger" aria-label="Open menu" aria-expanded="false" aria-controls="site-nav">
      <span class="bar"></span>&#9776;</button>
    <a class="mobile-logo" href="<?= h(project_url('/index.php')) ?>"><img src="assets/images/mobile-header-logo.png" alt="Logo"></a>
  </div>
  <div class="brand">
    <a href="<?= h(project_url('/index.php')) ?>">
      <div class="logo-container"><img class="header-image-icon" id="brandLogoIcon" src="<?= h(project_url('/assets/images/header-logo-icon.png')) ?>" alt="<?= h($SITE_NAME) ?> icon"><img class="header-image-domain" id="brandLogo" src="<?= h(project_url('/assets/images/header-logo-branding.png')) ?>" alt="<?= h($SITE_NAME) ?>"></div>
    </a>
  </div>

  <?php
  $isAdmin = user_is_admin();
  $currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
  $headerNavMode = 'buttons';
  try {
      if (class_exists('\App\Config')) {
          $headerNavMode = (string)\App\Config::get('ui.header_navigation_mode', 'buttons');
      }
  } catch (Throwable $e) {
      $headerNavMode = 'buttons';
  }
  if (!in_array($headerNavMode, ['buttons', 'dropdown'], true)) {
      $headerNavMode = 'buttons';
  }
$candidates_admin = [
  ['History', 'history.php'],
  ['Logs', 'logs.php'],
  ['Cron Health', 'cron.php'],
  ['Processes', 'processes.php'],
  ['Services', 'services.php'],
  ['Databases', 'database.php'],
  ['Server Tests', 'server_tests.php'],
  ['Alerts', file_exists(__DIR__.'/../alerts_admin.php') ? 'alerts_admin.php' : (file_exists(__DIR__.'/../alerts.php') ? 'alerts.php' : null)],
  ['Speedtest', 'speedtest.php'],
  ['Bookmarks', 'bookmarks.php'],
  ['Diagnostics', 'diag.php'],
  ['Config', 'config.php'],
  ['Backups', 'backups.php'],
];
$links_public = [
];
$navLinks = [];
foreach ($links_public as $L) {
    [$label, $href] = $L;
    if ($href && file_exists(__DIR__ . '/../' . $href)) {
        $navLinks[] = [$label, $href];
    }
}
if ($isAdmin) {
    foreach ($candidates_admin as $L) {
        [$label, $href] = $L;
        if ($href && file_exists(__DIR__ . '/../' . $href)) {
            $navLinks[] = [$label, $href];
        }
    }
}
?>
  <nav id="site-nav" class="tabs nav-mode-<?= h($headerNavMode) ?>">
  <?php
  foreach ($navLinks as $L) {
      [$label, $href] = $L;
      echo '<a href="'.h($href).'">'.h($label).'</a>';
  }
  if ($headerNavMode === 'dropdown' && $navLinks) {
      echo '<label class="header-nav-select-wrap" for="headerNavSelect">';
      echo '<span class="sr-only">Header navigation</span>';
      echo '<select id="headerNavSelect" class="header-nav-select" aria-label="Header navigation">';
      foreach ($navLinks as $L) {
          [$label, $href] = $L;
          echo '<option value="' . h(project_url('/' . ltrim($href, '/'))) . '"' . ($currentPage === basename($href) ? ' selected' : '') . '>' . h($label) . '</option>';
      }
      echo '</select>';
      echo '</label>';
    }
?>
  </nav>
  
  <a href="services.php" id="sys-badge" class="sys-badge" role="status" title="Service status">
  <span class="dot"></span><span class="txt">Loading…</span>
  </a>

  <div class="spacer"></div>
  <div class="userbox" data-open="0">
    <?php $cu = current_user(); ?>
    <button  class="userbtn" type="button" id="userbtn" aria-haspopup="menu" aria-expanded="false" autocomplete="off">
      <img class="avatar" id="userAvatarImg" src="<?= h(user_avatar_url($cu, 32)) ?>" alt="">
      <span class="name"><?= h(user_display_name($cu)) ?></span>
      <span class="role muted">(<?= h($cu['role'] ?? 'user') ?>)</span>
      <svg class="chev" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z" fill="currentColor"/></svg>
    </button>
    <div class="usermenu" id="usermenu" hidden>
      <a href="<?= h(project_url('/users.php')) ?>">My Profile</a>
      <a href="<?= h(project_url('/auth/logout.php')) ?>">Logout</a>
    </div>
  </div>
</header>

<!-- MOBILE MINIMAL FIX -->
<style>
#mobileDrawer[hidden]{display:none;}
#mobileDrawer{position:fixed;inset:0;display:none;grid-template-rows:auto 1fr;z-index:1000;}
#mobileDrawer.show{display:grid;background:rgba(0,0,0,.5);}
#mobileDrawer .panel{background:var(--card,#111);color:var(--fg,#e6eef2);padding:1rem;max-width:85vw;width:260px;height:100%;overflow:auto;position:relative;z-index:2;}
#mobileDrawer .panel a{display:block;padding:.5rem 0;border-bottom:1px solid rgba(255,255,255,.08);text-decoration:none;color:inherit;}
#mobileDrawer .backdrop{position:fixed;inset:0;z-index:1;}
.sr-only{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
.app-header #site-nav.nav-mode-dropdown > a{display:none;}
.app-header #site-nav .header-nav-select-wrap{display:none;}
.app-header #site-nav.nav-mode-dropdown .header-nav-select-wrap{display:block;}
.app-header .header-nav-select-wrap{position:relative;}
.app-header .header-nav-select{
  min-width:200px;
  max-width:320px;
  border:1px solid var(--border, rgba(255,255,255,.14));
  border-radius:999px;
  padding:.55rem 2.25rem .55rem .9rem;
  background:var(--card, rgba(20,24,32,.96));
  color:var(--fg, #e6eef2);
  box-shadow:0 0 0 1px rgba(255,255,255,.04) inset;
  appearance:none;
  -webkit-appearance:none;
  -moz-appearance:none;
  cursor:pointer;
}
.app-header .header-nav-select:focus{
  outline:none;
  box-shadow:0 0 0 2px rgba(255,255,255,.14);
}
.app-header .header-nav-select-wrap::after{
  content:"";
  position:absolute;
  right:.95rem;
  top:50%;
  width:.55rem;
  height:.55rem;
  border-right:2px solid currentColor;
  border-bottom:2px solid currentColor;
  transform:translateY(-70%) rotate(45deg);
  pointer-events:none;
  opacity:.7;
}
.app-header .header-nav-select option{
  background:var(--card, rgba(20,24,32,.96));
  color:var(--fg, #e6eef2);
}
.app-header .mobile-bar{display:none;}
@media (max-width:720px){
  .app-header .mobile-bar{display:flex !important;align-items:center;gap:.5rem;}
  .app-header .brand, .app-header #site-nav, .app-header .theme-picker, .app-header .userbox{display:none !important;}
}
</style>
<script>
(function(){
  var drawer = document.getElementById('mobileDrawer');
  var toggle = document.getElementById('mobileToggle') || document.getElementById('navToggle');
  var navSelect = document.getElementById('headerNavSelect');
  if (navSelect) {
    navSelect.addEventListener('change', function(){
      if (navSelect.value) window.location.href = navSelect.value;
    });
  }
  if (!drawer) {
    var aside = document.createElement('aside');
    aside.id = 'mobileDrawer';
    aside.setAttribute('hidden','');
    aside.setAttribute('aria-hidden','true');
    document.body.appendChild(aside);
    drawer = aside;
  }
  function ensure(){
    if (drawer.querySelector('.panel')) return;
    var site = document.getElementById('site-nav');
    var links = site ? Array.prototype.map.call(site.querySelectorAll('a'), function(a){
      return '<a href="' + a.getAttribute('href') + '">' + (a.textContent||'').trim() + '</a>';
    }).join('') : '';
    drawer.innerHTML = '<div class="panel">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">' +
      '<strong>Menu</strong><button id="mdClose" class="btn">Close</button>' +
      '</div><nav>' + links + '</nav></div>' +
      '<a class="backdrop" href="#" aria-label="Close"></a>';
  }
  function openD(e){ if (e) e.preventDefault(); ensure(); drawer.hidden=false; drawer.classList.add('show'); document.body.style.overflow='hidden'; }
  function closeD(e){ if (e) e.preventDefault(); drawer.classList.remove('show'); drawer.hidden=true; document.body.style.overflow=''; }
  document.addEventListener('click', function(evt){
    var t = evt.target;
    if (t.closest && (t.closest('#mobileToggle') || t.closest('#navToggle') || (t.classList && t.classList.contains('hamburger') && t.closest('.mobile-bar')))){ openD(evt); return; }
    if (t.matches && (t.matches('#mdClose') || t.matches('#mobileDrawer .backdrop'))){ closeD(evt); return; }
  }, {passive:false});
  drawer.addEventListener('click', function(e){
    var a = e.target && e.target.closest && e.target.closest('nav a'); if(!a) return;
    setTimeout(function(){ closeD(); }, 0);
  });
  document.addEventListener('keydown', function(e){ if (e.key==='Escape') closeD(); });
  window.__md_open=openD; window.__md_close=closeD;
})();
</script>


<!-- Mobile drawer container -->
<aside id="mobileDrawer" hidden aria-hidden="true"></aside>
