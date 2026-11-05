#!/bin/sh
# Installs Lumberjack with Docker or Podman. It leaves lumberjack/update.sh behind for updates.
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
        if docker-compose version >/dev/null 2>&1; then echo "docker-compose"; return; fi
    fi
    if [ "$engine" != docker ]; then
        if podman compose version >/dev/null 2>&1; then echo "podman compose"; return; fi
        if podman-compose version >/dev/null 2>&1; then echo "podman-compose"; return; fi
    fi
    fail "no Docker Compose or Podman Compose found${engine:+ for $engine}. Install one and run this again."
}

# The engine an earlier install runs on: compose labels its containers with the project,
# which is the folder's name.
installed_engine() {
    for engine in docker podman; do
        # A failing command can still print (Docker Desktop's WSL stub does), so it must succeed.
        if ids=$("$engine" ps -aq --filter "label=com.docker.compose.project=$(basename "$DIR")" 2>/dev/null) \
            && [ -n "$ids" ]; then
            echo "$engine"
            return
        fi
    done
}

download() { curl -fsSL "$1" -o "$2"; }

random() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }

case $ENGINE in ''|docker|podman) ;; *) fail "unknown engine '$ENGINE': use docker or podman" ;; esac
command -v curl >/dev/null 2>&1 || fail "curl is needed"
[ -n "$ENGINE" ] || ENGINE=$(installed_engine)
COMPOSE=$(find_compose)
say "Using $COMPOSE in $DIR"
mkdir -p "$DIR"
cd "$DIR"

# Kept from here on: update.sh never touches compose.yaml or .env.docker.
if [ ! -f update.sh ]; then
    # shellcheck disable=SC2016 # update.sh expands these itself
    printf '#!/bin/sh\n# Updates Lumberjack: pulls the image and restarts it. compose.yaml and .env.docker stay as they are.\nset -eu\ncd "$(dirname "$0")"\nCOMPOSE="%s"\n' "$COMPOSE" > update.sh
    cat >> update.sh <<'EOF'
$COMPOSE --env-file .env.docker pull
# podman-compose does not recreate running containers on up; the volumes stay.
$COMPOSE --env-file .env.docker down
$COMPOSE --env-file .env.docker up -d

echo "Waiting for Lumberjack to start"
port=$(sed -n 's/^LUMBERJACK_PORT=//p' .env.docker)
i=0
until curl -fsS "http://localhost:${port:-8080}/up" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 90 ]; then
        echo "Lumberjack did not answer within 3 minutes. See: $COMPOSE --env-file .env.docker logs lumberjack" >&2
        exit 1
    fi
    sleep 2
done
echo "Lumberjack runs on port ${port:-8080}."
EOF
    chmod +x update.sh
fi

if [ -f .env.docker ]; then
    say "Already installed, updating"
    exec sh ./update.sh
fi

[ -f compose.yaml ] || download "$SOURCE/compose.yaml" compose.yaml

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

sh ./update.sh

token=$($COMPOSE --env-file .env.docker logs lumberjack 2>&1 | grep -A2 'setup token' || true)
if [ -n "$token" ]; then
    printf '%s\n\nOpen %s/setup to create the first administrator.\n' "$token" "$url"
fi
printf 'Settings: %s/.env.docker. To update, or to apply changed settings: %s/update.sh\n' "$DIR" "$DIR"
