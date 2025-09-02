(function(){
  'use strict';
  if (window.__SECURITY_JS_BOUND__) return;
  window.__SECURITY_JS_BOUND__ = true;

  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function val(node){ return node ? (node.type === 'checkbox' ? (node.checked ? '1' : '0') : (node.value ?? '')) : ''; }
  function set(node, v){ if (!node) return; if (node.type === 'checkbox') node.checked = !!v; else node.value = (v ?? ''); }
  function parseEmail(addr){ if (!addr) return ''; var m = String(addr).match(/<([^>]+)>/); return (m && m[1]) ? m[1] : String(addr).trim(); }

  // emit toasts using the exact event bus other pages use
  function t(level, text){
    try {
      document.dispatchEvent(new CustomEvent('toast', { detail: { level: level, text: text } }));
      document.dispatchEvent(new CustomEvent('toast', { detail: { type: level,  message: text } }));
    } catch(_){}
    if (typeof window.notify === 'function') try { window.window.toast.show(level, text); } catch(_){}
    if (typeof window.toast  === 'function') try { window.toast(level,  text); } catch(_){}
  }

  function pickForm(){ return $('#security-form') || $('form.security') || $('form[action*="security"]') || $('form'); }
  function mapFields(form){
    function pick(k){ return form.querySelector('#'+k) || form.querySelector('[name="'+k+'"]') || form.querySelector('[data-key="'+k+'"]'); }
    return {
      form: form,
      saveBtn:   form.querySelector('#btnSecSave, [data-action="save"], #sec-save, button[type="submit"]'),
      testBtn:   form.querySelector('#btnSecTest, #mailTest, [data-test-email]'),
      // mail / smtp
      mail_transport: pick('mail_transport'),
      mail_from:      pick('mail_from'),
      mail_replyto:   pick('mail_replyto'),
      sendmail_path:  pick('sendmail_path'),
      smtp_host:      pick('smtp_host'),
      smtp_port:      pick('smtp_port'),
      smtp_secure:    pick('smtp_secure'),
      smtp_user:      pick('smtp_user'),
      smtp_pass:      pick('smtp_pass'),
      smtp_timeout:   pick('smtp_timeout'),
      // misc commonly saved
      BASE_URL:       pick('BASE_URL'),
      THEME_DEFAULT:  pick('THEME_DEFAULT'),
      MAIL_FROM:      pick('MAIL_FROM'),
      CRON_TOKEN:     pick('CRON_TOKEN'),
      sec_email:      pick('sec_email'),
      alert_emails:   pick('alert_emails')
    };
  }

  
  function gather(form){
    // Start with FormData (captures only named fields)
    var out = {};
    try {
      out = Object.fromEntries(new FormData(form).entries());
    } catch (e) {
      out = {};
    }
    // Augment with any inputs/selects/textareas that have an id (or data-key) but no name
    try {
      form.querySelectorAll('input,select,textarea').forEach(function(el){
        var key = el.name || el.id || el.getAttribute('data-key');
        if (!key) return;
        if (out[key] === undefined) {
          if (el.type === 'checkbox') out[key] = el.checked ? '1' : '0';
          else out[key] = (el.value ?? '');
        }
      });
    } catch (_){}
    return out;
  }
 catch(e) {
      var out = {};
      form.querySelectorAll('input,select,textarea').forEach(function(el){
        var k = el.name || el.id; if (!k) return;
        out[k] = val(el);
      });
      return out;
    }
  }

  function bind(){
    var form = pickForm();
    if (!form) return;
    var F = mapFields(form);
    var baseline = {};

    // Prime UI with existing server values
    fetch('api/security_get.php', { credentials:'include' })
      .then(function(r){ return r.ok ? r.json() : { ok:false }; })
      .then(function(j){
        if (!j || !j.ok) return;
        var s = j.settings || {}; baseline = s;
        set(F.mail_transport, s.mail_transport || 'phpmail');
        set(F.mail_from,      s.mail_from || '');
        set(F.mail_replyto,   s.mail_replyto || '');
        set(F.sendmail_path,  s.sendmail_path || '/usr/sbin/sendmail');
        set(F.smtp_host,      s.smtp_host || '');
        set(F.smtp_port,      s.smtp_port || 587);
        set(F.smtp_secure,    s.smtp_secure || 'tls');
        set(F.smtp_user,      s.smtp_user && s.smtp_user !== 'set' ? s.smtp_user : '');
        set(F.smtp_timeout,   s.smtp_timeout || 12);
        set(F.sec_email,      s.alert_emails || s.sec_email || '');
        // password intentionally not echoed
      }).catch(function(){ /* ignore */ });

    function doSave(ev){
      if (ev) ev.preventDefault();
      var body = gather(F.form);
      // normalize alert emails key
      if (body.email && !body.alert_emails) body.alert_emails = body.email;
      if (!body.alert_emails && F.sec_email) body.alert_emails = val(F.sec_email);
      if (F.saveBtn) F.saveBtn.disabled = true;
      fetch('api/security_set.php', {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        credentials: 'include',
        body: JSON.stringify(body)
      })
      .then(function(r){ if (!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(function(j){
        if (j && j.ok) {
          t('confirmation','Settings saved');
          baseline = Object.assign({}, baseline, body);
          if (F.smtp_pass && body.smtp_pass) { F.smtp_pass.value=''; F.smtp_pass.placeholder='••• unchanged'; }
        } else {
          throw new Error((j && j.error) || 'save failed');
        }
      })
      .catch(function(err){ console.error(err); t('error','Save failed: '+err.message); })
      .finally(function(){ if (F.saveBtn) F.saveBtn.disabled = false; });
    }

    function doTest(ev){
      if (ev) ev.preventDefault();
      var to = parseEmail(val(F.mail_replyto) || val(F.mail_from) || '');
      if (!to) { t('warning','Enter an email address first'); return; }
      fetch('api/mail_test.php?to=' + encodeURIComponent(to), { credentials:'include' })
        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(function(j){
          if (j && j.ok) t('confirmation','Test email sent' + (j.transport?(' via '+j.transport):''));
          else throw new Error((j && j.result && j.result.error) ? j.result.error : (j && j.error) ? j.error : 'failed');
        })
        .catch(function(err){ t('error','Test email failed: '+err.message); });
    }

    if (F.saveBtn) F.saveBtn.addEventListener('click', doSave);
    if (F.form)    F.form.addEventListener('submit', doSave);
    if (F.testBtn) F.testBtn.addEventListener('click', doTest);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once:true });
  else bind();
})();