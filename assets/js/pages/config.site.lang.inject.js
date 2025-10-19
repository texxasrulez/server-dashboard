(function(){
  'use strict';
  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function el(tag, cls){ var n=document.createElement(tag); if(cls) n.className=cls; return n; }
  function text(elm){ return (elm && (elm.textContent||'')).trim(); }
  function isSitePane(n){
    if (!n) return false;
    var id = (n.id||'').toLowerCase();
    if (id.indexOf('pane-site')>=0) return true;
    if ((n.getAttribute('data-pane')||'').toLowerCase()==='site') return true;
    return false;
  }
  function findSitePane(){
    var candidates = qa('#pane-site, [data-pane="site"], .pane-site, .tab-pane.site');
    if (candidates.length) return candidates[0];
    // fallback: find container holding known Site cards (Themes / Import/Export)
    var cards = qa('.card');
    for (var i=0;i<cards.length;i++){
      var h = q('h3,h2,h4', cards[i]);
      if (!h) continue;
      var t = text(h).toLowerCase();
      if (t.indexOf('themes')>=0 || t.indexOf('import')>=0) {
        return cards[i].parentNode || document.body;
      }
    }
    return document.body;
  }
  function findThemesCard(pane){
    var cards = qa('.card', pane||document);
    for (var i=0;i<cards.length;i++){
      var h = q('h3,h2,h4', cards[i]);
      var t = text(h).toLowerCase();
      if (t.indexOf('theme')>=0) return cards[i];
    }
    return null;
  }
  function currentLocale(){
    try { 
      if (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.i18n && window.__CONFIG_DATA__.i18n.locale) return String(window.__CONFIG_DATA__.i18n.locale);
    } catch(_){}
    var htmlLang = document.documentElement.getAttribute('lang');
    return (htmlLang && htmlLang.trim()) || 'en';
  }
  function ensureSelector(){
    var pane = findSitePane();
    if (!pane) return;
    var themeCard = findThemesCard(pane);
    var host = themeCard || pane;
    if (q('#siteLangSelect', host)) return; // already added

    // Build row under themes card; if no themes, create a card
    var container;
    if (themeCard){
      container = el('div');
      container.style.display='flex'; container.style.flexWrap='wrap'; container.style.alignItems='center'; container.style.gap='12px';
      // small label
      var label = el('label'); label.textContent = 'Language'; label.style.minWidth='120px'; label.style.fontWeight='600';
      var sel = el('select', 'input'); sel.id='siteLangSelect';
      var saveBtn = el('button', 'btn'); saveBtn.id='siteLangSave'; saveBtn.textContent='Save';
      var status = el('span'); status.className='muted'; status.id='siteLangStatus'; status.style.marginLeft='8px';
      container.appendChild(label); container.appendChild(sel); container.appendChild(saveBtn); container.appendChild(status);
      themeCard.appendChild(container);
    } else {
      var card = el('div','card'); var h=el('h3'); h.textContent='Language'; card.appendChild(h);
      container = el('div'); container.style.display='flex'; container.style.flexWrap='wrap'; container.style.alignItems='center'; container.style.gap='12px';
      var sel = el('select','input'); sel.id='siteLangSelect';
      var saveBtn = el('button','btn'); saveBtn.id='siteLangSave'; saveBtn.textContent='Save';
      var status = el('span'); status.className='muted'; status.id='siteLangStatus'; status.style.marginLeft='8px';
      container.appendChild(sel); container.appendChild(saveBtn); container.appendChild(status);
      card.appendChild(container);
      pane.appendChild(card);
    }

    // Populate languages
    var selEl = q('#siteLangSelect', host);
    var cur = currentLocale();
    fetch('api/i18n_languages.php', {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok || !Array.isArray(j.languages)) return;
        selEl.innerHTML='';
        j.languages.forEach(function(it){
          var o=document.createElement('option'); o.value=it.code; o.textContent=it.name||String(it.code||'').toUpperCase();
          if (it.code===cur) o.selected=true; selEl.appendChild(o);
        });
      }).catch(function(){});

    // Save handler
    var btn = q('#siteLangSave', host), status = q('#siteLangStatus', host);
    if (btn){
      btn.addEventListener('click', function(){
        var locale = selEl.value || 'en';
        var body = JSON.stringify({config:{i18n:{locale:locale}}});
        var url = 'api/config_import.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__||'');
        btn.disabled = true; if (status) status.textContent='Saving…';
        fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: body})
        .then(function(r){ return r.json().catch(function(){return null;}); })
        .then(function(j){
          if (j && j.ok) {
            if (status) status.textContent = 'Saved';
            try { document.documentElement.setAttribute('lang', locale); } catch(_){}
            try { window.__CONFIG_DATA__ = window.__CONFIG_DATA__ || {}; (window.__CONFIG_DATA__.i18n = window.__CONFIG_DATA__.i18n || {}).locale = locale; } catch(_){}
          } else {
            if (status) status.textContent = 'Save failed';
          }
        })
        .catch(function(){ if (status) status.textContent = 'Save failed'; })
        .finally(function(){ btn.disabled=false; setTimeout(function(){ if(status) status.textContent=''; }, 2000); });
      });
    }
  }

  function onReady(fn){ if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', fn); else fn(); }
  onReady(ensureSelector);
  window.addEventListener('hashchange', ensureSelector, false);
  document.addEventListener('click', function(e){
    var a = e.target.closest('a,button');
    if (!a) return;
    var href = a.getAttribute && a.getAttribute('href') || '';
    var text = (a.textContent||'').toLowerCase();
    if ((href && href.indexOf('#site')>=0) || text.indexOf('site')>=0) {
      setTimeout(ensureSelector, 0);
    }
  }, true);
})();