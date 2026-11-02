#!/bin/sh
# Prepares Lumberjack, then hands over to the command (supervisord by default).
set -eu

cd /app

as_app() { su-exec www-data "$@"; }

# Reverb credentials only matter inside this container, so they derive from
# APP_KEY unless given: stable across restarts, nothing extra to configure.
derive() { printf '%s' "$1:${APP_KEY:-}" | sha256sum | cut -c1-32; }
export REVERB_APP_ID="${REVERB_APP_ID:-lumberjack}"
export REVERB_APP_KEY="${REVERB_APP_KEY:-$(derive reverb-key)}"
export REVERB_APP_SECRET="${REVERB_APP_SECRET:-$(derive reverb-secret)}"

# A one-off command (docker run ... php artisan key:generate --show) runs as it
# is: the checks, migrations and caches below are for starting Lumberjack.
if [ "${1:-}" != "supervisord" ]; then
    exec su-exec www-data "$@"
fi

if [ -z "${APP_KEY:-}" ]; then
    cat >&2 <<'MSG'
APP_KEY is not set. Generate one and put it in your compose file or .env:

    docker run --rm ghcr.io/exeque/lumberjack php artisan key:generate --show

MSG
    exit 1
fi

if [ -z "${APP_URL:-}" ]; then
    echo "APP_URL is not set: use the address people open Lumberjack on, e.g. https://lumberjack.example.com" >&2
    exit 1
fi

case "$APP_URL" in
    https://*) export SESSION_SECURE_COOKIE="${SESSION_SECURE_COOKIE:-true}" ;;
esac

# Storage is a volume that may start empty, or a bind mount owned by someone else.
mkdir -p storage/app/private storage/app/public storage/app/setup \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Until setup is done, it is locked with a token: whoever reaches a fresh
# Lumberjack first would otherwise become its administrator. The token is
# printed below and kept in storage, so it survives restarts.
if [ -z "${LUMBERJACK_SETUP_TOKEN:-}" ] && [ ! -f storage/app/setup/complete ]; then
    token_file=storage/app/setup/token
    if [ ! -s "$token_file" ]; then
        head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$token_file"
        chown www-data:www-data "$token_file"
        chmod 600 "$token_file"
    fi
    export LUMBERJACK_SETUP_TOKEN="$(cat "$token_file")"
    cat <<MSG

  Lumberjack is not set up yet. Open ${APP_URL}/setup and enter this setup token:

      ${LUMBERJACK_SETUP_TOKEN}

MSG
fi

if [ "${LUMBERJACK_MIGRATE:-true}" = "true" ]; then
    # The database may still be starting.
    tries=0
    until as_app php artisan migrate --force --isolated; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "The database did not answer; giving up." >&2
            exit 1
        fi
        echo "Waiting for the database ($tries)..." >&2
        sleep 2
    done
fi

as_app php artisan storage:link --force >/dev/null 2>&1 || true
as_app php artisan optimize

exec "$@"
