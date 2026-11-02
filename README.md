<p align="center"><img src="./art/cover.jpg" alt="Lumberjack - every layer of your Laravel app, ring by ring: errors, performance and logs on your own server" width="100%"></p>

# Lumberjack

Self-hosted error tracking, logs and performance monitoring for Laravel.

- **Issues:** exceptions and error logs grouped into issues, with occurrences,
  stack traces, regressions, escalation detection, labels, notes and history.
- **Performance:** routes, queries (with the line that ran them and N+1 hints),
  jobs, scheduled tasks and commands.
- **Logs:** searchable, live.
- **Alerts:** e-mail, Discord and signed webhooks, per project.
- **MCP:** agents can triage issues, write notes and open repository issues.

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
