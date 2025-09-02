
/* assets/js/utils/console-filter.js — silence noisy dev logs without touching source */
(function(){
  'use strict';
  if (window.__CONSOLE_FILTER__) return; window.__CONSOLE_FILTER__ = true;
  var LOG_PREFIXES = [/^\[userbox\]\s+init/i]; // add more patterns here if needed
  var orig = console.log;
  console.log = function(){
    try{
      var first = arguments[0];
      if (typeof first === 'string' && LOG_PREFIXES.some(function(rx){ return rx.test(first); })) return;
    }catch(e){ /* ignore */ }
    return orig.apply(this, arguments);
  };
})();
