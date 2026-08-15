#!/bin/sh

set -eu

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

upsert_env() {
    key="$1"
    value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '\n%s=%s\n' "$key" "$value" >> .env
    fi
}

upsert_env "APP_URL" "http://localhost:8000"
upsert_env "APP_TIMEZONE" "America/El_Salvador"
upsert_env "APP_LOCALE" "es"
upsert_env "APP_FALLBACK_LOCALE" "es"
upsert_env "DB_CONNECTION" "pgsql"
upsert_env "DB_HOST" "postgres"
upsert_env "DB_PORT" "5432"
upsert_env "DB_DATABASE" "sistema_ugda"
upsert_env "DB_USERNAME" "root"
upsert_env "DB_PASSWORD" "root"
upsert_env "SESSION_DRIVER" "file"
upsert_env "CACHE_STORE" "file"
upsert_env "MAIL_MAILER" "smtp"
upsert_env "MAIL_HOST" "mailpit"
upsert_env "MAIL_PORT" "1025"
upsert_env "MAIL_FROM_ADDRESS" "\"noreply@ugda.local\""
upsert_env "MAIL_FROM_NAME" "\"Sistema UGDA\""

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

php artisan optimize:clear >/dev/null 2>&1 || true

until php artisan migrate --seed --force; do
    echo "Waiting for database to be ready..."
    sleep 2
done

php artisan storage:link >/dev/null 2>&1 || true

php artisan serve --host=0.0.0.0 --port=8000
