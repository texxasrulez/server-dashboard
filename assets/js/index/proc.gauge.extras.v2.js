
// proc.gauge.extras.v2.js — robust add‑on: gradient & needle with polling + fallbacks
(function(){
  function $all(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
  function svgEl(name){ return document.createElementNS('http://www.w3.org/2000/svg', name); }

  function install(root){
    var host = root || document.getElementById('procGrid') || document;
    $all('.proc-card', host).forEach(function(card){
      var id = card.getAttribute('data-id') || 'g';
      var svg = card.querySelector('svg.arc.gauge'); if (!svg) return;

      // gradient (unique id per card)
      var gradId = 'grad-' + id;
      if (!svg.querySelector('#'+gradId)){
        var defs = svg.querySelector('defs') || svgEl('defs');
        if (!defs.parentNode) svg.insertBefore(defs, svg.firstChild);
        var lg = svgEl('linearGradient');
        lg.setAttribute('id', gradId);
        lg.setAttribute('x1','10'); lg.setAttribute('y1','50');
        lg.setAttribute('x2','90'); lg.setAttribute('y2','50');
        lg.setAttribute('gradientUnits','userSpaceOnUse');
        [['0%','var(--ok)'], ['60%','var(--warn)'], ['85%','var(--danger)'], ['100%','var(--danger)']].forEach(function(st){
          var stop = svgEl('stop'); stop.setAttribute('offset', st[0]); stop.setAttribute('stop-color', st[1]); lg.appendChild(stop);
        });
        defs.appendChild(lg);
        var prog = svg.querySelector('.progress'); 
        if (prog){ prog.style.stroke = 'url(#'+gradId+')'; } // use style to override CSS
      }

      // needle
      if (!svg.querySelector('.needle')){
        var g = svgEl('g'); g.setAttribute('class','needle'); g.setAttribute('transform','rotate(180 50 50)');
        var hand = svgEl('line'); hand.setAttribute('class','hand');
        hand.setAttribute('x1','50'); hand.setAttribute('y1','50'); hand.setAttribute('x2','50'); hand.setAttribute('y2','13');
        var hub = svgEl('circle'); hub.setAttribute('class','hub'); hub.setAttribute('cx','50'); hub.setAttribute('cy','50'); hub.setAttribute('r','3.2');
        g.appendChild(hand); g.appendChild(hub);
        svg.appendChild(g);
      }
    });
  }

  // Wrap setGauge when available; retry until found
  var _wrapped = false;
  function wrapIfReady(){
    if (_wrapped) return;
    if (typeof window.setGauge === 'function'){
      var orig = window.setGauge;
      window.setGauge = function(root, id, pct, text, state){
        // original behavior
        try { orig.apply(this, arguments); } catch(e){ /* keep quiet */ }
        // ensure extras exist and rotate needle
        try {
          install(root);
          var host = root || document.getElementById('procGrid') || document;
          var el = host.querySelector('.proc-card[data-id="'+id+'"]');
          if (el){
            var nd = el.querySelector('.needle');
            if (nd){
              var p = Math.max(0, Math.min(100, Number(pct)||0));
              var ang = 180 * (1 - (p/100));
              nd.setAttribute('transform', 'rotate('+ang+' 50 50)');
            }
          }
        } catch(e){ /* no-op */ }
      };
      _wrapped = true;
      try{ console.debug && console.debug('[proc.gauge.extras] active'); }catch(_){}
    }
  }

  // Poll for setGauge for up to ~10s
  var tries = 0;
  var tm = setInterval(function(){
    if (_wrapped || tries++ > 100){ clearInterval(tm); return; }
    wrapIfReady();
  }, 100);

  // Also attempt on DOM ready & load (covers fast loads)
  document.addEventListener('DOMContentLoaded', wrapIfReady);
  window.addEventListener('load', wrapIfReady);

  // MutationObserver: if procGrid changes, ensure defs/needle exist
  try{
    var grid = document.getElementById('procGrid');
    if (grid){
      var mo = new MutationObserver(function(){ install(grid); });
      mo.observe(grid, {childList:true, subtree:true});
    }
  }catch(_){}
})();
