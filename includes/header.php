<?php require_once __DIR__ . '/init.php'; ?>
<?php require_once __DIR__ . '/i18n.php'; ?>
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
    <button id="mobileToggle" class="hamburger" aria-label="<?= h(__('header.mobile.open_menu', 'Open menu')) ?>" aria-expanded="false" aria-controls="site-nav">
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
  if (!in_array($headerNavMode, ['buttons', 'dropdown', 'left-sidebar'], true)) {
      $headerNavMode = 'buttons';
  }
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
    $featureDefs = class_exists('\App\Config')
        ? \App\Config::featureDefinitions()
        : [];
    foreach ($featureDefs as $featureKey => $definition) {
        $href = (string) ($definition['href'] ?? '');
        if (
            $href === '' ||
            !\App\Config::featureEnabled((string) $featureKey) ||
            !file_exists(__DIR__ . '/../' . $href)
        ) {
            continue;
        }
        $fallback = (string) ($definition['label'] ?? $featureKey);
        $i18nKey = (string) ($definition['i18n_key'] ?? '');
        $label = $i18nKey !== '' ? __($i18nKey, $fallback) : $fallback;
        $navLinks[] = [$label, $href];
    }
    if (file_exists(__DIR__ . '/../config.php')) {
        $navLinks[] = [__('header.nav.config', 'Config'), 'config.php'];
    }
}
?>
  <?php
  $renderNavLink = static function ($label, $href, $currentPage, $extraClass = '') {
      $isCurrent = ($currentPage === basename((string)$href));
      $classAttr = trim((string)$extraClass . ($isCurrent ? ' is-active' : ''));
      return '<a href="'.h($href).'"' . ($classAttr !== '' ? ' class="'.h($classAttr).'"' : '') . ($isCurrent ? ' aria-current="page"' : '') . '>'.h($label).'</a>';
  };
  ?>
  <nav id="site-nav" class="tabs nav-mode-<?= h($headerNavMode) ?>">
  <?php
  foreach ($navLinks as $L) {
      [$label, $href] = $L;
      echo $renderNavLink($label, $href, $currentPage);
  }
  if ($headerNavMode === 'dropdown' && $navLinks) {
      $dropdownLinks = $navLinks;
      array_unshift($dropdownLinks, [__('header.nav.main_page', 'Main Page'), 'index.php']);
      echo '<label class="header-nav-select-wrap" for="headerNavSelect">';
      echo '<span class="sr-only">' . h(__('header.navigation', 'Navigation')) . '</span>';
      echo '<select id="headerNavSelect" class="header-nav-select" aria-label="' . h(__('header.navigation', 'Navigation')) . '">';
      foreach ($dropdownLinks as $L) {
          [$label, $href] = $L;
          echo '<option value="' . h(project_url('/' . ltrim($href, '/'))) . '"' . ($currentPage === basename($href) ? ' selected' : '') . '>' . h($label) . '</option>';
      }
      echo '</select>';
      echo '</label>';
    }
?>
  </nav>
  
  <div class="spacer"></div>
  <a href="services.php" id="sys-badge" class="sys-badge" role="status" title="<?= h(__('header.system_status', 'Service status')) ?>">
  <span class="dot"></span><span class="txt"><?= h(__('header.loading', 'Loading...')) ?></span>
  </a>
  <div class="userbox" data-open="0">
    <?php $cu = current_user(); ?>
    <button  class="userbtn" type="button" id="userbtn" aria-haspopup="menu" aria-expanded="false" autocomplete="off">
      <img class="avatar" id="userAvatarImg" src="<?= h(user_avatar_url($cu, 32)) ?>" alt="">
      <span class="name"><?= h(user_display_name($cu)) ?></span>
      <span class="role muted">(<?= h($cu['role'] ?? 'user') ?>)</span>
      <svg class="chev" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z" fill="currentColor"/></svg>
    </button>
    <div class="usermenu" id="usermenu" hidden>
      <a href="<?= h(project_url('/users.php')) ?>"><?= h(__('header.profile', 'My Profile')) ?></a>
      <a href="<?= h(project_url('/auth/logout.php')) ?>"><?= h(__('header.logout', 'Logout')) ?></a>
    </div>
  </div>
</header>
<?php if ($headerNavMode === 'left-sidebar' && $navLinks) : ?>
<aside class="app-side-nav" aria-label="<?= h(__('header.navigation', 'Navigation')) ?>">
  <div class="app-side-nav-inner">
    <div class="app-side-nav-title"><?= h(__('header.navigation', 'Navigation')) ?></div>
    <nav class="app-side-nav-links">
      <?= $renderNavLink(__('header.nav.main_page', 'Main Page'), 'index.php', $currentPage) ?>
      <?php
      foreach ($navLinks as $L) {
          [$label, $href] = $L;
          echo $renderNavLink($label, $href, $currentPage);
      }
      ?>
    </nav>
  </div>
