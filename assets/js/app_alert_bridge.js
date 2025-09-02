(function(){
  if(!window._alert_to_toast){
    const orig = window.alert;
    window.alert = function(msg){ try{ toast && toast.warn(String(msg)); } catch(e){ orig(msg); } };
    window._alert_to_toast = true;
  }
})();