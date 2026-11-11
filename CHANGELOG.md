# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

### Added

- **OpenTelemetry**: apps with an OpenTelemetry SDK, and the OpenTelemetry Collector's `otlphttp` exporter, send traces and logs over OTLP/HTTP to `/api/v1/opentelemetry` with the project's server token, in protobuf or JSON, plain or gzip. Server spans become requests, consumer spans job runs, database spans queries, outgoing HTTP and Redis spans spans of the trace, `exception` span events issues with frames read from the stack trace, and log records log lines (at error and above also issues); the resource's environment, `service.version` and `host.name` set the environment, release and host. Same limits as the agent's batches, at most 5000 events an export; metrics are taken and dropped. See the new OpenTelemetry docs page.
- **Weekly summary** alert rule, off by default: once a week, on the day and at the time set on the rule (Monday 08:00 by default, in the app's time zone), each project's past 7 days against the 7 before: requests, error rate, p95 and Apdex, new issues and regressions, the most frequent issues, the routes that got slower, uptime and outages, maintenance windows and overruns, failed jobs and missed scheduled tasks, and the releases first seen in the week with the one that brought the most new issues. Like the other rules it is a team rule with project overrides; each channel gets one message with a section per project routed to it, projects with nothing in the week left out. Mail gets the full report, chat channels a line per project with a link, and webhooks the full report as JSON with `event: weekly_summary`.
- **Maintenance windows** per project and environment, planned on the Health page or over MCP, or reported by the app with a `maintenance` ingest event (the Laravel library sends one on `artisan down` and `artisan up`). During a window health checks and queue backlogs are muted up to its expected end, down time does not count against uptime or the SLO widget up to the expected end, errors are flagged **Under maintenance** (a filter on the issues list), and other alerts are still sent, marked as sent during maintenance, with a `maintenance` object in webhooks. Past its expected end the new **Maintenance overrun** rule fires at once and every 30 minutes until the window ends, then says how long it ran over; the expected length and the repeat are project settings. Windows show as bands on the charts, in the health history, on the Releases page and on screens.
- **Source maps** for browser errors: upload a bundle's map to `POST /api/ingest/sourcemaps` (with `php artisan lumberjack:sourcemaps` from the Laravel library, or curl), and browser stack traces show the original files, lines and code. Issues group on the original frames, so they stay the same across builds. The minified frames are a toggle away on the issue page; maps are listed under the project's settings and kept 90 days, or longer while they belong to one of the last 10 releases.
- **GitHub** connector: one repository per connection, read with its own read-only fine-grained token (Metadata, Pull requests, Actions, Contents), which connecting checks permission by permission. Its page shows open pull requests, those waiting for review, pull requests merged and workflow runs failed against the period before, open issues, CI on the default branch and the latest release, with tabs for pull requests, Actions and releases. It also counts commits (over time, by author, the latest ones and the top contributors), pull request lead time, stars, forks and watchers. With the optional Issues and security alert permissions it shows issues opened and closed, open bugs, and open Dependabot, code scanning and secret scanning alerts. Every list loads more on demand, and each release opens to its notes. A connection can follow another branch than the default one for CI and commits. Each connection picks which key figures its page shows, and the figures link to the matching page on GitHub. The same figures are data sources for screens.
- **Vipps MobilePay** connector: one ledger per connection, read from the Report API with a sales unit's API keys (client ID, client secret, subscription key, merchant serial number), which connecting uses to look up the ledger the sales unit settles to. Its page shows revenue, transactions, refunds, fees and payouts over the period against the period before, and the revenue of each day; whole days only, as Vipps MobilePay books its ledger per day. It also shows the average order, refund and fee rates, the balance waiting to be paid out, sales by hour and weekday, revenue per sales unit, and lists of the latest transactions, the largest sales and the payouts, loading more on demand. Days are fetched in parallel. The same figures are data sources for screens.

### Changed

- **Connectors** has a page of its own, always in the project's sidebar: every connector as a card with its icon, what it reads, its tags and its connections, connected ones first, with a search and tag filters kept in the address. The chevron beside the entry folds out the connected ones. **Data sources** no longer lists the connectors, and links to the page instead.

## [0.7.0] - 2026-11-11

### Added

- **Data source value** health check: holds a number from a data source against a warn and a fail limit (`>`, `>=`, `<`, `<=`, `=` or `!=`), judged after each fetch. JSON and Prometheus sources are read at a path; Umami, mosparo and connected apps' number sources over a period, which the check keeps pulled. It fails when the source fails, is not fetched in time or has no number, and shows the value it read. The source is picked in the same searchable, grouped picker as in the screen designer. The MCP tools set it up too.
- **About** shows the changelog the install was built with, by release, with a sticky row of versions to jump to.

### Changed

- A grouped sidebar entry folds its pages out from a chevron beside it, not on hover, so the sidebar no longer changes height under the mouse.
- With a single connector, the sidebar shows it as its own entry instead of under **Connectors**.
- Periods a health check reads are pulled before those only screens show when an app's pulls are rationed, and count on the **Data sources** page as being read: a failing one shows as failing there.

## [0.6.1] - 2026-11-10

### Changed

- Dependencies updated, among them Laravel 13.42, Inertia 3.11 and Tailwind 4.3.4.

## [0.6.0] - 2026-11-10

### Added

- **Connectors**: Lumberjack reads outside services itself, listed under **Data sources**. The first are Umami, on Umami Cloud with an API key or self-hosted with an API key or a login, picking the website from your own and your teams', and mosparo. Each connector can be connected as often as needed, such as several Umami websites in one project.
- An **Umami** page per connected project: visitors, visits, page views, bounce rate and time per visit against the period before, traffic with releases marked, the busiest hours of the week, notes kept as Umami annotations, and pages (with their domain and LCP), sources, audience and events to filter by with a click, search across the period and load more of. The tab, website and view are kept in the address.
- UTM values can be given names, shown on the page, in screens and as the name of a campaign's data source.
- Umami's figures, lists, custom events and UTM campaigns as data sources for screen widgets.
- A **mosparo** page per connected mosparo project: valid and spam submissions, the spam rate and the requests delayed or blocked over 1, 7 or 30 days against the days before, and those submissions per day. Its counts, spam rate and submissions per day are data sources for screens; as mosparo counts whole days, they offer no hour.
- Both connector pages keep their key figures above their tabs and a **Connection** tab, for team owners and admins, to connect another or disconnect.
- On **Data sources**, each connection lists its sources below it, folded away until opened.

### Changed

- A service connection's credentials and settings are kept in one encrypted column.

## [0.5.1] - 2026-11-10

### Added

- **Connect a client** on the team page, and the dialog with a new token, show the setup for Claude Code, Codex, Cursor, VS Code and Claude Desktop, with the token kept in an environment variable or the client's secret store instead of a committed file.

## [0.5.0] - 2026-11-10

### Added

- MCP tokens get a permission per area (issues, telemetry, health checks, scheduled tasks, alerts, data sources, screens, project settings, team) at None, Read, Write or Manage, with presets, single tools turned on or off, and changes per project.
- A token can cover all projects, including ones created later.
- Tokens can be edited without making a new one.
- 55 new MCP tools: set up health checks, alert rules, data sources and project settings, manage labels and bulk-change issues, read releases, browser errors, queues, hosts and metric series, and manage screens and displays. `list_my_permissions` tells an agent what its token may do where.

### Changed

- The token's read or write scope and **Can design screens** are replaced by permissions. Existing tokens keep what they could do, and Read also reaches the new read tools.
- The metrics API reads only the projects where the token reads telemetry, and lists issues only where it reads issues.
- Agents only see the MCP tools their token may use.

## [0.4.1] - 2026-11-09

### Added

- A searchable tree select for data sources, widget sources and time zones, as deep as the groups go.
- The data sources page groups connected sources by what their app files them under with `#[Group]`.

### Fixed

- Release and other summary alerts on Discord link back with a button, like custom alerts.
- A release alert no longer says "First seen just now".
- Changing a scheduled command's description no longer makes a new task and a false "missed" alert. The task keeps its runs under the new name; its issues start fresh.

## [0.4.0] - 2026-11-09

### Added

- Agents can design status screens over MCP: read screens, widgets and data sources, change them live, and open a preview link that expires after 5 minutes. Changing needs a token with **Can design screens**, and only within the token's projects.
- **History** in the screen designer: look at one of the last 20 versions and go back to it.
- The designer notices when a screen was changed somewhere else, and saving on top of it is refused.
- The **Data source** picker in the designer groups connected sources by what their app files them under with `#[Group]`, within each project.

### Changed

- A chart with **Y axis starts at zero** off now fits its axis to the values drawn, at both ends.

## [0.3.1] - 2026-11-09

### Fixed

- A list showing a data source read a raw translation key in its corner.

## [0.3.0] - 2026-11-09

### Added

- **Unit** on a data source value: percent, durations, bytes, bits per second, or your own with a prefix, a word and **Divide by**.
- **Resolution** on a chart of a connected data source.
- Each connected app has a **Time zone**, and Lumberjack tells it the resolution and time zone a series is grouped by.

### Changed

- **External JSON** is called **Data source** in the designer.

## [0.2.0] - 2026-11-09

### Added

- Connected data sources: connect an app using the Laravel package under **Data sources**, and Lumberjack pulls the figures, series, lists and tables it offers, to show on a screen. Pulled only over https, signed with a pull token per connection, one source per call, and only for what a screen shows.

### Changed

- The name of an HTTP check without one of its own is its host, not its whole address.

### Fixed

- Health check chips on a screen could read just "HTTP:" or "SSL:" with no address.

## [0.1.10] - 2026-11-09

### Added

- Health checks Lumberjack runs: **Domain expiry** and **SMTP server**, and HTTP checks can require a value at a JSON path.
- About shows system administrators the size of the database.
- Schedule can sort by next run first.

### Changed

- A reported health check without a name of its own reads as its slug in words: `queue:default` is shown as **Queue: Default**, with the slug under it.
- The name of a check Lumberjack runs is optional, and comes from its settings: an SSL check on example.com is **SSL: example.com**.
- The details of a health check, route, release, job and command open in a dialog instead of under the list.

## [0.1.9] - 2026-11-09

### Added

- System administrators are told when a newer release is out, checked every six hours or right away with **Check now** on About. `LUMBERJACK_UPDATE_CHECK=false` turns it off.
- About links to the changelog.

## [0.1.8] - 2026-11-09

### Added

- **Copy as Markdown** on an issue copies its details, latest stacktrace, context, notes and history, ready to hand to an agent.

### Fixed

- A long release hash on an issue is cut to fit its card, with the full value on hover.

## [0.1.7] - 2026-11-09

### Changed

- The Schedule page cuts a long command to one line and shows the description under it in a muted color, also the description of the artisan command itself. Needs `exeque/laravel-lumberjack` 0.1.5.

## [0.1.6] - 2026-11-09

### Changed

- A missed scheduled task says that Lumberjack received no record of the run, not that the scheduler never ran it.

## [0.1.5] - 2026-11-09

### Changed

- The Schedule page shows the command a task runs, with its description under it. Needs `exeque/laravel-lumberjack` 0.1.3.
- A task's runs open in a dialog.

### Fixed

- Telegram buttons on custom alerts use the primary and danger styles.
- A custom alert with a link Discord or Slack would refuse no longer loses the message: link labels are cut at 75 characters and URLs over 512 are dropped.
- A scheduled artisan command with a description no longer makes two issues when it fails.

## [0.1.4] - 2026-11-09

### Added

- Custom alerts: apps send alerts with a title, message, level, fields, tables and links to named topics, whose channels are picked on the Alerts page. Works on Discord, Slack, Teams, Telegram, ntfy, Pushover, webhooks and mail.
- A project can add channels to a team's topic, or customize it for that project only.
- **Sent alerts** on the Alerts page lists the latest custom alerts and how each channel went.
- New release alert: sent when an environment reports a release for the first time.
- Host charts on the overview: CPU, memory, disk, response times, exceptions and failed jobs, one line per host, with focus on a host through the legend.

### Changed

- Channels on a rule are picked in a dialog, grouped by type, and the rule shows how many it sends to.
- A new channel is no longer added to alert rules automatically.
- The heartbeat answer lists the event types the server knows under `features`.
- An event of an unknown type is skipped and the rest of its batch kept, instead of rejecting the batch.

### Fixed

- The favicon on the docs site works when the docs are served from the root of a domain.

## [0.1.3] - 2026-11-06

### Changed

- Docker: the database and storage are kept in folders next to `compose.yaml`.

## [0.1.2] - 2026-11-05

No changes to the application. The install script and Docker images are now tested in CI.

## [0.1.1] - 2026-11-05

### Fixed

- Project settings install the Laravel package from Packagist.
- A first install is quiet about containers that are not there yet.

## [0.1.0] - 2026-11-05

First public release.

[Unreleased]: https://github.com/ExeQue/lumberjack/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/ExeQue/lumberjack/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/ExeQue/lumberjack/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/ExeQue/lumberjack/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/ExeQue/lumberjack/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/ExeQue/lumberjack/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/ExeQue/lumberjack/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/ExeQue/lumberjack/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/ExeQue/lumberjack/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/ExeQue/lumberjack/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ExeQue/lumberjack/compare/v0.1.10...v0.2.0
[0.1.10]: https://github.com/ExeQue/lumberjack/compare/v0.1.9...v0.1.10
[0.1.9]: https://github.com/ExeQue/lumberjack/compare/v0.1.8...v0.1.9
[0.1.8]: https://github.com/ExeQue/lumberjack/compare/v0.1.7...v0.1.8
[0.1.7]: https://github.com/ExeQue/lumberjack/compare/v0.1.6...v0.1.7
[0.1.6]: https://github.com/ExeQue/lumberjack/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/ExeQue/lumberjack/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/ExeQue/lumberjack/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/ExeQue/lumberjack/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/ExeQue/lumberjack/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/lumberjack/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/lumberjack/releases/tag/v0.1.0
