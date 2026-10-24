#!/bin/sh
set -e
cd /var/www/html

# storage/ adalah volume (lampiran upload, log) — pastikan strukturnya ada.
mkdir -p storage/app/private storage/app/public storage/logs \
         storage/framework/cache storage/framework/sessions storage/framework/views
chown -R www-data:www-data storage bootstrap/cache

run() { su -s /bin/sh www-data -c "$*"; }

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    run php artisan migrate --force --no-interaction
    # Eolas: every demo workspace has its own database.
    run php artisan eolas:migrate-workspaces --no-interaction || true
fi
run php artisan storage:link --force >/dev/null 2>&1 || true
run php artisan config:cache
run php artisan route:cache
run php artisan view:cache

exec "$@"
