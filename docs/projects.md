# Projects

A project is one app you monitor. It belongs to a team. Every member of the team can see
it, and team owners and admins change its settings.

## The projects list

**Projects** in the sidebar shows a card for each project of the team:

- open issues, and the errors, p95 and failed jobs of the last 24 hours;
- a chart of errors per hour;
- **Calm** when the project has no open issues and every agent checks in;
- **Agent offline** (or **2 agents offline**) and **Not seen: …** when an
  [agent](#agents) stopped checking in, with the hosts it ran on. Hover for the full list.

**Search projects** filters the cards.

## Live updates

Every page updates in place when what it shows changes: another member edits something,
an MCP tool acts, or new data arrives from the app.

- **Project pages**: the overview, issues and an issue, routes, queries, releases,
  jobs, schedule, commands, logs and health, as data arrives and when a health check,
  scheduled task, label or queue alert is changed.
- **Sidebar**: the badge counts of issues, jobs, schedule, health and commands, and the
  team's projects.
- **Projects list**: new, renamed and removed projects, and each card's figures as its
  issues, jobs and agents change.
- **Project settings**: keys, labels, log channels, agents, retention, alerts and the
  repository, and what the project inherits from the team.
- **Team**: members, invitations, MCP tokens, alert channels and rules, and retention.
- **Screens**: the screens list, Displays, and every open kiosk and TV when its screen
  is changed (see [Status screens](./screens)).
- **Administration**: teams, users, the audit log and retention.

While you edit a settings page, it does not change under you. It says **This page
changed elsewhere** with a **Reload** button instead. **Reload** loads the new state and
drops your unsaved edits. Saving keeps your edits and loads the rest.

## Creating a project

Team owners and admins create projects with **New project**. Give it a **Name** and an
**Issue prefix**. The prefix is suggested from the name and starts every
[issue number](./issues#issue-numbers). It has 2 to 8 capital letters and digits, starts
with a letter and is unique in the team.

The new project opens on its **Installation** settings. They show how to install the
SDK, the `.env` values, the agent setup, the browser script and a test command. The
server token is shown only once, right after you create the project. Copy it then, or
rotate it later.

## Switching project

Pick another project in the sidebar or the [command palette](./finding-things) to open
the same page in it: from the issues to its issues, from a job to its jobs. A page about
one thing, like a trace, opens the other project's overview.

## Settings

Only team owners and admins change settings, labels, log channels, agents, retention
and the repository. Members see everything and work on issues.

The settings are split into sections, one open at a time. The address names the open
section (for example `#agents`), so you can link to it.

- **General:** the name, the **slug**, the issue prefix, allowed origins and extra
  secret fields. The slug is used in URLs and as the project argument in MCP. It takes
  letters, digits, `-` and `_`, and is unique in the team.
- **Installation:** what to install and set, as when the project was created.
- **Keys:** the server token for the agent, the public key for browser errors, and
  public stats. Public stats is a secret URL that serves the project's figures on the
  [metrics API](./metrics-api#authentication) without a token. It is off by default.
  You can rotate all three. The server token is shown only once.
- **Allowed origins:** browser errors are accepted only from these origins, such as
  `https://shop.example.com` without a path. Until you add one, browser errors are
  rejected.
- **Extra secret fields:** keys whose values are replaced with `[filtered]` before they
  are stored, in headers, session, Context and log context. These come on top of the
  keys that are always masked: `password`, `passwd`, `pwd`, `secret`, `token`,
  `authorization`, `cookie`, `api_key`, `apikey`, `access_key`, `private_key`, `jwt`,
  `dsn`, `credit_card`, `card_number`, `cvv` and `session_id`. Values that look like
  secrets are masked whatever their key, see [Security](./administration#security).
  - A plain word masks every key that contains it: `cpr`.
  - `*` is a wildcard in the key name: `card_*`.
  - A dotted pattern matches the full path: `session.checkout.*`.
- **Agents:** the servers that send data, see [Agents](#agents).
- **Alerts:** the project's [own channels, and its rules](./alerts#team-rules-and-project-overrides): the team's by default, or customized for the project.
- **Log channels:** the channels the project's log lines go to, with the lines of the
  last 24 hours and each channel's own [retention](#retention). Names are kebab-case.
  Lines sent to a channel the project does not have go to `default`, which cannot be
  deleted. Deleting another channel moves its lines to `default`. A channel that
  received lines in the last 7 days but does not exist yet gets a **Create** button
  here and a banner on the Logs page.
- **Labels:** rename, recolor or delete [issue labels](./issues#working-on-an-issue).
- **Retention:** see [Retention](#retention).
- **Repository integration:** see [Repository integration](#repository-integration).
- **Automatic triage:** see [MCP](./mcp#automatic-triage).
- **Danger zone:** see [Deleting a project](#deleting-a-project).

The project's [data sources](./data-sources.md) are listed in the settings menu too.

## Agents

An agent is a small process from the SDK that runs once on every server of the app,
under Supervisor. It sends the app's data to Lumberjack and checks in every minute.
Install it as the **Installation** settings show.

**Agents** in the settings lists every agent that has checked in:

| Column        | Shows                                                                                         |
| ------------- | --------------------------------------------------------------------------------------------- |
| **Host**      | The server or pod it runs on.                                                                 |
| **Name**      | The agent's name, when it has one.                                                            |
| **Running**   | SDK and runtime, e.g. "Laravel 0.1.0 · PHP 8.5.1". Hover for the agent and framework version. |
| **Last seen** | Its last check-in.                                                                            |
| **Load**      | The 1 minute load against the number of cores, and the highest load per hour over 24 hours.   |
| **Memory**    | Memory in use, and the highest use per hour over 24 hours. Hover for the bytes.               |
| **Disk**      | Disk in use. Hover for the bytes.                                                             |
| **Status**    | **Online** or **Offline**.                                                                    |

Memory and disk turn amber above 90%, load above the number of cores. In a container,
the container's own limits count.

![The Agents section of a project's settings, with the load, memory and disk of each server](./screenshots/settings-agents.png)

### Offline agents

- An agent that has not checked in for **5 minutes** is **Offline**. The projects list
  warns about it.
- Every team member who can see the project gets an **Agents** notification, once per
  project, however many agents went offline. It is on in the app by default. Each
  member can turn it on or off for mail, the app and the browser in their
  [notification settings](./teams#notifications). When the agent checks in again and
  later goes offline, members are told again.
- An agent that has not checked in for **24 hours** is removed: its server or pod is
  most likely gone.
- **Remove** removes an agent right away. It comes back if it checks in again.

### What agents report

With each check-in, an agent reports:

- the size of the app's queues that the SDK watches, shown on the
  [Jobs page](./performance#queues) and used by [queue backlog alerts](./alerts#queue-backlog);
- how busy its host is: load, memory and disk, shown in the table above.

Queue sizes are kept for 30 days and host metrics for 7 days by default. Both follow
[retention](#retention).

## Browser script

The project settings show the script tag for browser errors:

```html
<script
    src="https://lumberjack.example/sdk/v1.js"
    data-key="lj_pub_..."
    data-environment="production"
    defer
></script>
```

| Attribute          | Does                                                                    |
| ------------------ | ----------------------------------------------------------------------- |
| `data-key`         | The project's public key. Without it nothing is sent.                   |
| `data-environment` | The environment of the page.                                            |
| `data-release`     | The release, e.g. a commit hash.                                        |
| `data-user`        | The user's id, for [users affected](./issues#users-affected).           |
| `data-trace`       | The trace id of the request that rendered the page.                     |
| `data-announce`    | `true` logs a line in the console when the script is active.            |
| `data-vitals`      | `false` stops measuring [Web Vitals](./performance#browser).            |
| `data-endpoint`    | Where to send, instead of `/api/ingest/browser` on the script's server. |

Crawlers and automated browsers are ignored.

With each page view's Web Vitals, the script sends the class of device: mobile, tablet
or desktop.

The script also has a small API on `window.Lumberjack`:

| Call                                          | Does                                                                                                     |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `Lumberjack.init({ ... })`                    | Sets any of the options above, and `ignore`: strings or regular expressions matched against the message. |
| `Lumberjack.setUser(id)`                      | Sets the user, `null` to clear it.                                                                       |
| `Lumberjack.captureException(error, context)` | Sends an error you caught yourself, with extra context.                                                  |
| `Lumberjack.flush()`                          | Sends what is waiting now.                                                                               |
| `Lumberjack.version`                          | The script's version.                                                                                    |

The script ignores common noise: "Script error.", "ResizeObserver loop",
"Non-Error promise rejection captured", "Uncaught (in promise)", and errors that come
only from browser extensions.

## Retention

Retention sets how long each kind of data is kept. You set it on three levels. Each
level is the default for the level below it, and the most that level may keep:

1. **Application**, the whole Lumberjack: **Administration > Retention**, for system
   administrators. Empty uses the built-in default and sets no maximum.
2. **Team**: **Team > Retention** in the sidebar, for team owners and admins. Members
   can read it. At most the application value.
3. **Project**: **Retention** in the project's settings, for team owners and admins. At
   most the team value. Job classes and log channels can have their own value, also at
   most the team value.

Each value is a number of days from 1 to 3650, empty to inherit, or **Forever**. A team
or project can choose **Forever** only when the application keeps that data forever, and
the team sets no number for its projects. The built-in defaults are never forever.

A value above the limit is rejected, with the limit named. When you lower a level, the
levels below it are capped at once and show as **Capped**. Their own values come back
when the limit is raised again. A level with no value sets no limit, so a project can
keep data longer than the built-in default when neither its team nor the application
sets a limit.

![The retention table of a project, with the limit from the team and what is stored](./screenshots/settings-retention.png)

For each data type, the table shows the **Default** the level inherits, the value of
**This level**, the limit from above, the **Rows** stored, the age of the **Oldest** row,
and **Deletes before**: the date up to which data is deleted next. Data is deleted once
a day.

| Data type            | Default  | What it is                                                         |
| -------------------- | -------- | ------------------------------------------------------------------ |
| Occurrences          | 90 days  | Each time an error happened, with its stack trace and context      |
| Requests             | 14 days  | Recorded HTTP requests and the request counts per minute           |
| Queries              | 14 days  | Database queries recorded with requests and jobs                   |
| Spans                | 14 days  | Outgoing HTTP calls, cache operations and custom timings in traces |
| Web vitals           | 30 days  | Page load metrics from browsers                                    |
| Job runs             | 30 days  | Each attempt of a queued job. Can be set per job class             |
| Scheduled task runs  | 30 days  | Each run of a scheduled task                                       |
| Command runs         | 30 days  | Each console command run                                           |
| Logs                 | 14 days  | Log lines. Can be set per channel                                  |
| Health results       | 14 days  | Each health check result and its latency                           |
| Health outages       | 365 days | Periods a check was down or degraded. Uptime is counted from them  |
| Data source history  | 30 days  | What each [data source](./data-sources.md) answered, for charts    |
| Queue size samples   | 30 days  | Queue length and oldest job age, reported by the agents            |
| Host metrics         | 7 days   | CPU, memory and disk, reported by the agents                       |
| Alert history        | 90 days  | Every alert sent, to which channel, and whether it got through     |
| Audit log            | 365 days | What administrators did. Application level only                    |
| Notification history | 90 days  | Everyone's [notification center](./teams#notification-center). Application level only |

Issues themselves are never deleted, only their occurrences. Failed job runs, failed or
missed scheduled runs and failed command runs are kept at least as long as occurrences.
An outage is kept until it ends.

## Repository integration

Link a GitHub repository to create GitHub issues from Lumberjack issues, with a button
or through MCP.

1. Enter the **Repository** as `owner/name`.
2. Create a fine-grained token with only **Issues: read and write** on that repository.
   **Create token on GitHub** opens GitHub with these settings filled in. Paste it under
   **Fine-grained token**.
3. Click **Test connection**. It checks the token and says whether the repository is
   private or public.
4. For a public repository, tick **Allow creating issues in a public repository**.
5. Optionally list **Labels**, comma separated, for the created issues.

Lumberjack warns 14 days before the token expires.

## Deleting a project

The **Danger zone** deletes the project and all its data, after you type the project's
name. Deleting the data can take several minutes.

## Limits

| What                                      | Limit             |
| ----------------------------------------- | ----------------- |
| Allowed origins                           | 50                |
| Extra secret fields                       | 50, 64 characters |
| Log channel name                          | 64 characters     |
| Browser errors per page view              | 100               |
| Same browser error per page view          | 5                 |
| Retention value                           | 1 to 3650 days    |
