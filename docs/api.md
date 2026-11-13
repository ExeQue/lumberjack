# Ingest and API

SDKs and their agents send data to Lumberjack over HTTP. This page lists the endpoints,
their payloads and limits, for running Lumberjack behind a proxy or writing your own SDK.

## Endpoints

| Endpoint                                 | Auth                              | Limit                                                     |
| ---------------------------------------- | --------------------------------- | --------------------------------------------------------- |
| `POST /api/ingest`                       | Server token, as a bearer token   | 600 batches a minute per project                          |
| `POST /api/ingest/heartbeat`             | Server token                      | Counted with the batches                                  |
| `POST /api/ingest/browser?key=`          | Public key, and an allowed origin | 30 a minute per address and key, 300 a minute per address |
| `POST /api/ingest/sourcemaps`            | Server token                      | 120 a minute per project                                  |
| `POST /api/v1/opentelemetry/v1/...`      | Server token                      | Counted with the batches                                  |
| `GET /api/health`                        | Server token                      | 120 a minute per project                                  |
| `DELETE /api/activity/actor`             | Server token                      | 60 a minute per project                                   |
| `GET` or `POST /api/health/ping/{token}` | The heartbeat check's secret URL  | 60 a minute per address                                   |
| `/mcp`                                   | MCP token, as a bearer token      | 120 calls a minute per token                              |
| `GET /api/v1/...`                        | Team token, or public stats key   | 120 a minute per token or key                             |

See the [metrics API](./metrics-api) and [MCP](./mcp) pages for those endpoints, and
[OpenTelemetry](./opentelemetry) for sending OTLP from an OpenTelemetry SDK or collector.

### Responses

| Status | When                                                                                  |
| ------ | ------------------------------------------------------------------------------------- |
| `202`  | A batch is accepted, with `{"accepted": <count>}`. It is processed in the background. |
| `401`  | The token is wrong or missing.                                                        |
| `400`  | The body is not valid gzip.                                                           |
| `413`  | The body is larger than 1 MB.                                                         |
| `415`  | The browser endpoint got a compressed body.                                           |
| `422`  | The payload is invalid. The answer names the fields at fault.                         |
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

| Field         | Required | Notes                                                                                                                                                                              |
| ------------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `v`           | yes      | Always `1`.                                                                                                                                                                        |
| `environment` | no       | At most 64 characters.                                                                                                                                                             |
| `release`     | no       | At most 64 characters.                                                                                                                                                             |
| `host`        | no       | At most 255 characters.                                                                                                                                                            |
| `events`      | yes      | At most 5,000 events, each of type `request`, `request_counts`, `query`, `exception`, `job`, `log`, `schedule`, `command`, `span`, `health`, `alert`, `maintenance` or `activity`. |

### Requests

A `request` event carries `method`, `route`, `url`, `status`, `duration_ms` and
`memory_peak`. `route` is the route's URI, or the path when no route matched. It may
also carry:

| Field        | Notes                                                                                                                                                                               |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `domain`     | The host the request was made to, such as `shop.example.com`. Include the port only when it is not the default. Without it, Lumberjack takes the host from `url`. Stored lowercase. |
| `route_name` | The framework's name for the route, such as `orders.show`.                                                                                                                          |

### Sampling

An SDK that samples marks each run. A `request`, `job`, `command` or `schedule` event,
and each `query` and `span` of a run, may carry:

| Field         | Notes                                                                                                       |
| ------------- | ----------------------------------------------------------------------------------------------------------- |
| `sample_rate` | The share of runs the SDK picks, from 0 to 1. Defaults to `1`.                                              |
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

