#!/usr/bin/env bash
#
# server-audit.sh - Monthly-ish security / sanity audit
# Run as root (cron or manual). Sends a report card + raw log via email.
#

#####################################
# CONFIG
#####################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "/etc/server-dashboard/dashboard_env.sh" ]; then
    # shellcheck disable=SC1091
    source "/etc/server-dashboard/dashboard_env.sh"
elif [ -f "$SCRIPT_DIR/lib/dashboard_env.sh" ]; then
    # shellcheck disable=SC1091
    source "$SCRIPT_DIR/lib/dashboard_env.sh"
fi
if declare -F dashboard_env_bootstrap >/dev/null 2>&1; then
    dashboard_env_bootstrap "$SCRIPT_DIR"
fi

AUDIT_EMAIL="${AUDIT_EMAIL:-}"
SENDMAIL="${SENDMAIL:-/usr/sbin/sendmail}"
AUDIT_EMAIL_FROM="${AUDIT_EMAIL_FROM:-${MAIL_FROM:-server-dashboard@localhost.invalid}}"
AUDIT_EMAIL_ENVELOPE_FROM="${AUDIT_EMAIL_ENVELOPE_FROM:-${MAIL_ENVELOPE_FROM:-$AUDIT_EMAIL_FROM}}"
LOG_FILE="/var/log/server-audit.log"

# If you want to skip heavy tools, flip these:
RUN_NMAP_LOCAL=1
RUN_NMAP_LAN=0     # 0 = off by default (LAN scan can be noisy/slow)
RUN_RKHUNTER=1
RUN_AIDE=0         # Default OFF: enable only if you've configured+initialized AIDE

#####################################
# INTERNAL SETUP
#####################################

# Intentionally NOT using `set -e` (audit should keep going and report partial results)
set -uo pipefail

# Conservative, explicit PATH for cron/systemd
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

TMP_REPORT="$(mktemp /tmp/server-audit.XXXXXX)"
FINAL_REPORT="$(mktemp /tmp/server-audit-final.XXXXXX)"

cleanup() {
    rm -f "$TMP_REPORT" "$FINAL_REPORT" /tmp/rkhunter-audit.txt /tmp/aide-audit.txt 2>/dev/null || true
}
trap cleanup EXIT

# Prevent overlapping runs (cron + manual, or slow IO days)
if command -v flock >/dev/null 2>&1; then
    LOCK_DIR="/run/lock/backup-suite"
    mkdir -p "$LOCK_DIR"
    chmod 1777 "$LOCK_DIR"
    LOCK_FILE="$LOCK_DIR/$(basename "$0").${UID}.lock"
    exec 9>"$LOCK_FILE"
    flock -n 9 || exit 0
fi

log() {
    local level="$1"; shift
    local msg="$*"
    local ts icon

    case "$level" in
        INFO)  icon="ℹ️ " ;;
        OK)    icon="✅ " ;;
        WARN)  icon="⚠️ " ;;
        ERROR) icon="⛔ " ;;
        *)     icon=""   ;;
    esac

    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    printf '[%s] [%s] %s%s\n' "$ts" "$level" "$icon" "$msg" | tee -a "$TMP_REPORT" >/dev/null
}

section() {
    local title="$1"
    echo "" | tee -a "$TMP_REPORT" >/dev/null
    printf '===== %s =====\n' "$title" | tee -a "$TMP_REPORT" >/dev/null
}

# Normalize to a systemd unit name (add ".service" when absent)
unit_name() {
    local u="$1"
    if [[ "$u" == *.* ]]; then
        printf '%s' "$u"
    else
        printf '%s.service' "$u"
    fi
}

# Reliable service existence + state check on Debian/systemd
check_service() {
    local unit_in="$1"
    local desc="$2"
    local unit
    unit="$(unit_name "$unit_in")"

    # Does systemd know about it?
    if systemctl list-unit-files --type=service --no-legend "$unit" 2>/dev/null | awk '{print $1}' | grep -qx "$unit"; then
        if systemctl is-active "$unit" >/dev/null 2>&1; then
            log "OK" "Service $unit_in ($desc) is active."
        else
            state="$(systemctl is-active "$unit" 2>/dev/null || true)"
            log "ERROR" "Service $unit_in ($desc) is NOT active (state: ${state:-unknown})."
            {
                echo "# systemctl --no-pager -l status $unit"
                systemctl --no-pager -l status "$unit" 2>&1 | sed -n '1,20p' || true
            } >> "$TMP_REPORT" 2>&1
        fi
    else
        # Fallback: transient units can exist without a unit-file
        if systemctl is-active "$unit" >/dev/null 2>&1 || systemctl is-enabled "$unit" >/dev/null 2>&1; then
            if systemctl is-active "$unit" >/dev/null 2>&1; then
                log "OK" "Service $unit_in ($desc) is active."
            else
                log "WARN" "Service $unit_in ($desc) exists but is not active."
            fi
        else
            log "INFO" "Service $unit_in ($desc) not found; skipping."
        fi
    fi
}

