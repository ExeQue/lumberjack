<p align="center"><img src="./art/cover.jpg" alt="Lumberjack - every layer of your Laravel app, ring by ring: errors, performance and logs on your own server" width="100%"></p>

# Lumberjack

Self-hosted error tracking, logs and performance monitoring for Laravel.

- **Issues:** exceptions, browser errors and error logs grouped into issues, with
  occurrences, stack traces, users affected, regressions, escalation detection,
  labels, notes, watching and history.
- **Performance:** routes with Apdex, Web Vitals by device, queries (with the line that ran
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
- [Status screens](docs/screens.md): dashboards for TVs, pairing, displays and
  share links.
- [Metrics API](docs/metrics-api.md): read figures from Grafana, Prometheus or a
  script, and public stats.
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

[Monocle](https://github.com/ExeQue/laravel-monocle) profiles every request, job and
command while `APP_DEBUG` is on: the round button in the bottom right corner of a page
opens its captures, and `/_monocle` is the dashboard. For a coding agent, add its MCP
server (`php artisan mcp:start monocle`) to your client.

Checks, as CI runs them:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --parallel
npm run check
npm run types:check
```

### Demo data

`php artisan migrate --seed` (or `php artisan migrate:fresh --seed`) fills a fresh
database in about half a minute. Every password is `password`.

- **Local Team** with the **Demo** project for the SDK workbench: its server token,
  public key and three MCP agent tokens are fixed (the seeder prints them), so a
  fresh seed keeps the workbench and local MCP clients working. `admin@lumberjack`
  owns it and administers this Lumberjack; `sofie@lumberjack` is an admin and
  `jonas@lumberjack` a member. `eve@lumberjack` and `noah@lumberjack` are in
  **Acme Agency** and cannot see Demo.
- The **showcase**: six projects with a month of believable data, made to show
  every page and to take the screenshots from.
    - Local Team: **Storefront** (a webshop on `shop.example.com`), **Orders API** (a
      SaaS API on `api.example.com`), **Marketing Site** and **Fulfillment Worker** (a
      queue and schedule worker without web traffic), joined by `maja@lumberjack`
      (admin), `oskar@lumberjack` and `ida@lumberjack` (members).
    - Acme Agency: **Acme Booking**, with `clara@lumberjack` (admin).
    - **Northwind Labs**: **Customer Portal**, with `freja@lumberjack` (owner),
      `emil@lumberjack` (admin) and `asta@lumberjack` (member).
    - In them: releases over 30 days, with one bad Storefront release that broke
      checkout and was rolled back; traffic that follows the day and the week, as
      exact per-minute counts for the last week and sampled request runs with
      queries (an N+1 on the product page, a slow sales report), spans and web
      vitals; about 30 issues in every status, with assignees, labels, notes that
      mention people, watchers and an escalating one right now; a week of job runs,
      queue sizes with one backlog, scheduled tasks that failed or were missed and
      commands; two weeks of health results with outages, a check recovering, one
      down and one stale; agents with a day of host samples; log lines per channel;
      alert channels (placeholders that reach nobody) with what they sent; status
      screens from the templates and a TV showing two of them; retention settings
      for the application, a team and a project.

The data is laid out relative to the minute it is seeded in and is the same on
every run. The showcase only adds: `php artisan db:seed --class=ShowcaseSeeder`
on a database in use creates the showcase people and projects that are missing and
fills those, leaving everything else as it was. The health checks Lumberjack runs
point at placeholder hosts, so after a couple of hours they turn red when the
scheduler runs them.

The screenshots in `docs/screenshots` are taken from a running Lumberjack with
Puppeteer:

```bash
npx puppeteer browsers install chrome   # once
npm run docs:screenshots                # every screenshot
npm run docs:screenshots -- issues jobs # only these
```

It signs in at `LUMBERJACK_URL` (default `https://lumberjack.test`) as `DOCS_EMAIL`
and `DOCS_PASSWORD` (default the seeded `admin@lumberjack` / `password`) and shows the
project named `DOCS_PROJECT` (default the seeded `Storefront`). It only opens pages
and dialogs; it never saves anything. Set `CHROME_PATH` to use an installed Chrome
instead of the one Puppeteer downloads.

The committed images are reduced to 256 colors with ImageMagick, about a third of the
size:

```bash
for f in docs/screenshots/*.png; do convert "$f" -dither None -colors 256 "PNG8:$f"; done
```
