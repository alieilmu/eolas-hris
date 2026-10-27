#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Eolas HRIS — installer on-premise (Ubuntu 22.04/24.04, Debian 12).
#
#   sudo ./install.sh                     # tanya jawab
#   sudo ./install.sh --non-interactive \
#        --company "PT Contoh" --domain hris.contoh.co.id --tls letsencrypt \
#        --admin-name "Admin HR" --admin-email hr@contoh.co.id --license license.json
#   (password Super Admin dari variabel EOLAS_ADMIN_PASSWORD)
#
# TLS: letsencrypt (domain publik, port 80/443 terbuka ke internet), internal (jaringan
# kantor: sertifikat Caddy, browser perlu mempercayai CA-nya), custom (certs/cert.pem +
# certs/key.pem dari perusahaan), none (HTTP saja, hanya untuk uji coba).
# ---------------------------------------------------------------------------
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/lib.sh"

COMPANY="" DOMAIN="" TLS="" ADMIN_NAME="" ADMIN_EMAIL="" LICENSE="" NONINTERACTIVE=0 SKIP_DOCKER=0 SKIP_OS=0 HTTP_PORT=80 HTTPS_PORT=443
while [ $# -gt 0 ]; do
    case "$1" in
        --company) COMPANY="$2"; shift 2 ;;
        --domain) DOMAIN="$2"; shift 2 ;;
        --tls) TLS="$2"; shift 2 ;;
        --admin-name) ADMIN_NAME="$2"; shift 2 ;;
        --admin-email) ADMIN_EMAIL="$2"; shift 2 ;;
        --license) LICENSE="$2"; shift 2 ;;
        --dir) EOLAS_DIR="$2"; shift 2 ;;
        --http-port) HTTP_PORT="$2"; shift 2 ;;
        --https-port) HTTPS_PORT="$2"; shift 2 ;;
        --non-interactive) NONINTERACTIVE=1; shift ;;
        --skip-docker-install) SKIP_DOCKER=1; shift ;;
        --skip-os-check) SKIP_OS=1; shift ;;
        -h|--help) sed -n 2,14p "$0"; exit 0 ;;
        *) die "Opsi tidak dikenal: $1" ;;
    esac
done

ask() { # ask VAR "Pertanyaan" [bawaan]
    local var="$1" q="$2" def="${3:-}" v
    [ -n "${!var}" ] && return
    [ "$NONINTERACTIVE" = 1 ] && { [ -n "$def" ] && printf -v "$var" '%s' "$def" && return; die "Isi --${var,,} (mode non-interactive)"; }
    read -r -p "$q${def:+ [$def]}: " v
    printf -v "$var" '%s' "${v:-$def}"
}
rand() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }

[ "$(id -u)" = 0 ] || die "Jalankan sebagai root (sudo ./install.sh)"
VERSION=$(cat "$HERE/VERSION")
[ -f "$EOLAS_DIR/.env" ] && die "Eolas sudah terpasang di $EOLAS_DIR. Untuk versi baru pakai: eolas-update $HERE"

say "Eolas HRIS $VERSION — pemeriksaan server"
if [ "$SKIP_OS" = 0 ]; then
    . /etc/os-release 2>/dev/null || die "Sistem operasi tidak dikenali"
    case "$ID:${VERSION_ID:-}" in
        ubuntu:22.04|ubuntu:24.04|debian:12) ;;
        *) die "Didukung: Ubuntu 22.04/24.04, Debian 12 (ini: ${PRETTY_NAME:-?}). Pakai --skip-os-check bila yakin." ;;
    esac
    cpus=$(nproc); mem=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo); disk=$(df -Pm "$(dirname "$EOLAS_DIR")" | awk 'NR==2 {print $4}')
    [ "$cpus" -ge 2 ] || die "Minimal 2 vCPU (ada $cpus)"
    [ "$mem" -ge 3500 ] || die "Minimal 4 GB RAM (ada ${mem} MB)"
    [ "$disk" -ge 20000 ] || die "Minimal 20 GB ruang kosong (ada ${disk} MB)"
