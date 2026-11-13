# Metrics API

A read-only JSON API for charting Lumberjack's data in Grafana or reading it from any
HTTP client: requests, errors, latency, issues, jobs, queues, hosts, web vitals and
releases. It also serves the current gauges in the Prometheus text format, for scraping
and alerting.

## Authentication

- **A team token**, sent as `Authorization: Bearer lj_mcp_...`. Create it under **API
  and MCP tokens** on the team page, as described in [MCP](./mcp#tokens). The token
  reads the projects it covers that its creator may see, where it may use
  `get_metric_series`. `issues` also needs `list_issues` in that project.
- **Public stats**, without a header. An owner or admin turns on **Public stats** in a
  project's **Settings, Keys**. That creates a secret URL,
  `https://your-lumberjack/api/v1/public/ps_.../`, which serves the same endpoints for
  that one project. Rotating the URL stops the old one at once. Every change goes into
  the [audit log](./administration).

Public stats show aggregates only. They leave out issue messages, stack traces, user
ids, query strings and host names. Hosts show as `host 1`, `host 2`. Issue titles are
left out unless **Include issue titles** is ticked.

Paths below are relative to `https://your-lumberjack/api/v1` with a token, or to the
public stats URL without one.

## Limits and errors

| What                               | Limit                                   |
| ---------------------------------- | --------------------------------------- |
| Requests per token or public key   | 120 a minute, then `429`                |
| Unknown tokens or keys per address | 30 a minute, then `429` for that minute |
| Time range of a series             | at most 90 days and 2,000 points        |
| Freshness                          | answers can be up to 30 seconds old     |

| Status | When                                                                  |
| ------ | --------------------------------------------------------------------- |
| `401`  | The token is wrong or missing.                                        |
| `404`  | The public key is unknown, or the credential cannot read the project. |
| `422`  | A parameter is invalid.                                               |

Data past a project's [retention](./projects#retention) is deleted and does not show.

## Endpoints

| Endpoint                            | Returns                                                                                                                                                                                               |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /projects`                     | The projects the credential reads: `uuid`, `slug`, `name`.                                                                                                                                            |
| `GET /projects/{p}/summary`         | Requests, errors, error rate, p50/p95/p99, Apdex, open issues, users affected, job runs, failed and stuck jobs, health check counts, and the previous period's requests, errors, p95 and failed jobs. |
| `GET /projects/{p}/series/{metric}` | A time series, see below.                                                                                                                                                                             |
| `GET /projects/{p}/routes`          | Per route: name, domain (with several), requests, p50/p95/p99, error rate, Apdex.                                                                                                                     |
| `GET /projects/{p}/issues`          | Open issues, last seen first: reference, title, status, occurrences, users.                                                                                                                           |
| `GET /projects/{p}/jobs`            | Per job class: runs, failures, failure rate, p50/p95 duration, p95 wait.                                                                                                                              |
| `GET /projects/{p}/queues`          | Per queue: latest size, oldest age, p95 wait.                                                                                                                                                         |
| `GET /projects/{p}/hosts`           | Per agent: online, last seen, CPU load per core, memory and disk in use.                                                                                                                              |
| `GET /projects/{p}/releases`        | Release health: requests, error rate, p95, occurrences, new issues, regressions.                                                                                                                      |
| `GET /metrics`                      | Prometheus text format of the current gauges of every project.                                                                                                                                        |

`{p}` is a project's slug or uuid. The summary and tables take:

| Parameter     | Values                                                     |
| ------------- | ---------------------------------------------------------- |
| `period`      | `1h`, `24h` (default), `7d` or `30d`, ending now           |
| `environment` | Optional. Only this environment.                           |
| `limit`       | Tables only. 100 by default, 50 for releases, at most 500. |

Durations are in milliseconds, rates and shares are fractions from 0 to 1, and sizes are
in bytes.

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

| Metric                                 | Unit  | Splits by                                 |
| -------------------------------------- | ----- | ----------------------------------------- |
| `requests`, `errors` (5xx)             | count | environment, route, domain, release       |
| `error_rate`                           | ratio | environment, route, domain, release       |
| `p50`, `p95`, `p99`                    | ms    | environment, route, domain, host, release |
| `apdex`                                | score | environment, route, domain, release       |
| `occurrences`, `users`                 | count | environment, host, release                |
| `new_issues`                           | count | environment, release                      |
| `job_runs`, `job_failures`             | count | environment, queue, host                  |
| `queue_wait` (p95)                     | ms    | environment, queue, host                  |
| `queue_size`                           | count | queue, host                               |
| `queue_workers`                        | count | queue, host                               |
| `cpu_load`, `memory_used`, `disk_used` | ratio | host                                      |
| `lcp_p75`, `inp_p75`                   | ms    | environment, route, release               |
| `cls_p75`                              | score | environment, route, release               |
| `db_size`                              | bytes | environment, check                        |
| `db_connections`                       | count | environment, check                        |
| `db_connections_used`                  | ratio | environment, check                        |
| `activity`                             | count | environment, activity                     |

Query parameters, which match Grafana's macros:

- `from`, `to`: epoch milliseconds (`${__from}`, `${__to}`) or ISO 8601. The last 24
  hours by default.
- `interval`: the bucket size, such as `30s`, `5m`, `1h` or `1d` (`${__interval}`), or
  `auto` (the default) for about 300 points. The interval is at least a minute, rounded
  up to whole minutes, and raised so a series has at most 2,000 points. The answer's
  `interval` says what was used.
- `environment`, `route`, `domain`, `queue`, `job`, `host`, `release`, `check`,
  `activity`: filters. `route`, `domain`, `queue`, `job`, `host`, `check` (a health
  check's slug, such as `database:pgsql`) and `activity` (`group:name`, such as
  `billing:invoice.paid`) take `*` as a wildcard (`/api/*`, `*.example.com`). `route` matches a
  route's URI or its name (`orders.*`). A filter that does not apply to a metric is ignored, except `host`, which is
  rejected with 422.
- `group_by`: one series per environment, route, domain, host, queue, release, check or
  activity (as `group:name`).
  Only the busiest `top` are returned, 10 by default and at most 50.

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

In a bucket without data, counts are zero and other metrics have no point. Without
`group_by`, `series` is the metric's name.

#### How the numbers are counted

- Request totals and error rates count every request, sampled or not. Percentiles and
  Apdex use only the sampled runs. See [Sampling](./performance#sampling). With a
  `release` filter or split, totals are estimated from the sampled runs and their
  sample rate.
- Apdex uses a 500 ms target, as on the [Requests page](./performance#apdex).
- `cpu_load` is the 1 minute load per core (1 is every core busy); memory and disk are
  the share in use. A host metric is the highest value over the hosts. A queue's size
  is the largest it reported in a bucket, summed over the queues.
- The database metrics come from [database health checks](./health#database-metrics).
  Each check counts its largest figure in a bucket, summed over the checks. A database
  reported by several servers counts once. `db_connections_used` is `connections` over
  `max_connections`, the highest over the checks.
- `activity` counts [activity](./activity) per hour when the interval is whole hours,
  so an hourly series reaches back as far as the hourly counts are kept. A shorter
  interval counts the activity itself, kept for less time. With hourly buckets, the
  first hour of the range counts whole.

### Prometheus

`GET /metrics` serves gauges for every project the credential reads, labeled with the
project's slug. Counts and rates cover the last hour. The database gauges show each
database check's latest report.

| Gauge                                     | Labels                            |
| ----------------------------------------- | --------------------------------- |
| `lumberjack_requests`                     | `project`                         |
| `lumberjack_error_rate`                   | `project`                         |
| `lumberjack_request_duration_p95_seconds` | `project`                         |
| `lumberjack_apdex`                        | `project`                         |
| `lumberjack_open_issues`                  | `project`                         |
| `lumberjack_users_affected`               | `project`                         |
| `lumberjack_failed_jobs`                  | `project`                         |
| `lumberjack_health_checks`                | `project`, `state`                |
| `lumberjack_queue_size`                   | `project`, `connection`, `queue`  |
| `lumberjack_queue_workers`                | `project`, `connection`, `queue`  |
| `lumberjack_queue_oldest_age_seconds`     | `project`, `connection`, `queue`  |
| `lumberjack_agents_online`                | `project`                         |
| `lumberjack_database_size_bytes`          | `project`, `environment`, `check` |
| `lumberjack_database_connections`         | `project`, `environment`, `check` |
| `lumberjack_database_max_connections`     | `project`, `environment`, `check` |

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
   and add one. Under **Authentication**, pick **Bearer token**, paste the token, and
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

For alerting on gauges, use a Prometheus that scrapes `/api/v1/metrics` as above.

To start quickly, import [`docs/grafana/lumberjack.json`](./grafana/lumberjack.json)
under **Dashboards, New, Import**. It shows requests, error rate, p95 per route and the
routes table for the project picked in the `project` variable.
