(function(){
  function $(s, c){ return (c||document).querySelector(s); }
  function $$(s, c){ return Array.from((c||document).querySelectorAll(s)); }

  function applyMetrics(data){
    if (!data) return;

    // Generic [data-metric] bindings
    $$('[data-metric]').forEach(el => {
      const key = el.getAttribute('data-metric');
      let val = null;
      switch(key){
        case 'uptime': val = data.uptime_human || ''; break;
        case 'load1':  val = (data.loadavg && data.loadavg[0]!=null) ? Number(data.loadavg[0]).toFixed(3) : ''; break;
        case 'load5':  val = (data.loadavg && data.loadavg[1]!=null) ? Number(data.loadavg[1]).toFixed(3) : ''; break;
        case 'load15': val = (data.loadavg && data.loadavg[2]!=null) ? Number(data.loadavg[2]).toFixed(3) : ''; break;
        case 'mem_used_human': val = (data.memory && data.memory.used_human) || ''; break;
        case 'mem_total_human': val = (data.memory && data.memory.total_human) || ''; break;
        case 'mem_used_pct': val = (data.memory && data.memory.used_percent!=null) ? data.memory.used_percent+'%' : ''; break;
        case 'disk_used_human': val = (data.disk && data.disk.used_human) || ''; break;
        case 'disk_free_human': val = (data.disk && data.disk.free_human) || ''; break;
        case 'disk_total_human': val = (data.disk && data.disk.total_human) || ''; break;
        case 'disk_used_pct': val = (data.disk && data.disk.used_percent!=null) ? data.disk.used_percent+'%' : ''; break;
        case 'disk_temp_c': val = (data.disk && data.disk.temp_c!=null) ? data.disk.temp_c+'°C' : ''; break;
        case 'cpu_temp_package':
          val = (data.cpu && data.cpu.temps && data.cpu.temps.package!=null)
            ? Number(data.cpu.temps.package).toFixed(1)+'°C'
            : '';
          break;
        case 'cpu_temp_core_avg':
          if (data.cpu && data.cpu.temps && data.cpu.temps.cores) {
            const vals = Object.values(data.cpu.temps.cores).map(Number).filter(v => !isNaN(v));
            if (vals.length) {
              const sum = vals.reduce((a,b)=>a+b,0);
              const avg = sum / vals.length;
              val = avg.toFixed(1)+'°C';
            } else {
              val = '';
            }
          } else {
            val = '';
          }
          break;
        default: val = ''; break;
      }
      if (val!==null) el.textContent = val;
    });

    // Memory and disk meters if declared
    const memBar = $('[data-meter="memory-used"]');
    if (memBar && data.memory && data.memory.used_percent!=null){
      memBar.style.setProperty('--pct', data.memory.used_percent);
      const pctEl = memBar.closest('.proc-row') && memBar.closest('.proc-row').querySelector('.pct');
      if (pctEl) pctEl.textContent = data.memory.used_percent + '%';
    }
    const diskBar = $('[data-meter="disk-used"]');
    if (diskBar && data.disk && data.disk.used_percent!=null){
      diskBar.style.setProperty('--pct', data.disk.used_percent);
      const pctEl = diskBar.closest('.proc-row') && diskBar.closest('.proc-row').querySelector('.pct');
      if (pctEl) pctEl.textContent = data.disk.used_percent + '%';
    }

    // Server Processes rows: update by data-proc-name using CPU%
    if (Array.isArray(data.processes)){
      data.processes.forEach(p => {
        const row = document.querySelector('.proc-row[data-proc-name="'+p.name+'"]') 
                 || Array.from(document.querySelectorAll('.proc-row')).find(r=> (r.querySelector('.name')?.textContent||'').trim()===p.name);
        if (!row) return;
        const meter = row.querySelector('.proc-meter');
        if (meter){
          const pct = Math.max(0, Math.min(100, Math.round(p.cpu)));
          meter.style.setProperty('--pct', pct);
          meter.setAttribute('data-pct', pct);
        }
        const pctEl = row.querySelector('.pct');
        if (pctEl) pctEl.textContent = Math.round(p.cpu) + '%';
      });
    }
  }

  async function fetchMetrics(){
    try{
      const r = await fetch('api/metrics_summary.php', { cache:'no-store' });
      if (!r.ok) throw 0;
      return await r.json();
    }catch(e){ return null; }
  }

  async function update(){ const data = await fetchMetrics(); applyMetrics(data); }

  window.addEventListener('dashboard:refresh', update);
  document.addEventListener('DOMContentLoaded', update);
})();