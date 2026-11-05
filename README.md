<p align="center"><img src="./art/cover.jpg" alt="Lumberjack - every layer of your app, ring by ring: errors, performance and logs on your own server" width="100%"></p>

# Lumberjack

Lumberjack watches your apps from your own server, whatever they are built with. It collects errors, logs and
performance data, runs health checks, sends alerts, shows status screens on a TV and lets
AI agents work with your issues over MCP.

![The overview of a project: requests, error rate, p95, failed jobs, open issues and health checks](docs/screenshots/overview.png)

## What you can do with it

- **Know when production breaks.** Exceptions, browser errors and error logs become issues,
  and an alert reaches you by email, Slack, Microsoft Teams, Discord, Telegram, ntfy,
  Pushover or a webhook.
- **Find out why a page is slow.** Response times, Web Vitals, queries and N+1 hints per
  route, down to a single trace.
- **Check a release.** See which deploy brought new errors or slowdowns.
- **Watch background work.** See which jobs, scheduled tasks and commands failed or never
  ran, and which queues back up.
- **Check that your sites are up.** Run HTTP, SSL, TCP, DNS and heartbeat checks, and track
  outages and uptime.
- **Search your logs**, or watch them live.
- **Put a status screen on the wall.** Design a dashboard and show it on any TV.
- **Let an AI agent read your errors.** Agents can triage issues, write notes, open issues in
  your repository and draft pull requests.

![An issue with its stack trace, occurrences and affected users](docs/screenshots/issue.png)

![A status screen on a TV](docs/screenshots/screen-kiosk.png)

## Get started

Lumberjack runs as one Docker image, with Postgres and Redis beside it:

```bash
docker compose --env-file .env.docker up -d
```

[Running Lumberjack with Docker](docs/docker.md) has the whole setup. Your apps send data
through a [library](docs/libraries.md), such as
[`exeque/laravel-lumberjack`](https://github.com/ExeQue/laravel-lumberjack) for Laravel, or
straight to the [ingest API](docs/api.md) from any language or framework.

## Guides

- [Get alerted when production breaks](docs/guides/alerting.md)
- [Find out why a page is slow](docs/guides/slow-pages.md)
- [Check a release](docs/guides/releases.md)
- [Watch queues, jobs and scheduled tasks](docs/guides/background-work.md)
- [Put a status screen on a TV](docs/guides/status-tv.md)
- [Let an AI agent work with your errors](docs/guides/ai-agents.md)
- [Show Lumberjack data in Grafana](docs/guides/grafana.md)

## Reference

- [Running Lumberjack with Docker](docs/docker.md): installation, settings, reverse proxy,
  backups and updates.
- [Libraries](docs/libraries.md): the libraries your apps send data with.
- [Setup and administration](docs/administration.md): first-run setup, recovery,
  administrators and the command line.
- [Teams and accounts](docs/teams.md): members, roles, invitations, your profile and
  two-factor authentication.
- [Projects](docs/projects.md)
- [Issues](docs/issues.md)
- [Alerts](docs/alerts.md)
- [Performance and traces](docs/performance.md)
- [Releases](docs/releases.md)
- [Health](docs/health.md)
- [Finding things](docs/finding-things.md): search, filters and the command palette.
- [Status screens](docs/screens.md) and [widgets](docs/widgets.md)
- [MCP](docs/mcp.md)
- [Metrics API](docs/metrics-api.md): read figures from Grafana, Prometheus or a script.
- [Ingest and API](docs/api.md): the endpoints SDKs and agents send to, and their limits.
- [Development](docs/development.md): run Lumberjack from source, demo data and screenshots.
