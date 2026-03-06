#!/usr/bin/env bash
# Shared environment/bootstrap helpers for scripts in ./scripts

set +u

_dashboard_trim() {
  local s="${1:-}"
  s="${s#${s%%[![:space:]]*}}"
  s="${s%${s##*[![:space:]]}}"
  printf '%s' "$s"
}

_dashboard_first_existing() {
  local p
  for p in "$@"; do
    [ -n "$p" ] || continue
    if [ -f "$p" ]; then
      printf '%s' "$p"
      return 0
    fi
  done
  return 1
}

_dashboard_guess_root_from_script_dir() {
  local script_dir="${1:-}"
  local guess=""

  if [ -n "${WEB_ADMIN_ROOT:-}" ] && [ -d "${WEB_ADMIN_ROOT}" ]; then
    guess="${WEB_ADMIN_ROOT}"
  elif [ -n "${DASH_WEB_ADMIN_ROOT:-}" ] && [ -d "${DASH_WEB_ADMIN_ROOT}" ]; then
    guess="${DASH_WEB_ADMIN_ROOT}"
  elif [ -n "$script_dir" ] && [ -f "$script_dir/../includes/init.php" ]; then
    guess="$(cd "$script_dir/.." && pwd)"
  elif [ -f "./includes/init.php" ]; then
    guess="$(pwd)"
  fi

  printf '%s' "$guess"
}

_dashboard_load_env_file() {
  local script_dir="${1:-}"
  local root="${2:-}"
  local hinted="${DASHBOARD_SCRIPTS_ENV:-}"
  local local_generated=""
  local etc_env="/etc/server-dashboard/scripts.env"

  if [ -n "$root" ]; then
    local_generated="$root/state/generated/dashboard-scripts.env"
  elif [ -n "$script_dir" ]; then
    local_generated="$script_dir/../state/generated/dashboard-scripts.env"
  fi

  local env_file
  env_file="$(_dashboard_first_existing "$hinted" "$local_generated" "$etc_env")" || return 0

  # shellcheck disable=SC1090
  source "$env_file"
}

_dashboard_cfg_get() {
  local cfg_file="${1:-}"
  local jq_path="${2:-}"
  [ -n "$cfg_file" ] || return 0
  [ -f "$cfg_file" ] || return 0
  command -v jq >/dev/null 2>&1 || return 0
  jq -r "$jq_path // empty" "$cfg_file" 2>/dev/null || true
}

dashboard_env_bootstrap() {
  local script_dir="${1:-}"

  local root
  root="$(_dashboard_guess_root_from_script_dir "$script_dir")"
  _dashboard_load_env_file "$script_dir" "$root"

  root="$(_dashboard_guess_root_from_script_dir "$script_dir")"
  if [ -n "$root" ]; then
    WEB_ADMIN_ROOT="$root"
  fi
  export WEB_ADMIN_ROOT

  if [ -z "${STATE_DIR:-}" ] && [ -n "${WEB_ADMIN_ROOT:-}" ]; then
    STATE_DIR="$WEB_ADMIN_ROOT/state"
  fi
  export STATE_DIR

  local cfg_file=""
  if [ -n "${WEB_ADMIN_ROOT:-}" ]; then
    cfg_file="$WEB_ADMIN_ROOT/config/local.json"
  fi

  if [ -z "${BACKUP_ROOT:-}" ] && [ -n "$cfg_file" ]; then
    local cfg_root
    cfg_root="$(_dashboard_cfg_get "$cfg_file" '.backups.fs_root')"
    if [ -n "$cfg_root" ]; then
      BACKUP_ROOT="$cfg_root"
    fi
  fi
  export BACKUP_ROOT

  if [ -z "${BACKUP_EXCLUDES:-}" ] && [ -n "$cfg_file" ]; then
    local cfg_excludes
    cfg_excludes="$(_dashboard_cfg_get "$cfg_file" '.backups.exclude_dirs')"
    if [ -n "$cfg_excludes" ]; then
      BACKUP_EXCLUDES="$cfg_excludes"
    fi
  fi
  export BACKUP_EXCLUDES

  if [ -z "${HESTIA_DIR:-}" ] && [ -n "$cfg_file" ]; then
    local cfg_hestia_dir
    cfg_hestia_dir="$(_dashboard_cfg_get "$cfg_file" '.backups.hestia_source_dir')"
    if [ -n "$cfg_hestia_dir" ]; then
      HESTIA_DIR="$cfg_hestia_dir"
    fi
  fi
  if [ -z "${HESTIA_DIR:-}" ]; then
    HESTIA_DIR="/backup"
  fi
  export HESTIA_DIR

  if [ -z "${STATE_OWNER:-}" ] && [ -n "${STATE_DIR:-}" ] && [ -d "$STATE_DIR" ]; then
    local owner
    owner="$(stat -c '%U:%G' "$STATE_DIR" 2>/dev/null || true)"
    owner="$(_dashboard_trim "$owner")"
    if [ -n "$owner" ]; then
      STATE_OWNER="$owner"
    fi
  fi

  if [ -z "${STATE_OWNER:-}" ] && [ -n "${BACKUP_CHOWN:-}" ]; then
    STATE_OWNER="$BACKUP_CHOWN"
  fi

  if [ -z "${REPORT_EMAIL_TO:-}" ] && [ -n "$cfg_file" ]; then
    local mail_to
    mail_to="$(_dashboard_cfg_get "$cfg_file" '.mail.sec_email[0]')"
    if [ -z "$mail_to" ]; then
      mail_to="$(_dashboard_cfg_get "$cfg_file" '.security.admin_emails[0]')"
    fi
    if [ -n "$mail_to" ]; then
      REPORT_EMAIL_TO="$mail_to"
      AUDIT_EMAIL="$mail_to"
      BACKUP_ALERT_EMAIL="$mail_to"
    fi
  fi

  export STATE_OWNER REPORT_EMAIL_TO AUDIT_EMAIL BACKUP_ALERT_EMAIL
}
