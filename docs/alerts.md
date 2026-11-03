# Alerts

Alerts go to **channels** and are chosen by **rules**: the team's, which every project
uses, and a project's own where it customizes one.

## Channels

Set up a channel once on the team's **Alerts** page and use it in every project.
A project can also have channels of its own, in its settings.

| Type            | What it needs                                                                                                         |
| --------------- | --------------------------------------------------------------------------------------------------------------------- |
| Mail            | An email address.                                                                                                     |
| Discord         | A channel webhook URL, and optionally the sender name.                                                                |
| Slack           | An incoming webhook URL (`https://hooks.slack.com/...`).                                                              |
| Microsoft Teams | A Workflows webhook URL.                                                                                              |
| Telegram        | A bot token and a chat id (a group id starting with `-`, or `@name` of a public channel). The bot has to be a member. |
| ntfy            | The topic URL, on ntfy.sh or your own server, and an access token for a protected topic.                              |
| Pushover        | Your user or group key and an application token from pushover.net/apps.                                               |
| Webhook         | Any HTTPS endpoint. Project channels only.                                                                            |

**Add new channel** opens a short wizard: pick where alerts go, then fill in the
details. Each type explains where to find its URL or keys and shows a preview of a
message (Mail as a Gmail inbox row; Discord, Slack, Teams and Telegram as the message
itself). Editing opens the same dialog at the details.

Discord, Slack, Teams and Telegram messages carry the same content: the headline, the issue
number and title, the message, the location, release and occurrence count, and a link
to the issue.

A delivery that meets a dropped connection, a rate limit (429) or a server error (5xx)
is tried again up to three times within about 8 seconds. One the other side refuses,
such as a removed webhook, fails at once and shows on the channel.

**Send test** delivers a test message so you can check the setup. Each channel shows
when it last delivered or failed.

Webhook, Teams and ntfy URLs may not point at private or reserved addresses (this
server, the network behind it). Set `ALERTS_WEBHOOKS_ALLOW_PRIVATE=true` to allow that,
for example in local development.

## Rules

A rule is on or off, sends to the channels you pick, and can be limited to some
environments (comma separated, empty means all).

### Team rules and project overrides

The team has one rule per alert type, on its **Alerts** page under **Rules**. Every
project uses them, so a new project alerts like the others from the start. Team rules
send to the team's shared channels only, since they serve every project. Only team
owners and admins change them.

In a project's settings, under **Alerts**, each type shows either:

- **Team default**: what the team's rule does (on or off, its channels and
  environments), read only. **Customize** gives the project a rule of its own for that
  type, starting as a copy of the team's, to change for this project only. It can also
  send to the project's own channels, such as a webhook.
- **Customized**: the project's own rule, edited in place. Changes to the team's rule
  no longer reach it. **Use the team's** deletes it, after asking, and the project
  follows the team's rule again.

