# OpenTelemetry

Any app with an [OpenTelemetry](https://opentelemetry.io/) SDK, or an OpenTelemetry Collector,
can send traces, logs and exceptions to Lumberjack over OTLP/HTTP. Use it for languages
without a [Lumberjack library](./libraries.md). The data lands where a library's would:
requests, queries, jobs, traces, logs and issues, with the same scrubbing, grouping,
releases and alerts.

## Endpoints

| Endpoint                                | Takes                      |
| --------------------------------------- | -------------------------- |
| `POST /api/v1/opentelemetry/v1/traces`  | Spans                      |
| `POST /api/v1/opentelemetry/v1/logs`    | Log records                |
| `POST /api/v1/opentelemetry/v1/metrics` | Metrics, which are dropped |

Set the base endpoint `https://lumberjack.example/api/v1/opentelemetry`; SDKs and the
collector add `/v1/traces` and `/v1/logs` themselves. Authenticate with the project's
server token as a bearer token.

- Encodings: protobuf (`application/x-protobuf`) and JSON (`application/json`, the
  [OTLP/JSON](https://opentelemetry.io/docs/specs/otlp/#json-protobuf-encoding) form with hex
  trace and span ids), plain or with `Content-Encoding: gzip`.
- No gRPC. Run a collector to turn gRPC into HTTP.
- No metrics. Lumberjack answers a metrics export with success and stores nothing, so an
  SDK that exports metrics by default does not log an error every minute. Turn them off with
  `OTEL_METRICS_EXPORTER=none` to save the traffic.

## SDK

Most SDKs read these environment variables:

```bash
OTEL_SERVICE_NAME=shop
OTEL_EXPORTER_OTLP_ENDPOINT=https://lumberjack.example/api/v1/opentelemetry
OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer lj_srv_..."
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_TRACES_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp
OTEL_METRICS_EXPORTER=none
OTEL_RESOURCE_ATTRIBUTES="deployment.environment.name=production,service.version=2.4.1"
```

`http/json` works too. Escape the space in the header as `%20` if your SDK asks for it.

## Collector

The collector's `otlphttp` exporter, behind any receiver:

```yaml
receivers:
    otlp:
        protocols:
            grpc:
            http:

processors:
    batch:

exporters:
    otlphttp/lumberjack:
        endpoint: https://lumberjack.example/api/v1/opentelemetry
        headers:
            Authorization: Bearer lj_srv_...
        compression: gzip

service:
    pipelines:
        traces:
            receivers: [otlp]
            processors: [batch]
            exporters: [otlphttp/lumberjack]
        logs:
            receivers: [otlp]
            processors: [batch]
            exporters: [otlphttp/lumberjack]
```

Keep the batch processor's batches under the body limit below, e.g. with
`send_batch_max_size: 1000`.

## Mapping

| OpenTelemetry                                                                       | Lumberjack                                                                                                                                                                                                         |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `deployment.environment.name`, or `deployment.environment` (resource)               | Environment                                                                                                                                                                                                        |
| `service.version` (resource)                                                        | Release                                                                                                                                                                                                            |
| `host.name` (resource)                                                              | Host                                                                                                                                                                                                               |
| Server span with `http.request.method` (or `http.method`)                           | A request: route from `http.route`, else the path with its ids replaced; URL from `url.full`, `http.url` or its parts; domain from `server.address`; status from `http.response.status_code` or `http.status_code` |
| Consumer span                                                                       | A job run: the span's name, queue from `messaging.destination.name`, failed when the span's status is error                                                                                                        |
| Span with `db.system.name` (or `db.system`) and `db.query.text` (or `db.statement`) | A query, on the connection named by `db.namespace` or `db.name`                                                                                                                                                    |
| Span with `db.system` `redis`                                                       | A Redis span on the trace                                                                                                                                                                                          |
| Client span with `http.request.method`                                              | An outgoing HTTP span on the trace                                                                                                                                                                                 |
| Span event `exception`                                                              | An issue: title from `exception.type`, message from `exception.message`, frames from `exception.stacktrace`                                                                                                        |
| Log record                                                                          | A log line in the `default` channel: severity as the level, body as the message, attributes as the context                                                                                                         |
| Log record at error or above                                                        | Also an issue, as any log line; with `exception.*` attributes, an issue for that exception                                                                                                                         |

- Severity: trace and debug are `debug`, info is `info`, warn is `warning`, error is `error`,
  fatal is `critical`.
- Stack traces are read into frames from the formats of PHP, JavaScript, Java, .NET, Ruby and
  Python. A trace in another format (such as Go's) is kept as text in the occurrence's context.
  Frames under `vendor`, `node_modules` and `site-packages`, and of the Java and .NET runtimes,
  are not counted as your code.
- Spans that match none of the rows (internal spans, producer spans, gRPC, cache and mail
  calls) are not stored. There is no OpenTelemetry convention for an artisan command or a
  scheduled task, so neither is made from a span.
- Attributes Lumberjack does not use are ignored, never rejected.

## Responses

As OTLP/HTTP asks: errors carry a `google.rpc.Status` message in the request's encoding,
except a wrong token and a rate limit, which answer in JSON.

| Status | When                                                                           |
| ------ | ------------------------------------------------------------------------------ |
| `200`  | Accepted, with an empty export response. It is processed in the background.    |
| `400`  | The body is not valid gzip, or not an export request.                          |
| `401`  | The token is wrong or missing.                                                 |
| `413`  | The body is larger than 1 MB, or 20 MB after gzip, or holds over 5000 events.  |
| `415`  | The body is neither `application/x-protobuf` nor `application/json`.           |
| `429`  | The project's rate limit is hit, with `Retry-After`. Exporters retry after it. |

## Limits

The endpoints share the project's [ingest limits](./api.md#limits): 600 exports a minute per
project, counted with the agent's batches, and a body of at most 1 MB, or 20 MB once
inflated, holding at most 5000 events (spans, exceptions and log records it is turned into).
