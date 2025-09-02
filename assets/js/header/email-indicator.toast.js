/*! email-indicator.toast.js — UI toasts for mail health (no spelunking required)
 * Polls api/email_status.php?verbose=1 and emits toasts when:
 *   - an account is failing (ok:false or unseen is null)
 *   - a previously failing account recovers (ok:true with numeric unseen)
 * Debounced: one toast per account per state ("fail"/"ok") per session unless state changes.
 */
(function(){
  'use strict';

  var POLL_MS = 120000; // 2 minutes
  var timer = null;
  var KEY_PREFIX = 'email_toast_state:'; // sessionStorage

  function toast(kind, msg){
    try{
      if (window.toast && window.toast[kind]) { window.toast[kind](msg); return; }
      if (kind === 'error') console.error(msg);
      else console.log(kind.toUpperCase()+':', msg);
    }catch(_){
      // last resort
      if (kind === 'error') alert(msg);
    }
  }

  function getState(addr){
    try{ return sessionStorage.getItem(KEY_PREFIX+addr)||''; }catch(_){ return ''; }
  }
  function setState(addr, val){
    try{ sessionStorage.setItem(KEY_PREFIX+addr, val); }catch(_){}
  }
  function clearAllStates(){
    try{
      for (var i=sessionStorage.length-1;i>=0;i--){
        var k=sessionStorage.key(i);
        if (k && k.indexOf(KEY_PREFIX)===0) sessionStorage.removeItem(k);
      }
    }catch(_){}
  }

  function classify(entry){
    // entry: { address, provider, ok, unseen, error?, detail? }
    if (!entry) return 'unknown';
    if (entry.ok===false || entry.unseen==null) return 'fail';
    if (typeof entry.unseen === 'number') return 'ok';
    return 'unknown';
  }

  function summarizeFail(entry){
    var p = String(entry.provider||'').toLowerCase();
    var addr = entry.address || 'mail account';
    if (entry.error) return entry.error; // if backend gave us a concrete message
    if (p==='google' || p==='gmail') return 'Gmail notifier for '+addr+' needs attention (token missing or expired). Reconnect in Config → Email.';
    if (p==='imap') return 'IMAP notifier for '+addr+' failed to fetch unread mail. Check password/app password or server reachability.';
    return 'Notifier for '+addr+' failed to fetch unread mail.';
  }

  function checkOnce(){
    return fetch('api/email_status.php?verbose=1', {credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){
        if (!j || !j.accounts) return;
        j.accounts.forEach(function(ac){
          var state = classify(ac);
          var prev = getState(ac.address||'');
          if (state==='fail' && prev!=='fail'){
            toast('error', summarizeFail(ac));
            setState(ac.address||'', 'fail');
          } else if (state==='ok' && prev==='fail'){
            toast('success', 'Email notifier reconnected for '+(ac.address||'account')+'.');
            setState(ac.address||'', 'ok');
          } else if (!prev){
            // seed state silently
            setState(ac.address||'', state);
          }
        });
      }).catch(function(_){ /* network error -> ignore */ });
  }

  function start(){ stop(); checkOnce(); timer = setInterval(checkOnce, POLL_MS); }
  function stop(){ if (timer) { clearInterval(timer); timer = null; } }

  // Hook visibility (pause when hidden)
  document.addEventListener('visibilitychange', function(){
    if (document.hidden) stop(); else start();
  });

  // Reset states if accounts change
  window.addEventListener('email-accounts-changed', function(){ clearAllStates(); checkOnce(); });

  // Expose (optional)
  window.EmailIndicatorToasts = { start:start, stop:stop, ping:checkOnce };

  // auto-start AFTER indicator init (if available), else on DOM ready
  function lateStart(){
    try{
      if (window.EmailIndicator && typeof window.EmailIndicator.init==='function'){
        // Try to start a little after the main indicator begins polling
        setTimeout(start, 1500);
      } else {
        start();
      }
    }catch(_){ start(); }
  }
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', lateStart);
  } else {
    setTimeout(lateStart, 0);
  }
})();