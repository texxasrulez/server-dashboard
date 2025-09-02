// assets/js/pages/login.check-admin.js
(function(){
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  function createBanner(msg){
    var d = document.createElement('div');
    d.className = 'alert danger login-admin-warning';
    d.style.margin = '12px 0';
    d.style.borderRadius = '8px';
    d.style.border = '1px solid var(--accent)';
    d.style.background = 'rgba(26,188,156,0.12)';
    d.style.padding = '10px 12px';
    d.innerHTML = '<strong>Security notice:</strong> ' + msg;
    return d;
  }
  ready(function(){
    var form = document.querySelector('form[action*="login"], form#login, form[name="login"]') || document.querySelector('form');
    if (!form) return;
    try {
      fetch('api/users_admin_health.php', { credentials:'same-origin' })
        .then(function(r){ return r.json().catch(function(){ return {}; }); })
        .then(function(j){
          if (!j || !j.default_admin_unsafe) return;
          var msg = 'Default account <code>admin</code> has no password. Log in and set a new password immediately.';
          var banner = createBanner(msg);
          var mount = form.parentNode || document.body;
          mount.insertBefore(banner, form);
        })
        ['catch'](function(){ /* no-op */ });
    } catch(e){ /* no-op */ }
  });
})();