</aside>
<?php endif; ?>

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
.ui-nav-left-sidebar .app-header #site-nav{display:none;}
.ui-nav-left-sidebar .app-side-nav{
  position:fixed;
  top:var(--app-side-nav-top, 76px);
  left:0;
  bottom:0;
  width:max-content;
  min-width:0;
  max-width:min(320px, calc(100vw - 24px));
  padding:.9rem .65rem;
  border-right:1px solid var(--card-border, var(--border, rgba(255,255,255,.08)));
  background:var(--card-bg, var(--card, #171a21));
  color:var(--fg, #e6eef2);
  box-shadow:var(--card-shadow, rgba(0, 0, 0, 0.25) 0 6px 22px);
  overflow:auto;
  z-index:90;
}
.ui-nav-left-sidebar .app-side-nav-inner{
  display:flex;
  flex-direction:column;
  gap:.25rem;
}
.ui-nav-left-sidebar .app-side-nav-title{
  margin:0 0 .55rem;
  padding:0 .55rem;
  font-size:.76rem;
  font-weight:700;
  letter-spacing:.08em;
  text-transform:uppercase;
  color:var(--muted, var(--fg, #e6eef2));
  opacity:.92;
}
.ui-nav-left-sidebar .app-side-nav-links{
  display:flex;
  flex-direction:column;
  gap:.12rem;
}
.ui-nav-left-sidebar .app-side-nav-links a{
  display:block;
  padding:.52rem .7rem;
  border-radius:10px;
  border:1px solid transparent;
  color:inherit;
  text-decoration:none;
  opacity:1;
  white-space:nowrap;
  transition:
    background .14s ease,
    border-color .14s ease,
    box-shadow .14s ease,
    color .14s ease;
}
.ui-nav-left-sidebar .app-side-nav-links a:hover{
  background:var(--btn-bg-hover, rgba(255,255,255,.07));
  border-color:var(--btn-border, rgba(255,255,255,.12));
}
.ui-nav-left-sidebar .app-side-nav-links a.is-active,
.ui-nav-left-sidebar .app-side-nav-links a[aria-current="page"]{
  background:var(--btn-bg, rgba(255,255,255,.04));
  border-color:var(--btn-border, rgba(255,255,255,.12));
  box-shadow:
    0 0 0 1px color-mix(in srgb, var(--border, rgba(255,255,255,.08)) 70%, transparent) inset,
    0 1px 8px rgba(0,0,0,.12);
}
body.ui-nav-left-sidebar .content,
body.ui-nav-left-sidebar .footer{
  margin-left:var(--app-side-nav-width, 232px);
}
.app-header .mobile-bar{display:none;}
@media (max-width:720px){
  .ui-nav-left-sidebar .app-side-nav{display:none;}
  body.ui-nav-left-sidebar .content,
  body.ui-nav-left-sidebar .footer{margin-left:0;}
  .app-header .mobile-bar{display:flex !important;align-items:center;gap:.5rem;}
  .app-header .brand, .app-header #site-nav, .app-header .theme-picker, .app-header .userbox{display:none !important;}
}
</style>
<script>
(function(){
  var drawer = document.getElementById('mobileDrawer');
  var toggle = document.getElementById('mobileToggle') || document.getElementById('navToggle');
  var navSelect = document.getElementById('headerNavSelect');
  function syncSidebarLayout(){
    try{
      var body = document.body;
      if(!body || body.dataset.navMode !== 'left-sidebar') return;
      var header = document.querySelector('.app-header');
      var side = document.querySelector('.app-side-nav');
      if(!header || !side) return;
      var headerRect = header.getBoundingClientRect();
      var top = Math.ceil(headerRect.bottom);
      body.style.setProperty('--app-side-nav-top', top + 'px');
      side.style.width = 'max-content';
      var width = Math.ceil(side.getBoundingClientRect().width) + 8;
      body.style.setProperty('--app-side-nav-width', width + 'px');
    }catch(e){}
  }
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
      '<strong><?= h(__('header.mobile.menu', 'Menu')) ?></strong><button id="mdClose" class="btn"><?= h(__('common.close', 'Close')) ?></button>' +
      '</div><nav>' + links + '</nav></div>' +
      '<a class="backdrop" href="#" aria-label="<?= h(__('common.close', 'Close')) ?>"></a>';
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
  window.addEventListener('load', syncSidebarLayout);
  window.addEventListener('resize', syncSidebarLayout);
  document.addEventListener('DOMContentLoaded', syncSidebarLayout);
  window.__md_open=openD; window.__md_close=closeD;
})();
</script>


<!-- Mobile drawer container -->
<aside id="mobileDrawer" hidden aria-hidden="true"></aside>
