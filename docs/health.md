# Health

The **Health** page of a project shows whether the things your app depends on work:
its database, cache, queues and disks, the URLs people visit, the certificates they
use. Checks come from two places, and both work the same once a result is in.

| Source              | Where it runs                        | Set up                            |
| ------------------- | ------------------------------------ | --------------------------------- |
| Reported by the app | Inside the app, sent through ingest  | In the app, through its SDK       |
| Run by Lumberjack   | This server, on an interval you pick | On the Health page, **Add check** |

## Checks the app reports

An SDK runs checks inside the app and sends one `health` event per check with the
normal ingest batch. How checks are registered and scheduled is up to the SDK; see
its documentation.

A check is known by its **slug** (`database:pgsql`, `disk:s3`, `queue:redis:default`)
within an environment. A slug Lumberjack has not seen before becomes a new check on
its first result; nothing has to be set up on this side. A result with an unknown
status or a slug that is not lowercase letters, digits and `: . _ -` (at most 100
characters) is skipped, and the rest of the batch is kept. Each result carries a
status (`ok`, `warning` or `failed`), a message, up to 20 numeric metrics and how long
the check took; `latency_ms` is shown as the check's latency.

When several servers report the same slug, each server keeps its own state, and the
check shows the worst of them. One server with a full disk is not hidden by two
healthy ones; the row lists the hosts with their status, and the details show each
host's last result.

### Stale checks

A check that stops reporting shows as **stale** (gray), not down: the app may have
been switched off, or the check renamed. It is stale after three times its usual
spacing without a result (so after 3 minutes for a check that runs every minute), or
after 15 minutes while the spacing is not known yet. A stale check has no uptime and
sends no alert; it comes back by itself with its next result. **Archive** puts away a
check that will not report again, for example after renaming a slug.

### Cleaning up

- **Hosts** that stop reporting a check (a replaced Kubernetes pod, a retired server)
  are forgotten after 24 hours, so the host list does not grow with every deploy.
- **Reported checks** that have sent nothing for 7 days are archived by themselves.
  An archived check that reports again comes back with its history.
- **Delete** in a check's details removes it with its results and outages. A reported
  check that is deleted but still registered in the app comes back as a new check on
  its next result, so remove it from the app first.

## Checks Lumberjack runs

**Add check** offers five types. Each runs every 1, 5, 15 or 60 minutes, can carry an
environment label, and runs on the low-priority `health` queue of the background
workers.

| Type      | Checks                                                                                                                                                           |
| --------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| HTTP      | A URL answers with the expected status (200 unless set), optionally with a text in the body. Warns when slower than a limit you set.                             |
| SSL       | A host's certificate: warns a number of days before it expires (14) and fails close to it (3).                                                                   |
| TCP       | A port accepts a connection, e.g. a database or mail server.                                                                                                     |
| DNS       | A name has records of a type (A, AAAA, CNAME, MX, TXT), optionally one with the value you expect.                                                                |
| Heartbeat | A dead man's switch: your cron job or backup calls a secret URL when it is done; the check fails when no call came within its interval plus a grace (5 minutes). |

A heartbeat check shows its ping URL and a `curl` line to copy. The URL takes `GET`
or `POST` without a body:

```bash
php artisan backup:run && curl -fsS -m 10 https://lumberjack.example.com/api/health/ping/...
```

### Private networks

HTTP, SSL and TCP checks resolve the host first and refuse it when any address it
has is private, loopback, link-local or otherwise reserved (IPv4 and IPv6), so a check
cannot be used to reach this server or the network behind it. The check then connects
to the address it checked. Redirects are followed by hand, at most three, each checked
the same way. Timeouts are short (10 seconds for HTTP unless set lower).

Self-hosters who want to watch internal services can turn this off:

```dotenv
LUMBERJACK_HEALTH_ALLOW_PRIVATE=true
```

## Status

A single bad result does not change a check's status:

- After **degrade after** (2) bad results in a row it becomes **down** when the latest
  failed, or **degraded** when it only warned.
- After **recover after** (5) good results in a row it is **ok** again.

Checks the app reports send their own thresholds; the details let you set others for
a slug (leave both empty to go back to the app's). The details also hold a display
name and description, and **mute**, which keeps showing the check but sends no
alerts.

Each time a check leaves ok an **outage** starts, with the first message; it ends when
the check is ok again. The details list the outages with their duration and the worst
status they reached, next to the recent results and a chart of how long the check
took.

**Uptime** is the share of the period (top of the page) the check was not down, counted
from when the check was added. Degraded time counts as up, as on a status page. The
strip on each row has one segment per part of the period, colored by the worst the
check was in it.

The project overview shows how many checks are ok, and below its numbers every check
as a chip, worst first: down in red, degraded in amber, stale and pending in gray.
Hover a chip for its last message, click it for its details on the Health page. The
sidebar counts the checks that are degraded or down.

## Alerts

Three alert rules, all on by default, each with its own channels and environments:
**Health degraded** when a check keeps warning, **Health down** when it keeps failing
(also when a degraded check goes down), and **Health recovered** when it is ok again.
So a check going down can page someone while a degraded one only posts to a channel,
or recoveries can be left out. A check that is degraded or down is still followed while
its rule is off: turning **Health degraded** off does not stop the later down or
recovered message.
A check that goes bad again within 30 minutes of its last alert stays quiet, so a
flapping check does not flood the channel. Muted and archived checks send nothing.
See [Alerts](./alerts#webhooks) for the webhook body.

## Retention

Results are kept as long as the project's performance data; outages, which uptime is
counted from, as long as its errors.
