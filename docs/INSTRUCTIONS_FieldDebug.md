# Field Debug Helpers

## URL flags
- `?showapi=1` — adds a small “View JSON” link above the index page sections (HDD cards, Process gauges). Click **hide** to remove them. You can also call `DashboardDebug.hideApiLinks()` from the console.

## Debug panel (Shift+D, admin only)
- **Latency Ping** — measures client RTT and shows server processing time.
- **Server Time Skew** — computes client vs server clock offset using the ping’s ISO timestamp (`api/debug_ping.php`).
- **Recent API calls** — live list with status + durations. Turn on **Trace APIs** to add `?trace=1` which adds response timing (`trace.elapsed_ms`) to supported APIs.
- **API rate (per min)** — rolling count of calls in the past 60s for each API path. Useful to detect runaway polling.

## Error surfaces on cards
- The HDD and Processes modules surface inline messages on HTTP errors or JSON parse failures (e.g., `Error: HTTP 404`, `Error: json`). Open Console for the matching stack line (`HDD …` or `PROC …`) for details.
