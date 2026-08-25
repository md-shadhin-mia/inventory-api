#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-app}"

if [ -z "${APP_KEY}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with 'php artisan key:generate --show'." >&2
    exit 1
fi

# Wait for Postgres. A PDO probe (rather than pg_isready) verifies the host,
# the credentials AND that the database itself exists.
echo "Waiting for postgres at ${DB_HOST}:${DB_PORT}..."
i=0
until php -r '
    try {
        new PDO(
            sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 2]
        );
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
' 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "FATAL: postgres unreachable after 60s" >&2
        exit 1
    fi
    sleep 1
done
echo "Postgres ready."

# Framework caches MUST be built here, not at image build time: config:cache
# snapshots every env() value into bootstrap/cache/config.php, and at build time
# DB_HOST / REDIS_HOST / APP_KEY do not exist yet.
#
# This is safe because no env() call exists outside config/ — keep it that way.
if [ "${SKIP_OPTIMIZE:-false}" != "true" ]; then
    su-exec www-data php artisan config:clear
    su-exec www-data php artisan config:cache
    su-exec www-data php artisan event:cache
    su-exec www-data php artisan route:cache
    su-exec www-data php artisan view:cache
fi

# Migrations are opt-in and app-role only. --isolated takes an advisory lock so
# multiple replicas cannot race. The queue-worker never migrates; it waits on
# the app container's healthcheck instead.
if [ "$ROLE" = "app" ] && [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    su-exec www-data php artisan migrate --force --isolated
fi

exec "$@"
