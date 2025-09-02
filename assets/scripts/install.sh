#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="log-watcher"
DEST_DIR=""
OWNER="$(id -un):$(id -gn)"
MODE="0640"
DO_INITIAL_COPY=1
ENABLE_NOW=1

# Resolve script and repo paths early (used regardless of --dest)
_src="${BASH_SOURCE[0]:-$0}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${_src}")" >/dev/null 2>&1 && pwd -P)"
# Try two-levels-up repo root; if that fails, fall back to SCRIPT_DIR
if REPO_ROOT_TMP="$(cd -- "${SCRIPT_DIR}/../.." >/dev/null 2>&1 && pwd -P)"; then
  REPO_ROOT="${REPO_ROOT_TMP}"
else
  REPO_ROOT="${SCRIPT_DIR}"
fi


# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dest)         DEST_DIR="${2:-}"; shift 2 ;;
    --dest=*)       DEST_DIR="${1#*=}"; shift 1 ;;
    --owner)        OWNER="${2:-}"; shift 2 ;;
    --owner=*)      OWNER="${1#*=}"; shift 1 ;;
    --mode)         MODE="${2:-}"; shift 2 ;;
    --mode=*)       MODE="${1#*=}"; shift 1 ;;
    --no-initial-copy) DO_INITIAL_COPY=0; shift 1 ;;
    --enable-now)      ENABLE_NOW=1;  shift 1 ;;
    --disable-now)     ENABLE_NOW=0;  shift 1 ;;
    --) shift; break ;;
    -*)
      echo "Unknown option: $1" >&2
      exit 2
      ;;
    *) break ;;
  esac
done

# Normalize OWNER to user:group; support --owner=user (fill primary group)
if [[ "${OWNER}" != *:* ]]; then
  _owner_user="${OWNER}"
  _owner_group="$(id -gn "${_owner_user}" 2>/dev/null || true)"
  if [[ -z "${_owner_group}" ]]; then
    echo "ERROR: could not resolve primary group for user '${_owner_user}' (from --owner '${OWNER}')." >&2
    exit 2
  fi
  OWNER="${_owner_user}:${_owner_group}"
fi


if [[ $EUID -ne 0 ]]; then
  echo "This installer must run as root." >&2
  exit 1
fi

# Compute defaults if not provided
if [[ -z "${DEST_DIR}" ]]; then
  DEST_DIR="${REPO_ROOT}/state/logs_mirror"
fi
# Validate OWNER and apply to destination dir
USER_NAME="${OWNER%%:*}"
GROUP_NAME="${OWNER##*:}"
if ! id -u "${USER_NAME}" >/dev/null 2>&1; then
  echo "ERROR: user '${USER_NAME}' not found (from --owner '${OWNER}')" >&2
  exit 2
fi
if ! getent group "${GROUP_NAME}" >/dev/null 2>&1; then
  echo "ERROR: group '${GROUP_NAME}' not found (from --owner '${OWNER}')" >&2
  exit 2
fi

echo "[*] Creating destination directory: ${DEST_DIR}"
mkdir -p "${DEST_DIR}"
# try to set ownership of the destination; non-fatal if it fails
if ! chown "${USER_NAME}:${GROUP_NAME}" "${DEST_DIR}" 2>/dev/null; then
  echo "WARN: could not chown ${DEST_DIR} to ${USER_NAME}:${GROUP_NAME} (will still chown files individually)" >&2
fi


echo "[*] Ensuring dependencies..."
if ! command -v inotifywait >/dev/null 2>&1; then
  # Try to install inotify-tools across common distros
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -y && apt-get install -y inotify-tools
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y inotify-tools
  elif command -v yum >/dev/null 2>&1; then
    yum install -y inotify-tools
  elif command -v zypper >/dev/null 2>&1; then
    zypper --non-interactive install inotify-tools
  elif command -v apk >/dev/null 2>&1; then
    apk add --no-cache inotify-tools
  else
    echo "Could not install inotify-tools automatically. Please install it manually and re-run." >&2
    exit 1
  fi
fi

echo "[*] Creating destination directory: ${DEST_DIR}"
mkdir -p "${DEST_DIR}"

# Split owner
USER_NAME="${OWNER%%:*}"
GROUP_NAME="${OWNER##*:}"

# Create etc dir
ETC_DIR="/etc/log-watcher"
mkdir -p "${ETC_DIR}"

ENV_FILE="${ETC_DIR}/${SERVICE_NAME}.env"
BIN_COPY="/usr/local/bin/copy_log_once.sh"
BIN_WATCH="/usr/local/bin/log_watcher.sh"
UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

cd -- "${SCRIPT_DIR}"
echo "[*] Installing scripts to /usr/local/bin"
install -m 0755 scripts/copy_log_once.sh "${BIN_COPY}"
install -m 0755 scripts/log_watcher.sh "${BIN_WATCH}"

echo "[*] Writing environment file: ${ENV_FILE}"
cat > "${ENV_FILE}" <<EOF
DEST_DIR="${DEST_DIR}"
OWNER="${OWNER}"
MODE="${MODE}"
EOF
chmod 0644 "${ENV_FILE}"
if ! grep -qF 'OWNER="'"${OWNER}"'"' "${ENV_FILE}"; then
  echo "ERROR: OWNER not persisted correctly to ${ENV_FILE} (expected ${OWNER})." >&2
  exit 2
fi

echo "[*] Writing systemd unit: ${UNIT_FILE}"
cat > "${UNIT_FILE}" <<EOF
[Unit]
Description=Log watcher to copy only .log files from /var/log into a flat destination
After=network.target
Wants=network.target

[Service]
User=${USER_NAME}
Group=${GROUP_NAME}
SupplementaryGroups=adm
Type=simple
EnvironmentFile=-/etc/log-watcher/%n.env
# Compatibility: if %n.env not found (older naming), also try default name
EnvironmentFile=-/etc/log-watcher/log-watcher.env
ExecStart=/usr/local/bin/log_watcher.sh
Restart=always
RestartSec=3
Nice=5
IOSchedulingClass=best-effort
IOSchedulingPriority=6
# Limit memory/CPU if desired (commented by default)
# MemoryMax=512M
# CPUQuota=50%

[Install]
WantedBy=multi-user.target
EOF

echo "[*] Reloading systemd daemon"
systemctl daemon-reload

if [[ "${DO_INITIAL_COPY}" -eq 1 ]]; then
  echo "[*] Running initial one-shot copy"
  DEST_DIR="${DEST_DIR}" OWNER="${OWNER}" MODE="${MODE}" "${BIN_COPY}"
fi

if [[ "${ENABLE_NOW}" -eq 1 ]]; then
  echo "[*] Enabling and starting ${SERVICE_NAME}.service"
  systemctl enable --now "${SERVICE_NAME}.service"
else
  echo "[*] Installed but not enabling the service (per --disable-now)."
fi

echo "[*] Done. Settings:"
echo "  dest:  ${DEST_DIR}"
echo "  owner: ${OWNER}"
echo "  mode:  ${MODE}"
