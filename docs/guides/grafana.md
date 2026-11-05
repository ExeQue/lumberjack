# Send Lumberjack metrics to Grafana

Chart Lumberjack's requests, errors, latency, queues and hosts in Grafana, and alert on
them there. Grafana reads Lumberjack's read-only metrics API.

## 1. Get a credential

Pick one:

- **A team token.** A team owner or admin creates one under **Team** > **API and MCP
  tokens**, with the projects Grafana may read and the **read** scope. It starts with
  `lj_mcp_` and is shown once. See [Let an AI agent read your errors](./ai-agents.md)
  for the form.
- **Public stats**, for one project and without a token. An owner or admin turns on
  **Public stats** under **Keys** in the project's **Settings**. This gives an unguessable
  URL ending in `/api/v1/public/ps_.../` that serves aggregates only: no error messages,
  stack traces or host names.

## 2. Add the data source

1. In Grafana, install the
   [Infinity data source](https://grafana.com/grafana/plugins/yesoreyeram-infinity-datasource/)
   plugin and add a data source of that type.
2. Under **Authentication**, pick **Bearer token** and paste the token. For public
   stats, leave authentication off.
3. Add your Lumberjack's address to **Allowed hosts**.

## 3. Import the dashboard

Lumberjack ships a ready-made dashboard:
[`docs/grafana/lumberjack.json`](../grafana/lumberjack.json).

1. In Grafana, go to **Dashboards**, **New**, **Import**, and upload the file.
2. Pick the Infinity data source you added.
3. At the top of the dashboard, set **Lumberjack URL** to your Lumberjack's address and
   **Project slug** to the project, such as `shop`.

**When it works:** the dashboard shows **Requests**, **Error rate**, **p95 per route** for
the five busiest routes, and a **Routes** table, refreshed every 30 seconds.

## 4. Build your own panels

In a new panel, pick the Infinity data source, type **JSON**, source **URL**, format
**Time series**, and a URL such as:

```
https://your-lumberjack/api/v1/projects/shop/series/p95?from=${__from}&to=${__to}&interval=${__interval}
```

Set **Rows / root** to `points`, and add the columns `t` (timestamp), `value` (number)
and `series` (string).

- Swap `p95` for another metric: `requests`, `errors`, `error_rate`, `apdex`,
  `occurrences`, `queue_size`, `queue_wait`, `job_failures`, `cpu_load`, `lcp_p75` and
  more.
- Add `&group_by=route` (or `queue`, `host`, `release`, ...) for one line per value.
- Add filters such as `&environment=production` or `&route=/api/*`.

For a table, use format **Table**, a URL such as
`https://your-lumberjack/api/v1/projects/shop/routes?period=24h`, and **Rows / root**
`rows`. Other tables are `issues`, `jobs`, `queues`, `hosts` and `releases`.

With public stats, replace `https://your-lumberjack/api/v1` with the public stats URL.

## 5. Alert on the numbers

Lumberjack serves its current numbers in the Prometheus text format at
`/api/v1/metrics`:

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

Add that Prometheus as a data source in Grafana and alert on gauges such as
`lumberjack_error_rate`, `lumberjack_request_duration_p95_seconds` or
`lumberjack_queue_size`. These cover what Lumberjack's own alert rules do not, such as
a project's error rate or p95 crossing a limit.

## Limits

| What                             | Limit                         |
| -------------------------------- | ----------------------------- |
| Requests per token or public key | 120 a minute                  |
| Time range of a series           | 90 days, at most 2,000 points |

## Reference

- [Metrics API](../metrics-api.md): every endpoint, metric, filter and gauge.
- [MCP](../mcp.md): creating and revoking tokens.
