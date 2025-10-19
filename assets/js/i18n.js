(function(){
  'use strict';
  var LOCALE = document.documentElement.getAttribute('lang') || 'en';
  var MAP = {};
  function setText(el, key){
    var val = t(key);
    if (val == null) return;
    if (el.hasAttribute('data-i18n-attr')) {
      var attr = el.getAttribute('data-i18n-attr');
      el.setAttribute(attr, val);
    } else {
      el.textContent = val;
    }
  }
  function applyDOM(){
    var nodes = document.querySelectorAll('[data-i18n]');
    nodes.forEach(function(n){ setText(n, n.getAttribute('data-i18n')); });
  }
  function t(key, fallback){
    var cur = MAP;
    (String(key||'').split('.')).some(function(k){
      if (cur && typeof cur === 'object' && k in cur) { cur = cur[k]; return false; }
      cur = null; return true;
    });
    if (cur == null) return (fallback!=null) ? fallback : key;
    return String(cur);
  }
  window.I18N = { t: t, apply: applyDOM, locale: function(){return LOCALE;}, load: load };
  function load(locale){
    LOCALE = locale || LOCALE;
    var url = 'assets/i18n/'+LOCALE+'.json?_=' + Date.now();
    return fetch(url, {credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
      MAP = j||{}; applyDOM(); return MAP;
    }).catch(function(){ MAP={}; });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ load(LOCALE); });
  else load(LOCALE);
})();