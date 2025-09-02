/* header_sanity.js — warns if required IDs are missing */
(function(){
  try{
    var b = document.getElementById('userbtn');
    var m = document.getElementById('usermenu');
    if (!b || !m) {
      console.warn('[header] Expected IDs missing:', {hasBtn: !!b, hasMenu: !!m});
    }
  }catch(e){}
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
