
(function(){
  'use strict';
  function $(s,c){ return (c||document).querySelector(s); }
  function ensureTip(){ var t=document.getElementById('sparkTip'); if(t) return t;
    t=document.createElement('div'); t.id='sparkTip'; t.style.position='fixed'; t.style.zIndex=9999;
    t.style.pointerEvents='none'; t.style.padding='6px 8px'; t.style.borderRadius='8px'; t.style.fontSize='12px';
    t.style.background='rgba(15,15,15,.9)'; t.style.color='var(--text-color,#eee)'; t.style.border='1px solid rgba(255,255,255,.12)';
    t.style.boxShadow='0 4px 16px rgba(0,0,0,.35)'; t.hidden=true; document.body.appendChild(t); return t; }
  function showTip(html,x,y){ var t=ensureTip(); t.innerHTML=html; t.hidden=false; var r=t.getBoundingClientRect();
    t.style.left=Math.min(x+14, innerWidth-r.width-8)+'px'; t.style.top=Math.min(y+12, innerHeight-r.height-8)+'px'; }
  function hideTip(){ var t=document.getElementById('sparkTip'); if(t) t.hidden=true; }
  function bind(canvas, series, name, fmt){
    if(!canvas || !Array.isArray(series) || !series.length) return;
    var times = (window.__sparkTimes || []);
    function idx(ev){
      var rect=canvas.getBoundingClientRect();
      var x=Math.max(0, Math.min(ev.clientX-rect.left, rect.width));
      var i=Math.round((series.length-1)*(rect.width? (x/rect.width):0));
      return Math.max(0, Math.min(series.length-1, i));
    }
    canvas.addEventListener('mousemove', function(ev){
      var i=idx(ev); var val=series[i];
      var when = times[i] ? new Date(times[i]*1000).toLocaleString() : '';
      var html='<div style="font-weight:600;margin-bottom:2px;">'+name+'</div>'
              + (when? '<div>'+when+'</div>':'')
              + '<div>value: ' + (fmt? fmt(val): String(val)) + '</div>';
      showTip(html, ev.clientX, ev.clientY);
    }, false);
    ['mouseleave','mousedown','blur'].forEach(function(ev){ canvas.addEventListener(ev, hideTip, false); });
  }
  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function(){
    var attempts=0; var t=setInterval(function(){
      attempts++; 
      var load=$('#chartLoad'), mem=$('#chartMem'), net=$('#chartNet');
      var L=window.__sparkLoad, M=window.__sparkMem, N=window.__sparkNet;
      if(load && mem && net && Array.isArray(L) && L.length && Array.isArray(M) && Array.isArray(N)){
        bind(load, L, 'CPU load', function(v){ return (v||0).toFixed(2); });
        bind(mem,  M,  'Memory used', function(v){ v=Math.max(0,Math.min(1,v)); return Math.round(v*100)+'%'; });
        bind(net,  N,  'Net activity', function(v){ return Math.round((v||0)*100)+'%'; });
        clearInterval(t);
      }
      if(attempts>30) clearInterval(t);
    }, 300);
  });
})();
