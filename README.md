<p align="center"><img src="./art/cover.jpg" alt="Lumberjack - every layer of your Laravel app, ring by ring: errors, performance and logs on your own server" width="100%"></p>

# Lumberjack

Self-hosted error tracking, logs and performance monitoring for Laravel.

- **Issues:** exceptions, browser errors and error logs grouped into issues, with
  occurrences, stack traces, users affected, regressions, escalation detection,
  labels, notes, watching and history.
- **Performance:** routes with Apdex and Web Vitals, queries (with the line that ran
  them and N+1 hints), jobs and queues, scheduled tasks, commands and traces.
- **Releases:** which deploy brought which errors and slowdowns.
- **Health:** checks the app reports, and HTTP, SSL, TCP, DNS and heartbeat checks
  Lumberjack runs, with outages and uptime.
- **Logs:** searchable, live.
- **Alerts:** email, Slack, Microsoft Teams, Discord, Telegram, ntfy, Pushover and
  signed webhooks, with team rules and project overrides.
- **Retention:** per kind of data, on the application, team and project level.
- **MCP:** agents can triage issues, write notes, open repository issues and draft
  pull requests.

Every page updates live as data arrives.

![The overview of a project: requests, error rate, p95, failed jobs, open issues and health checks](docs/screenshots/overview.png)

Applications send to Lumberjack with the Laravel SDK,
[`exeque/laravel-lumberjack`](https://github.com/ExeQue/laravel-lumberjack).

## Run it

One Docker image runs the web server, live updates (Reverb), the queues (Horizon)
and the scheduler. Postgres and Redis run beside it with Docker Compose, and a
reverse proxy with TLS goes in front of one port.

```bash
docker compose --env-file .env.docker up -d
```

The whole setup, the compose file included, is in [docs/docker.md](docs/docker.md).

## Documentation

- [Running Lumberjack with Docker](docs/docker.md): installation, settings,
  reverse proxy, backups and updates.
- [Setup and system administration](docs/administration.md): first-run setup,
  the setup token, recovery, administrators and the command line.
- [Teams and accounts](docs/teams.md): members, roles, invitations, your profile,
  two-factor authentication and notifications.
- [Projects](docs/projects.md), [Issues](docs/issues.md), [Alerts](docs/alerts.md),
  [Performance and traces](docs/performance.md), [Releases](docs/releases.md),
  [Health](docs/health.md), [Finding things](docs/finding-things.md) and
  [MCP](docs/mcp.md): using it.
- [Ingest and API](docs/api.md): the endpoints SDKs and agents send to, and their
  limits.

## Development

Lumberjack is a Laravel 13 application with an Inertia and React frontend. It
needs PHP 8.5, Node 22, Postgres and Redis.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
```

Checks, as CI runs them:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --parallel
npm run check
npm run types:check
```

The screenshots in `docs/screenshots` are taken from a running Lumberjack with
Puppeteer:

```bash
npx puppeteer browsers install chrome   # once
npm run docs:screenshots                # every screenshot
npm run docs:screenshots -- issues jobs # only these
```

It signs in at `LUMBERJACK_URL` (default `https://lumberjack.test`) as `DOCS_EMAIL`
and `DOCS_PASSWORD` (default the seeded `admin@lumberjack` / `password`) and shows the
project named `DOCS_PROJECT` (default `Demo`). It only opens pages and dialogs; it
never saves anything. Set `CHROME_PATH` to use an installed Chrome instead of the one
Puppeteer downloads. Fill the project with data first, for example with the buttons
of the SDK's workbench.

The committed images are reduced to 256 colors with ImageMagick, about a third of the
size:

```bash
for f in docs/screenshots/*.png; do convert "$f" -dither None -colors 256 "PNG8:$f"; done
```
