
// Global hotkeys: g+h History, g+l Logs (legacy), g+s Server Tests, g+d Diagnostics, g+c Config
(function(){
  var map = {'h':'history.php','l':'logs.php','s':'server_tests.php','d':'diagnostics.php','c':'config.php'};
  document.addEventListener('keydown', function(ev){
    if ((ev.key === 'g' || ev.key === 'G') && !ev.ctrlKey && !ev.metaKey){
      var once = function(e){
        document.removeEventListener('keydown', once);
        var key = (e && typeof e.key === 'string') ? e.key : '';
        var k = key.toLowerCase();
        if (map[k]) { window.location.href = map[k]; }
      };
      document.addEventListener('keydown', once, {once:true});
    }
  });
})();
