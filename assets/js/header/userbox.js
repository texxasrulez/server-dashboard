/* userbox.js — ultra-resilient header dropdown (no-flash) */
(function(){
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function log(){ try{ console.log.apply(console, ['[userbox]', ...arguments]); }catch(e){} }
  function warn(){ try{ console.warn.apply(console, ['[userbox]', ...arguments]); }catch(e){} }

  ready(function(){
    const header = document.querySelector('.app-header');
    const box = header ? header.querySelector('.userbox') : null;
    if (!box) {
      warn('no .userbox in header');
      window.__UserMenu = { state: () => ({ error:'no .userbox', hasBtn:false, hasMenu:false }) };
      return;
    }
    if (getComputedStyle(box).position === 'static') box.style.position = 'relative';

    // Find or create trigger
    let btn = document.getElementById('userbtn') ||
              box.querySelector('.userbtn') ||
              box.querySelector('button') ||
              box.querySelector('.name') ||
              box;
    if (btn === box) {
      const b = document.createElement('button');
      b.className = 'userbtn';
      b.type = 'button';
      b.innerHTML = '<span class="name">Menu</span>';
      box.insertBefore(b, box.firstChild);
      btn = b;
    }
    if (btn && btn.tagName !== 'BUTTON') {
      btn.setAttribute('role','button');
      btn.setAttribute('tabindex','0');
      btn.addEventListener('keydown', (e)=>{
        if (e.key==='Enter' || e.key===' ') { e.preventDefault(); btn.click(); }
      });
    }

    // Find or create menu
    let menu = document.getElementById('usermenu') || box.querySelector('.usermenu');
    if (!menu) {
      menu = document.createElement('div');
      menu.className = 'usermenu';
      menu.hidden = true;
      const base = (document.querySelector('body')?.getAttribute('data-api-metrics') || location.pathname).replace(/\/api\/.*$/,'');
      const users = base.replace(/\/[^/]*$/, '/users.php');
      const logout = base.replace(/\/[^/]*$/, '/auth/logout.php');
      menu.innerHTML = '<a href="'+users+'">My Profile</a><a href="'+logout+'">Logout</a>';
      box.appendChild(menu);
      log('created missing .usermenu');
    }

    // --- NO-FLASH GUARD --- ensure hidden before any open
    if (!menu.hasAttribute('hidden')) menu.hidden = true;

    function close(){ if(menu.hidden) return; menu.classList.remove('is-open'); menu.hidden = true; btn.setAttribute('aria-expanded','false'); }
    function open(){ if(!menu.hidden) return; menu.hidden = false; menu.classList.add('is-open'); btn.setAttribute('aria-expanded','true'); }
    function toggle(e){ if(e){ e.preventDefault?.(); e.stopPropagation?.(); } (menu.hidden ? open : close)(); }

    btn.addEventListener('click', toggle);
    document.addEventListener('click', function(e){ if(menu.hidden) return; if(e.target===btn || menu.contains(e.target)) return; close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });

    window.__UserMenu = {
      open, close, toggle,
      state: () => ({ hasBtn: !!btn, hasMenu: !!menu, hidden: menu.hidden, btnTag: btn?.tagName, menuClasses: menu?.className })
    };
    log('init (no-flash)', window.__UserMenu.state());
  });
})();

(function(){
  try{
    var btn = document.getElementById('userbtn') || document.querySelector('.userbtn');
    var menu = (btn && btn.closest('.userbox')) ? btn.closest('.userbox').querySelector('.usermenu') : document.querySelector('.userbox .usermenu');
    if (btn){
      btn.setAttribute('aria-haspopup', 'menu');
      btn.setAttribute('aria-expanded', 'false');
    }
    if (menu && menu.hasAttribute('hidden') === false){
      menu.hidden = true;
    }
  }catch(e){/* swallow */}
})();
