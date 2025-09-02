(function(){
  function qs(s,ctx=document){ return ctx.querySelector(s); }
  function on(el, ev, fn){ el && el.addEventListener(ev, fn); }
  // Copy for any button with data-copy-raw inside the metrics card
  document.addEventListener('click', async (e)=>{
    const a = e.target.closest('[data-copy-raw]');
    if (!a) return;
    e.preventDefault();
    const card = a.closest('.card');
    const raw = card ? card.querySelector('.raw-preview') : document.querySelector('.raw-preview');
    try{
      await navigator.clipboard.writeText(raw ? raw.textContent : '');
      const prev = a.textContent;
      a.textContent = 'Copied!';
      setTimeout(()=> a.textContent = prev, 1200);
    }catch(_){
      alert('Copy failed');
    }
  });

  // Backward compatibility: support old #btnCopyRaw if present
  const old = qs('#btnCopyRaw');
  if (old && !old.hasAttribute('data-copy-raw')) old.setAttribute('data-copy-raw','1');
})();