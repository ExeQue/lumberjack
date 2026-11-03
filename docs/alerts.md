# Alerts

Alerts go to **channels** and are chosen per project by **rules**.

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

**Send test** delivers a test message so you can check the setup. Each channel shows
when it last delivered or failed.

Webhook, Teams and ntfy URLs may not point at private or reserved addresses (this
server, the network behind it). Set `ALERTS_WEBHOOKS_ALLOW_PRIVATE=true` to allow that,
for example in local development.

## Rules

Each project has one rule per alert type. A rule is on or off, sends to the channels
you pick, and can be limited to some environments (comma separated, empty means all).

| Rule                | Fires on                                                                               | On by default |
| ------------------- | -------------------------------------------------------------------------------------- | ------------- |
| New issue           | The first occurrence of a new issue.                                                   | Yes           |
| Regression          | A new occurrence of a resolved issue.                                                  | Yes           |
| Escalation          | An open issue escalating (10+ occurrences in 5 minutes, far above usual).              | Yes           |
| Resolved or ignored | Someone resolving or ignoring an issue, with their comment. Bulk changes are left out. | No            |
| Note                | Every note on an issue, by a person or an agent.                                       | No            |

At most one message is sent per issue per rule every 30 minutes (notes are not
throttled). Ignored issues and browser errors never send. If the issue has a triage
note, its first line is included in the message.

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

`event` is the rule (`new_issue`, `regression`, `escalation`, `status_change`, `note`)
or `test`. `status_change` and `note` are filled in for those events.

Every request is signed with the channel's secret, shown once when the channel is
created:

- `X-Lumberjack-Event`: the event.
- `X-Lumberjack-Timestamp`: when it was sent.
- `X-Lumberjack-Signature`: `sha256=` and the HMAC-SHA256 of
  `{timestamp}.{body}` with the secret.

The laravel-lumberjack package verifies these for you; see its webhooks docs.
