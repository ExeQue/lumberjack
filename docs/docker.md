# Running Lumberjack with Docker

One image runs everything Lumberjack needs: the web server, Reverb for live
updates, Horizon for the queues and the scheduler. Postgres and Redis run beside
it in `compose.yaml`. You put a reverse proxy with TLS in front of one port.

```
reverse proxy (TLS) ──► :8080 lumberjack ──► postgres
                                         └─► redis
```

## Start

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
            image: ghcr.io/exeque/lumberjack:latest
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
            volumes:
                - lumberjack-storage:/app/storage
            # Lets DB_HOST / REDIS_HOST=host.docker.internal reach services on this server.
            extra_hosts:
                - 'host.docker.internal:host-gateway'
            depends_on:
                postgres:
                    condition: service_healthy
                redis:
                    condition: service_healthy

        postgres:
            image: postgres:18-alpine
            restart: unless-stopped
            environment:
                POSTGRES_DB: ${DB_DATABASE:-lumberjack}
                POSTGRES_USER: ${DB_USERNAME:-lumberjack}
                POSTGRES_PASSWORD: ${DB_PASSWORD:?set DB_PASSWORD}
            volumes:
                - postgres-data:/var/lib/postgresql
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
            image: redis:7-alpine
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
        lumberjack-storage:
        postgres-data:
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

    Also fill in `APP_URL` (the address people open Lumberjack on) and
    `DB_PASSWORD`.

4. Start it:

    ```bash
    docker compose --env-file .env.docker up -d
    ```

5. Point your reverse proxy at port 8080 (see below).

6. Read the setup token from the log and open `APP_URL/setup`:

    ```bash
    docker compose logs lumberjack | grep -A2 'setup token'
    ```

    Setup creates the first administrator with credentials of your own choosing.
    See [administration.md](administration.md).

### While the image is private

The image on GHCR is private for now. Sign in to the registry before steps 3
and 4, with a GitHub token that has the `read:packages` scope:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
```

## Settings

Everything is set through environment variables in `.env.docker`.

| Variable                       | Default       |                                                                                                                                   |
| ------------------------------ | ------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `APP_URL`                      | (required)    | The public address, e.g. `https://lumberjack.example.com`                                                                         |
| `APP_KEY`                      | (required)    | Encryption key. Keep it: changing it signs everyone out and breaks stored secrets                                                 |
| `DB_PASSWORD`                  | (required)    | Password of the bundled Postgres                                                                                                  |
| `LUMBERJACK_SETUP_TOKEN`       | generated     | Locks first-run setup. Without one, a token is generated on first start, printed in the log and kept in `storage/app/setup/token` |
| `LUMBERJACK_PORT`              | `8080`        | Port on the host                                                                                                                  |
| `HORIZON_INGEST_PROCESSES`     | `2`           | Most workers for incoming telemetry                                                                                               |
| `HORIZON_BACKGROUND_PROCESSES` | `2`           | Most workers for everything else (alerts, pruning)                                                                                |
| `MAIL_*`                       |               | SMTP for invitations, password resets and e-mail alerts; see [Mail](./administration#mail) for the two sender addresses           |
| `TRUSTED_PROXIES`              | Docker ranges | Proxies whose `X-Forwarded-*` headers are believed: `127.0.0.1,172.16.0.0/12`. Comma separated; `*` for any (not recommended)     |
| `LUMBERJACK_MIGRATE`           | `true`        | Run database migrations on start                                                                                                  |

### Your own Postgres or Redis

Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (or
`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`) to the server you already run, and
remove the `postgres` or `redis` service and its `depends_on` entry from
`compose.yaml`. Lumberjack needs Postgres 16 or newer and Redis 7 or newer.

`localhost` does not work as the host: inside the container it is the container
itself. For a Postgres or Redis on the same server, use `host.docker.internal`
(compose.yaml maps it to the server). The service has to accept connections from
the Docker network:

- Postgres: `listen_addresses` includes the Docker bridge (e.g. `'*'` or
  `172.17.0.1`), and `pg_hba.conf` allows the Docker subnet, e.g.
  `host lumberjack lumberjack 172.16.0.0/12 scram-sha-256`.
- Redis: `bind` includes the bridge address, and `protected-mode` is off or a
  password is set (`REDIS_PASSWORD`).

Alternatively, `network_mode: host` on the `lumberjack` service makes `localhost`
the server itself (Linux only). Lumberjack then listens on port 8080 of the
server directly and `ports:` is ignored.

## Reverse proxy

Live updates use WebSockets on the same host and port as the app (path `/app`).
The proxy has to pass the `Upgrade` header through and set `X-Forwarded-Proto`.

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
headers are believed. The default covers a proxy on the same server or in a Docker
network (`127.0.0.1,172.16.0.0/12`). If your proxy connects from somewhere else, set it
to the proxy's address. Keep the list short: anyone connecting from a trusted address
can claim any client IP, which defeats the login and ingest rate limits. Links in mails
and alerts always use `APP_URL`, whatever host a request names.

## Data and backups

| Volume               | Holds                                                                                                                     |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `postgres-data`      | Everything Lumberjack records. Back this up: `docker compose exec postgres pg_dump -U lumberjack lumberjack > backup.sql` |
| `redis-data`         | Queues and cache; Lumberjack recovers without it                                                                          |
| `lumberjack-storage` | The setup flag and token, uploaded files                                                                                  |

## Updating

```bash
docker compose pull
docker compose --env-file .env.docker up -d
```

Migrations run on start. To run them yourself instead, set
`LUMBERJACK_MIGRATE=false` and run
`docker compose exec lumberjack php artisan migrate --force`.

## Commands

Artisan runs inside the container:

```bash
docker compose exec lumberjack php artisan lumberjack:make-admin you@example.com
docker compose exec lumberjack php artisan setup:clear
```

## Inside the image

Supervisord runs four processes and restarts any that stops. All of them log to
the container's output (`docker compose logs lumberjack`).

| Process   |                                                         |
| --------- | ------------------------------------------------------- |
| web       | FrankenPHP on port 8080; forwards `/app` to Reverb      |
| reverb    | WebSockets, on 127.0.0.1:8081 inside the container only |
| horizon   | Queue workers                                           |
| scheduler | `schedule:work`                                         |

Reverb's credentials are derived from `APP_KEY`, so there is nothing to set for
them. Its HTTP API is not reachable from outside the container.
