#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)"
CONFIG_FILE="${REPO_ROOT}/config/privileged_logs.json"
PHP_BIN="${PHP_BIN:-php}"
GLOBAL_MAX_LINES=500

fail() {
  printf '%s\n' "$1" >&2
  exit "${2:-1}"
}

usage() {
  fail "Usage: log_bridge.sh --key <allowlisted_key> --mode <tail|head> --lines <count> [--search <literal>]" 64
}

KEY=""
MODE="tail"
LINES=""
SEARCH=""

while (($# > 0)); do
  case "$1" in
    --key)
      shift
      KEY="${1-}"
      ;;
    --mode)
      shift
      MODE="${1-}"
      ;;
    --lines)
      shift
      LINES="${1-}"
      ;;
    --search)
      shift
      SEARCH="${1-}"
      ;;
    --help|-h)
      usage
      ;;
    *)
      fail "Denied: unsupported argument." 64
      ;;
  esac
  shift || true
done

[[ -n "${KEY}" ]] || usage
[[ "${KEY}" =~ ^[a-z0-9_]+$ ]] || fail "Denied: invalid log key." 64
[[ "${MODE}" == "tail" || "${MODE}" == "head" ]] || fail "Denied: unsupported action." 64
[[ "${LINES}" =~ ^[0-9]+$ ]] || fail "Denied: invalid line count." 64

if (( LINES < 1 )); then
  LINES=1
fi
if (( LINES > GLOBAL_MAX_LINES )); then
  LINES="${GLOBAL_MAX_LINES}"
fi

if [[ "${SEARCH}" == *$'\n'* || "${SEARCH}" == *$'\r'* ]]; then
  fail "Denied: invalid search literal." 64
fi
if ((${#SEARCH} > 160)); then
  SEARCH="${SEARCH:0:160}"
fi

lookup="$("${PHP_BIN}" -r '
$cfg = json_decode((string) @file_get_contents($argv[1]), true);
if (!is_array($cfg) || !isset($cfg["logs"]) || !is_array($cfg["logs"])) {
    fwrite(STDERR, "Invalid privileged log catalog.\n");
    exit(2);
}
$key = (string) $argv[2];
foreach ($cfg["logs"] as $item) {
    if (!is_array($item) || (string) ($item["key"] ?? "") !== $key) {
        continue;
    }
    $source = isset($item["source"]) && is_array($item["source"]) ? $item["source"] : [];
    $type = strtolower(trim((string) ($source["type"] ?? "file")));
    $path = trim((string) ($source["path"] ?? ""));
    $unit = trim((string) ($source["unit"] ?? ""));
    $allowSearch = !array_key_exists("allow_search", $item) || !empty($item["allow_search"]) ? "1" : "0";
    $defaultLines = max(25, (int) ($item["default_lines"] ?? 120));
    $maxLines = max($defaultLines, (int) ($item["max_lines"] ?? 300));
    $sourceValue = $type === "journal" ? $unit : $path;
    echo $key, "\t", $type, "\t", $sourceValue, "\t", $allowSearch, "\t", $defaultLines, "\t", $maxLines;
    exit(0);
}
fwrite(STDERR, "Denied: unknown log key.\n");
exit(64);
' "${CONFIG_FILE}" "${KEY}")"

IFS=$'\t' read -r CFG_KEY CFG_TYPE CFG_SOURCE CFG_ALLOW_SEARCH CFG_DEFAULT_LINES CFG_MAX_LINES <<< "${lookup}"

[[ "${CFG_KEY}" == "${KEY}" ]] || fail "Denied: unknown log key." 64
[[ "${CFG_MAX_LINES}" =~ ^[0-9]+$ ]] || fail "Denied: invalid log catalog." 2
if (( LINES > CFG_MAX_LINES )); then
  LINES="${CFG_MAX_LINES}"
fi

run_reader() {
  case "${CFG_TYPE}" in
    file)
      [[ -n "${CFG_SOURCE}" && "${CFG_SOURCE}" == /* ]] || fail "Denied: invalid file source." 2
      if [[ ! -f "${CFG_SOURCE}" ]]; then
        fail "Requested log source is not present on this host." 1
      fi
      if [[ "${MODE}" == "head" ]]; then
        head -n "${LINES}" -- "${CFG_SOURCE}"
      else
        tail -n "${LINES}" -- "${CFG_SOURCE}"
      fi
      ;;
    journal)
      [[ "${CFG_SOURCE}" =~ ^[A-Za-z0-9_.@:-]+$ ]] || fail "Denied: invalid journal source." 2
      [[ "${MODE}" == "tail" ]] || fail "Denied: head is not supported for journal sources." 64
      journalctl --no-pager --output=short-iso --lines "${LINES}" --unit "${CFG_SOURCE}"
      ;;
    *)
      fail "Denied: unsupported source type." 2
      ;;
  esac
}

if [[ -n "${SEARCH}" ]]; then
  [[ "${CFG_ALLOW_SEARCH}" == "1" ]] || fail "Denied: search is not allowed for this log." 64
  if ! run_reader | LC_ALL=C grep -F -- "${SEARCH}"; then
    exit 0
  fi
else
  run_reader
fi
