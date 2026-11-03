# Metrics API

A read-only JSON API for charting Lumberjack's data in Grafana, or reading it from any
HTTP client: requests, errors, latency, issues, jobs, queues, hosts, web vitals and
releases. It also serves the current gauges in the Prometheus text format, for scraping
and alerting.

## Authentication

Two ways in, both read only:

- **A team token**, as a bearer token: `Authorization: Bearer lj_mcp_...`. These are the
  tokens under **API and MCP tokens** on the team page (see [MCP](./mcp#tokens)); a
  token of either scope reads the projects it lists, and nothing of any other project.
  Its expiry, revocation and "last used" work as for MCP.
- **Public stats**, without a header. In a project's **Settings, Keys**, an owner or
  admin turns on **Public stats**. That creates an unguessable URL,
  `https://your-lumberjack/api/v1/public/ps_.../`, which serves the same endpoints for
  that one project. Public stats are off by default, can be rotated (the old URL stops
  working at once) or turned off again, and every change goes in the
  [audit log](./administration). They are aggregates only: no issue messages, stack
  traces, user ids, query strings or host names (hosts show as `host 1`, `host 2`).
  Issue titles are left out too, unless **Include issue titles** is ticked.

Everything below is relative to `https://your-lumberjack/api/v1` with a token, or to the
public stats URL without one.

## Limits and caching

| What                               | Limit                                   |
| ---------------------------------- | --------------------------------------- |
| Requests per token or public key   | 120 a minute, then a 429                |
| Unknown tokens or keys per address | 30 a minute, then a 429 for that minute |
| Identical questions                | answered from a 30 second cache         |
| Time range of a series             | at most 90 days, at most 2,000 points   |

A wrong or missing token gets a 401, an unknown public key or a project the credential
does not read a 404, invalid parameters a 422. Series times are rounded down to 30
seconds, so a wall of panels refreshing every few seconds asks Postgres once per 30
seconds, not once per panel and refresh. Data past a project's
[retention](./projects#retention) is gone and does not show.

## Endpoints

| Endpoint                            | Returns                                                                                                                                                                                               |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /projects`                     | The projects the credential reads: `uuid`, `slug`, `name`.                                                                                                                                            |
| `GET /projects/{p}/summary`         | Requests, errors, error rate, p50/p95/p99, Apdex, open issues, users affected, job runs, failed and stuck jobs, health check counts, and the previous period's requests, errors, p95 and failed jobs. |
| `GET /projects/{p}/series/{metric}` | A time series, see below.                                                                                                                                                                             |
| `GET /projects/{p}/routes`          | Per route: requests, p50/p95/p99, error rate, Apdex.                                                                                                                                                  |
| `GET /projects/{p}/issues`          | Open issues, last seen first: reference, title, status, occurrences, users.                                                                                                                           |
| `GET /projects/{p}/jobs`            | Per job class: runs, failures, failure rate, p50/p95 duration, p95 wait.                                                                                                                              |
| `GET /projects/{p}/queues`          | Per queue: latest size, oldest age, p95 wait.                                                                                                                                                         |
| `GET /projects/{p}/hosts`           | Per agent: online, last seen, CPU load per core, memory and disk in use.                                                                                                                              |
| `GET /projects/{p}/releases`        | Release health: requests, error rate, p95, occurrences, new issues, regressions.                                                                                                                      |
| `GET /metrics`                      | Prometheus text format of the current gauges of every project.                                                                                                                                        |

`{p}` is a project's slug or uuid. The summary and tables cover a `period` ending now:
`1h`, `24h` (default), `7d` or `30d`, as on the project pages, with an optional
`environment`; the tables take a `limit` (100 by default, 50 for releases, at most 500).
Durations are in milliseconds, rates and shares are fractions from 0 to 1.

Tables come as typed columns and rows:

```json
{
    "columns": [
        { "name": "method", "type": "string", "unit": null },
        { "name": "route", "type": "string", "unit": null },
        { "name": "requests", "type": "number", "unit": "count" },
        { "name": "p95", "type": "number", "unit": "ms" }
    ],
    "rows": [["GET", "/checkout", 1204, 412.5]]
}
```

### Series

`GET /projects/{p}/series/{metric}`, where `{metric}` is one of:

| Metric                                 | Unit  | Splits by                         |
| -------------------------------------- | ----- | --------------------------------- |
| `requests`, `errors` (5xx)             | count | environment, route, release       |
| `error_rate`                           | ratio | environment, route, release       |
| `p50`, `p95`, `p99`                    | ms    | environment, route, host, release |
| `apdex`                                | score | environment, route, release       |
| `occurrences`, `users`                 | count | environment, host, release        |
| `new_issues`                           | count | environment, release              |
| `job_runs`, `job_failures`             | count | environment, queue, host          |
| `queue_wait` (p95)                     | ms    | environment, queue, host          |
| `queue_size`                           | count | queue, host                       |
| `cpu_load`, `memory_used`, `disk_used` | ratio | host                              |
| `lcp_p75`, `inp_p75`                   | ms    | environment, route, release       |
| `cls_p75`                              | score | environment, route, release       |

Query parameters, named so Grafana's macros drop straight in:

- `from`, `to`: epoch milliseconds (`${__from}`, `${__to}`) or ISO 8601. The last 24
  hours by default.
- `interval`: the bucket size, such as `30s`, `5m`, `1h` or `1d` (`${__interval}`), or
  `auto` (the default) for about 300 points. At least a minute.
- `environment`, `route`, `queue`, `job`, `host`, `release`: filters. `route`, `queue`,
  `job` and `host` take `*` as a wildcard (`/api/*`). A filter a metric's data does not
  carry, such as a route for a queue's size, is ignored.
- `group_by`: one series per environment, route, host, queue or release, the busiest
  `top` (10 by default, at most 50) of them.

```json
{
    "metric": "p95",
    "unit": "ms",
    "interval": "5m",
    "from": "2026-11-03T10:00:00Z",
    "to": "2026-11-03T11:00:00Z",
    "points": [
        { "t": "2026-11-03T10:00:00Z", "series": "GET /checkout", "value": 412 }
    ]
}
```

Counts are zero in a bucket without data; other metrics have no point there. Without
`group_by`, `series` is the metric's name.

How the numbers are counted:

- Request totals and error rates come from every request the SDK counted, sampled or
  not; percentiles and the Apdex split only from the runs the SDK picked (see
  [Sampling](./performance#sampling)). Asking for a `release` reads the runs weighed by
  their sample rate instead, as the per-minute counts carry no release.
- Apdex uses a 500 ms target, as on the Routes page.
- `cpu_load` is the 1 minute load per core (1 is every core busy); memory and disk are
  the share in use. A host metric is the highest over the hosts, a queue's size the
  largest it reported in a bucket, added up over the queues.

### Prometheus

`GET /metrics` serves gauges for every project the token reads (or the public stats
project), labelled with the project's slug. Counts and rates cover the last hour.

| Gauge                                     | Labels                           |
| ----------------------------------------- | -------------------------------- |
| `lumberjack_requests`                     | `project`                        |
| `lumberjack_error_rate`                   | `project`                        |
| `lumberjack_request_duration_p95_seconds` | `project`                        |
| `lumberjack_apdex`                        | `project`                        |
| `lumberjack_open_issues`                  | `project`                        |
| `lumberjack_users_affected`               | `project`                        |
| `lumberjack_failed_jobs`                  | `project`                        |
| `lumberjack_health_checks`                | `project`, `state`               |
| `lumberjack_queue_size`                   | `project`, `connection`, `queue` |
| `lumberjack_queue_oldest_age_seconds`     | `project`, `connection`, `queue` |
| `lumberjack_agents_online`                | `project`                        |

```yaml
scrape_configs:
    - job_name: lumberjack
      scheme: https
      metrics_path: /api/v1/metrics
      scrape_interval: 60s
      authorization:
          type: Bearer
          credentials: lj_mcp_...
      static_configs:
          - targets: ['your-lumberjack']
```

For public stats, drop `authorization` and set `metrics_path` to
`/api/v1/public/ps_.../metrics`.

## Grafana

1. Install the [Infinity data source](https://grafana.com/grafana/plugins/yesoreyeram-infinity-datasource/)
   and add one. Under **Authentication** pick **Bearer token** and paste the token, and
   add your Lumberjack's address to **Allowed hosts**. For public stats, leave
   authentication off.
2. In a panel, pick the data source, type **JSON**, source **URL**, format **Time
   series**, and a URL such as

    ```
    https://your-lumberjack/api/v1/projects/shop/series/p95?from=${__from}&to=${__to}&interval=${__interval}
    ```

    with **Rows / root** `points` and the columns `t` (timestamp), `value` (number) and
    `series` (string). With `group_by` each series becomes its own line.

3. For a table, use format **Table**, a URL such as `.../projects/shop/routes?period=24h`,
   and **Rows / root** `rows`.

For alerting on gauges, add a Prometheus data source over a Prometheus that scrapes
`/api/v1/metrics` as above.

[`docs/grafana/lumberjack.json`](./grafana/lumberjack.json) is a dashboard to import
(**Dashboards, New, Import**): requests, error rate, p95 per route and the routes table
for one project, picked with the `project` variable.
