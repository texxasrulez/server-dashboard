# Server Diagnostics (`server_tests.php`)

Read-only page to inspect PHP/env/filesystem/network. No server state changes.

## Endpoints

- `api/server_tests.php` — returns JSON payload used by the page.

## Security Updates Check

- The **Quick Scan** and **Security** tabs use read-only package-manager checks only.
- APT-family hosts prefer local `apt-check` data when available and fall back to `apt-get -s upgrade`.
- DNF/YUM, `zypper`, and `pacman`/`checkupdates` are queried with read-only status commands only.
- If the package manager is present but its metadata is stale or security advisories cannot be classified safely, the page shows a warning with that reason instead of a generic `unknown`.

## Buttons

- **Run all** — reload data
- **Copy JSON** — puts prettified JSON on clipboard
- **Export JSON** — downloads `server-tests.json`

All styling follows theme tokens; mobile layout stacks cards.
