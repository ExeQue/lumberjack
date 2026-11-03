# Projects

A project is one app. It belongs to a team; every member of the team can see it, and
owners and admins can change its settings.

## Creating a project

Give it a name and an issue prefix. The prefix is suggested from the name (initials
for several words, the word itself for one) and is used for
[issue numbers](./issues#issue-numbers).

The project settings then show how to install the package, the `.env` values and the
agent setup.

## Switching project

Pick another project in the sidebar or the [command palette](./finding-things) and you
land on the same page in it: from the issues to its issues, from a job to its jobs.
Pages that belong to one thing, like a trace, open the other project's overview.

## Settings

- **Keys:** the server token (for the agent) and the public key (for browser errors).
  Both can be rotated; the server token is shown only once.
- **Allowed origins:** browser errors are only accepted from these origins.
- **Extra secret fields:** masked before storage in headers, session, Context and log
  context. A plain word masks every key containing it (`cpr`), `*` is a wildcard on the
  key name (`card_*`), and a dotted pattern matches the full path
  (`session.checkout.*`).
- **Agents:** each server's agent checks in every minute with what it runs (SDK and
  runtime, e.g. "Laravel 0.1.0 · PHP 8.5.1"; hover for the agent and framework). One
  that stops is shown as offline, and is removed after 24 hours without a heartbeat,
  since its server or pod is most likely gone (Kubernetes names every pod for
  itself). **Remove** forgets one right away; it comes back if it checks in again.
  The heartbeat also reports the size of the app's queues (the package's `queues`
  config), kept for 30 days by default for the [Jobs page](./performance#queues), and how busy
  its host is: the table shows the 1 minute load against the cores, memory and disk
  in use (hover for the bytes), with the highest load and memory per hour over the
  last day. Memory and disk turn amber past 90%, load past the number of cores. In a
  container the container's own limits count. Host samples are kept for 7 days by
  default. Both follow [retention](#retention).
- **Alerts:** the project's [own channels, and its rules](./alerts#team-rules-and-project-overrides): the team's by default, or customized for the project.
- **Labels:** rename, recolor or delete [issue labels](./issues#working-on-an-issue).
- **Retention:** see below.
- **Automatic triage:** see [MCP](./mcp#automatic-triage).

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

| Attribute          | Does                                                            |
| ------------------ | --------------------------------------------------------------- |
| `data-key`         | The project's public key. Without it nothing is sent.           |
| `data-environment` | The environment of the page.                                    |
| `data-release`     | The release, e.g. a commit hash.                                |
| `data-user`        | The user's id, for [users affected](./issues#users-affected).   |
| `data-trace`       | The trace id of the request that rendered the page.             |
| `data-announce`    | `true` logs a line in the console when the script is active.    |
| `data-vitals`      | `false` stops measuring [Web Vitals](./performance#web-vitals). |

Crawlers and automated browsers are skipped.

## Retention

Every kind of data has its own retention, set on three levels. Each level is the
default for the one below it and the most it may keep:

1. **Application**, the whole Lumberjack: **Administration > Retention**, for system
   administrators. Empty uses the built-in default and sets no maximum.
2. **Team**: the team page, for team owners and admins. At most the application value.
3. **Project**: the project's settings, for whoever administers the project. At most
   the team value; job classes and log channels can have their own, also at most the
   team value.

Each value is a number of days (1 to 3650), empty to inherit, or **forever**. Forever
can only be chosen where no level above sets a number.

Saving more than a level above allows is refused, naming the limit. Lowering a level
does not change what the levels below store: their values are capped right away, shown
as capped, and come back once the limit is raised again. A level that stores nothing
sets no limit, so a project can keep longer than the built-in default as long as
neither its team nor the application says otherwise.

Each table shows per data type what the level inherits, the limit from above, how many
rows are stored (counted up to 100,000; an estimate on the application page) with the
age of the oldest, and the date the daily prune would delete up to.

| Group          | Data type           | Default  | Holds                                                             |
| -------------- | ------------------- | -------- | ----------------------------------------------------------------- |
| Errors         | Occurrences         | 90 days  | Each time an error happened, with stack trace and context         |
| Performance    | Requests            | 14 days  | One row per HTTP request: route, status and duration              |
| Performance    | Queries             | 14 days  | Database queries recorded with their requests and jobs            |
| Performance    | Spans               | 14 days  | Trace spans: outgoing HTTP, cache and custom timings              |
| Performance    | Web vitals          | 30 days  | Page load metrics from browsers                                   |
| Jobs           | Job runs            | 30 days  | Each queued job attempt; can be set per job class                 |
| Jobs           | Scheduled task runs | 30 days  | Each run of a scheduled task                                      |
| Jobs           | Command runs        | 30 days  | Each Artisan command run                                          |
| Logs           | Logs                | 14 days  | Log lines; can be set per channel                                 |
| Health         | Health results      | 14 days  | Each health check probe and its latency                           |
| Health         | Health outages      | 365 days | Periods a check was down or degraded; uptime is counted from them |
| Infrastructure | Queue size samples  | 30 days  | Queue length and oldest job age, sampled by the agent             |
| Infrastructure | Host metrics        | 7 days   | CPU, memory and disk samples from the agent                       |
| Alerts         | Alert history       | 90 days  | Every alert sent, to which channel, and whether it got through    |
| Administration | Audit log           | 365 days | What administrators did; set on the application level only        |

Issues themselves are never pruned, only their occurrences. Failed job runs, failed or
missed scheduled runs and failed command runs stay at least as long as occurrences.
Open outages are kept until they end. Data is pruned once a day, at 03:15
Europe/Copenhagen.

Upgrading keeps what was set: an old Errors value became Occurrences (and Health
outages when it was longer than a year), Performance became Requests, Queries, Spans,
Web vitals and Health results, Jobs became Job runs, Scheduled task runs and Command
runs, and Logs stayed Logs.

## Repository integration

Link a GitHub repository to create GitHub issues from Lumberjack issues, by button or
through MCP. It needs a fine-grained token with only **Issues: read and write** on that
repository; **Create token on GitHub** opens GitHub with the right settings filled in.
Lumberjack warns before the token expires. Creating issues in a public repository has
to be allowed explicitly.

## Deleting a project

The danger zone deletes the project and all its data. Raw data is removed in the
background in batches and can take several minutes.
