#!/usr/bin/env bash
# Tim Production: membuat paket instalasi klien dari image yang sudah dibangun.
#   installer/build-package.sh 1.0.0 [image sumber, bawaan hris_eloas-app:latest]
# Hasil: dist/eolas-hris-<versi>/ dan dist/eolas-hris-<versi>.tar.gz (installer, image
# aplikasi + MySQL + Caddy untuk server tanpa internet, panduan, SHA256SUMS).
set -euo pipefail
VERSION="${1:?versi, mis. 1.0.0}"
SOURCE="${2:-hris_eloas-app:latest}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/dist/eolas-hris-$VERSION"

# The image must not carry dumps or the hosted service's secrets.
docker run --rm --entrypoint sh "$SOURCE" -c 'cd /var/www/html && ! ls ./*.sql .env >/dev/null 2>&1' \
    || { echo "Image $SOURCE berisi file .sql atau .env: bangun ulang (cek .dockerignore)"; exit 1; }

rm -rf "$OUT" && mkdir -p "$OUT/images"
docker tag "$SOURCE" "eolas-hris:$VERSION"
echo "$VERSION" > "$OUT/VERSION"
cp -r "$ROOT/installer/install.sh" "$ROOT/installer/lib.sh" "$ROOT/installer/docker-compose.yml" "$ROOT/installer/bin" "$OUT/"
cp "$ROOT/installer/PANDUAN.md" "$OUT/PANDUAN.md"
docker save "eolas-hris:$VERSION" | gzip -1 > "$OUT/images/eolas-hris-$VERSION.tar.gz"
for img in mysql:8.4 caddy:2.8.4-alpine; do
    docker pull -q "$img" >/dev/null
    docker save "$img" | gzip -1 > "$OUT/images/$(echo "$img" | tr ':/' '--').tar.gz"
done
( cd "$OUT" && find . -type f ! -name SHA256SUMS -printf '%P\n' | sort | xargs sha256sum > SHA256SUMS )
tar -C "$ROOT/dist" -czf "$ROOT/dist/eolas-hris-$VERSION.tar.gz" "eolas-hris-$VERSION"
echo "Paket: $ROOT/dist/eolas-hris-$VERSION.tar.gz ($(du -h "$ROOT/dist/eolas-hris-$VERSION.tar.gz" | cut -f1))"
