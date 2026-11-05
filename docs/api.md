# Ingest and API

SDKs and their agents send data to Lumberjack over HTTP. This page lists the endpoints,
their payloads and limits, for running Lumberjack behind a proxy or writing your own SDK.
An official SDK does all of this for you.

## Endpoints

| Endpoint                                 | Auth                              | Limit                                                     |
| ---------------------------------------- | --------------------------------- | --------------------------------------------------------- |
| `POST /api/ingest`                       | Server token, as a bearer token   | 600 batches a minute per project                          |
| `POST /api/ingest/heartbeat`             | Server token                      | Counted with the batches                                  |
| `POST /api/ingest/browser?key=`          | Public key, and an allowed origin | 30 a minute per address and key, 300 a minute per address |
| `GET /api/health`                        | Server token                      | 120 a minute per project                                  |
| `GET` or `POST /api/health/ping/{token}` | The heartbeat check's secret URL  | 60 a minute per address                                   |
| `/mcp`                                   | MCP token, as a bearer token      | 120 calls a minute per token                              |
| `GET /api/v1/...`                        | Team token, or public stats key   | 120 a minute per token or key                             |

The read-only [metrics API](./metrics-api) under `/api/v1` has its own page, and so does
[MCP](./mcp).

### Responses

| Status | When                                                                              |
| ------ | --------------------------------------------------------------------------------- |
| `202`  | A batch is accepted, with `{"accepted": <count>}`. It is processed in the background. |
| `401`  | The token is wrong or missing.                                                    |
| `400`  | The body is not valid gzip.                                                       |
| `413`  | The body is larger than 1 MB.                                                     |
| `415`  | The browser endpoint got a compressed body.                                       |
| `422`  | The payload is invalid. The answer names the fields at fault.                     |
| `429`  | A rate limit is hit, or the address sent 30 unknown server or MCP tokens this minute. |

## Batches

`POST /api/ingest` takes a JSON batch, plain or with `Content-Encoding: gzip`:

```json
{
    "v": 1,
    "environment": "production",
    "release": "4f2c1a9",
    "host": "web-1",
    "events": [{ "type": "exception", "...": "..." }]
}
```

| Field         | Required | Notes                                                                                                                    |
| ------------- | -------- | ------------------------------------------------------------------------------------------------------------------------ |
| `v`           | yes      | Always `1`.                                                                                                              |
| `environment` | no       | At most 64 characters.                                                                                                   |
| `release`     | no       | At most 64 characters.                                                                                                   |
| `host`        | no       | At most 255 characters.                                                                                                  |
| `events`      | yes      | At most 5,000 events, each of type `request`, `request_counts`, `query`, `exception`, `job`, `log`, `schedule`, `command`, `span` or `health`. |

### Requests

A `request` event carries `method`, `route`, `url`, `status`, `duration_ms` and
`memory_peak`. `route` is the route's URI, or the path when no route matched. It may
also carry:

| Field        | Notes                                                                                                                                                                  |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `domain`     | The host the request was made to, such as `shop.example.com`. Include the port only when it is not the default. Without it, Lumberjack takes the host from `url`. Stored lowercase. |
| `route_name` | The framework's name for the route, such as `orders.show`.                                                                                                             |

### Sampling

An SDK that samples marks each run. A `request`, `job`, `command` or `schedule` event,
and each `query` and `span` of a run, may carry:

| Field         | Notes                                                                                 |
| ------------- | ------------------------------------------------------------------------------------- |
| `sample_rate` | The share of runs the SDK picks, from 0 to 1. Defaults to `1`.                         |
| `sampled`     | `true` when the run was picked, `false` when it was kept because it was slow or failed. Defaults to `true`. |

A `request_counts` event counts every request the SDK recorded since its last one,
sampled or not:

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

| Field                      | Notes                                                                                     |
| -------------------------- | ----------------------------------------------------------------------------------------- |
| `counts`                   | Required. A list of at most 501 rows. A missing list or a longer one is rejected with 422. |
| `counts[].method`          | The HTTP method.                                                                          |
| `counts[].route`           | The route's URI, or the path when no route matched.                                       |
| `counts[].domain`          | Optional, as on a `request` event.                                                        |
| `counts[].status`          | The response status.                                                                      |
| `counts[].count`           | The number of requests. A row without a positive `count` is skipped.                      |
| `counts[].duration_ms_sum` | The summed duration of those requests, in milliseconds.                                   |
| `from`, `to`, `timestamp`  | Unix time in seconds. The counts are filed under the minute of `timestamp`.               |

You can send one event per request or one every few seconds. The result is the same.
The counts are kept as long as the [request runs](./projects#retention). The pages use
them as described under [Sampling](./performance#sampling).

## Heartbeat

The agent sends `POST /api/ingest/heartbeat` once a minute. It fills the project's
[Agents](./projects#agents) list and the [Queues card](./performance#queues).

| Field         | Notes                                                                                                                                                         |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `host`        | Required. At most 255 characters.                                                                                                                             |
| `name`        | The agent's name. At most 64 characters.                                                                                                                      |
| `environment` | At most 64 characters.                                                                                                                                        |
| `client`      | The `sdk`, `agent`, `runtime` and `framework`, each with a `name` and a `version`.                                                                            |
| `queues`      | At most 50, each with `connection`, `queue`, `size`, and optionally `oldest_age_s` and `workers`.                                                             |
| `metrics`     | `cpu_load_1m`, `cpu_cores`, `memory_used_bytes`, `memory_total_bytes`, `disk_used_bytes`, `disk_total_bytes` and `php_memory_peak_bytes`.                       |

The answer holds the project's health check states under `health`, the same as
[`GET /api/health`](./health#reading-states-from-the-app).

## Browser

`POST /api/ingest/browser?key=<public key>` takes plain JSON. The browser script sends
it with `sendBeacon` as `text/plain`. A batch holds at most 50 events of type
`exception` or `vital`.

The request's `Origin` must be one of the project's
[allowed origins](./projects#settings). Lumberjack answers the CORS preflight for those
origins. Requests from crawlers and automated browsers are accepted and dropped.

## Limits

| What                                | Limit     |
| ----------------------------------- | --------- |
| Batch body                          | 1 MB      |
| Events per batch                    | 5,000     |
| Rows per `request_counts` event     | 501       |
| Events per browser batch            | 50        |
| Queues per heartbeat                | 50        |
| Unknown tokens per address a minute | 30        |