A new shared channel is added to the team's rules that are on by default. A new project
channel is added to the project's rules that are on by default, customizing those that
still followed the team (a team rule cannot send to one project's channel).

| Rule                | Fires on                                                                               | On by default |
| ------------------- | -------------------------------------------------------------------------------------- | ------------- |
| New issue           | The first occurrence of a new issue.                                                   | Yes           |
| Regression          | A new occurrence of a resolved issue.                                                  | Yes           |
| Escalation          | An open issue escalating (10+ occurrences in 5 minutes, far above usual).              | Yes           |
| Resolved or ignored | Someone resolving or ignoring an issue, with their comment. Bulk changes are left out. | No            |
| Note                | Every note on an issue, by a person or an agent.                                       | No            |
| Queue backlog       | A queue over its thresholds for a while, and again when it is back under.              | Yes           |
| Health degraded     | A [health check](./health) that keeps warning past its threshold.                      | Yes           |
| Health down         | A health check that keeps failing past its threshold, also after being degraded.       | Yes           |
| Health recovered    | A degraded or down health check that passes again past its recovery threshold.         | Yes           |

At most one message is sent per issue per rule every 30 minutes. Regressions and
notes are not throttled: a regression only follows someone resolving the issue, so
each one is news. Ignored issues and browser errors never send. If the issue has a triage
note, its first line is included in the message.

### Queue backlog

Agents report the size of the app's queues every minute (see the
[Jobs page](./performance#queues)). The queue backlog rule fires when a queue has more
than **1,000 jobs** waiting, or its oldest job has waited more than **10 minutes**, for
**5 minutes**; each of the three can be changed on the rule, and either threshold left
empty to turn it off. It sends once when the backlog starts and once when the queue is
back under, and starts again at most once every 30 minutes, so a queue that hovers
around a threshold does not flood the channel. The rule takes every queue the agents
report; environments do not apply to it.

This is the alert that catches dead workers: with no worker running, no job starts, so
no wait time is measured, but the queue size keeps growing. The check runs every minute
(`php artisan lumberjack:check-queue-backlogs` in the schedule) on the latest sample
of each queue, from whichever host reported it last.

## Webhooks

A webhook channel POSTs JSON to your endpoint, so your app can react, for example by
turning a status banner on while an issue escalates.

```json
{
    "version": "1",
    "sent_at": "2026-11-03T10:12:00+00:00",
    "event": "new_issue",
    "project": { "id": "...", "slug": "shop", "name": "Shop" },
    "environment": "production",
    "issue": {
        "id": "...",
        "reference": "SHOP-42",
        "title": "Illuminate\\Database\\QueryException",
        "message": "SQLSTATE[40001]: Deadlock found when trying to get lock",
        "status": "open",
        "source": "server",
        "escalating": false,
        "occurrences": 1,
        "first_seen_at": "...",
        "last_seen_at": "...",
        "url": "https://lumberjack.example.com/projects/.../issues/..."
    },
    "burst": null,
    "status_change": null,
    "note": null
}
```

`event` is the rule (`new_issue`, `regression`, `escalation`, `status_change`, `note`,
`queue_backlog`, `health_degraded`, `health_down`, `health_recovered`) or `test`. `status_change` and `note` are filled in
for those events.

A queue backlog has no issue; its body carries the queue and the thresholds instead,
with `state` either `started` or `recovered`:

```json
{
    "version": "1",
    "sent_at": "2026-11-03T10:12:00+00:00",
    "event": "queue_backlog",
    "project": { "id": "...", "slug": "shop", "name": "Shop" },
    "queue": {
        "connection": "redis",
        "name": "default",
        "state": "started",
        "size": 1240,
        "oldest_age_s": 840,
        "since": "2026-11-03T10:05:00+00:00",
        "url": "https://lumberjack.example.com/projects/.../jobs"
    },
    "thresholds": { "size": 1000, "age_minutes": 10, "sustain_minutes": 5 }
}
```

A health check has no issue either; `check.state` is `down`, `degraded` or
`recovered`, and `hosts` lists the servers it fails on (empty for checks Lumberjack
runs):

```json
{
    "version": "1",
    "sent_at": "2026-11-03T10:12:00+00:00",
    "event": "health_down",
    "project": { "id": "...", "slug": "shop", "name": "Shop" },
    "environment": "production",
    "check": {
        "id": "...",
        "name": "disk:s3",
        "slug": "disk:s3",
        "source": "reported",
        "type": null,
        "state": "down",
        "message": "The disk is not writable.",
        "hosts": ["web-1"],
        "since": "2026-11-03T10:10:00+00:00",
        "url": "https://lumberjack.example.com/projects/.../health?check=..."
    }
}
```

Every request is signed with the channel's secret, shown once when the channel is
created:

- `X-Lumberjack-Event`: the event.
- `X-Lumberjack-Timestamp`: when it was sent.
- `X-Lumberjack-Signature`: `sha256=` and the HMAC-SHA256 of
  `{timestamp}.{body}` with the secret.

The laravel-lumberjack package verifies these for you; see its webhooks docs.
