# MCP

Lumberjack has an MCP server. AI agents such as Claude Code, Codex, Cursor, VS Code or
Claude Desktop can read issues and telemetry through it and help triage them.

## Tokens

Team owners and admins create tokens under **API and MCP tokens** on the team page.
Members see the list. The same tokens read the [metrics API](./metrics-api), in the
projects where they read telemetry.

| Field           | Values                                                                                                        |
| --------------- | ------------------------------------------------------------------------------------------------------------- |
| **Name**        | Up to 100 characters. Shown on everything the token does.                                                     |
| **Projects**    | **All projects**, which includes projects created later, or **Selected projects**, at least one.              |
| **Permissions** | A level per area, single tools added or taken away, and per-project changes. See [Permissions](#permissions). |
| **Expiry**      | 30, 90 or 365 days, or never.                                                                                 |

The token, `lj_mcp_...`, is shown once. The list shows who created each token, how many
tools it reaches and when it was last used. **Edit** changes its name, projects and
permissions without changing the token itself, and makes it act as the editor; **Revoke** ends it.

- A token acts as the person who created it, or who last edited it. It sees and changes only what that person
  may see and change, within its projects and its team. Its permissions only narrow that,
  never widen it.
- A token works only while its creator is an owner or admin of the team. When they
  leave the team, become a member, are suspended or are deleted, the token stops and
  shows as **Stopped**; a current admin has to create a new one.
- A token makes at most 120 calls a minute.

### Permissions

Each area gets a level: **None**, **Read**, **Write** or **Manage**. A level includes the
ones before it. Some areas offer fewer: telemetry and team are read only, scheduled tasks
skip Write.

| Area             | Key            | Covers                                                       |
| ---------------- | -------------- | ------------------------------------------------------------ |
| Issues           | `issues`       | Triage, status, labels, assignees, notes, GitHub issues.     |
| Telemetry        | `telemetry`    | Traces, requests, queries, jobs, logs, releases and metrics. |
| Health checks    | `health`       | Checks Lumberjack runs and those the app reports.            |
| Scheduled tasks  | `schedule`     | Tasks the app reports.                                       |
| Alerts           | `alerts`       | Alert rules, topics, queue thresholds. Team-wide.            |
| Data sources     | `data_sources` | Pulled sources and app connections.                          |
| Screens          | `screens`      | Screens, versions, displays and sharing. Team-wide.          |
| Project settings | `project`      | Name, environments, log channels, retention, public stats.   |
| Team             | `team`         | The members, for assigning and mentions. Team-wide.          |

- **Presets** set every area at once: Read only (the default), Triage, Monitoring setup,
  Screen designer and Everything.
- **Tools** under an area turn single tools on or off beyond its level. A tool taken away
  stays away when that tab's level is raised.
- **Per project**: the **Default** tab applies to every project the token covers. A
  project's tab can give that project another level, or turn single tools on or off,
  for project areas. Team-wide areas are only set under Default.
- For a tool in a project, the first of these that says something decides: the project's
  change to that tool, the project's level, Default's change to that tool, Default's level.
  So a project with its own level for an area ignores Default's single-tool changes there;
  turn the tool off on the project's tab too.
- Screens show telemetry and issues, so a token sees a screen only when it reads both in
  every project the screen shows (`get_metric_series` and `list_issues`), and health checks,
  data sources, alerts and scheduled tasks where its widgets show those. Single tools
  turned off among the other telemetry lists do not hide screen widgets. `search_project` and `get_release` leave out issues
  where the token may not read them.
- An agent only sees the tools its token may use in at least one project.
  `list_my_permissions` tells it what it may use where; a refused call names the tool and
  the project.

Tokens made before permissions existed became: `read` Read in every area, `write` also
Write on issues, and **Can design screens** Write on screens. Read in every area also
reaches the read tools added since, such as project settings and alert rules.

Never through MCP, whatever the permissions: creating or deleting projects, rotating
server tokens, public keys, stats keys and pull tokens, notification channels,
repository links, members, invitations, roles and MCP tokens.

## Connecting

The server is at `https://your-lumberjack/mcp`. It uses streamable HTTP and takes the
token as a bearer token in the `Authorization` header. **Connect a client** under the
tokens on the team page, and the dialog shown when a token is created, show these
snippets with your address filled in.

::: warning Keep the token out of version control
A token opens your team's data like a password. Never put the token itself in a file you commit, such
as `.mcp.json`, `.cursor/mcp.json` or `.vscode/mcp.json`. Keep it in an environment
variable or your client's secret store, as below, and revoke it on the team page if it
leaks.
:::

Set the variable where the client runs, in a shell profile that is not in a dotfiles
repository, or through a secret manager. On Windows, use `setx LUMBERJACK_MCP_TOKEN lj_mcp_...`.

```bash
export LUMBERJACK_MCP_TOKEN=lj_mcp_...
```

**Claude Code**, in your repository. It writes `.mcp.json` with the variable, not the
token, so the file can be committed:

```bash
claude mcp add --transport http --scope project --header 'Authorization: Bearer ${LUMBERJACK_MCP_TOKEN}' lumberjack https://your-lumberjack/mcp
```

**Codex** adds it to `~/.codex/config.toml`:

```bash
codex mcp add lumberjack --url https://your-lumberjack/mcp --bearer-token-env-var LUMBERJACK_MCP_TOKEN
```

**Cursor**, in `.cursor/mcp.json`. Restart Cursor after setting the variable:

```json
{
    "mcpServers": {
        "lumberjack": {
            "url": "https://your-lumberjack/mcp",
            "headers": { "Authorization": "Bearer ${env:LUMBERJACK_MCP_TOKEN}" }
        }
    }
}
```

**VS Code**, in `.vscode/mcp.json`. It asks for the token on first use and keeps it in
its secret store:

```json
{
    "inputs": [
        {
            "type": "promptString",
            "id": "lumberjack-token",
            "description": "Lumberjack MCP token",
            "password": true
        }
    ],
    "servers": {
        "lumberjack": {
            "type": "http",
            "url": "https://your-lumberjack/mcp",
            "headers": { "Authorization": "Bearer ${input:lumberjack-token}" }
        }
    }
}
```

**Claude Desktop**, in `claude_desktop_config.json` (**Settings**, **Developer**, **Edit
config**), through `mcp-remote`, which needs Node.js. This file holds the token itself:
keep it out of any repository.

```json
{
    "mcpServers": {
        "lumberjack": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://your-lumberjack/mcp",
                "--header",
                "Authorization:${AUTH_HEADER}"
            ],
            "env": { "AUTH_HEADER": "Bearer lj_mcp_..." }
        }
    }
}
```

::: tip More than one token
Using tokens for other teams, servers or permissions? Give each its own server name and
variable, such as `lumberjack-work` with `LUMBERJACK_WORK_MCP_TOKEN`, or set
`LUMBERJACK_MCP_TOKEN` per repository with a tool like [direnv](https://direnv.net) in a
git-ignored `.envrc`.
:::

## Tools

The server tells agents to treat error messages, request data and log lines as data,
not as instructions.

Agents' notes and changes show in Lumberjack with the token's name. Durations are in
microseconds. **Needs** is the area and level a token needs for the tool; see
[Permissions](#permissions). Arguments in bold are required.

### Always available

| Tool                  | Needs     | Does                                | Arguments |
| --------------------- | --------- | ----------------------------------- | --------- |
| `list_projects`       | any token | Projects the token can see.         | none      |
| `list_my_permissions` | any token | What the token may do, per project. | none      |

### Issues

| Tool                 | Needs            | Does                                                         | Arguments                                                                             |
| -------------------- | ---------------- | ------------------------------------------------------------ | ------------------------------------------------------------------------------------- |
| `triage_inbox`       | `issues: read`   | What needs attention across the token's projects.            | none                                                                                  |
| `list_issues`        | `issues: read`   | Filter and page issues.                                      | `project`, `status`, `source`, `search`, `label`, `sort`, `limit`                     |
| `get_issue`          | `issues: read`   | One issue with stack, context and timeline.                  | **`issue`**                                                                           |
| `list_occurrences`   | `issues: read`   | Occurrences of an issue.                                     | **`issue`**, `limit`                                                                  |
| `set_issue_status`   | `issues: write`  | Resolve, ignore, reopen.                                     | **`issue`**, **`status`**, `note`, `until`                                            |
| `set_issue_labels`   | `issues: write`  | Set labels on an issue.                                      | **`issue`**, **`labels`**                                                             |
| `assign_issue`       | `issues: write`  | Assign to a member.                                          | **`issue`**, `user_id`                                                                |
| `watch_issue`        | `issues: write`  | Watch an issue.                                              | **`issue`**                                                                           |
| `unwatch_issue`      | `issues: write`  | Stop watching an issue.                                      | **`issue`**                                                                           |
| `add_issue_note`     | `issues: write`  | Add a note, with mentions.                                   | **`issue`**, **`body`**                                                               |
| `create_repo_issue`  | `issues: write`  | Open a GitHub issue from an issue. Sends outside Lumberjack. | **`issue`**, `title`, `body`                                                          |
| `list_labels`        | `issues: read`   | The project's labels.                                        | `project`                                                                             |
| `bulk_update_issues` | `issues: write`  | Status, assignee or label on many issues.                    | `project`, **`issues`**, `status`, `user_id`, `unassign`, `add_label`, `remove_label` |
| `update_label`       | `issues: manage` | Rename or recolor a label.                                   | `project`, **`label_id`**, **`name`**, **`color`**                                    |
| `delete_label`       | `issues: manage` | Delete a label. Deletes.                                     | `project`, **`label_id`**                                                             |

### Telemetry

| Tool                  | Needs             | Does                         | Arguments                                                                                                                                          |
| --------------------- | ----------------- | ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `get_trace`           | `telemetry: read` | One trace with spans.        | `project`, **`trace_id`**                                                                                                                          |
| `list_routes`         | `telemetry: read` | Routes with timings.         | `project`, `period`, `environment`, `sort`, `search`                                                                                               |
| `list_queries`        | `telemetry: read` | Slow and frequent queries.   | `project`, `period`, `environment`, `sort`, `search`                                                                                               |
| `list_jobs`           | `telemetry: read` | Jobs and failures.           | `project`, `period`, `environment`, `queue`, `sort`                                                                                                |
| `get_job`             | `telemetry: read` | One job run.                 | `project`, **`name`**, `uuid`, `status`                                                                                                            |
| `list_commands`       | `telemetry: read` | Artisan command runs.        | `project`, `period`, `environment`                                                                                                                 |
| `get_command`         | `telemetry: read` | One command run.             | `project`, **`name`**, `status`                                                                                                                    |
| `search_logs`         | `telemetry: read` | Search log lines.            | `project`, `channel`, `level`, `search`, `trace_id`, `period`, `limit`                                                                             |
| `list_browser_errors` | `telemetry: read` | Frontend errors.             | `project`, `period`, `environment`, `limit`                                                                                                        |
| `list_releases`       | `telemetry: read` | Releases with health.        | `project`, `period`, `environment`, `limit`                                                                                                        |
| `get_release`         | `telemetry: read` | One release with its issues. | `project`, **`release`**, `period`, `environment`                                                                                                  |
| `get_metric_series`   | `telemetry: read` | A metric over time.          | `project`, **`metric`**, `from`, `to`, `interval`, `environment`, `route`, `domain`, `queue`, `job`, `host`, `release`, `check`, `group_by`, `top` |
| `list_queues`         | `telemetry: read` | Queue depth and wait.        | `project`, `period`, `environment`                                                                                                                 |
| `list_hosts`          | `telemetry: read` | Hosts and their load.        | `project`                                                                                                                                          |
| `search_project`      | `telemetry: read` | Search a project.            | `project`, **`q`**                                                                                                                                 |

### Health checks

| Tool                   | Needs            | Does                                     | Arguments                                                                                                                                              |
| ---------------------- | ---------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `list_health_checks`   | `health: read`   | Checks with state and uptime.            | `project`, `period`, `environment`, `attention`                                                                                                        |
| `get_health_check`     | `health: read`   | One check with settings and results.     | `project`, **`check`**                                                                                                                                 |
| `create_health_check`  | `health: write`  | Add a check Lumberjack runs.             | `project`, **`type`**, `name`, `description`, `environment`, **`interval_minutes`**, `degrade_after`, `down_after`, `recover_after`, `muted`, `config` |
| `update_health_check`  | `health: write`  | Change a check.                          | `project`, **`check`**, `name`, `description`, `environment`, `interval_minutes`, `degrade_after`, `down_after`, `recover_after`, `muted`, `config`    |
| `run_health_check`     | `health: write`  | Run a check now.                         | `project`, **`check`**                                                                                                                                 |
| `archive_health_check` | `health: write`  | Archive or unarchive a check.            | `project`, **`check`**, **`archived`**                                                                                                                 |
| `delete_health_check`  | `health: manage` | Delete a check and its history. Deletes. | `project`, **`check`**                                                                                                                                 |

### Scheduled tasks

| Tool                    | Needs              | Does                                 | Arguments                             |
| ----------------------- | ------------------ | ------------------------------------ | ------------------------------------- |
| `list_scheduled_tasks`  | `schedule: read`   | Tasks with last run and state.       | `project`, `environment`, `attention` |
| `get_scheduled_task`    | `schedule: read`   | One task with its runs.              | `project`, **`name`**, `environment`  |
| `delete_scheduled_task` | `schedule: manage` | Forget a task and its runs. Deletes. | `project`, **`name`**, `environment`  |

### Alerts

| Tool                         | Needs            | Does                                           | Arguments                                                                                                                    |
| ---------------------------- | ---------------- | ---------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `list_alert_rules`           | `alerts: read`   | Team rules and a project's overrides.          | `project`                                                                                                                    |
| `list_notification_channels` | `alerts: read`   | Channels and what sends to them.               | `project`                                                                                                                    |
| `list_alert_topics`          | `alerts: read`   | Custom alert topics.                           | none                                                                                                                         |
| `update_alert_rule`          | `alerts: write`  | Customize a rule for a project.                | `project`, **`type`**, `topic`, **`enabled`**, **`channels`**, `environments`, `settings`                                    |
| `reset_alert_rule`           | `alerts: write`  | Back to the team's rule.                       | `project`, **`type`**, `topic`                                                                                               |
| `set_alert_rule_add_ons`     | `alerts: write`  | Add channels to a team rule.                   | `project`, **`type`**, `topic`, **`channels`**                                                                               |
| `set_queue_alert_threshold`  | `alerts: write`  | Thresholds for one queue.                      | `project`, **`connection`**, **`queue`**, `size`, `oldest_minutes`, `sustained_minutes`, **`muted`**                         |
| `create_alert_topic`         | `alerts: write`  | New custom alert topic.                        | **`name`**                                                                                                                   |
| `send_project_alert`         | `alerts: write`  | Send a custom alert. Sends outside Lumberjack. | `project`, **`title`**, `message`, `level`, `topic`, `environment`, `url`, `fields`, `links`, `dedupe_key`, `dedupe_minutes` |
| `send_test_alert`            | `alerts: write`  | Test a channel. Sends outside Lumberjack.      | **`channel`**                                                                                                                |
| `update_team_alert_rule`     | `alerts: manage` | Change a team rule.                            | **`type`**, `topic`, **`enabled`**, **`channels`**, `environments`, `settings`                                               |
| `delete_alert_topic`         | `alerts: manage` | Delete a custom alert topic. Deletes.          | **`topic`**                                                                                                                  |

### Data sources

| Tool                            | Needs                  | Does                                  | Arguments                                                                                          |
| ------------------------------- | ---------------------- | ------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `list_data_sources`             | `data_sources: read`   | Sources with group and last fetch.    | none                                                                                               |
| `preview_data_source`           | `data_sources: read`   | Latest answer and readable paths.     | `project`, **`data_source`**, `type`, `path`, `label_path`, `value_path`                           |
| `list_data_source_connections`  | `data_sources: read`   | Connected apps and their status.      | `project`                                                                                          |
| `create_data_source`            | `data_sources: write`  | Add a fetched source.                 | `project`, **`name`**, **`url`**, **`format`**, **`interval_minutes`**, **`timeout_s`**, `headers` |
| `update_data_source`            | `data_sources: write`  | Change a fetched source.              | `project`, **`data_source`**, `name`, `url`, `format`, `interval_minutes`, `timeout_s`, `headers`  |
| `fetch_data_source`             | `data_sources: write`  | Fetch a source now.                   | `project`, **`data_source`**                                                                       |
| `create_data_source_connection` | `data_sources: manage` | Connect an app.                       | `project`, **`environment`**, **`url`**, `timezone`                                                |
| `update_data_source_connection` | `data_sources: manage` | Change an app's address or time zone. | `project`, **`connection`**, `url`, `timezone`                                                     |
| `delete_data_source`            | `data_sources: manage` | Delete a source. Deletes.             | `project`, **`data_source`**                                                                       |
| `delete_data_source_connection` | `data_sources: manage` | Disconnect an app. Deletes.           | `project`, **`connection`**                                                                        |

### Screens

| Tool                      | Needs             | Does                                     | Arguments                                                                                                                                                                 |
| ------------------------- | ----------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `list_screens`            | `screens: read`   | Screens in the team.                     | none                                                                                                                                                                      |
| `get_screen`              | `screens: read`   | Layout and widgets.                      | **`screen`**                                                                                                                                                              |
| `list_widget_types`       | `screens: read`   | Widgets and their options.               | `type`                                                                                                                                                                    |
| `get_screen_data`         | `screens: read`   | Widget data as shown.                    | **`screen`**, `widget`                                                                                                                                                    |
| `list_screen_versions`    | `screens: read`   | Saved versions.                          | **`screen`**                                                                                                                                                              |
| `preview_screen`          | `screens: read`   | Signed preview link.                     | **`screen`**                                                                                                                                                              |
| `get_screen_design_guide` | `screens: read`   | Design guide for agents.                 | none                                                                                                                                                                      |
| `create_screen`           | `screens: write`  | New screen.                              | **`name`**, `template`                                                                                                                                                    |
| `update_screen`           | `screens: write`  | Name, theme, settings.                   | **`screen`**, **`revision`**, `name`, `theme`, `aspect`, `orientation`, `fit`, `show_header`, `show_clock`, `clock_format`, `light_from`, `light_to`, `timezone`, `scope` |
| `update_screen_layout`    | `screens: write`  | Grid layout.                             | **`screen`**, **`revision`**, **`cols`**, **`rows`**, **`widgets`**                                                                                                       |
| `update_screen_widget`    | `screens: write`  | One widget.                              | **`screen`**, **`revision`**, **`id`**, **`widget`**                                                                                                                      |
| `restore_screen_version`  | `screens: write`  | Restore a version.                       | **`screen`**, **`revision`**, **`version`**                                                                                                                               |
| `export_screen`           | `screens: read`   | Screen as a file.                        | **`screen`**                                                                                                                                                              |
| `list_displays`           | `screens: read`   | Paired displays.                         | none                                                                                                                                                                      |
| `list_screen_viewers`     | `screens: read`   | Who has it open.                         | **`screen`**                                                                                                                                                              |
| `duplicate_screen`        | `screens: write`  | Copy a screen.                           | **`screen`**                                                                                                                                                              |
| `reload_screen`           | `screens: write`  | Reload its TVs.                          | **`screen`**                                                                                                                                                              |
| `import_screen`           | `screens: manage` | Screen from a file.                      | **`json`**                                                                                                                                                                |
| `assign_display`          | `screens: manage` | What a display shows.                    | **`display`**, **`screen`**                                                                                                                                               |
| `set_screen_share`        | `screens: manage` | Public share link. Can make data public. | **`screen`**, **`enabled`**, `include_titles`                                                                                                                             |
| `delete_screen`           | `screens: manage` | Delete a screen. Deletes.                | **`screen`**                                                                                                                                                              |

### Project settings

| Tool                    | Needs             | Does                                               | Arguments                                                                  |
| ----------------------- | ----------------- | -------------------------------------------------- | -------------------------------------------------------------------------- |
| `get_project_overview`  | `project: read`   | Health of the project at a glance.                 | `project`, `period`, `environment`                                         |
| `get_project_settings`  | `project: read`   | Settings without secrets.                          | `project`                                                                  |
| `update_project`        | `project: write`  | Name, slug, prefix, origins, scrubbed keys.        | `project`, `name`, `slug`, `issue_prefix`, `allowed_origins`, `scrub_keys` |
| `create_log_channel`    | `project: write`  | Add a log channel.                                 | `project`, **`name`**                                                      |
| `delete_log_channel`    | `project: manage` | Delete a log channel and its lines. Deletes.       | `project`, **`channel_id`**                                                |
| `set_project_retention` | `project: manage` | Days kept per data type. Deletes.                  | `project`, `days`, `forever`, `channels`                                   |
| `set_public_stats`      | `project: manage` | Public stats link on or off. Can make data public. | `project`, **`enabled`**, `include_titles`                                 |

### Team

| Tool           | Needs        | Does               | Arguments |
| -------------- | ------------ | ------------------ | --------- |
| `list_members` | `team: read` | Members and roles. | `project` |

### About screens

An agent reads `get_screen_design_guide` first. It sees only screens that show projects
the token covers, and a change may not make a screen show projects outside the token. A
screen with a Wi-Fi password or a media widget needs a token covering every project of
the team. Changes need the `revision` they were based on, and are refused if the screen
changed in the meantime. A preview stops working when its token does, or no longer
covers the screen.

### Argument details

`project` is a project slug from `list_projects`. It is required only when the token
covers more than one project. `issue` is an issue's number, such as `DEMO-42`, or its
id. `period` is `1h`, `24h`, `7d` or `30d` (default `24h`). Arguments in bold are
required. Every tool also describes its arguments to the agent itself.

| Tool                   | Arguments                                                                                                                                                                                                                                                                           |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `list_projects`        | none                                                                                                                                                                                                                                                                                |
| `get_project_overview` | `project`, `period`, `environment`                                                                                                                                                                                                                                                  |
| `triage_inbox`         | none                                                                                                                                                                                                                                                                                |
| `list_issues`          | `project`, `status` (`open` by default, which includes `in_progress`, or `resolved`, `ignored`), `source` (`server`, `browser`, `log`), `search` (title or message), `label`, `sort` (`last_seen` by default, `occurrences`, `users`, `first_seen`), `limit` (1 to 100, default 25) |
| `get_issue`            | **`issue`**                                                                                                                                                                                                                                                                         |
| `list_occurrences`     | **`issue`**, `limit` (1 to 100, default 20)                                                                                                                                                                                                                                         |
| `set_issue_status`     | **`issue`**, **`status`** (`open`, `in_progress`, `resolved`, `ignored`), `note` (added as a note), `until` for `ignored`: `escalation` (default), `1h`, `24h`, `7d`, `count:10`, `count:100`, `count:1000` or `forever`                                                            |
| `assign_issue`         | **`issue`**, `user_id` from `list_members`. Leave it out to unassign.                                                                                                                                                                                                               |
| `set_issue_labels`     | **`issue`**, **`labels`**: a list of names. An empty list removes all. New names create labels. Up to 10 labels of at most 32 characters.                                                                                                                                           |
| `watch_issue`          | **`issue`**                                                                                                                                                                                                                                                                         |
| `unwatch_issue`        | **`issue`**                                                                                                                                                                                                                                                                         |
| `add_issue_note`       | **`issue`**, **`body`**: Markdown. The first line is the summary shown in alerts. `<@id>` tags a member.                                                                                                                                                                            |
| `create_repo_issue`    | **`issue`**, `title` (defaults to the exception and message), `body` (Markdown)                                                                                                                                                                                                     |
| `list_members`         | `project`                                                                                                                                                                                                                                                                           |
| `list_routes`          | `project`, `period`, `environment`, `sort` (`p95` by default, `count`, `errors`, `total`), `search`                                                                                                                                                                                 |
| `list_queries`         | `project`, `period`, `environment`, `sort` (`total` by default, `p95`, `count`), `search` (SQL text)                                                                                                                                                                                |
| `list_jobs`            | `project`, `period`, `environment`, `queue`, `sort` (`errors` by default, `count`, `duration`, `wait`)                                                                                                                                                                              |
| `list_commands`        | `project`, `period`, `environment`                                                                                                                                                                                                                                                  |
| `list_scheduled_tasks` | `project`, `environment`, `attention` (only tasks whose last run failed or was missed)                                                                                                                                                                                              |
| `get_job`              | `project`, **`name`** (the full job class), `uuid` (one job's attempts), `status` (`queued`, `processing`, `processed`, `released`, `failed`, `stuck`)                                                                                                                              |
| `get_command`          | `project`, **`name`** (such as `reports:send`), `status` (`running`, `finished`, `failed`)                                                                                                                                                                                          |
| `get_scheduled_task`   | `project`, **`name`** as `list_scheduled_tasks` shows it, `environment`                                                                                                                                                                                                             |
| `list_health_checks`   | `project`, `period` (for uptime), `environment`, `attention` (only degraded, down or stale checks)                                                                                                                                                                                  |
| `get_trace`            | `project`, **`trace_id`**                                                                                                                                                                                                                                                           |
| `search_logs`          | `project`, `channel`, `level` (minimum: `debug` to `emergency`), `search`, `trace_id`, `period`, `limit` (1 to 200, default 50)                                                                                                                                                     |

## Automatic triage

The project settings have a ready-made GitHub Actions workflow that runs Claude Code in
your repository. Through the MCP tools, it triages new and regressed issues and answers
notes that tag it, without resolving them.

![The Automatic triage section of a project's settings](./screenshots/settings-triage.png)

When it is sure of the cause and the fix is small, or a comment asks for one, it opens a
**draft pull request** from a `lumberjack/<issue number>` branch with "Fixes #N" in its
body, and links it in its note or comment. It never pushes to the default branch or
merges. CI does not start on these pull requests by itself: push to the branch or re-run
CI.

1. Create a token with the **Triage** preset (issues: Write) that covers the project.
2. Add the secrets to the GitHub repository: `LUMBERJACK_MCP_TOKEN` (the token) and
   `CLAUDE_CODE_OAUTH_TOKEN` (from `claude setup-token`, using your Claude
   subscription). To pay per use instead, swap the commented lines for
   `ANTHROPIC_API_KEY`.
3. Commit the workflow shown in the settings as
   `.github/workflows/lumberjack-triage.yml`.

It runs:

| When                                                                        | Does                                                                |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Every 15 minutes, or by hand (**Run workflow**)                             | Works through the triage inbox and notes in Lumberjack that tag it. |
| A GitHub issue labeled `lumberjack` is opened or labeled, or gets a comment | Answers on the GitHub issue. Comments by bots are left alone.       |

Lumberjack puts the `lumberjack` label on the GitHub issues it creates. A run can take
up to 20 minutes. Only one run per issue, or for the inbox, runs at a time.

Your Lumberjack must be on a public URL that GitHub can reach. The settings also show
how to run the same triage once in a local Claude Code session.

The workflow shows as "Lumberjack Triage" in the Actions tab and commits as
"Lumberjack", from `lumberjack@noreply.invalid`. To show the Lumberjack logo on those
commits, create a GitHub App or account with the logo as its avatar and set the
repository variable `LUMBERJACK_GIT_EMAIL` to its email. For an app, that is `<bot user id>+<app slug>[bot]@users.noreply.github.com`.
Get the bot user id with `gh api /users/<app slug>[bot] --jq .id`.
