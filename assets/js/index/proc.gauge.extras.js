
// proc.gauge.extras.js — non-invasive add-on: gradient & needle
(function(){
  function $all(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
  function svgEl(name){ return document.createElementNS('http://www.w3.org/2000/svg', name); }

  function install(root){
    if (!root) return;
    $all('.proc-card', root).forEach(function(card){
      var id = card.getAttribute('data-id') || 'g';
      var svg = card.querySelector('svg.arc.gauge'); if (!svg) return;

      // gradient (id unique per card)
      var gradId = 'grad-' + id;
      if (!svg.querySelector('#'+gradId)){
        var defs = svgEl('defs');
        var lg = svgEl('linearGradient');
        lg.setAttribute('id', gradId);
        lg.setAttribute('x1','10'); lg.setAttribute('y1','50');
        lg.setAttribute('x2','90'); lg.setAttribute('y2','50');
        lg.setAttribute('gradientUnits','userSpaceOnUse');
        [['0%','var(--ok)'], ['60%','var(--warn)'], ['85%','var(--danger)'], ['100%','var(--danger)']].forEach(function(st){
          var stop = svgEl('stop'); stop.setAttribute('offset', st[0]); stop.setAttribute('stop-color', st[1]); lg.appendChild(stop);
        });
        defs.appendChild(lg);
        svg.insertBefore(defs, svg.firstChild);
        var prog = svg.querySelector('.progress'); if (prog) prog.setAttribute('stroke', 'url(#'+gradId+')');
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

  // wrap setGauge if present
  var wrapApplied = false;
  function tryWrap(){
    if (wrapApplied) return;
    if (typeof window.setGauge === 'function'){
      var orig = window.setGauge;
      window.setGauge = function(root, id, pct, text, state){
        // call original behavior
        orig.apply(this, arguments);
        try{
          // lazy install extras once
          if (root && !root.__extrasInstalled){ install(root); root.__extrasInstalled = true; }
          // rotate needle
          var el = root ? root.querySelector('.proc-card[data-id="'+id+'"]') : null;
          if (el){
            var nd = el.querySelector('.needle');
            if (nd){
              var p = Math.max(0, Math.min(100, Number(pct)||0));
              var ang = 180 * (1 - (p/100));
              nd.setAttribute('transform', 'rotate('+ang+' 50 50)');
            }
          }
        }catch(e){ /* keep silent to avoid breaking */ }
      };
      wrapApplied = true;
    }
  }

  // Try on DOM ready, then retry after a tick to catch late script
  document.addEventListener('DOMContentLoaded', function(){
    tryWrap();
    setTimeout(tryWrap, 50);
  });
  // Also attempt on load
  window.addEventListener('load', tryWrap);
})();
