#!/usr/bin/env bash
set -euo pipefail

# Install PHP dependencies on first boot (vendor/ is git-ignored and not baked
# into the image so the bind mount stays the single source of truth).
if [ ! -f vendor/autoload_runtime.php ]; then
    echo "[entrypoint] vendor/ missing - running composer install..."
    composer install --no-interaction --prefer-dist
fi

# Wait for Postgres, then make sure both databases + schema exist.
if [ "${SKIP_DB_BOOTSTRAP:-0}" != "1" ]; then
    echo "[entrypoint] waiting for database..."
    until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "database", 5432) ? 0 : 1);'; do
        sleep 1
    done

    php bin/console doctrine:database:create --if-not-exists --no-interaction || true
    php bin/console doctrine:database:create --if-not-exists --no-interaction --env=test || true
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
fi

exec "$@"
