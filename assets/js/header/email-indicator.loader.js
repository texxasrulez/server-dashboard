/*! email-indicator.loader.js — robust loader for header notifier + toast UX */
(function(){
  'use strict';
  var base = (document.currentScript && document.currentScript.src) ? (document.currentScript.src.replace(/\/[^\/]*$/, '')) : 'assets/js/header';
  function load(src){ return new Promise(function(res,rej){ var s=document.createElement('script'); s.src=src; s.async=false; s.onload=res; s.onerror=function(){rej(new Error('Failed to load '+src));}; document.head.appendChild(s); }); }
  function hasIndicator(){ return !!(window.EmailIndicator && (window.EmailIndicator.init||window.EmailIndicator.start)); }

  var stamp = (window.APP_BUILD_STAMP || window.BUILD_TS || Date.now());

  (function boot(){
    var chain = Promise.resolve();
    if (!hasIndicator()){
      chain = chain.then(function(){ return load(base + '/email-indicator.js?v='+stamp); });
    }
    chain = chain.then(function(){ return load(base + '/email-indicator.toast.js?v='+stamp); });
    chain.then(function(){
      try{
        if (window.EmailIndicator && window.EmailIndicator.init) window.EmailIndicator.init();
        if (window.EmailIndicatorToasts) window.EmailIndicatorToasts.start();
      }catch(_){/* ignore */}
    }).catch(function(e){
      if (window.toast && window.toast.error) window.toast.error(e.message);
      else console.error(e);
    });
  })();
})();