#####################################
# 0. HEADER
#####################################

echo "===== SERVER AUDIT START =====" > "$TMP_REPORT"
log "INFO" "Starting server audit at $(date '+%Y-%m-%d %H:%M:%S')"
log "INFO" "Writing report to $LOG_FILE"

HOSTNAME="$(hostname)"
PRIMARY_IP="$(ip -4 addr show scope global 2>/dev/null | awk '/inet / {print $2}' | head -n1 || echo 'unknown')"

log "INFO" "Hostname: $HOSTNAME"
log "INFO" "Primary IPv4: $PRIMARY_IP"

# Extra candy (cheap signal)
KERNEL="$(uname -r 2>/dev/null || echo unknown)"
UPTIME="$(uptime -p 2>/dev/null || echo unknown)"
log "INFO" "Kernel: $KERNEL"
log "INFO" "Uptime: $UPTIME"

#####################################
# 1. PORT SCAN (LOCAL VIEW)
#####################################

section "1. PORT SCAN (LOCAL VIEW)"

if [[ "$RUN_NMAP_LOCAL" -eq 1 ]]; then
    if command -v nmap >/dev/null 2>&1; then
        log "INFO" "Running nmap -Pn -sV --open against localhost."
        {
            echo "# nmap localhost"
            nmap -Pn -sV --open localhost || echo "nmap localhost failed (non-zero exit code)."
        } >> "$TMP_REPORT" 2>&1
    else
        log "WARN" "nmap not installed; skipping local port scan."
    fi
else
    log "INFO" "Local nmap scan disabled by config."
fi

if [[ "$RUN_NMAP_LAN" -eq 1 ]]; then
    if command -v nmap >/dev/null 2>&1 && [[ -n "${PRIMARY_IP:-}" && "${PRIMARY_IP:-}" != "unknown" ]]; then
        NET_PREFIX="${PRIMARY_IP%.*}"
        log "INFO" "Running nmap scan against LAN: ${NET_PREFIX}.0/24 (may take a while)."
        {
            echo "# nmap ${NET_PREFIX}.0/24"
            nmap -Pn -sV --open "${NET_PREFIX}.0/24" || echo "nmap LAN scan failed (non-zero exit code)."
        } >> "$TMP_REPORT" 2>&1
    else
        log "WARN" "LAN nmap requested but PRIMARY_IP is unknown; skipping."
    fi
fi

#####################################
# 2. SSH / AUTH HARDENING
#####################################

section "2. SSH / AUTH HARDENING"

if command -v sshd >/dev/null 2>&1 || [[ -x /usr/sbin/sshd ]]; then
    log "INFO" "Checking sshd runtime configuration (sshd -T)."

    PERMIT_ROOT="$(sshd -T 2>/dev/null | awk '/^permitrootlogin / {print $2}' || echo 'unknown')"
    PASS_AUTH="$(sshd -T 2>/dev/null | awk '/^passwordauthentication / {print $2}' || echo 'unknown')"

    if [[ "$PERMIT_ROOT" == "no" || "$PERMIT_ROOT" == "prohibit-password" || "$PERMIT_ROOT" == "without-password" || "$PERMIT_ROOT" == "forced-commands-only" ]]; then
        log "OK" "SSH root login is effectively disabled (PermitRootLogin $PERMIT_ROOT)."
    else
        log "WARN" "SSH root login is NOT fully disabled (PermitRootLogin $PERMIT_ROOT)."
    fi

    if [[ "$PASS_AUTH" == "no" ]]; then
        log "OK" "SSH PasswordAuthentication is disabled."
    else
        log "WARN" "SSH PasswordAuthentication is enabled (PasswordAuthentication $PASS_AUTH)."
    fi

    if [[ -f /var/log/auth.log ]]; then
        today="$(date '+%b %e')"
        FAILS_TODAY="$(grep -F "$today" /var/log/auth.log 2>/dev/null | grep -c 'Failed password' || true)"
        log "INFO" "SSH failed password lines in auth.log for today: $FAILS_TODAY"
    elif command -v journalctl >/dev/null 2>&1; then
        FAILS_TODAY="$(journalctl -u ssh -S today 2>/dev/null | grep -c 'Failed password' || true)"
        log "INFO" "SSH failed password lines in journal today: $FAILS_TODAY"
    fi
