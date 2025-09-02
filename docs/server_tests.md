# Server Diagnostics (`server_tests.php`)

Read-only page to inspect PHP/env/filesystem/network. No server state changes.

## Endpoints

- `api/server_tests.php` — returns JSON payload used by the page.

## Buttons
- **Run all** — reload data
- **Copy JSON** — puts prettified JSON on clipboard
- **Export JSON** — downloads `server-tests.json`

All styling follows theme tokens; mobile layout stacks cards.
