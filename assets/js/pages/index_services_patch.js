/* === services API integration === */
async function fetchServices(){
  const res = await fetch('api/services.php?fn=list', {credentials:'same-origin'});
  const json = await res.json();
  return json.items || [];
}

async function renderServicesFromAPI(){
  const wrap = document.querySelector('#services'); if(!wrap) return;
  const items = await fetchServices();
  wrap.innerHTML='';
  let upCount=0;
  items.forEach(s => {
    const up = !!s.enabled; if(up) upCount++;
    const card = document.createElement('div'); card.className='service';
    card.innerHTML = `
      <div class="top">
        <span class="dot" style="background:${up?'var(--accent-2)':'#ff6b6b'}"></span>
        <div class="name">${escapeHtml(s.name)}</div>
        <span class="badge ${up?'up':'down'}">${up?'UP':'DOWN'}</span>
      </div>
      <div class="meta">
        <span>Host: ${escapeHtml(s.host)}:${String(s.port)}</span>
        <span>Type: ${escapeHtml(s.check || 'tcp')}</span>
      </div>`;
    wrap.appendChild(card);
  });
  const upEl = document.querySelector('#svcUpCount'); if(upEl) upEl.textContent = upCount;
  const totEl = document.querySelector('#svcTotal'); if(totEl) totEl.textContent = items.length;
}