fi
for t in openssl gzip sha256sum; do command -v "$t" >/dev/null || die "Perlu '$t' (apt install $t)"; done

if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
    [ "$SKIP_DOCKER" = 1 ] && die "Docker + compose plugin belum ada"
    say "Memasang Docker"
    command -v curl >/dev/null || { apt-get update -qq && apt-get install -y -qq curl; }
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
fi

ask COMPANY "Nama perusahaan"
ask DOMAIN "Alamat aplikasi (domain atau IP server)" "$(hostname -I 2>/dev/null | awk '{print $1}')"
ask TLS "HTTPS: letsencrypt / internal / custom / none" "$( [[ "$DOMAIN" =~ ^[0-9.]+$ ]] && echo internal || echo letsencrypt )"
ask ADMIN_NAME "Nama Super Admin" "Administrator"
ask ADMIN_EMAIL "Email Super Admin"
case "$TLS" in letsencrypt|internal|custom|none) ;; *) die "TLS harus letsencrypt, internal, custom atau none" ;; esac
[ "$TLS" = letsencrypt ] && [[ "$DOMAIN" =~ ^[0-9.]+$ ]] && die "Let's Encrypt butuh nama domain, bukan IP"
if [ -z "${EOLAS_ADMIN_PASSWORD:-}" ]; then
    [ "$NONINTERACTIVE" = 1 ] && die "Isi EOLAS_ADMIN_PASSWORD"
    read -r -s -p "Password Super Admin (min. 10 karakter): " EOLAS_ADMIN_PASSWORD; echo
fi
[ "${#EOLAS_ADMIN_PASSWORD}" -ge 10 ] || die "Password Super Admin minimal 10 karakter"
[ -z "$LICENSE" ] || [ -f "$LICENSE" ] || die "File lisensi $LICENSE tidak ada"

