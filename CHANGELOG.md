# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

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

[Unreleased]: https://github.com/ExeQue/lumberjack/compare/v0.3.1...HEAD
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
