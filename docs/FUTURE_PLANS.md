# Future Plans (Roadmap)

This list is prioritized, bite‑sized, and designed as **drop‑ins** on top of the baseline. Each item will ship as a small, surgical update (1–3 files), so we avoid regressions.

## Tier 1 — Quality of Life
1. **Reconnect Google (OAuth) button** on Email tab, per account.
2. **Token checker** (green/red dot + last modified) with `api/email_token_info.php` (secrets redacted).
3. **Verbose SMTP toasts** in Send Test panel — surface transport + exact server error text.
4. **Backup retention + prune**: keep N latest config backups (`data/backups/`), add “Prune” and “Download latest” buttons.
5. **Rate‑limit cron refresh** buttons (already mild; add exponential backoff on repeated clicks).

## Tier 2 — Observability
6. **History mini‑graphs**: 15‑minute sparkline for CPU/RAM/load using existing history endpoint; no heavy charting deps.
7. **Verbose mode** for `email_status.php?verbose=1` to optionally include a short, redacted reason for failures.

## Tier 3 — Hardening
8. **CSP + SRI** for admin layout: lock down `script-src` and fingerprint static assets.
9. **Schema validation** on save: server validates types against `schema.php`, returns a diff preview before commit.

## Tier 4 — Nice to Have
10. **One‑click backup/restore** for `local.json` with confirm dialogs.
11. **Theme toggle** persisted to `site.theme` (`data-theme` attribute).

---

### Shipping plan

- Each feature lands as a **drop‑in zip** with a tiny changelog at the top of the file(s).
- Nothing invasive; no framework changes; no DB migrations.
- If a drop‑in misbehaves, revert by restoring the previous file(s) only.
