#!/bin/bash
#
# dovecot_expunge.sh
#   Daily Dovecot housekeeping:
#     - Expunge Spam older than 14 days (per user)
#     - Expunge Trash older than 30 days (per user)
#     - Expunge messages flagged \Deleted in key mailboxes (per user)
#     - Recalculate quotas (per user, if enabled)
#

set -euo pipefail

# --- config -------------------------------------------------------------
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

DOVEADM_BIN="/usr/bin/doveadm"

# Mailbox names as Dovecot sees them
INBOX_MAILBOX="INBOX"
ARCHIVE_MAILBOX="Archive"
TRASH_MAILBOX="Trash"
SPAM_MAILBOX="Spam"
SENT_MAILBOX="Sent"
DRAFTS_MAILBOX="Drafts"

SPAM_RETENTION_DAYS=14
TRASH_RETENTION_DAYS=30

# Hestia-style mail root: /home/<sysuser>/mail/<domain>/<user>/
MAIL_HOME_BASE="/home"

LOG_FILE="/var/log/dovecot-expunge.log"
HOSTNAME="$(hostname -f 2>/dev/null || hostname)"
NOW_HUMAN="$(date '+%Y-%m-%d %H:%M:%S')"

# --- helpers ------------------------------------------------------------
log() {
    echo "$NOW_HUMAN [$HOSTNAME] $*" >> "$LOG_FILE"
}

run_doveadm() {
    local desc="$1"; shift
    if ! "$@" >> "$LOG_FILE" 2>&1; then
        log "ERROR: $desc failed"
        return 1
    else
        log "OK:   $desc"
        return 0
    fi
}

discover_mail_users() {
    # Discover user@domain from /home/*/mail/<domain>/<user>/
    local dir
    declare -A seen
    local users=()

    shopt -s nullglob
    for dir in "$MAIL_HOME_BASE"/*/mail/*/*; do
        [ -d "$dir" ] || continue
        local user domain email
        user=$(basename "$dir")
        domain=$(basename "$(dirname "$dir")")
        email="${user}@${domain}"
        if [[ -z "${seen[$email]:-}" ]]; then
            seen[$email]=1
            users+=("$email")
        fi
    done
    shopt -u nullglob

    echo "${users[@]}"
}

expunge_older_than_user() {
    local user="$1"
    local mailbox="$2"
    local days="$3"
    local label="$4"

    local count
    count=$("$DOVEADM_BIN" search -u "$user" mailbox "$mailbox" savedbefore "${days}d" 2>/dev/null | wc -l || echo 0)
    log "$label ($user): found $count messages older than ${days}d to expunge"

    run_doveadm \
      "Expunge $label older than ${days}d for $user" \
      "$DOVEADM_BIN" expunge -u "$user" mailbox "$mailbox" savedbefore "${days}d"
}

expunge_deleted_flag_user() {
    local user="$1"
    local mailbox="$2"
    local label="$3"

    local count
    count=$("$DOVEADM_BIN" search -u "$user" mailbox "$mailbox" flag "\\Deleted" 2>/dev/null | wc -l || echo 0)
    log "$label ($user): found $count messages flagged \\Deleted to expunge"

    run_doveadm \
      "Expunge \\Deleted in $label for $user" \
      "$DOVEADM_BIN" expunge -u "$user" mailbox "$mailbox" flag "\\Deleted"
}

quota_recalc_user() {
    local user="$1"
    run_doveadm \
      "Quota recalc for $user" \
      "$DOVEADM_BIN" quota recalc -u "$user"
}

# --- sanity checks ------------------------------------------------------
mkdir -p "$(dirname "$LOG_FILE")"

if ! command -v "$DOVEADM_BIN" >/dev/null 2>&1; then
    log "ERROR: doveadm not found at $DOVEADM_BIN"
    exit 1
fi

log "Starting Dovecot expunge maintenance"

# Discover users
USER_LIST=($(discover_mail_users))

if [ "${#USER_LIST[@]}" -eq 0 ]; then
    log "WARN: No mail users discovered under $MAIL_HOME_BASE/*/mail/*/* – nothing to do."
    log "Finished Dovecot expunge maintenance"
    exit 0
fi

log "Discovered ${#USER_LIST[@]} mail users: ${USER_LIST[*]}"

# --- Age-based cleanups -------------------------------------------------
for user in "${USER_LIST[@]}"; do
    # Spam 14d
    expunge_older_than_user "$user" "$SPAM_MAILBOX"  "$SPAM_RETENTION_DAYS"  "Spam"
    # Trash 30d
    expunge_older_than_user "$user" "$TRASH_MAILBOX" "$TRASH_RETENTION_DAYS" "Trash"
done

# --- \Deleted across main mailboxes ------------------------------------
for user in "${USER_LIST[@]}"; do
    expunge_deleted_flag_user "$user" "$INBOX_MAILBOX"   "INBOX"
    expunge_deleted_flag_user "$user" "$ARCHIVE_MAILBOX" "Archive"
    expunge_deleted_flag_user "$user" "$SENT_MAILBOX"    "Sent"
    expunge_deleted_flag_user "$user" "$DRAFTS_MAILBOX"  "Drafts"
    expunge_deleted_flag_user "$user" "$TRASH_MAILBOX"   "Trash"
    expunge_deleted_flag_user "$user" "$SPAM_MAILBOX"    "Spam"
done

# --- Quota recalc per user (safe even if quotas aren't enabled) --------
for user in "${USER_LIST[@]}"; do
    quota_recalc_user "$user" || true
done

log "Finished Dovecot expunge maintenance"
exit 0
