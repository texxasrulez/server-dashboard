(function(){
  'use strict';
  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function $all(s, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }
  function on(el, ev, fn){ if(el) el.addEventListener(ev, fn, false); }
  function fmtBytes(n){ if(n==null) return ''; var u=['B','KB','MB','GB','TB']; var i=0,x=+n; while(x>=1024&&i<u.length-1){x/=1024;i++;} return (x>=10?x.toFixed(0):x.toFixed(1))+' '+u[i]; }
  function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function toggleRowDetails(tr){
    if (!tr || tr.classList.contains('details-row')) return;
    var nameCell = tr.cells && tr.cells[0]; if (!nameCell) return;
    var db = nameCell.textContent.trim();
    if (!db) return;
    var next = tr.nextElementSibling;
    if (next && next.classList.contains('details-row')){
      next.parentNode.removeChild(next);
      return;
    }
    var colSpan = tr.cells.length;
    var details = document.createElement('tr');
    details.className = 'details-row';
    var td = document.createElement('td');
    td.colSpan = colSpan;
    td.innerHTML = '<div class="db-details muted">Loading tables…</div>';
    details.appendChild(td);
    tr.parentNode.insertBefore(details, tr.nextSibling);

    fetch('database.php?action=tables&db=' + encodeURIComponent(db))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok){ td.innerHTML = '<div class="error">Failed to load tables</div>'; return; }
        var html = ['<div class="db-details"><table class="table compact"><thead><tr><th>Table</th><th>Rows</th><th>Data</th><th>Index</th><th>Updated</th></tr></thead><tbody>'];
        (j.tables||[]).forEach(function(t){
          html.push('<tr class="table-row" data-db="'+escapeHtml(j.db)+'" data-table="'+escapeHtml(t.name)+'">');
          html.push('<td class="tbl-name"><button class="linklike">'+escapeHtml(t.name)+'</button></td>');
          html.push('<td class="nowrap">'+(t.rows!=null? Number(t.rows).toLocaleString(): '')+'</td>');
          html.push('<td class="nowrap">'+fmtBytes(t.data_bytes)+'</td>');
          html.push('<td class="nowrap">'+fmtBytes(t.index_bytes)+'</td>');
          html.push('<td>'+(t.update_time? escapeHtml(t.update_time): '')+'</td>');
          html.push('</tr>');
        });
        html.push('</tbody></table></div>');
        td.innerHTML = html.join('');
      })
      .catch(function(err){
        td.innerHTML = '<div class="error">'+escapeHtml((err&&err.message)||'Network error')+'</div>';
      });
  }

  function openModal(db, table, payload){
    var el = $('#dbModal');
    if (!el){
      el = document.createElement('div');
      el.className = 'modal'; el.id = 'dbModal'; el.setAttribute('hidden','');
      el.innerHTML = ''
        + '<div class="modal-dialog">'
        + '  <div class="modal-head">'
        + '    <div class="modal-title"></div>'
        + '    <button class="modal-close" aria-label="Close">×</button>'
        + '  </div>'
        + '  <div class="modal-body"><div class="modal-content"></div></div>'
        + '</div>';
      document.body.appendChild(el);
      on($('.modal-close', el), 'click', function(){ el.setAttribute('hidden',''); });
      on(el, 'click', function(ev){ if (ev.target === el) el.setAttribute('hidden',''); });
      document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') el.setAttribute('hidden',''); });
    }
    $('.modal-title', el).textContent = db + '.' + table + ' (first ' + (payload && payload.limit || 100) + ' rows)';
    var mount = $('.modal-content', el);
    var cols = payload.columns || [];
    var rows = payload.rows || [];
    var out = ['<div class="tablewrap"><table class="table compact"><thead><tr>'];
    cols.forEach(function(c){ out.push('<th>'+escapeHtml(c)+'</th>'); });
    out.push('</tr></thead><tbody>');
    rows.forEach(function(r){
      out.push('<tr>');
      cols.forEach(function(c){ out.push('<td>'+escapeHtml(r[c])+'</td>'); });
      out.push('</tr>');
    });
    out.push('</tbody></table></div>');
    mount.innerHTML = out.join('');
    el.removeAttribute('hidden');
  }

  function handleBodyClicks(ev){
    var tr = ev.target.closest('#dbTable tbody tr');
    if (!tr) return;
    var trow = ev.target.closest('.table-row');
    if (trow){
      var db = trow.getAttribute('data-db');
      var tbl = trow.getAttribute('data-table');
      fetch('database.php?action=preview&db=' + encodeURIComponent(db) + '&table=' + encodeURIComponent(tbl) + '&limit=100')
        .then(function(r){ return r.json(); })
        .then(function(j){ if(j && j.ok){ openModal(db, tbl, j); } else { if (window.toast) window.toast.error((j&&j.error)||'Preview failed'); } })
        .catch(function(err){ if (window.toast) window.toast.error((err&&err.message)||'Network error'); });
      return;
    }
    var cell = ev.target.closest('td');
    if (!cell || cell.cellIndex !== 0) return;
    toggleRowDetails(tr);
  }

  document.addEventListener('DOMContentLoaded', function(){
    var tb = $('#dbTable tbody');
    if (tb) tb.addEventListener('click', handleBodyClicks);
  });
})();