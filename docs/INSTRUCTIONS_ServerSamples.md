# INSTRUCTIONS — Server Samples (.htaccess, web.config)

**Files:**  
- `.htaccess` (Apache)  
- `web.config` (IIS)

**Purpose:** Provide optional, drop-in hardening for `state/` and correct JSON MIME type on hosts that need it.

## Apache (`.htaccess`)
- Blocks direct web access to `/state/` when allowed by server config.
- Place in the project root (already included).

## IIS (`web.config`)
- Hides `state/` as a URL segment.
- Adds JSON MIME mapping for hosts missing it.

## Compatibility
- These files are optional; the app runs without them.
- If your host forbids overrides, handle equivalent rules in vhost/site settings.

## Theme/layout
- N/A (config-only).

