(function(){
  function send(event, data, level){
    try{
      const payload = {
        ts: Math.floor(Date.now()/1000),
        path: location.pathname,
        event,
        level: level || 'info',
        data: data || {}
      };
      const blob = new Blob([JSON.stringify(payload)], {type:'application/json'});

      (function(){
        var endpoint = '';
        try {
          var m = (document.body && document.body.dataset && document.body.dataset.apiMetrics)
            ? document.body.dataset.apiMetrics
            : '';
          if (m) {
            // Derive “…/api/” base from “…/api/metrics_summary.php”
            endpoint = m.replace(/\/[^\/]*$/, '/') + 'client_log.php';
          } else {
            var base = (window.PROJECT_URL_BASE || '');
            endpoint = (base ? base : '.') + '/api/client_log.php';
          }
        } catch(_) {
          endpoint = '/api/client_log.php';
        }
        navigator.sendBeacon && navigator.sendBeacon(endpoint, blob);
      })();

    } catch(e){ /* noop */ }
  }

  window.clientLog = {
    info: (e,d)=>send(e,d,'info'),
    error:(e,d)=>send(e,d,'error')
  };

  window.addEventListener('error', (ev)=>{
    send('js_error', {message: ev.error && ev.error.message || ev.message, filename: ev.filename, lineno: ev.lineno}, 'error');
  });

  window.addEventListener('unhandledrejection', (ev)=>{
    send('promise_rejection', {reason: String(ev.reason)}, 'error');
  });
})();