else
    log "ERROR" "sshd not found; SSH daemon may not be installed or accessible."
fi

#####################################
# 3. FAIL2BAN STATUS
#####################################

section "3. FAIL2BAN"

if command -v fail2ban-client >/dev/null 2>&1; then
    if systemctl is-active fail2ban >/dev/null 2>&1; then
        log "OK" "fail2ban service is active."

        {
            echo "# fail2ban-client status"
            fail2ban-client status || echo "fail2ban-client status returned non-zero."
        } >> "$TMP_REPORT" 2>&1
    else
        log "ERROR" "fail2ban service is NOT active."
    fi
else
    log "WARN" "fail2ban-client not installed; brute-force SSH protection may be limited."
fi

#####################################
# 4. CRITICAL SERVICES
#####################################

section "4. CRITICAL SERVICES"

check_service "nginx"          "Web server (nginx)"
check_service "apache2"        "Web server (Apache)"
check_service "exim4"          "MTA (Exim4)"
check_service "dovecot"        "IMAP/POP (Dovecot)"
check_service "spamassassin"   "SpamAssassin (spamd)"
check_service "fail2ban"       "fail2ban"
check_service "mariadb"        "MariaDB"
check_service "mysql"          "MySQL"
check_service "php8.2-fpm"     "PHP-FPM 8.2"
check_service "redis-server"   "Redis"

#####################################
# 4.5 FIREWALL QUICK LOOK (candy)
#####################################

section "4.5 FIREWALL QUICK LOOK"

if command -v ufw >/dev/null 2>&1; then
    {
        echo "# ufw status"
        ufw status 2>&1 || true
    } >> "$TMP_REPORT"
else
    log "INFO" "ufw not installed; skipping."
fi

if command -v nft >/dev/null 2>&1; then
    {
        echo "# nft list ruleset (first 120 lines)"
        nft list ruleset 2>/dev/null | sed -n '1,120p' || true
    } >> "$TMP_REPORT"
fi

#####################################
# 5. DISK / FS HEALTH SNAPSHOT
#####################################

section "5. DISK / FILESYSTEM SNAPSHOT"

if command -v df >/dev/null 2>&1; then
    log "INFO" "Disk usage (df -h):"
    {
        echo "# df -h"
        df -h
    } >> "$TMP_REPORT" 2>&1
fi

if command -v mount >/dev/null 2>&1; then
    log "INFO" "Mounted filesystems:"
    {
        echo "# mount | grep -E 'ext4|xfs|btrfs|zfs|nvme|sda'"
        mount | grep -E 'ext4|xfs|btrfs|zfs|nvme|sda' || true
    } >> "$TMP_REPORT" 2>&1
fi

#####################################
# 6. RKHUNTER
#####################################

section "6. RKHUNTER"

if [[ "$RUN_RKHUNTER" -eq 1 ]]; then
    if command -v rkhunter >/dev/null 2>&1; then
        log "INFO" "Running rkhunter --check --sk (this can take a few minutes)."

        if rkhunter --check --sk --nocolors --cronjob >/tmp/rkhunter-audit.txt 2>&1; then
            log "OK" "rkhunter completed without fatal issues (review /var/log/rkhunter.log for details)."
        else
            log "WARN" "rkhunter reported warnings or errors; review /var/log/rkhunter.log."
        fi

        {
            echo "# tail -n 50 /var/log/rkhunter.log"
            tail -n 50 /var/log/rkhunter.log 2>/dev/null || echo "Unable to read /var/log/rkhunter.log"
        } >> "$TMP_REPORT" 2>&1
    else
        log "WARN" "rkhunter not installed; skipping rootkit checks."
    fi
else
    log "INFO" "rkhunter check disabled by config."
fi

#####################################
# 7. AIDE INTEGRITY CHECK (optional)
#####################################

