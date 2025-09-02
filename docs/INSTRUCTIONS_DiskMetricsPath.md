# Disk Metrics Path — Override & Debug

Location: `api/metrics_summary.php` (+ optional `config.php`).

## Auto‑detect order
1. `/`
2. `$_SERVER['DOCUMENT_ROOT']`
3. Project root (`__DIR__/..`)
4. Current working directory

## Explicit override
- Add in `config.php`:
```php
define('DISK_METRICS_PATH', '/'); // e.g., '/', '/home', '/mnt/data'
```
- Or temporary via query for testing:
```
/api/metrics_summary.php?disk=/home&debug=1
```

## Debug
Add `?debug=1` to the API to see `"debug": { "disk": { "chosen": "...", "tried": [...], "open_basedir": "..." } }`.
