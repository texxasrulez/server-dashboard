# Server Diagnostics (server_test.php)

This page provides **read-only** diagnostics. It does not change server state.

## Sections
- **PHP**: version and SAPI
- **INI**: relevant ini values
- **Extensions**: common extensions presence
- **OPcache**: status, memory, hit rate
- **Filesystem**: basic checks for project paths, disk space
- **Network**: DNS / HTTPS (via get_headers) / cURL presence
- **Environment**: server and OS info

## Actions
- **Run all**: fetches `api/server_test.php` and renders cards
- **Copy JSON**: copies last result to clipboard
- **Export JSON**: downloads a JSON file of last result

### Endpoint
`api/server_test.php` returns JSON and performs only read-only operations.
