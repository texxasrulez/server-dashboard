#!/usr/bin/env bash
set -euo pipefail

# Reports largest files across runtime/backups directories.
# Usage:
#   bash bin/report-largest-files.sh
#   bash bin/report-largest-files.sh --top 25

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TOP=10
OUT_TXT="$ROOT/state/largest_files_report.txt"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --top)
      TOP="${2:-10}"
      shift 2
      ;;
    --out)
      OUT_TXT="${2:-$OUT_TXT}"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 2
      ;;
  esac
done

if ! [[ "$TOP" =~ ^[0-9]+$ ]] || [ "$TOP" -le 0 ]; then
  echo "--top must be a positive integer" >&2
  exit 2
fi

mkdir -p "$(dirname "$OUT_TXT")"

declare -a roots=()
for d in data state config/backups; do
  [ -d "$d" ] && roots+=("$d")
done

if [ "${#roots[@]}" -eq 0 ]; then
  echo "No target directories found." | tee "$OUT_TXT"
  exit 0
fi

tmp="$(mktemp)"
find "${roots[@]}" -type f -printf '%s\t%p\n' 2>/dev/null | sort -nr > "$tmp"

{
  echo "Largest files report"
  echo "Generated: $(date -Iseconds)"
  echo "Scope: ${roots[*]}"
  echo "Top: $TOP"
  echo
  printf '%-12s  %s\n' "SIZE" "PATH"
  echo "------------  ----------------------------------------"
  head -n "$TOP" "$tmp" | while IFS=$'\t' read -r bytes path; do
    [ -z "${bytes:-}" ] && continue
    human="$(numfmt --to=iec --suffix=B "$bytes" 2>/dev/null || echo "${bytes}B")"
    printf '%-12s  %s\n' "$human" "$path"
  done
} | tee "$OUT_TXT"

rm -f "$tmp"
