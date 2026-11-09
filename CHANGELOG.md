# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

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

[Unreleased]: https://github.com/ExeQue/lumberjack/compare/v0.1.5...HEAD
[0.1.5]: https://github.com/ExeQue/lumberjack/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/ExeQue/lumberjack/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/ExeQue/lumberjack/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/ExeQue/lumberjack/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/lumberjack/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/lumberjack/releases/tag/v0.1.0
