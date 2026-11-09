# Running Lumberjack with Docker

One image runs everything Lumberjack needs: the web server, Reverb for live
updates, Horizon for the queues and the scheduler. Postgres and Redis run beside
it in `compose.yaml`, behind a reverse proxy with TLS on one port.

```
reverse proxy (TLS) ──► :8080 lumberjack ──► postgres
                                         └─► redis
```

Rather run it without Docker? See [Installing from source](./source.md).

## Install with one command

On a server with Docker Compose or Podman Compose, in the folder Lumberjack should live in:

```bash
curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh
```

Docker is used when it is installed. To pick one, pass `docker` or `podman`:

```bash
curl -fsSL https://exeque.github.io/lumberjack/install.sh | sh -s podman
```

The script asks for the address people open Lumberjack on, puts `compose.yaml` and a
filled-in `.env.docker` in a `lumberjack` folder, starts Lumberjack and prints the setup
token. It also leaves `lumberjack/update.sh` behind for updates. Read
[the script on GitHub](https://github.com/ExeQue/lumberjack/blob/main/install.sh) before you
run it. Every release carries the script, the files it downloads and their checksums, so
you can check that what you got is what was released:

```bash
curl -fsSLO https://exeque.github.io/lumberjack/install.sh
curl -fsSL https://github.com/ExeQue/lumberjack/releases/latest/download/SHA256SUMS | sha256sum -c --ignore-missing
sh install.sh
```

Or hand it to a coding agent first:

```text
Review the install script at https://exeque.github.io/lumberjack/install.sh before I run
it. Do not run it. Fetch it and check it against SHA256SUMS on the latest release of
https://github.com/ExeQue/lumberjack, and compare it with install.sh in that release. Tell
me, in short lists: every file and folder it creates or changes, every command it runs and
whether any needs root, every address it contacts, and how it makes and stores the
secrets. Flag anything that does not match the version on GitHub or that a plain installer
would not need.
```

Set `APP_URL` or `LUMBERJACK_PORT` (default `8080`) in front of `sh` to skip the question or
use another port, e.g. `curl -fsSL .../install.sh | APP_URL=https://lumberjack.example.com sh`.
Then point your reverse proxy at the port (see [Reverse proxy](#reverse-proxy)), and fill in
the mail settings in `lumberjack/.env.docker`.

## Install by hand

1. In a new folder, create `compose.yaml` with this content (it is also in the
   repository as [`compose.yaml`](https://github.com/ExeQue/lumberjack/blob/main/compose.yaml)):

    <!-- compose.yaml -->

    ```yaml
    # Lumberjack with its own Postgres and Redis. Put a reverse proxy (with TLS) in
    # front of port 8080; WebSockets for live updates use the same port.
    #
    #   cp .env.docker.example .env.docker   # then fill it in
    #   docker compose --env-file .env.docker up -d
    #   docker compose logs lumberjack       # shows the setup token on first start
    #
    # To use a Postgres or Redis you already run, point DB_* / REDIS_* at it and
    # remove the service (and its depends_on entry). On this same server, use
    # host.docker.internal as the host: localhost is the container itself.

    services:
        lumberjack:
            image: ${LUMBERJACK_IMAGE:-ghcr.io/exeque/lumberjack:latest}
            restart: unless-stopped
            ports:
                - '${LUMBERJACK_PORT:-8080}:8080'
            environment:
                APP_URL: '${APP_URL:?set APP_URL to the address people open Lumberjack on}'
                APP_KEY: '${APP_KEY:?generate one with docker run --rm ghcr.io/exeque/lumberjack php artisan key:generate --show}'
                LUMBERJACK_SETUP_TOKEN: ${LUMBERJACK_SETUP_TOKEN:-}
                DB_HOST: ${DB_HOST:-postgres}
                DB_PORT: ${DB_PORT:-5432}
                DB_DATABASE: ${DB_DATABASE:-lumberjack}
                DB_USERNAME: ${DB_USERNAME:-lumberjack}
                DB_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD}
                REDIS_HOST: ${REDIS_HOST:-redis}
                REDIS_PORT: ${REDIS_PORT:-6379}
                REDIS_PASSWORD: ${REDIS_PASSWORD:-}
                # The reverse proxy in front: a proxy on this server or in a Docker network connects from 172.16.0.0/12.
                TRUSTED_PROXIES: ${TRUSTED_PROXIES:-127.0.0.1,172.16.0.0/12}
                # Queue workers: ingest handles incoming telemetry, background the rest.
                HORIZON_INGEST_PROCESSES: ${HORIZON_INGEST_PROCESSES:-2}
                HORIZON_BACKGROUND_PROCESSES: ${HORIZON_BACKGROUND_PROCESSES:-2}
                MAIL_MAILER: ${MAIL_MAILER:-smtp}
                MAIL_HOST: ${MAIL_HOST:-}
                MAIL_PORT: ${MAIL_PORT:-587}
                MAIL_USERNAME: ${MAIL_USERNAME:-}
                MAIL_PASSWORD: ${MAIL_PASSWORD:-}
                MAIL_FROM_ADDRESS: ${MAIL_FROM_ADDRESS:-lumberjack@example.com}
                MAIL_FROM_NAME: ${MAIL_FROM_NAME:-Lumberjack}
                MAIL_ALERTS_FROM_ADDRESS: ${MAIL_ALERTS_FROM_ADDRESS:-}
                MAIL_NOTIFICATIONS_FROM_ADDRESS: ${MAIL_NOTIFICATIONS_FROM_ADDRESS:-}
                # Browser push keys: made and stored on first use when empty.
                VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:-}
                VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:-}
                VAPID_SUBJECT: ${VAPID_SUBJECT:-}
            # Uploads, the setup flag and logs, in a folder next to this file like the database.
            volumes:
                - ./data/storage:/app/storage
            # Lets DB_HOST / REDIS_HOST=host.docker.internal reach services on this server.
            extra_hosts:
                - 'host.docker.internal:host-gateway'
            depends_on:
                postgres:
                    condition: service_healthy
                redis:
                    condition: service_healthy

        postgres:
            image: docker.io/library/postgres:18-alpine
            restart: unless-stopped
            environment:
                POSTGRES_DB: ${DB_DATABASE:-lumberjack}
                POSTGRES_USER: ${DB_USERNAME:-lumberjack}
                POSTGRES_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD}
            # A folder next to this file, so the database is easy to find, copy and back up.
            volumes:
                - ./data/postgres:/var/lib/postgresql
            healthcheck:
                test:
                    [
                        'CMD-SHELL',
                        'pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}',
                    ]
                interval: 5s
                timeout: 3s
                retries: 20

        redis:
            image: docker.io/library/redis:7-alpine
            restart: unless-stopped
            command: ['redis-server', '--appendonly', 'yes']
            volumes:
                - redis-data:/data
            healthcheck:
                test: ['CMD', 'redis-cli', 'ping']
                interval: 5s
                timeout: 3s
                retries: 20

    volumes:
        redis-data:
    ```

2. Create `.env.docker` beside it ([`.env.docker.example`](https://github.com/ExeQue/lumberjack/blob/main/.env.docker.example)):

    <!-- .env.docker.example -->

    ```dotenv
    # Settings for compose.yaml. Copy to .env.docker and fill in.

    # The address people open Lumberjack on, as the reverse proxy serves it.
    APP_URL=https://lumberjack.example.com

    # docker run --rm ghcr.io/exeque/lumberjack php artisan key:generate --show
    APP_KEY=

    # Password of the bundled Postgres (or of your own, with DB_HOST set).
    DB_PASSWORD=

    # Optional: lock first-run setup with a token of your own. Without one, the
    # container makes one on first start and prints it in its log.
    LUMBERJACK_SETUP_TOKEN=

    # Port on the host the reverse proxy connects to.
    LUMBERJACK_PORT=8080

    # Queue workers at most: ingest handles incoming telemetry, background the rest.
    # Raise them on a busy server.
    HORIZON_INGEST_PROCESSES=2
    HORIZON_BACKGROUND_PROCESSES=2

    # Mail, for invitations, password resets and e-mail alerts.
    MAIL_HOST=
    MAIL_PORT=587
    MAIL_USERNAME=
    MAIL_PASSWORD=
    MAIL_FROM_ADDRESS=lumberjack@example.com
    ```

3. Generate an application key and put it in `.env.docker` as `APP_KEY`:

    ```bash
    docker run --rm ghcr.io/exeque/lumberjack php artisan key:generate --show
    ```

    Also fill in `APP_URL` and `DB_PASSWORD`.

4. Start it. The database and the app's storage are kept in `data/` next to
   `compose.yaml`; Docker makes the folders, Podman needs them made first:

    ```bash
    mkdir -p data/postgres data/storage
    docker compose --env-file .env.docker up -d
    ```

5. Point your reverse proxy at port 8080 (see [Reverse proxy](#reverse-proxy)).

6. Read the setup token from the log and open `APP_URL/setup`:

    ```bash
    docker compose logs lumberjack | grep -A2 'setup token'
    ```

    Setup creates the first administrator; see
    [Setup and system administration](./administration.md).

## Settings

| Variable                                 | Default                            |                                                                                                                                                                                                    |
| ---------------------------------------- | ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_URL`                                | (required)                         | The public address, e.g. `https://lumberjack.example.com`                                                                                                                                          |
| `APP_KEY`                                | (required)                         | Encryption key. Keep it: changing it signs everyone out and breaks stored secrets                                                                                                                  |
| `DB_PASSWORD`                            | (required)                         | Password of the bundled Postgres                                                                                                                                                                   |
| `LUMBERJACK_SETUP_TOKEN`                 | generated                          | Locks first-run setup. Without one, a token is generated on first start and printed in the log                                                                                                     |
| `LUMBERJACK_IMAGE`                       | `ghcr.io/exeque/lumberjack:latest` | The image, e.g. pinned to a version: `ghcr.io/exeque/lumberjack:0.2`                                                                                                                               |
| `LUMBERJACK_PORT`                        | `8080`                             | Port on the host                                                                                                                                                                                   |
| `HORIZON_INGEST_PROCESSES`               | `2`                                | Most workers for incoming telemetry                                                                                                                                                                |
| `HORIZON_BACKGROUND_PROCESSES`           | `2`                                | Most workers for everything else (alerts, pruning)                                                                                                                                                 |
| `MAIL_*`                                 |                                    | SMTP for invitations, password resets and e-mail alerts; see [Mail](./administration#mail) for the two sender addresses                                                                            |
| `TRUSTED_PROXIES`                        | Docker ranges                      | Proxies whose `X-Forwarded-*` headers are trusted: `127.0.0.1,172.16.0.0/12`. Comma separated, `*` for any (not recommended)                                                                       |
| `LUMBERJACK_MIGRATE`                     | `true`                             | Run database migrations on start                                                                                                                                                                   |
| `LOG_LEVEL`                              | `info`                             | How much Lumberjack itself logs, to the container's output                                                                                                                                         |
| `SESSION_SECURE_COOKIE`                  | from `APP_URL`                     | `true` when `APP_URL` starts with `https://`: the session cookie is then only sent over HTTPS                                                                                                      |
| `ALERTS_WEBHOOKS_ALLOW_PRIVATE`          | `false`                            | Allow alert channels to [private addresses](./alerts#channels)                                                                                                                                     |
| `LUMBERJACK_HEALTH_ALLOW_PRIVATE`        | `false`                            | Allow health checks of [private addresses](./health#private-networks)                                                                                                                              |
| `LUMBERJACK_UPDATE_CHECK`                | `true`                             | Ask GitHub for [newer releases](./administration#update-check)                                                                                                                                     |
| `REVERB_CLIENT_HOST`, `_PORT`, `_SCHEME` | from `APP_URL`                     | Where browsers open the live-update WebSocket, when it is not `APP_URL`                                                                                                                            |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`  | generated                          | Keys that sign [browser notifications](./teams#browser-notifications). Generated automatically. Set them to keep them across a database restore, see [Command line](./administration#command-line) |
| `VAPID_SUBJECT`                          | `APP_URL`                          | Who push services contact about this server: a `mailto:` address or an `https://` URL                                                                                                              |

### Your own Postgres or Redis

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (or
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`) to the server you already run, and
remove the `postgres` or `redis` service and its `depends_on` entry from
`compose.yaml`. Lumberjack needs Postgres 16 or newer and Redis 7 or newer.

For a Postgres or Redis on the same server, use `host.docker.internal` as the host, not
`localhost`, which is the container itself. The service must accept connections from
the Docker network:

- Postgres: `listen_addresses` includes the Docker bridge (e.g. `'*'` or
  `172.17.0.1`), and `pg_hba.conf` allows the Docker subnet, e.g.
  `host lumberjack lumberjack 172.16.0.0/12 scram-sha-256`.
- Redis: `bind` includes the bridge address, and `protected-mode` is off or a
  password is set (`REDIS_PASSWORD`).

On Linux you can instead set `network_mode: host` on the `lumberjack` service. Then
`localhost` is the server itself and `ports:` is ignored.

## Reverse proxy

Live updates use WebSockets on the same host and port as the app, under the path
`/app`. The proxy must pass the `Upgrade` header through and set `X-Forwarded-Proto`.

**Caddy**

```
lumberjack.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

**nginx**

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
}
```

**Traefik** handles WebSockets without extra settings; route the host to port 8080.

`TRUSTED_PROXIES` lists the proxies whose `X-Forwarded-For`, `-Proto` and `-Host`
headers are trusted. The default, `127.0.0.1,172.16.0.0/12`, covers a proxy on the same
server or in a Docker network; otherwise set it to the proxy's address. Keep the list
short: anyone who connects from a trusted address can claim any client IP and get
around the login and ingest rate limits.

Links in mails and alerts always use `APP_URL`.

## Data and backups

| Where           | Holds                                                                                                                                                        |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `data/postgres` | A folder next to `compose.yaml`: everything Lumberjack records. Back it up with `docker compose exec postgres pg_dump -U lumberjack lumberjack > backup.sql` |
| `redis-data`    | Queues and cache. Lumberjack recovers without it                                                                                                             |
| `data/storage`  | Next to it: uploads, the setup flag and the generated setup token                                                                                            |

## Updating

### From an install with Docker volumes

Before 0.1.3 the database and the app's storage lived in Docker volumes named
`postgres-data` and `lumberjack-storage`. An install made with the script keeps them,
since `update.sh` never changes `compose.yaml`. To move to the folders anyway, take the
new `compose.yaml` and copy the data across once, with Lumberjack stopped:

```bash
docker compose --env-file .env.docker down
mkdir -p data/postgres data/storage
for pair in postgres-data:postgres lumberjack-storage:storage; do
    docker run --rm -v "$(basename "$PWD")_${pair%%:*}:/from" -v "$PWD/data/${pair##*:}:/to" \
        docker.io/library/alpine cp -a /from/. /to/
done
docker compose --env-file .env.docker up -d
```

When Lumberjack runs with everything in place, `docker volume rm` the old volumes.

### Every update

Installed with the script? Run `lumberjack/update.sh`. It pulls the image and restarts
Lumberjack on the engine it was installed with, and leaves `compose.yaml` and `.env.docker`
as they are, changes of your own included. Run it after changing `.env.docker` too. Otherwise:

```bash
docker compose pull
docker compose --env-file .env.docker up -d
```

Migrations run on start. To run them yourself, set `LUMBERJACK_MIGRATE=false` and run
`docker compose exec lumberjack php artisan migrate --force`.

## Commands

Run artisan inside the container. All commands are listed on
[Setup and system administration](./administration#command-line).

```bash
docker compose exec lumberjack php artisan lumberjack:make-admin you@example.com
docker compose exec lumberjack php artisan setup:clear
```

## Inside the image

The container runs four processes, restarts any that stops, and logs them all to its
output.

| Process   |                                                         |
| --------- | ------------------------------------------------------- |
| web       | FrankenPHP on port 8080, forwards `/app` to Reverb      |
| reverb    | WebSockets, on 127.0.0.1:8081 inside the container only |
| horizon   | Queue workers                                           |
| scheduler | `schedule:work`                                         |

Horizon runs two groups of workers:

- **Ingest** workers take the `ingest` queue (`HORIZON_INGEST_PROCESSES`).
- **Background** workers take `alerts`, `default` and `health`, in that order
  (`HORIZON_BACKGROUND_PROCESSES`).

Reverb needs no settings. `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET`
override its generated credentials.