say "Memasang ke $EOLAS_DIR"
umask 077
mkdir -p "$EOLAS_DIR/certs" "$BACKUP_DIR"
cp "$HERE/docker-compose.yml" "$HERE/lib.sh" "$HERE/VERSION" "$EOLAS_DIR/"
cp -r "$HERE/bin" "$EOLAS_DIR/"
chmod 755 "$EOLAS_DIR" "$EOLAS_DIR/bin" "$EOLAS_DIR"/bin/* && chmod 644 "$EOLAS_DIR/docker-compose.yml" "$EOLAS_DIR/lib.sh" "$EOLAS_DIR/VERSION"
[ "$TLS" = custom ] && { [ -f "$HERE/certs/cert.pem" ] && [ -f "$HERE/certs/key.pem" ] || die "TLS custom: taruh certs/cert.pem dan certs/key.pem di folder paket"; cp "$HERE/certs/"*.pem "$EOLAS_DIR/certs/"; }

case "$TLS" in
    letsencrypt) site="$DOMAIN"; tls_line="" ; url="https://$DOMAIN" ;;
    internal) site="$DOMAIN"; tls_line="    tls internal"; url="https://$DOMAIN" ;;
    custom) site="$DOMAIN"; tls_line="    tls /certs/cert.pem /certs/key.pem"; url="https://$DOMAIN" ;;
    none) site=":80"; tls_line=""; url="http://$DOMAIN" ;;
esac
[ "$HTTPS_PORT" != 443 ] && [ "$TLS" != none ] && url="$url:$HTTPS_PORT"
[ "$HTTP_PORT" != 80 ] && [ "$TLS" = none ] && url="$url:$HTTP_PORT"
cat > "$EOLAS_DIR/Caddyfile" <<CADDY
$site {
$tls_line
    encode gzip
    header {
        Strict-Transport-Security "max-age=31536000"
        X-Content-Type-Options nosniff
        X-Frame-Options SAMEORIGIN
        Referrer-Policy strict-origin-when-cross-origin
        -Server
    }
    reverse_proxy app:80
}
CADDY
chmod 644 "$EOLAS_DIR/Caddyfile"

cat > "$EOLAS_DIR/.env" <<ENV
# Eolas HRIS ($COMPANY) — dibuat installer $(date +%F). RAHASIA: jangan dibagikan.
EOLAS_VERSION=$VERSION
EOLAS_EDITION=client
APP_NAME="Eolas HRIS"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=$url
APP_LOCALE=id
APP_TIMEZONE=Asia/Jakarta
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_DATABASE=eolas
DB_USERNAME=eolas
DB_PASSWORD=$(rand 32)
DB_ROOT_PASSWORD=$(rand 32)
SESSION_DRIVER=database
SESSION_LIFETIME=240
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=$( [ "$TLS" = none ] && echo false || echo true )
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
HTTP_PORT=$HTTP_PORT
HTTPS_PORT=$HTTPS_PORT
ENV
chmod 600 "$EOLAS_DIR/.env"

say "Memuat image aplikasi"
( cd "$HERE" && sha256sum -c --quiet SHA256SUMS ) || die "Checksum paket tidak cocok: unduh ulang paket"
for img in "$HERE"/images/*.tar.gz; do gunzip -c "$img" | docker load >/dev/null; done

say "Menjalankan Eolas (pertama kali ±2 menit)"
dc up -d || true
wait_healthy 300 || { docker logs --tail 40 eolas-app >&2; die "Aplikasi tidak sehat"; }

say "Menyiapkan data awal dan akun Super Admin"
dc exec -T -u www-data -e EOLAS_ADMIN_PASSWORD="$EOLAS_ADMIN_PASSWORD" app php artisan eolas:client:setup \
    --company="$COMPANY" --admin-name="$ADMIN_NAME" --admin-email="$ADMIN_EMAIL"
if [ -n "$LICENSE" ]; then
    dc exec -T app sh -c 'cat > /var/www/html/storage/app/license.json && chown www-data:www-data /var/www/html/storage/app/license.json' < "$LICENSE"
    dc exec -T -u www-data app php artisan eolas:license:check || warn "Lisensi tidak berlaku: pasang ulang dari menu Administrasi → Lisensi"
fi

say "Backup harian (02:30) dan perintah eolas-*"
for t in eolas-backup eolas-restore eolas-update; do ln -sf "$EOLAS_DIR/bin/$t" "/usr/local/bin/$t"; done
if command -v systemctl >/dev/null && [ -d /run/systemd/system ]; then
    cat > /etc/systemd/system/eolas-backup.service <<UNIT
[Unit]
Description=Eolas HRIS backup
After=docker.service
[Service]
Type=oneshot
Environment=EOLAS_DIR=$EOLAS_DIR BACKUP_DIR=$BACKUP_DIR
ExecStart=$EOLAS_DIR/bin/eolas-backup
UNIT
    cat > /etc/systemd/system/eolas-backup.timer <<UNIT
[Unit]
Description=Eolas HRIS backup harian
[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
    systemctl daemon-reload && systemctl enable --now eolas-backup.timer >/dev/null
else
    warn "systemd tidak ada: jadwalkan '$EOLAS_DIR/bin/eolas-backup' sendiri (cron 30 2 * * *)"
fi

cat <<DONE

  Eolas HRIS $VERSION terpasang untuk $COMPANY.

  Buka        : $url
  Masuk       : $ADMIN_EMAIL (password yang tadi dibuat)
  Lisensi     : $( [ -n "$LICENSE" ] && echo "terpasang" || echo "BELUM — pasang di Administrasi → Lisensi" )
  Data & file : $EOLAS_DIR (rahasia: .env)  ·  backup harian: $BACKUP_DIR
  Perintah    : eolas-backup · eolas-restore <folder> · eolas-update <folder paket>
$( [ "$TLS" = internal ] && echo "
  HTTPS internal: agar browser tidak memperingatkan, pasang sertifikat CA dari
  'docker exec eolas-caddy cat /data/caddy/pki/authorities/local/root.crt' di komputer pengguna." )

  Langkah berikut: login, aktifkan 2FA, ikuti Sesi Awal (profil perusahaan, format NRP,
  organisasi, import karyawan). Simpan salinan $EOLAS_DIR/.env di tempat aman.
DONE
