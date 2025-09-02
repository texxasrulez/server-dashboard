<?php
// Self-contained probe: no external CSS/JS needed.
?><!doctype html>
<html>



<meta charset="utf-8"/>
<title>Usermenu Probe (Inline)</title>
<style>
  :root{
    --menu-bg:#151a1f; --menu-fg:#e6eef5; --menu-border:rgba(255,255,255,.12);
    --shadow-4: rgba(0,0,0,.35) 0 12px 30px;
  }
  body{background:#0e1419;color:#e6eef5;font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;padding:24px}
  .app-header{display:flex;align-items:center;gap:16px;padding:8px 12px;border-radius:10px;background:#121820;max-width:860px}
  .brand{font-weight:700}
  .tabs a{color:#9fb3c8;text-decoration:none;margin-right:8px}
  .spacer{flex:1}
  .userbox{ position:relative; }
  .userbtn{ display:flex; align-items:center; gap:.4rem; background:transparent; border:0; color:inherit; cursor:pointer; }
  .avatar{ width:22px; height:22px; border-radius:999px; background:#2b3440; display:inline-block; }
  .usermenu{
    position:absolute; top:calc(100% + 6px); right:0; min-width:200px; padding:.35rem;
    background: var(--menu-bg); color: var(--menu-fg);
    border:1px solid var(--menu-border); border-radius:10px; box-shadow: var(--shadow-4);
  }
  .usermenu[hidden]{ display:none; }
  .usermenu a{ display:block; padding:.45rem .6rem; border-radius:8px; color:inherit; text-decoration:none; }
  .usermenu a:hover{ background: rgba(255,255,255,.06); }
</style>
</head>
<body>
<header class="app-header">
  <div class="brand">Header probe</div>
  <nav class="tabs"><a href="#">Services</a><a href="#">Logs</a></nav>
  <div class="spacer"></div>
  <div class="userbox">
    <button  class="userbtn" id="userbtn" aria-haspopup="menu" aria-expanded="false" autocomplete="off">
      <span class="avatar"></span>
      <span class="name">Gene Hawkins</span>
      <span class="role muted">(admin)</span>
    </button>
    <div id="usermenu" class="usermenu" hidden>
      <a href="../users.php">My Profile</a>
      <a href="../auth/logout.php">Logout</a>
    </div>
  </div>
</header>

<p>Click the name/avatar to toggle. In console try: <code>__UserMenu.state()</code></p>
<script>
(function(){
  function ready(f){ if (document.readyState!=='loading') f(); else document.addEventListener('DOMContentLoaded', f); }
  ready(function(){
    var btn=document.getElementById('userbtn'), menu=document.getElementById('usermenu');
    if(!btn||!menu) return;
    function close(){ if(menu.hidden) return; menu.hidden=true; }
    function open(){ if(!menu.hidden) return; menu.hidden=false; }
    function toggle(e){ e&&e.preventDefault&&e.preventDefault(); e&&e.stopPropagation&&e.stopPropagation(); menu.hidden?open():close(); }
    btn.addEventListener('click', toggle);
    document.addEventListener('click', function(e){ if(menu.hidden) return; if(e.target===btn||menu.contains(e.target)) return; close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
    window.__UserMenu={ open, close, toggle, state:function(){ return { hidden:menu.hidden, hasBtn:!!btn, hasMenu:!!menu }; } };
  });
})();
</script>


</body>
</html>
