# INSTRUCTIONS — Self-test

**File:** `tools/selftest.php`  
**Route:** `/tools/selftest.php`  
**Purpose:** Non-destructive portability check for common server prerequisites.

## What it checks
- PHP version ≥ 7.4 (works on 8.x)
- Sessions usable
- JSON extension present
- `state/` directory exists and is writable by the web server
- URL builder works (`project_url()`)
- Optional: cURL available, `allow_url_fopen` enabled
- Optional: `mbstring` availability

## How to run
1. Deploy the project normally.
2. Visit `/tools/selftest.php` in a browser.
3. Expect a table of checks showing ✅ for OK and ⚠️ if attention is needed.

## Fixes for common failures
- **`state/` not writable**  
  Linux (Apache/Nginx):  
  ```bash
  chown -R www-data:www-data state
  chmod -R u+rwX,g+rwX state
  ```
  Windows (IIS): grant Modify to the app pool identity on `state\`.
- **Sessions not active**  
  Ensure PHP sessions are enabled; check `session.save_path` is writable.
- **JSON/cURL/mbstring missing**  
  Install/enable the corresponding PHP extensions.
- **URL builder issues**  
  Confirm site base URL and web root mapping are correct; app does not rely on `DOCUMENT_ROOT`.

## Safety
- Read-only except for checking writability of `state/` (no file creation or deletion).
- No network calls required.

## Theme/layout
- Uses base styles (`/assets/css/core.css`). No special width or alignment requirements.
