(function(){
  'use strict';
  function el(tag, cls){ var n=document.createElement(tag); if(cls) n.className=cls; return n; }
  function pane(){
    return document.querySelector('#pane-site')
        || document.querySelector('[data-pane="site"]')
        || document.querySelector('.tab-pane.site')
        || document.querySelector('.pane-site')
        || document.querySelector('.tab-content, .content, main') || document.body;
  }
  function hasCard(p){ return !!p.querySelector('#siteLangSelect'); }
  function currentLocale(){
    try{ return String((window.__CONFIG_DATA__&&window.__CONFIG_DATA__.i18n&&window.__CONFIG_DATA__.i18n.locale) || document.documentElement.getAttribute('lang') || 'en'); }
    catch(_){ return 'en'; }
  }
  function buildCard(p){
    var card = el('div','card'); card.id='lang-card';
    var h = el('h3'); h.textContent='Language'; card.appendChild(h);
    var row = el('div'); row.style.display='flex'; row.style.flexWrap='wrap'; row.style.alignItems='center'; row.style.gap='12px';
    var sel = el('select'); sel.id='siteLangSelect'; sel.className='input';
    var saveBtn = el('button'); saveBtn.className='btn'; saveBtn.id='siteLangSave'; saveBtn.textContent='Save';
    var status = el('span'); status.className='muted'; status.id='siteLangStatus'; status.style.marginLeft='8px';
    row.appendChild(sel); row.appendChild(saveBtn); row.appendChild(status);
    card.appendChild(row);
    p.appendChild(card);

    var cur = currentLocale();
    fetch('api/i18n_languages.php', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok || !Array.isArray(j.languages)) return;
        sel.innerHTML='';
        j.languages.forEach(function(it){
          var o = el('option'); o.value = it.code; o.textContent = it.name || (it.code||'').toUpperCase();
          if (it.code === cur) o.selected = true;
          sel.appendChild(o);
        });
      }).catch(function(){});

    saveBtn.addEventListener('click', function(){
      var locale = sel.value || 'en';
      var body = JSON.stringify({config:{i18n:{locale:locale}}});
      var url = 'api/config_import.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__||'');
      saveBtn.disabled = true; status.textContent = 'Saving…';
      fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: body})
        .then(function(r){ return r.json().catch(function(){return null;}); })
        .then(function(j){
          if (j && j.ok) {
            status.textContent = 'Saved';
            try { document.documentElement.setAttribute('lang', locale); } catch(_){}
            try { window.__CONFIG_DATA__ = window.__CONFIG_DATA__ || {}; (window.__CONFIG_DATA__.i18n = window.__CONFIG_DATA__.i18n || {}).locale = locale; } catch(_){}
          } else {
            status.textContent = 'Save failed';
          }
        })
        .catch(function(){ status.textContent = 'Save failed'; })
        .finally(function(){ saveBtn.disabled = false; setTimeout(function(){ status.textContent=''; }, 2000); });
    });
  }
  function ensure(){
    var p = pane();
    if (!p) return;
    if (!hasCard(p)) buildCard(p);
  }
  // Try multiple triggers
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensure);
  else ensure();
  window.addEventListener('hashchange', function(){ if ((location.hash||'').toLowerCase().indexOf('site')>=0) ensure(); });
  document.addEventListener('click', function(e){
    var a = e.target.closest('a[href*="#site"]'); if (a) setTimeout(ensure, 0);
  });
})();