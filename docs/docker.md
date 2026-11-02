# Running Lumberjack with Docker

One image runs everything Lumberjack needs: the web server, Reverb for live
updates, Horizon for the queues and the scheduler. Postgres and Redis run beside
it in `compose.yaml`. You put a reverse proxy with TLS in front of one port.

```
reverse proxy (TLS) ──► :8080 lumberjack ──► postgres
                                         └─► redis
```

## Start

1. Copy the compose file and the example settings:

   ```bash
   curl -O https://raw.githubusercontent.com/exeque/lumberjack/main/compose.yaml
   curl -o .env.docker https://raw.githubusercontent.com/exeque/lumberjack/main/.env.docker.example
   ```

2. Generate an application key and put it in `.env.docker` as `APP_KEY`:

   ```bash
   docker run --rm ghcr.io/exeque/lumberjack php artisan key:generate --show
   ```

3. Fill in `APP_URL` (the address people open Lumberjack on) and `DB_PASSWORD`.

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

## Settings

Everything is set through environment variables in `.env.docker`.

| Variable | Default | |
|---|---|---|
| `APP_URL` | (required) | The public address, e.g. `https://lumberjack.example.com` |
| `APP_KEY` | (required) | Encryption key. Keep it: changing it signs everyone out and breaks stored secrets |
| `DB_PASSWORD` | (required) | Password of the bundled Postgres |
| `LUMBERJACK_SETUP_TOKEN` | generated | Locks first-run setup. Without one, a token is generated on first start, printed in the log and kept in `storage/app/setup/token` |
| `LUMBERJACK_PORT` | `8080` | Port on the host |
| `HORIZON_INGEST_PROCESSES` | `10` | Most workers for incoming telemetry |
| `HORIZON_BACKGROUND_PROCESSES` | `4` | Most workers for everything else (alerts, pruning) |
| `MAIL_*` | | SMTP for invitations, password resets and e-mail alerts |
| `TRUSTED_PROXIES` | private ranges | Proxies whose `X-Forwarded-*` headers are believed; `*` for any |
| `LUMBERJACK_MIGRATE` | `true` | Run database migrations on start |

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

If the proxy does not run on the same host or in a private network, set
`TRUSTED_PROXIES` to its address.

## Data and backups

| Volume | Holds |
|---|---|
| `postgres-data` | Everything Lumberjack records. Back this up: `docker compose exec postgres pg_dump -U lumberjack lumberjack > backup.sql` |
| `redis-data` | Queues and cache; Lumberjack recovers without it |
| `lumberjack-storage` | The setup flag and token, uploaded files |

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

| Process | |
|---|---|
| web | FrankenPHP on port 8080; forwards `/app` to Reverb |
| reverb | WebSockets, on 127.0.0.1:8081 inside the container only |
| horizon | Queue workers |
| scheduler | `schedule:work` |

Reverb's credentials are derived from `APP_KEY`, so there is nothing to set for
them. Its HTTP API is not reachable from outside the container.
