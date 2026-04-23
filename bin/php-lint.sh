#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

STATUS=0
while IFS= read -r file; do
  if ! php -l "$file" >/dev/null; then
    STATUS=1
  fi
done < <(find . \
  -path './vendor' -prune -o \
  -path './node_modules' -prune -o \
  -name '*.php' -print | sort)

exit "$STATUS"