section "7. AIDE INTEGRITY CHECK"

if [[ "$RUN_AIDE" -eq 1 ]]; then
    if ! command -v aide >/dev/null 2>&1; then
        log "WARN" "aide not installed; skipping filesystem integrity checks."
    else
        if [[ ! -r /etc/aide/aide.conf && ! -d /etc/aide/aide.conf.d ]]; then
            log "WARN" "aide enabled but no config found (/etc/aide/aide.conf missing); skipping."
        elif [[ ! -s /var/lib/aide/aide.db && ! -s /var/lib/aide/aide.db.gz && ! -s /var/lib/aide/aide.db.new* ]]; then
            log "WARN" "aide enabled but database not initialized; skipping (run aideinit once to set baseline)."
        else
            log "INFO" "Running aide --check (this can take a while)."

            if aide --check >/tmp/aide-audit.txt 2>&1; then
                log "OK" "aide integrity check passed (no differences)."
            else
                EC=$?
                if grep -qiE 'added|removed|changed|modified|differences|summary' /tmp/aide-audit.txt 2>/dev/null; then
                    log "WARN" "aide reports differences (exit $EC). Review /tmp/aide-audit.txt."
                else
                    log "ERROR" "aide failed (exit $EC). Review /tmp/aide-audit.txt."
                fi
            fi

            {
                echo "# tail -n 50 /tmp/aide-audit.txt"
                tail -n 50 /tmp/aide-audit.txt 2>/dev/null || true
            } >> "$TMP_REPORT" 2>&1
        fi
    fi
else
    log "INFO" "aide check disabled by config."
fi

#####################################
# 8. SUMMARY / REPORT CARD
#####################################

ERRORS=$(grep -c '\[ERROR\]' "$TMP_REPORT" || true)
WARNS=$(grep -c '\[WARN\]' "$TMP_REPORT" || true)

GRADE="UNKNOWN"
GRADE_ICON="❔"

if (( ERRORS == 0 && WARNS == 0 )); then
    GRADE="EXCELLENT"
    GRADE_ICON="🟢"
elif (( ERRORS == 0 && WARNS <= 3 )); then
    GRADE="GOOD"
    GRADE_ICON="🟡"
elif (( ERRORS <= 2 )); then
    GRADE="FAIR"
    GRADE_ICON="🟠"
else
    GRADE="HORRIBLE"
    GRADE_ICON="🔴"
fi

{
    echo "====== SERVER SECURITY REPORT CARD ======"
    echo "Host:   $HOSTNAME"
    echo "IP:     $PRIMARY_IP"
    echo "Date:   $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo
    echo "Grade:  ${GRADE_ICON}  $GRADE"
    echo "Errors: $ERRORS"
    echo "Warns:  $WARNS"
    echo
    echo "Legend:"
    echo "  🟢 EXCELLENT  = No warnings, no errors"
    echo "  🟡 GOOD       = Few warnings, no errors"
    echo "  🟠 FAIR       = Some errors, but not many"
    echo "  🔴 HORRIBLE   = Multiple errors / lots of warnings"
    echo
    echo "====== RAW AUDIT LOG ======"
    cat "$TMP_REPORT"
} > "$FINAL_REPORT"

cp "$FINAL_REPORT" "$LOG_FILE" 2>/dev/null || cp "$FINAL_REPORT" /tmp/server-audit.log 2>/dev/null || true

#####################################
# 9. EMAIL REPORT
#####################################

if [[ -n "${AUDIT_EMAIL:-}" ]]; then
    if [[ -x "$SENDMAIL" ]]; then
        SUBJECT="${GRADE_ICON} Server audit: $GRADE (E:$ERRORS W:$WARNS) on $HOSTNAME"
        if ! {
            echo "To: $AUDIT_EMAIL"
            echo "From: $AUDIT_EMAIL_FROM"
            echo "Sender: $AUDIT_EMAIL_ENVELOPE_FROM"
            echo "Subject: $SUBJECT"
            echo "MIME-Version: 1.0"
            echo "Content-Type: text/plain; charset=UTF-8"
            echo
            cat "$FINAL_REPORT"
        } | "$SENDMAIL" -t -i -f "$AUDIT_EMAIL_ENVELOPE_FROM"; then
            log "WARN" "Failed to send email with sendmail."
        fi
    else
        log "WARN" "sendmail binary not found; skipping email delivery."
    fi
fi

exit 0
