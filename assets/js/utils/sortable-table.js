// Sortable table utility (uniform across pages)
// Click or press Enter/Space on any thead > th to sort.
// Sets aria-sort="asc|desc" and reorders tbody rows in-place.
(function(global){
  'use strict';

  function getCellRaw(tr, idx){
    var c = tr.cells[idx];
    if(!c) return '';
    if (c.dataset && c.dataset.value !== undefined) return c.dataset.value;
    return c.textContent.trim();
  }

  function detectType(rows, idx, hinted){
    if (hinted) return hinted;
    for (var i=0;i<rows.length;i++){
      var s = getCellRaw(rows[i], idx).replace(/[,\s]/g,'');
      if (s==='') continue;
      if (/^-?\d+(?:\.\d+)?$/.test(s)) return 'num';
      break;
    }
    return 'str';
  }

  function compare(a, b, type){
    if(type==='num'){ return (parseFloat(a)||0) - (parseFloat(b)||0); }
    return String(a).localeCompare(String(b), undefined, {numeric:true, sensitivity:'base'});
  }

  function handleSort(ev){
    var th = ev.target.closest('th'); if(!th) return;
    if (ev.type === 'keydown' && !(ev.key==='Enter' || ev.key===' ')) return;
    var table = th.closest('table'); if(!table) return;
    var idx = th.cellIndex;
    var tbody = table.tBodies[0]; if(!tbody) return;
    var rows = Array.prototype.slice.call(tbody.rows);
    var hinted = th.getAttribute('data-sort') || null;
    var type = detectType(rows, idx, hinted);
    var dir = th.getAttribute('aria-sort')==='asc' ? 'desc' : 'asc';
    rows.sort(function(r1,r2){
      var a=getCellRaw(r1,idx), b=getCellRaw(r2,idx);
      var cmp = compare(a,b,type);
      return dir==='asc' ? cmp : -cmp;
    });
    // Clear existing rows and re-append
    var frag = document.createDocumentFragment();
    rows.forEach(function(r){ frag.appendChild(r); });
    tbody.innerHTML='';
    tbody.appendChild(frag);
    // Update aria-sort
    Array.prototype.forEach.call(th.parentNode.children, function(h){ h.removeAttribute('aria-sort'); });
    th.setAttribute('aria-sort', dir);
  }

  function prepareTable(table){
    if (!table || table.__sortablePrepared) return;
    table.__sortablePrepared = true;
    var ths = table.tHead ? table.tHead.rows[0].cells : [];
    Array.prototype.forEach.call(ths, function(th){
      th.setAttribute('role','columnheader');
      th.setAttribute('tabindex','0');
      th.classList.add('sortable');
    });
    table.addEventListener('click', handleSort);
    table.addEventListener('keydown', handleSort);
  }

  function init(selector){
    var tables = (selector ? document.querySelectorAll(selector) : document.querySelectorAll('table'));
    Array.prototype.forEach.call(tables, prepareTable);
  }

  // Auto-init
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', function(){ init('.js-sortable'); });
  } else { init('.js-sortable'); }

  global.SortableTable = { init: init };
})(window);
