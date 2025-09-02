// Adds 'is-active' + aria-current=page to the current header tab without touching PHP
(function(){
  try{
    var links = document.querySelectorAll('.app-header .tabs a[href]');
    if (!links.length) return;
    var here = location.pathname.split('/').pop() || 'index.php';
    links.forEach(function(a){
      var href = (a.getAttribute('href')||'').split('/').pop();
      if (href === here){
        a.classList.add('is-active');
        if (!a.hasAttribute('aria-current')) a.setAttribute('aria-current','page');
      }
    });
  }catch(e){ /* silent */ }
})();