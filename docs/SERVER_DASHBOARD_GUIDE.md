# Server Dashboard — Installation, Administration & User Guide

> This document covers setup, operation, and troubleshooting for the drop-anywhere PHP server dashboard. It assumes basic familiarity with Linux server administration and a working web server (Apache or Nginx) with PHP-FPM or mod_php.

---

## Table of Contents
- [1. Requirements](#1-requirements)
- [2. Files & Folders](#2-files--folders)
- [3. Installation](#3-installation)
- [4. Web Server Setup](#4-web-server-setup)
- [5. Post-Install Checklist](#5-post-install-checklist)
- [6. Configuration (UI)](#6-configuration-ui)
  - [6.1 UI](#61-ui)
  - [6.2 Alerts](#62-alerts)
  - [6.3 History](#63-history)
  - [6.4 Server Tests](#64-server-tests)
  - [6.5 Security Page (Mailer & Headers)](#65-security-page-mailer--headers)
- [7. Server Tests Page](#7-server-tests-page)
  - [7.1 Quick Scan](#71-quick-scan)
  - [7.2 Security](#72-security)
  - [7.3 Filesystem](#73-filesystem)
  - [7.4 Performance](#74-performance)
  - [7.5 Services](#75-services)
  - [7.6 History View](#76-history-view)
- [8. Alerts](#8-alerts)
  - [8.1 Test Email Button](#81-test-email-button)
  - [8.2 Scheduling Alerts](#82-scheduling-alerts)
- [9. Backups & Upgrades](#9-backups--upgrades)
- [10. Security Hardening](#10-security-hardening)
- [11. Troubleshooting](#11-troubleshooting)
- [12. FAQ](#12-faq)

---

## 1. Requirements

- **OS:** Linux (Debian/Ubuntu, RHEL/Fedora, Alpine, etc.)
- **Web Server:** Nginx + PHP-FPM, or Apache (pref. with PHP-FPM)
- **PHP:** 7.4+ (8.x recommended)
  - Extensions: `json`, `ctype`, `mbstring`, `openssl`, `filter`, `pcre`, `curl` (if using webhook), `sockets` (standard), `posix` (nice to have)
- **Permissions:** Web server user (e.g., `www-data` / `nginx` / `apache`) must have write access to:
  - `data/` (stores `security_config.json` and other state)
  - `state/` (stores history JSONL files)
- **Outbound connectivity:** Required for:
  - TLS/HTTP checks (security tests)
  - Service reachability (TCP connects)
  - Email (SMTP or sendmail per your mailer setup)

---

## 2. Files & Folders

```
web-admin/
  api/                      # JSON endpoints (e.g., server_tests.php, alerts_*.php)
  assets/                   # JS/CSS
  config.php                # Configuration UI
  security.php              # Security (headers, mailer From/Reply-To)
  server_tests.php          # Server tests UI
  includes/                 # init/auth/mailer/etc.
  lib/                      # PHP library classes
  data/                     # writable; holds security_config.json
  state/
    history/                # writable; YYYY-MM.jsonl with past test results
```

> **Drop-anywhere:** Paths are relative; there are no hardcoded absolute paths.

---

## 3. Installation

1. **Upload** the `` directory to your web root (or subdirectory).
2. **Create writable directories:**
   ```bash
   mkdir -p data state/history
   chown -R www-data:www-data data state
   chmod -R 750 data state
   ```
3. **Ensure PHP extensions are present** (see Requirements).
4. **Open** `https://your-host` and log in (if your deployment uses auth). If authentication is not yet set up, protect the directory at the web server level while you configure security.

---

## 4. Web Server Setup

### Nginx (example)
```nginx
location  {
  alias /var/www/your-site;
  index index.php;
  try_files $uri $uri/ index.php?$query_string;
}

location ~ ^.*\.php$ {
  include fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $request_filename;
  fastcgi_pass unix:/run/php/php-fpm.sock;
}
```

### Apache (example)
```apache
Alias /web-admin /var/www/your-site/web-admin
<Directory "/var/www/your-site/web-admin">
    AllowOverride All
    Require all granted
</Directory>

# If using FPM, proxy PHP to php-fpm; otherwise enable mod_php.
```

> Protect with HTTPS. Consider Basic Auth or IP allowlists on `` if you do not already have app auth enabled.

---

## 5. Post-Install Checklist

- `data/` and `state/` are writable by the web server user.
- PHP error logging enabled (server or `php.ini`) so you can see fatals quickly.
- You can open:
  - `config.php` (Configuration UI)
  - `security.php` (Security & Mailer)
  - `server_tests.php` (Test suite)
- **Security → Mailer**: set **From** and optionally **Reply-To**.
- **Config → Alerts**: set your notification email and webhook if applicable.
- **Config → History**: confirm logging enabled (default on). Adjust retention if needed.

---

## 6. Configuration (UI)

### 6.1 UI
- **Toast position:** Choose where in the viewport toast notifications appear. The setting is applied on save; a reload is now automatic after saving.
- Additional UI options may include theme and other display preferences.

### 6.2 Alerts
- **Email (optional):** Recipient of alert emails.  
- **Webhook URL (optional):** If you use a chat/webhook integration.  
- **Cron token (optional):** If you protect a cron-triggered endpoint with a token (see Alerts section).

**Test sending:** A **Send test email** button appears at the bottom of the Alerts section. It sends to **Alerts → Email** using **From / Reply-To** from the **Security** page.

### 6.3 History
- **Enable history logging:** On by default. Each test run appends to `state/history/YYYY-MM.jsonl`.
- **Retention (days):** Controls pruning policy (by month files).
- **Max samples to return:** Upper bound for API responses, used by the History UI.

### 6.4 Server Tests
- **Service targets (host:port[|label]):** List of endpoints to probe by TCP connect.
  - Examples:
    - `127.0.0.1:80|Nginx`
    - `127.0.0.1:3306|MySQL`
    - `127.0.0.1:6379|Redis`
- **Service connect timeout (ms):** TCP connect timeout per target.

> If the Services list is empty, the Services tab will prompt once and cache targets in `localStorage`.

### 6.5 Security Page (Mailer & Headers)
- **From / Reply-To:** Governs the sender for **Alerts** emails. This prevents the host’s default sender being used.
- Other HTTP-header/security options live here (HSTS, X-Frame-Options, etc., depending on your build).

---

## 7. Server Tests Page

Buttons along the top run different suites; results render as a table with **chips**:

- **Good** (green), **Medium** (amber), **Bad** (red).  
- Long details **wrap** and remain inside the viewport.
- “**Copy fix**” button appears inline on non-Good rows.

### 7.1 Quick Scan
Light, fast checks: OS basics, high-value issues.

### 7.2 Security
Common web/PHP hardening checks:
- TLS certificate days to expiry
- `display_errors`, `expose_php`, cookie flags
- Available security updates
- (Additional checks depending on distro & tooling)

### 7.3 Filesystem
- Disk free %
- Inodes free %
- Mount flags or writable paths (depending on environment)

### 7.4 Performance
- Load average (1m)
- Process basics
- Memory snapshot (where available)

### 7.5 Services
- Probes each `host:port` with a TCP connect using your configured timeout.
- Chips report `connected in N ms` or `error in N ms`.
- Invalid lines get a Medium chip (`invalid target`) without breaking the run.

### 7.6 History View
- **Charts:** Overall score, Disk Free %, Security Updates, Load (1m).
- **Range selectors:** `7d`, `30d`, `90d`, `All`.
- **Export:** Download JSON or CSV of the plotted series.
- **Recent runs:** Last 10 entries listed with timestamp, score, and issue count.

---

## 8. Alerts

Alerts summarize non-Good findings and notify via email (and optional webhook).

- **From / Reply-To** are taken from **Security** page values (`data/security_config.json`).
- **Recipient** is **Config → Alerts → Email**.

### 8.1 Test Email Button
Located at the bottom of **Config → Alerts**:
- Sends a simple test using the configured recipient and Security sender fields.
- Requires admin + CSRF (it will show an error if you’re not authorized).

### 8.2 Scheduling Alerts

**Option A — HTTP (if you prefer):**
- Protect `api/alerts_run.php` behind Basic Auth/IP allowlist (or use a `cron_token` if your build supports it).
- Cron example (runs every hour):
  ```cron
  5 * * * * curl -fsS https://your-hostapi/alerts_run.php >/dev/null
  ```

**Option B — PHP CLI:**
- If your PHP CLI environment is compatible with your web runtime:
  ```cron
  5 * * * * php /var/www/your-siteapi/alerts_run.php >/dev/null 2>&1
  ```
  > If the script expects web context (e.g., headers), prefer HTTP with auth.

---

## 9. Backups & Upgrades

- **Backup before upgrades:**
  - `` (code)
  - `data/` (persistent config like `security_config.json`)
  - `state/` (history)
- **Upgrade:**
  - Replace changed files only (as you’ve been doing).
  - Keep permissions on `data/` and `state/`.
  - Hard refresh browser (cache-busting for JS/CSS).

---

## 10. Security Hardening

- Serve over **HTTPS**; set HSTS on your main site.
- Restrict access to ``:
  - Application auth, and/or
  - Web server Basic Auth / IP allowlist.
- Ensure `display_errors=Off` in production.
- Set restrictive `open_basedir` where possible.
- Apply security updates regularly (your Security tests will flag counts).
- For mail, prefer a proper SMTP relay with SPF/DKIM/DMARC for better deliverability.

---

## 11. Troubleshooting

**Blank page (UI)**  
- Open DevTools → Console. A JS error will blank the SPA; the first error line tells you the exact file/line.
- Hard refresh (Ctrl/Cmd+F5) to invalidate cached JS.
- If you edited `assets/js/...`, ensure braces are balanced and there are no stray characters.

**500 errors (PHP)**  
- Check your PHP error log. Typical issues:
  - Missing extension
  - File permission/ownership on `data/` or `state/`
  - `open_basedir` restrictions — ensure project paths are allowed

**Email not sending**  
- Verify **Security → From/Reply-To** and **Config → Alerts → Email**.
- Use **Send test email**; read the toast error for reason (e.g., mailer not configured).
- Check server MTA (postfix/exim) or SMTP relay credentials if you use one.

**Services tab empty / wrong**  
- Confirm **Config → Server Tests → Service targets** saved.
- Format: `host:port|label` (label optional).
- If left empty, first click will prompt and cache to browser local storage.

**History not recording**  
- Ensure **History → Enable** is on and `state/history` is writable.
- If your server clock is incorrect, charts may look odd; fix NTP.

---

## 12. FAQ

**Q: Where are test results stored?**  
A: In `state/history/YYYY-MM.jsonl`. Each line is a JSON object with time, action, score, and result items.

**Q: Can I export history?**  
A: Yes. In **History**, use **Export JSON** or **Export CSV** for the currently selected range.

**Q: Can I run tests via API?**  
A: Yes. `POST` to `api/server_tests.php` with JSON like `{"action":"security"}`. Auth applies. Actions: `quick`, `security`, `filesystem`, `performance`, `services`.

**Q: How are grades calculated?**  
A: Each row is weighted: Good≈1.0, Medium≈0.6, Bad≈0.2. The score is a clamped average across items.

---

## Appendix — Example Service Targets

```
127.0.0.1:80|Nginx
127.0.0.1:443|TLS
127.0.0.1:3306|MySQL
127.0.0.1:6379|Redis
127.0.0.1:5432|Postgres
```

**Timeout (ms):** 1000–5000 is a sane range. Increase if probing across slower networks.

---

*End of document.*