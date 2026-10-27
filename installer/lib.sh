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
