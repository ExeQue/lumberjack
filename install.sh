#!/bin/sh
# Installs Lumberjack with Docker or Podman, or updates an install made by this script.
#
#   curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh
#   curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh -s podman
#
# The argument picks docker or podman; without one, Docker is used when installed.
# It installs into ./lumberjack, from the folder it is run in.
#
# Settings, all optional, as environment variables:
#   APP_URL            the address people open Lumberjack on (asked for when not set)
#   LUMBERJACK_PORT    port on the host (default 8080)
#   LUMBERJACK_SOURCE  where compose.yaml and the settings template come from
#                      (default https://exeque.github.io/lumberjack)
set -eu

SOURCE=${LUMBERJACK_SOURCE:-https://exeque.github.io/lumberjack}
PORT=${LUMBERJACK_PORT:-8080}
DIR=$PWD/lumberjack
ENGINE=${1:-}

say() { printf '\033[1m%s\033[0m\n' "$*"; }
fail() { printf 'Error: %s\n' "$*" >&2; exit 1; }

# The compose command: Docker's plugin, then Podman's, then the standalone tools.
find_compose() {
    engine=$ENGINE
    if [ "$engine" != podman ]; then
        if docker compose version >/dev/null 2>&1; then echo "docker compose"; return; fi
        if command -v docker-compose >/dev/null 2>&1; then echo "docker-compose"; return; fi
    fi
    if [ "$engine" != docker ]; then
        if podman compose version >/dev/null 2>&1; then echo "podman compose"; return; fi
        if command -v podman-compose >/dev/null 2>&1; then echo "podman-compose"; return; fi
    fi
    fail "no Docker Compose or Podman Compose found${engine:+ for $engine}. Install one and run this again."
}

download() { curl -fsSL "$1" -o "$2"; }

random() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }

case $ENGINE in ''|docker|podman) ;; *) fail "unknown engine '$ENGINE': use docker or podman" ;; esac
command -v curl >/dev/null 2>&1 || fail "curl is needed"
COMPOSE=$(find_compose)
say "Using $COMPOSE in $DIR"
mkdir -p "$DIR"
cd "$DIR"

download "$SOURCE/compose.yaml" compose.yaml

if [ -f .env.docker ]; then
    say "Found .env.docker, updating"
    $COMPOSE --env-file .env.docker pull
    # podman-compose does not recreate running containers on up; the volumes stay.
    $COMPOSE --env-file .env.docker down
else
    url=${APP_URL:-}
    if [ -z "$url" ]; then
        [ -r /dev/tty ] || fail "set APP_URL, e.g. APP_URL=https://lumberjack.example.com"
        printf 'Address people open Lumberjack on [http://localhost:%s]: ' "$PORT"
        read -r url </dev/tty || true
        url=${url:-http://localhost:$PORT}
    fi

    download "$SOURCE/env.docker.example" .env.docker
    # A Laravel key is 32 random bytes; the database password only needs to be long.
    sed -i.bak \
        -e "s|^APP_URL=.*|APP_URL=$url|" \
        -e "s|^APP_KEY=.*|APP_KEY=base64:$(random 32)|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(random 24 | tr -dc 'A-Za-z0-9')|" \
        -e "s|^LUMBERJACK_PORT=.*|LUMBERJACK_PORT=$PORT|" \
        .env.docker
    rm -f .env.docker.bak
    chmod 600 .env.docker
fi

$COMPOSE --env-file .env.docker up -d

say "Waiting for Lumberjack to start"
port=$(sed -n 's/^LUMBERJACK_PORT=//p' .env.docker)
i=0
until curl -fsS "http://localhost:${port:-8080}/up" >/dev/null 2>&1; do
    i=$((i + 1))
    [ "$i" -lt 90 ] || fail "Lumberjack did not answer within 3 minutes. See: cd $DIR && $COMPOSE --env-file .env.docker logs lumberjack"
    sleep 2
done

url=$(sed -n 's/^APP_URL=//p' .env.docker)
token=$($COMPOSE --env-file .env.docker logs lumberjack 2>&1 | grep -A2 'setup token' || true)
say "Lumberjack runs on port ${port:-8080}."
if [ -n "$token" ]; then
    printf '%s\n\nOpen %s/setup to create the first administrator.\n' "$token" "$url"
fi
printf 'Settings: %s/.env.docker (mail and more; run this script again to apply and update).\n' "$DIR"
