# Shared by install.sh and the eolas-* tools. EOLAS_DIR: where Eolas is installed.
EOLAS_DIR="${EOLAS_DIR:-/opt/eolas}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/eolas}"

say()  { printf '\033[1;32m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mGAGAL:\033[0m %s\n' "$*" >&2; exit 1; }

dc() { docker compose --project-directory "$EOLAS_DIR" -f "$EOLAS_DIR/docker-compose.yml" "$@"; }

env_get() { grep -E "^$1=" "$EOLAS_DIR/.env" | tail -1 | cut -d= -f2-; }

env_set() {
    if grep -qE "^$1=" "$EOLAS_DIR/.env"; then
        sed -i "s|^$1=.*|$1=$2|" "$EOLAS_DIR/.env"
    else
        printf '%s=%s\n' "$1" "$2" >> "$EOLAS_DIR/.env"
    fi
}

# Waits until the app answers its health check (or gives up after $1 seconds).
wait_healthy() {
    local limit="${EOLAS_HEALTH_TIMEOUT:-${1:-240}}" waited=0
    while [ "$waited" -lt "$limit" ]; do
        [ "$(docker inspect -f '{{.State.Health.Status}}' eolas-app 2>/dev/null)" = "healthy" ] && return 0
        sleep 5; waited=$((waited + 5))
    done
    return 1
}

# The daily backup at 02:30 WIB as a systemd timer, and the eolas-* commands on the PATH.
# Rewritten by eolas-update too, so a new version's schedule reaches installed servers.
install_backup_timer() {
    local unit=/etc/systemd/system/eolas-backup.service dir="$BACKUP_DIR"
    for t in eolas-backup eolas-restore eolas-update; do ln -sf "$EOLAS_DIR/bin/$t" "/usr/local/bin/$t"; done
    command -v systemctl >/dev/null && [ -d /run/systemd/system ] || return 1
    # A backup folder chosen at install time is kept.
    [ -f "$unit" ] && dir=$(sed -n 's/.*BACKUP_DIR=\([^ ]*\).*/\1/p' "$unit" | head -1) && [ -n "$dir" ] || dir="$BACKUP_DIR"
    cat > "$unit" <<UNIT
[Unit]
Description=Eolas HRIS backup
After=docker.service
[Service]
Type=oneshot
Environment=EOLAS_DIR=$EOLAS_DIR BACKUP_DIR=$dir
ExecStart=$EOLAS_DIR/bin/eolas-backup
UNIT
    cat > /etc/systemd/system/eolas-backup.timer <<UNIT
[Unit]
Description=Eolas HRIS backup harian
[Timer]
# Asia/Jakarta like the app, whatever the server clock (VPS are often UTC).
OnCalendar=*-*-* 02:30:00 Asia/Jakarta
Persistent=true
[Install]
WantedBy=timers.target
UNIT
    systemctl daemon-reload && systemctl enable eolas-backup.timer >/dev/null 2>&1 && systemctl restart eolas-backup.timer
}
