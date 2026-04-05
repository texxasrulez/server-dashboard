# Speedtest history

Optional CLI dependencies:

- `speedtest` (Ookla CLI)
- `librespeed-cli`

The collector script is `scripts/speedtest_collect.php`. Run it frequently from cron or a timer. The script checks the configured interval itself, so the scheduler can be simple.

Cron example:

```cron
*/5 * * * * /usr/bin/php /path/to/server-dashboard/scripts/speedtest_collect.php >> /var/log/server-dashboard-speedtest.log 2>&1
```

Notes:

- Tests run on the dashboard host, not in the browser.
- If both CLIs are missing, the dashboard shows the missing backend status and scheduled runs fail cleanly.
- The history store is `speedtest_history.ndjson` under the writable dashboard state directory.