| Field                      | Notes                                                                                      |
| -------------------------- | ------------------------------------------------------------------------------------------ |
| `counts`                   | Required. A list of at most 501 rows. A missing list or a longer one is rejected with 422. |
| `counts[].method`          | The HTTP method.                                                                           |
| `counts[].route`           | The route's URI, or the path when no route matched.                                        |
| `counts[].domain`          | Optional, as on a `request` event.                                                         |
| `counts[].status`          | The response status.                                                                       |
| `counts[].count`           | The number of requests. A row without a positive `count` is skipped.                       |
| `counts[].duration_ms_sum` | The summed duration of those requests, in milliseconds.                                    |
| `from`, `to`, `timestamp`  | Unix time in seconds. The counts are filed under the minute of `timestamp`.                |

Send one event per request or one every few seconds, with the same result. The counts
are kept as long as the [request runs](./projects#retention), and used as described
under [Sampling](./performance#sampling).

## Heartbeat

The agent sends `POST /api/ingest/heartbeat` once a minute. It fills the project's
[Agents](./projects#agents) list and the [Queues card](./performance#queues).

| Field         | Notes                                                                                                                                     |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `host`        | Required. At most 255 characters.                                                                                                         |
| `name`        | The agent's name. At most 64 characters.                                                                                                  |
| `environment` | At most 64 characters.                                                                                                                    |
| `client`      | The `sdk`, `agent`, `runtime` and `framework`, each with a `name` and a `version`.                                                        |
| `queues`      | At most 50, each with `connection`, `queue`, `size`, and optionally `oldest_age_s` and `workers`.                                         |
| `metrics`     | `cpu_load_1m`, `cpu_cores`, `memory_used_bytes`, `memory_total_bytes`, `disk_used_bytes`, `disk_total_bytes` and `php_memory_peak_bytes`. |

The answer holds the project's health check states under `health`, the same as
[`GET /api/health`](./health#reading-states-from-the-app), and the event types this
server knows under `features`. An event of a type the server does not know is skipped,
and the rest of its batch is kept, so a newer agent can talk to an older server.

## Alert events

An event of type `alert` sends a [custom alert](./alerts#custom-alerts):

```json
{
    "type": "alert",
    "title": "Payment failed",
    "topic": "payments",
    "level": "critical",
    "message": "Order 1042 was declined.",
    "url": "https://shop.example/orders/1042",
    "fields": [{ "name": "Amount", "value": "499 kr", "inline": true }],
    "tables": [
        {
            "title": "Items",
            "headers": ["Item", "Qty"],
            "rows": [["Print", "2"]]
        }
    ],
    "links": [
        {
            "label": "Refund",
            "url": "https://shop.example/r/1",
            "style": "danger"
        }
    ],
    "dedupe_key": "order-1042",
    "dedupe_minutes": 10
}
```

| Field                         | Notes                                                                                                        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `title`                       | Required. An event without it is skipped.                                                                    |
| `topic`                       | Which topic to send to. Made into a slug. Without it, `default`.                                             |
| `level`                       | `info`, `success`, `warning` or `critical`. Sets the color and the urgency.                                  |
| `message`                     | The text under the title.                                                                                    |
| `url`                         | Where the title links to. `http` or `https` only.                                                            |
| `fields`                      | At most 20, each with `name`, `value` and `inline`.                                                          |
| `tables`                      | At most 3, with `title`, `headers` (at most 8) and `rows` (at most 20).                                      |
| `links`                       | At most 5, with `label` (at most 75 characters), `url` (at most 512) and a `style` of `primary` or `danger`. |
| `dedupe_key`,`dedupe_minutes` | A repeat of the key within the minutes is listed, but not sent.                                              |
| `timestamp`                   | When it happened.                                                                                            |

## Maintenance events

An event of type `maintenance` opens or closes a
[maintenance window](./health#maintenance-windows) of the batch's environment:

```json
{
    "type": "maintenance",
    "state": "start",
    "message": "Upgrading the database",
    "expected_minutes": 15,
    "timestamp": 1762164000.123
}
```

| Field              | Notes                                                                                                                                                   |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `state`            | Required. `start` or `end`. An event with anything else is skipped.                                                                                     |
| `message`          | What is going on. Cut to 500 characters.                                                                                                                |
| `expected_minutes` | How long it should take, at most a week.                                                                                                                |
| `expected_until`   | When it should be over, in ISO 8601, kept between the start and a week later. Wins over `expected_minutes`. Without either, the project's default (30). |
| `timestamp`        | When it started or ended.                                                                                                                               |

The environment comes from the batch, like `release` and `host`; a batch without one
covers every environment of the project. A `start` while a window of the environment is
open keeps that window, with the new message and expected end where the event sends
them. An `end` closes the environment's open window, and does nothing when none is
open. Other fields are ignored. Browsers cannot send it.

## Activity events

An event of type `activity` records an [activity](./activity.md):

```json
{
    "type": "activity",
    "name": "order.placed",
    "group": "billing",
    "actor": { "type": "App\\Models\\User", "id": "17" },
    "subject": { "type": "order", "id": "42" },
    "payload": { "total": 499, "currency": "DKK" },
    "trace_id": "4bf92f3577b34da6",
    "timestamp": 1763025600.123
}
```

| Field       | Notes                                                                                              |
| ----------- | -------------------------------------------------------------------------------------------------- |
| `name`      | Required. Letters, digits, `.`, `_`, `:` and `-`, at most 128 characters. Otherwise it is skipped. |
| `group`     | Made into a slug. Without it, `default`.                                                           |
| `actor`     | Who did it: a `type` (at most 128 characters) and an `id` (at most 64). Left out without both.     |
| `subject`   | What it was done to, in the same form.                                                             |
| `payload`   | A JSON object, scrubbed. Larger than 16 KB, it is replaced by `{"_truncated": true}`.              |
| `trace_id`  | The trace it was recorded in.                                                                      |
| `timestamp` | When it happened.                                                                                  |

### Forgetting an actor

`DELETE /api/activity/actor` with the server token and a JSON body of the actor's `type`
and `id` deletes all activity of that actor in the project, and answers
`{"deleted": <count>}`. The hourly counts are kept.

## Browser

`POST /api/ingest/browser?key=<public key>` takes plain JSON. The browser script sends
it with `sendBeacon` as `text/plain`. A batch holds at most 50 events of type
`exception` or `vital`.

The request's `Origin` must be one of the project's
[allowed origins](./projects#settings), which get a CORS preflight answer. Requests from crawlers and automated browsers are accepted and dropped.

## Source maps

`POST /api/ingest/sourcemaps` takes one source map as a multipart upload, with the
server token. See [Source maps](./projects#source-maps) for what they do.

| Field     | Holds                                                                          |
| --------- | ------------------------------------------------------------------------------ |
| `file`    | The `.map` file, a version 3 source map of at most 20 MB.                      |
| `path`    | The public path of the bundle it belongs to, e.g. `/build/assets/app-3f9a.js`. |
| `release` | Optional. The release, as the browser script's `data-release` sends it.        |

```bash
for map in public/build/assets/*.js.map; do
    curl -fsS https://lumberjack.example/api/ingest/sourcemaps \
        -H "Authorization: Bearer $LUMBERJACK_TOKEN" \
        -F "file=@$map" \
        -F "path=/build/assets/$(basename "$map" .map)" \
        -F "release=$(git rev-parse --short HEAD)"
done
rm public/build/assets/*.map
```

The answer is `201` with the stored `path`, `release` and `size`. The same path and
release uploaded again replaces the map. A file that is not a source map gets a `422`,
and a map that could not be stored gets a `503`; upload it again.

## Limits

| What                                | Limit |
| ----------------------------------- | ----- |
| Batch body                          | 1 MB  |
| Events per batch                    | 5,000 |
| Rows per `request_counts` event     | 501   |
| Events per browser batch            | 50    |
| Source map file                     | 20 MB |
| Queues per heartbeat                | 50    |
| Unknown tokens per address a minute | 30    |
