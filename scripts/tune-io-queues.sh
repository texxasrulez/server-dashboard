#!/usr/bin/env bash
set -e

for dev in /sys/block/*; do
  name=$(basename "$dev")

  # Skip loopback, RAM, and CD/DVD
  case "$name" in
    loop*|ram*|sr*) continue ;;
  esac

  q="$dev/queue"

  # If rotational=0, assume SSD/NVMe
  if [ -f "$q/rotational" ] && [ "$(cat "$q/rotational")" -eq 0 ]; then
    echo 512  > "$q/nr_requests"      2>/dev/null || true
    echo 128  > "$q/read_ahead_kb"    2>/dev/null || true
  else
    # Spinners: slightly higher queue, slightly higher readahead
    echo 1024 > "$q/nr_requests"      2>/dev/null || true
    echo 256  > "$q/read_ahead_kb"    2>/dev/null || true
  fi
done
