/* assets/js/mobile.js — robust mobile drawer for nord */
(function(){
  'use strict';
  var mq = window.matchMedia('(max-width: 720px)');
  var body = document.documentElement ? document.body : document.body; // ensure reference
  var toggle, drawer, nav, pickerForm, userbox;

  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function all(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }

  function ensureNodes(){
    toggle = toggle || document.getElementById('mobileToggle');
    drawer = drawer || document.getElementById('mobileDrawer');
    nav    = nav    || document.getElementById('site-nav');
    pickerForm = pickerForm || (document.querySelector('.app-header form.theme-picker'));
    userbox = userbox || (document.querySelector('.app-header .userbox'));
  }

  function isMobile(){ return mq.matches; }

  function applyFlag(){
    if (isMobile()) document.body.classList.add('is-mobile');
    else document.body.classList.remove('is-mobile');
  }

  function buildDrawer(){
    ensureNodes();
    if (!drawer) return;
    var html = '';
    html += '<div class="panel">';
    html += '<div class="row" style="justify-content:space-between;align-items:center;margin-bottom:.5rem">';
    html += '<img src="assets/images/mobile-header-logo.png" alt="Logo" style="height:28px">';
    html += '<button class="btn secondary" type="button" id="mdClose">Close</button>';
    html += '</div>';
    // Primary nav links (clone current)
    html += '<nav id="mdNav">';
    if (nav){
      all('a', nav).forEach(function(a){
        html += '<a href="'+a.getAttribute('href')+'">'+(a.textContent||'')+'</a>';
      });
    }
    html += '</nav>';
    // Theme picker
    if (pickerForm){
      var sel = pickerForm.querySelector('select');
      if (sel){
        html += '<div class="sec"><h4>Theme</h4><div class="picker">';
        html += '<select id="mdTheme">'+sel.innerHTML+'</select>';
        html += '<button class="btn" id="mdApply">Apply</button>';
        html += '</div></div>';
      }
    }
    // User links
    html += '<div class="sec"><h4>Account</h4>';
    var profile = 'users.php', logout = 'auth/logout.php';
    html += '<nav><a href="'+profile+'">My Profile</a><a href="'+logout+'">Logout</a></nav>';
    html += '</div>';
    html += '</div>'; // panel
    html += '<a class="backdrop" href="#" aria-label="Close"></a>';
    drawer.innerHTML = html;
  }

  function openDrawer(open){
    ensureNodes();
    if (!drawer) return;
    if (open){
      buildDrawer();
      drawer.hidden = false;
      drawer.classList.add('show');
      document.body.style.overflow = 'hidden';
      var mdClose = document.getElementById('mdClose');
      var mdApply = document.getElementById('mdApply');
      var mdTheme = document.getElementById('mdTheme');
      if (mdClose) mdClose.addEventListener('click', function(){ openDrawer(false); });
      var bd = drawer.querySelector('.backdrop'); if (bd) bd.addEventListener('click', function(e){ e.preventDefault(); openDrawer(false); });
      if (mdApply && mdTheme && pickerForm){
        mdApply.addEventListener('click', function(){
          try {
            var hiddenSel = pickerForm.querySelector('select');
            if (hiddenSel){ hiddenSel.value = mdTheme.value; pickerForm.submit(); }
          } catch(e){}
        });
      }
    } else {
      drawer.classList.remove('show');
      drawer.hidden = true;
      document.body.style.overflow = '';
    }
  }

  function onToggle(e){
    e.preventDefault();
    openDrawer(true);
  }

  function clickAway(e){
    ensureNodes();
    if (!drawer || drawer.hidden) return;
    if (!drawer.contains(e.target) && e.target !== toggle){
      openDrawer(false);
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    ensureNodes();
    applyFlag();
    if (toggle) toggle.addEventListener('click', onToggle);
    if (mq.addEventListener) mq.addEventListener('change', applyFlag); else mq.addListener(applyFlag);
    document.addEventListener('click', clickAway);
  });
})();
