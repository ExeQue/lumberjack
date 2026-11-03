# Ingest and API

SDKs and their agents send to Lumberjack over HTTP. This page lists the endpoints and
their limits, for running Lumberjack behind a proxy or writing an SDK of your own. The
Laravel SDK does all of this for you.

| Endpoint                                 | Auth                              | Limit                            |
| ---------------------------------------- | --------------------------------- | -------------------------------- |
| `POST /api/ingest`                       | Server token, as a bearer token   | 600 batches a minute per project |
| `POST /api/ingest/heartbeat`             | Server token                      | Counted with the batches         |
| `POST /api/ingest/browser?key=`          | Public key, and an allowed origin | 30 a minute per address and key  |
| `GET /api/health`                        | Server token                      | 120 a minute per project         |
| `GET` or `POST /api/health/ping/{token}` | The heartbeat check's secret URL  | 60 a minute per address          |
| `/mcp`                                   | MCP token, as a bearer token      | 120 calls a minute per token     |

A wrong or missing token gets a 401, a limit a 429.

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
most 5,000 events, each of type `request`, `query`, `exception`, `job`, `log`,
`schedule`, `command`, `span` or `health`. Lumberjack answers `202` with
`{"accepted": <count>}` and processes the batch on its `ingest` queue.

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
