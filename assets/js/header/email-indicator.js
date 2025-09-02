// assets/js/header/email-indicator.js
// Theme-aware colored glyph via CSS var(--notify), supports single/multiple modes.
// Opens provider webmail pages in a NEW TAB (href targets set; overlay script can also enforce).

(function(){
  "use strict";
  var POLL_OK_MS = 60000, POLL_ERR_MS = 120000;

  function $(s, c){ return (c||document).querySelector(s); }
  function el(t, cls){ var e=document.createElement(t); if(cls) e.className=cls; return e; }

  // Inject minimal styles once
  if (!document.getElementById('email-indicator-style')){
    var st=document.createElement('style'); st.id='email-indicator-style';
    st.textContent =
      "#emailIndicatorBar{display:flex;align-items:center;gap:.4rem;margin-right:.5rem;}"+
      "#emailIndicatorBar a{display:inline-flex;align-items:center;text-decoration:none;line-height:1;}"+
      "#emailIndicatorBar .mailicon{display:inline-block;width:45px;height:45px;"+
        "background-color: var(--notify,#b22222);"+
        "-webkit-mask: url(assets/images/new_email.gif) center/contain no-repeat;"+
        "mask: url(assets/images/new_email.gif) center/contain no-repeat;}";
    document.head.appendChild(st);
  }

  function findAnchor(){
    return document.getElementById('userbox')
        || document.querySelector('.userbox')
        || document.querySelector('.header-right')
        || document.querySelector('.topbar .right, header .right')
        || document.querySelector('nav .user, .nav-right')
        || document.body;
  }

  // Build a service URL. Prefer api-supplied field, else provider map, else domain/mail/.
  function pickWebUrl(ac){
    if (!ac) return null;
    // API may send 'web' or 'webUrl'
    var w = ac.web || ac.webUrl;
    if (w) return w;
    var p = String(ac.provider||"").toLowerCase();
    var addr = String(ac.address||"");
    var domain = (function(){var _d = (addr && addr.indexOf('@')>-1) ? addr.split('@')[1] : ''; return (_d||'').toLowerCase(); })();

    // Normalize provider heuristics
    if (p === 'google' || p === 'gmail' || domain === 'gmail.com' || domain === 'googlemail.com')
      return "https://mail.google.com/mail/u/0/#inbox";

    if (p === 'microsoft' || p === 'outlook' || /(outlook|live|hotmail|msn)\.com$/.test(domain))
      return "https://outlook.live.com/mail/0/";

    if (p === 'yahoo' || /(^|\.)yahoo\./.test(domain))
      return "https://mail.yahoo.com/";

    // Fallback: your rule → https://<domain>/mail/
    if (domain) return "https://" + domain + "/mail/";
    return "https://" + location.hostname + "/mail/";
  }

  function parseTolerant(txt){
    try { return JSON.parse(txt); } catch(_){}
    var s = txt.indexOf('{'), e = txt.lastIndexOf('}');
    if (s>-1 && e>-1 && e>s){ try { return JSON.parse(txt.slice(s,e+1)); } catch(_){ return null; } }
    return null;
  }

  function sumUnseen(j){
    if (!j) return 0;
    if (typeof j.total_unseen === 'number') return j.total_unseen;
    if (Array.isArray(j.accounts)) return j.accounts.reduce(function(n,a){ return n + (a && (a.unseen|0)); }, 0);
    return (j.unseen|0);
  }
  function accountsWithUnseen(j){
    return (j && Array.isArray(j.accounts)) ? j.accounts.filter(function(a){ return a && (a.unseen|0) > 0; }) : [];
  }

  function makeLink(url, title){
    var A = el('a');
    A.href = url || '#';
    A.title = title || '';
    // open in new tab; overlay (force-newtab.js) will also enforce
    A.target = "_blank";
    A.rel = "noopener noreferrer";
    return A;
  }

  function render(bar, j){
    bar.innerHTML = '';
    var mode = (j && j.indicatorMode) || 'single';
    var list = accountsWithUnseen(j);
    var total = sumUnseen(j);
    if (!total){ bar.style.display='none'; return; }
    bar.style.display='inline-flex';

    if (mode === 'multiple' && list.length){
      list.forEach(function(a){
        var url = pickWebUrl(a);
        var A = makeLink(url, (a.address||'')+' — '+a.unseen+' new');
        var ICON = document.createElement('span'); ICON.className='mailicon';
        A.appendChild(ICON); bar.appendChild(A);
      });
    } else {
      var primary = list[0] || (j.accounts && j.accounts[0]) || null;
      var url = pickWebUrl(primary);
      var A = makeLink(url, total+' new email(s)');
      var ICON = document.createElement('span'); ICON.className='mailicon';
      A.appendChild(ICON); bar.appendChild(A);
    }
  }

  var bar = null;
  function ensureBar(){
    if (bar && document.body.contains(bar)) return bar;
    var anchor = findAnchor(); if (!anchor) return null;
    bar = document.getElementById('emailIndicatorBar');
    if (!bar){
      bar = el('div'); bar.id='emailIndicatorBar'; bar.style.display='none';
      if (anchor.firstChild) anchor.insertBefore(bar, anchor.firstChild); else anchor.appendChild(bar);
    }
    return bar;
  }

  function tick(){
    var b = ensureBar(); if (!b) return setTimeout(tick, 1000);
    fetch('api/email_status.php', {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var j = parseTolerant(txt);
        if (!j){ b.style.display='none'; return setTimeout(tick, POLL_ERR_MS); }
        if (j && j.enabled === false){ b.style.display='none'; return setTimeout(tick, POLL_OK_MS); }
        render(b, j);
        setTimeout(tick, POLL_OK_MS);
      })
      .catch(function(){ setTimeout(tick, POLL_ERR_MS); });
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', tick); }
  else { tick(); }
})();