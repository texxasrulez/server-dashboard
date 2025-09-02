// proc.gauge.extras.v3.js — smooth triangular needle + gradient, non-invasive
(function(){
  function $all(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }
  function svgEl(name){ return document.createElementNS('http://www.w3.org/2000/svg', name); }

  function installOnce(host){
    var root = host || document.getElementById('procGrid') || document;
    $all('.proc-card', root).forEach(function(card){
      var id = card.getAttribute('data-id') || 'g';
      var svg = card.querySelector('svg.arc.gauge'); if (!svg) return;

      // gradient (per card)
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
        var prog = svg.querySelector('.progress'); if (prog){ prog.style.stroke = 'url(#'+gradId+')'; }
      }

      // needle (triangle with base at hub, tip near arc)
      if (!svg.querySelector('.needle')){
        var g = svgEl('g'); g.setAttribute('class','needle');
        // CSS transform is smoother than attribute transform
        g.style.transformOrigin = '50px 50px';
        g.style.transform = 'rotate(180deg)';
        // shape: slim triangle; tweak CSS variables to tune width/length
        var tipR = 14;    // distance from top edge (smaller = longer needle)
        var baseR = 32;   // base radius (just above hub)
        var halfW = 1.6;  // half width of the needle at base

        // Convert polar to cartesian for the three points at default angle (up)
        function pt(r, ax){ var rad = (Math.PI/180) * ax; return [50 + r*Math.sin(rad), 50 - r*Math.cos(rad)]; }
        var p1 = pt(tipR,   0);    // tip (upwards at angle 0 in our local coords)
        var p2 = pt(baseR,  +halfW*6); // base right
        var p3 = pt(baseR,  -halfW*6); // base left
        var poly = svgEl('polygon');
        poly.setAttribute('class','blade');
        poly.setAttribute('points', p1.join(',')+' '+p2.join(',')+' '+p3.join(','));
        var hub = svgEl('circle'); hub.setAttribute('class','hub'); hub.setAttribute('cx','50'); hub.setAttribute('cy','50'); hub.setAttribute('r','3.2');
        g.appendChild(poly); g.appendChild(hub);
        svg.appendChild(g);
      }
    });
  }

  function setAngle(el, pct){
    var p = Math.max(0, Math.min(100, Number(pct)||0));
    // Map 0..100% to 180..0 degrees
    var ang = 180 * (1 - (p/100));
    el.style.transform = 'rotate('+ang+'deg)';
  }

  // Wrap setGauge (robust): retry until found
  var wrapped = false;
  function wrap(){
    if (wrapped) return;
    if (typeof window.setGauge === 'function'){
      var orig = window.setGauge;
      window.setGauge = function(root, id, pct, text, state){
        try { orig.apply(this, arguments); } catch(_) {}
        try {
          installOnce(root);
          var host = root || document.getElementById('procGrid') || document;
          var card = host.querySelector('.proc-card[data-id="'+id+'"]');
          if (card){
            var needle = card.querySelector('.needle');
            if (needle) setAngle(needle, pct);
          }
        } catch(_) {}
      };
      wrapped = true;
      try{ console.debug && console.debug('[proc.gauge.extras.v3] active'); }catch(_){}
    }
  }

  // Try multiple times (handles deferred script loading)
  var tries = 0, tm = setInterval(function(){
    if (wrapped || tries++ > 120){ clearInterval(tm); return; }
    wrap();
  }, 100);
  document.addEventListener('DOMContentLoaded', wrap);
  window.addEventListener('load', wrap);

  // If grid contents change later, ensure shapes exist
  try{
    var grid = document.getElementById('procGrid');
    if (grid){
      var mo = new MutationObserver(function(){ installOnce(grid); });
      mo.observe(grid, {childList:true, subtree:true});
    }
  }catch(_){}
})();
