#!/bin/sh
set -e
cd /var/www/backend

# Premier démarrage : dépendances, .env, clé d'application.
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force

# Attente de MariaDB.
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=3306", getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
  echo "En attente de MariaDB…"; sleep 2
done

# Seul le conteneur « app » migre et remplit la base (RUN_MIGRATIONS=1).
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
  if [ "$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -1)" = "0" ]; then
    php artisan db:seed --force
  fi
  chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
