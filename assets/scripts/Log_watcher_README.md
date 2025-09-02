# Log Watcher: Copy only `.log` files from `/var/log` (recursively)

This package installs a **watcher** that continuously copies **only** files with the exact suffix `.log` (no `.gz`, no rotations like `.log.1`) from `/var/log/**` into a single destination directory. The copied files are renamed to **`<parentdir>_<filename>`** to avoid collisions and to make their origin obvious (e.g., `/var/log/mysql/error.log` → `mysql_error.log`).

Empty files are **skipped**. Existing copies are only overwritten when the source is newer.

It provides:
- `install.sh` — installs binaries, environment, and a systemd service
- `uninstall.sh` — cleanly removes everything
- `/usr/local/bin/copy_log_once.sh` — one-shot copier
- `/usr/local/bin/log_watcher.sh` — long-running watcher using `inotifywait`
- `log-watcher.service` — systemd service
- `log-watcher.env` — environment configuration

## Requirements

- Linux with systemd
- Root privileges for install (it will refuse otherwise)
- Make executable `chmod +x filename.sh`
- `inotify-tools` package (installer will attempt to install via `apt`, `dnf`, `yum`, `zypper`, or `apk` if present)

## Installation (quick start)

```bash
sudo ./install.sh --dest /var/log-export --owner htmluser:htmlgroup --mode 0640
```

If `--dest` is omitted, the default is `/var/log-export`. If `--owner` is omitted, default is `root:root`. If `--mode` is omitted, default is `0640`.

The installer will:
1. Create the destination directory (if needed).
2. Write `/etc/log-watcher/log-watcher.env` with your settings.
3. Install the scripts to `/usr/local/bin/`.
4. Install and enable the `log-watcher.service` (systemd).
5. Run an initial one-shot copy of all current `.log` files.

## Uninstall

```bash
sudo ./uninstall.sh
```

By default this **does not** remove the destination directory or copied logs. Remove them manually if you want.

## Switches (install.sh)

- `--dest <DIR>`: Destination directory for copied logs. Default: `/var/log-export`.
- `--owner <USER:GROUP>`: Ownership for copied files. Default: `root:root`.
- `--mode <MODE>`: Octal permission mode for copied files (e.g., `0640`). Default: `0640`.
- `--no-initial-copy`: Install and start watcher but skip the first one-shot copy.
- `--enable-now/--disable-now`: Enable (default) or leave the service installed but disabled.
- `--service-name <NAME>`: Override systemd unit name (default: `log-watcher`).

## Behavior details

- **Exact `.log` only**: We only copy files whose basename ends with `.log` — using a strict match. `*.log.1`, `*.log.gz`, etc. are ignored.
- **Skip empty**: A file of size 0 at copy time is ignored.
- **Parent prefix**: We take the immediate parent directory name of the source file and prepend it to the filename: `/a/b/c/foo.log` → `c_foo.log`.
- **Overwrite only if newer**: We overwrite an existing destination file only when the source has a newer mtime.
- **Atomic writes**: We copy to a temporary file and then move it into place to avoid partial writes.
- **Watcher events**: We react to `close_write`, `moved_to`, and `create` within `/var/log` to catch new or rotated `.log` files. We re-check for `.log` and non-empty at event time.
- **Safety**: The watcher ignores files that do not end in `.log`. It also ignores hidden directories under `/var/log` by default (systemd service sets `--exclude` for inotify if desired? We handle pattern in the script).

## Service management

```bash
sudo systemctl status log-watcher.service
sudo systemctl restart log-watcher.service
sudo systemctl stop log-watcher.service
sudo journalctl -u log-watcher.service -f
```

If you passed `--service-name NAME`, replace `log-watcher` with your chosen name.

## Manual run (advanced)

One-shot copy using current env:
```bash
sudo /usr/local/bin/copy_log_once.sh
```

Run watcher in foreground (debug):
```bash
sudo /usr/local/bin/log_watcher.sh
```

## Troubleshooting

- If `inotifywait` is not found, re-run `install.sh`. It tries to install `inotify-tools`. On minimal distros you may need to add the repository containing `inotify-tools`.
- If no files appear: verify there **are** `.log` files (not just rotated ones), and that your service is active. Check `journalctl -u log-watcher.service`.
- SELinux/AppArmor could block writes to your `--dest`. Use a directory that is writable and has the right context, or adjust policies accordingly.
