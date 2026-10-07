#!/bin/sh
set -e

if [ "$APP_INIT" != "true" ] || [ ! -f "$APP_BASE_DIR/artisan" ]; then
    exit 0
fi

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY kosong. Jalankan: cp .env.example .env (root repository)." >&2
    exit 1
fi

cd "$APP_BASE_DIR"

php artisan migrate --force --no-interaction

DEVICE_COUNT="$(php artisan tinker --execute='echo \App\Models\Device::count();' 2>/dev/null | tail -n 1 | tr -d '[:space:]')"
if [ "$DEVICE_COUNT" = "0" ]; then
    echo "Database kosong, menjalankan seeder (data historis 7 hari)..."
    php artisan db:seed --force --no-interaction
fi

exit 0
