/* assets/js/pages/server_tests_enhance.js
   Makes the subheader sticky and arranges cards into specific rows:
   Row 1: PHP | OPcache | Network
   Row 2: Extensions  (full width, multi-column chips)
   Row 3: Filesystem  (full width, columnized)
   Row 4: Environment (full width)
*/
(function(){
  const onServerTests = /server_tests\.php(\?|$)/.test(location.pathname);
  if(!onServerTests) return;

  function q(sel,ctx){ return (ctx||document).querySelector(sel); }
  function qa(sel,ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  // Try to discover the height of the top app header and set CSS var
  const appHeader = q('header.app-header, .topbar, header');
  if(appHeader){
    const h = appHeader.getBoundingClientRect().height || 56;
    document.documentElement.style.setProperty('--st-topbar-h', h+'px');
  }

  // 1) Ensure we have a sticky subheader container
  let subhead = q('.st-head');
  let headerRow = q('.st-header.row') || q('.st-header') || q('.page-subhead') || q('.page-head');
  if(!subhead){
    subhead = document.createElement('div', 'card');
    subhead.className = 'st-head';
    // If we have an existing "st-header row" keep it inside; otherwise, synthesize one
    if(headerRow){
      headerRow.parentNode.insertBefore(subhead, headerRow);
      subhead.appendChild(headerRow);
    }else{
      // Synthesize from current title + actions if possible
      const title = q('h1, .page-title, .content h1');
      const actions = q('.actions, .btnbar, .page-actions');
      const row = document.createElement('div');
      row.className = 'row st-header';
      if(title) row.appendChild(title.cloneNode(true));
      if(actions) row.appendChild(actions.cloneNode(true));
      subhead.appendChild(row);
      const host = q('main, .content, body');
      (host||document.body).insertBefore(subhead, (host||document.body).firstChild);
    }
  }

  // 2) Build / ensure the grid container
  let grid = q('#stGrid');
  if(!grid){
    grid = document.createElement('div');
    grid.id = 'stGrid';
    // Try to find a logical cards container
    const after = subhead.nextElementSibling;
    if(after){
      after.parentNode.insertBefore(grid, after);
    }else{
      document.body.appendChild(grid);
    }
  }

  // Move all server test cards into the grid (but don't grab global cards like toasts)
  const cards = qa('.card').filter(c=>{
    // Skip the sticky header if it was styled as a card (safety)
    if(c.closest('.st-head')) return false;
    // Heuristic: server tests cards have titles in the body area; exclude nav/header widgets
    const t = (q('.card-title', c)?.textContent || '').trim().toLowerCase();
    return !!t && /php|opcache|extensions|filesystem|environment|network/.test(t);
  });
  cards.forEach(c=> grid.appendChild(c));

  // 3) Classify cards by title to control placement
  cards.forEach(card=>{
    const t = (q('.card-title', card)?.textContent || '').trim().toLowerCase();
    if(!t) return;
    if(t.startsWith('php')) card.classList.add('st-php');
    else if(t.includes('opcache')) card.classList.add('st-opcache');
    else if(t.includes('network')) card.classList.add('st-network');
    else if(t.includes('extensions')) card.classList.add('st-extensions');
    else if(t.includes('filesystem')) card.classList.add('st-filesystem');
    else if(t.includes('environment') || t === 'env') card.classList.add('st-env');
  });

  // 4) Extensions: wrap chips into a grid for many columns
  const ext = q('.st-extensions');
  if(ext){
    // find a container that has many chip children
    let host = q('.chip-wrap', ext) || q('.card-body', ext) || ext;
    const chips = qa('.chip, .pill', host);
    if(chips.length){
      let wrap = q('.chip-wrap', ext);
      if(!wrap){
        wrap = document.createElement('div');
        wrap.className = 'chip-wrap';
        // Move all chip-like nodes into wrap
        chips.forEach(ch=> wrap.appendChild(ch));
        host.appendChild(wrap);
      }
    }
  }

  // 5) Filesystem: columnize sections
  const fs = q('.st-filesystem');
  if(fs){
    // Collect section blocks inside filesystem card body
    const body = q('.card-body', fs) || fs;
    const blocks = qa(':scope > .kv, :scope > .section, :scope > .row, :scope > div', body)
      .filter(el=> el !== body && el.className !== 'card-title' && el.textContent.trim() !== '');
    if(blocks.length){
      let gridFs = q('.fs-grid', fs);
      if(!gridFs){
        gridFs = document.createElement('div');
        gridFs.className = 'fs-grid';
        blocks.forEach(b=> gridFs.appendChild(b));
        body.appendChild(gridFs);
      }
    }
  }
})();
