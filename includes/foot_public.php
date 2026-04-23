</div><!-- /.content -->
<!-- Public foot: no header status or footer text -->




<!-- toast dynamic loader (footer, drop-anywhere) -->
<script>
(function() {
  if (window.__toastLoaderInjected) return; window.__toastLoaderInjected = true;
  var prefixes=["","../","../../","../../../","../../../../"];
  function loadCSS(href){return new Promise(function(res,rej){var l=document.createElement("link");l.rel="stylesheet";l.href=href;l.onload=function(){res(href)};l.onerror=function(){l.remove();rej(href)};document.head.appendChild(l);});}
  function loadJS(src){return new Promise(function(res,rej){var s=document.createElement("script");s.src=src;s.defer=true;s.onload=function(){res(src)};s.onerror=function(){s.remove();rej(src)};document.head.appendChild(s);});}
  function boot(){
    var i=0;
    (function next(){
      if(i>=prefixes.length){console.error("[toast] notify assets not found under any relative path");return;}
      var pre=prefixes[i++];
      var css=pre+"assets/css/notify.css";
      var js =pre+"assets/js/utils/notify.js";
      loadCSS(css).then(function(){return loadJS(js)}).then(function(){
        if(!window.__toastBasePath) window.__toastBasePath=pre;
        if(window.fetch && !window.fetch.__toast_log_patch){
          var _f=window.fetch.bind(window);
          window.fetch=function(url,opts){
            try{ if(typeof url==="string" && url.indexOf("/api/client_log.php")===0){ url=(window.__toastBasePath||"")+"api/client_log.php"; } }catch(e){}
            return _f(url,opts);
          };
          window.fetch.__toast_log_patch=true;
        }
        if(window.ToastAuto && window.ToastAuto.init){ window.ToastAuto.init(); }
      }).catch(next);
    })();
  }
  if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot,{once:true});} else {boot();}
})();
</script>
<!-- /toast dynamic loader -->
</body></html>
