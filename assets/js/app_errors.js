// assets/js/app_errors.js
(function () {
  function showErrorToast(msg) {
    try {
      if (window.toast && toast.error) {
        toast.error(msg, { sticky: true, duration: 12000 });
      } else {
        console.error('[toast-missing]', msg);
      }
    } catch (e) {
      console.error('[toast-exception]', e, msg);
    }
  }

  window.addEventListener('error', function (ev) {
    try {
      var m = ev && ev.message ? ev.message : 'Unknown script error';
      var src = ev && ev.filename ? (' at ' + ev.filename) : '';
      var pos = (ev && (ev.lineno || ev.colno)) ? (':' + (ev.lineno||0) + ':' + (ev.colno||0)) : '';
      var stackTop = (ev && ev.error && ev.error.stack) ? ('\n' + String(ev.error.stack).split('\n')[0]) : '';
      showErrorToast('Error: ' + m + src + pos + stackTop);
    } catch (e) {
      showErrorToast('Error');
    }
  });

  window.addEventListener('unhandledrejection', function (ev) {
    var reason = ev.reason;
    var msg = 'Unhandled promise: ' + (reason && (reason.message || reason.toString()) || 'unknown');
    showErrorToast(msg);
    try {
      if (window.clientLog && clientLog.error) {
        clientLog.error('promise_rejection', {
          message: (reason && (reason.message || reason.toString())) || 'unknown'
        });
      }
    } catch (e) {}
  });
})();
