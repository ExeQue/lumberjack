# Ingest and API

SDKs and their agents send to Lumberjack over HTTP. This page lists the endpoints and
their limits, for running Lumberjack behind a proxy or writing an SDK of your own. An
SDK does all of this for you.

| Endpoint                                 | Auth                              | Limit                                                     |
| ---------------------------------------- | --------------------------------- | --------------------------------------------------------- |
| `POST /api/ingest`                       | Server token, as a bearer token   | 600 batches a minute per project                          |
| `POST /api/ingest/heartbeat`             | Server token                      | Counted with the batches                                  |
| `POST /api/ingest/browser?key=`          | Public key, and an allowed origin | 30 a minute per address and key, 300 a minute per address |
| `GET /api/health`                        | Server token                      | 120 a minute per project                                  |
| `GET` or `POST /api/health/ping/{token}` | The heartbeat check's secret URL  | 60 a minute per address                                   |
| `/mcp`                                   | MCP token, as a bearer token      | 120 calls a minute per token                              |
| `GET /api/v1/...`                        | Team token, or public stats key   | 120 a minute per token or key                             |

The read-only [metrics API](./metrics-api) under `/api/v1` (Grafana, Prometheus) has its
own page. A wrong or missing token gets a 401, a limit a 429. An address that sends 30 unknown
server or MCP tokens in a minute gets a 429 for the rest of that minute, before any token
is looked up. The browser endpoint checks the key and origin before it reads the body,
and answers a compressed body with a 415: browsers send plain JSON.

## Batches

`POST /api/ingest` takes a JSON batch of at most 1 MB, plain or with
`Content-Encoding: gzip`:

```json
{
    "v": 1,
    "environment": "production",
    "release": "4f2c1a9",
    "host": "web-1",
    "events": [{ "type": "exception", "...": "..." }]
}
```

`environment` and `release` are at most 64 characters, `host` 255. A batch holds at
most 5,000 events, each of type `request`, `request_counts`, `query`, `exception`, `job`,
`log`, `schedule`, `command`, `span` or `health`. Lumberjack answers `202` with
`{"accepted": <count>}` and processes the batch on its `ingest` queue.

### Requests

A `request` event carries its `method`, `route` (the route's URI, or the path when no
route matched), `url`, `status`, `duration_ms` and `memory_peak`, and may carry:

- `domain`: the host the request was made to, such as `shop.example.com`, with the port
  only when it is not the default one. Without it, Lumberjack takes the host (and port)
  of `url`. Stored lowercased.
- `route_name`: the framework's name of the route, such as `orders.show`.

### Sampling

An SDK that samples says so on each run, so Lumberjack can show the true traffic. A
`request`, `job`, `command` or `schedule` event, and each `query` and `span` of a run,
may carry:

- `sample_rate`: the share of runs the SDK picks, from 0 to 1.
- `sampled`: `true` when the run was picked, `false` when it was kept only because it
  was slow or failed.

An event without them counts as `sample_rate: 1`, `sampled: true`.

A `request_counts` event counts every request the SDK recorded, sampled or not, since
its last one:

```json
{
    "type": "request_counts",
    "counts": [
        {
            "method": "GET",
            "route": "/orders/{order}",
            "domain": "shop.example.com",
            "status": 200,
            "count": 2,
            "duration_ms_sum": 31.4
        }
    ],
    "from": 1762179000.12,
    "to": 1762179005.34,
    "timestamp": 1762179005.34
}
```

`route` is the route's URI, or the path when no route matched; `domain` is optional, as
on a `request` event, and counts without one are kept apart under no domain. `counts` is a list of at
most 501 rows; a batch with a longer one, or none, is turned down with a 422. A row
without a positive `count` is skipped. Lumberjack adds the counts up per project,
environment, minute (of `timestamp`), method, domain, route and status, so one event per request
(a process per request) and one per few seconds (a long-running worker) end up the same. The counts
are kept as long as the [request runs](./projects#retention).

How the pages use these is under [Sampling](./performance#sampling).

## Heartbeat

`POST /api/ingest/heartbeat` is the agent checking in, once a minute. It carries the
`host` (required), the agent's `name` and `environment`, the `client` (the SDK, agent,
runtime and framework, each with a name and a version), up to 50 `queues` with their
`size` and `oldest_age_s`, and the host's `metrics`: `cpu_load_1m`, `cpu_cores`,
`memory_used_bytes`, `memory_total_bytes`, `disk_used_bytes`, `disk_total_bytes` and
`php_memory_peak_bytes`. These fill the project's [Agents](./projects#agents) list and
the [Queues card](./performance#queues).

The answer carries the project's health check states under `health`, the same as
[`GET /api/health`](./health#reading-states-from-the-app).

## Browser

`POST /api/ingest/browser?key=<public key>` takes plain JSON (the script sends it with
`sendBeacon` as `text/plain`) with at most 50 events of type `exception` or `vital`.
The request's `Origin` has to be one of the project's
[allowed origins](./projects#settings); the CORS preflight is answered for them.
Requests from crawlers and automated browsers are accepted and dropped.
