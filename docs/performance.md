# Performance and traces

Pick a period (1 h, 24 h, 7 d, 30 d) and an environment at the top of each page. Hover
a chart to see the value of each bucket.

| Page     | Shows                                                                                                                                                  |
| -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Overview | Requests, error rate, p95, failed jobs and open issues, with the change from the period before, and the latest issues, slowest routes and failed jobs. |
| Routes   | Requests, p50/p95/p99, 5xx rate and Apdex per route. Click a route for its hourly chart, status codes and slowest requests.                            |
| Queries  | Queries grouped by normalized SQL, highlighted (as in traces): calls, total time, share of database time, p95 and max. N+1 patterns are flagged.       |
| Jobs     | Runs, failure rate, stuck and retried jobs, duration and wait time per job class. Click a job for its runs.                                            |
| Schedule | Scheduled tasks per environment with their last and next run. A task that does not run when expected is marked missed and becomes an issue.            |
| Commands | Artisan commands with runs, failures and duration. Click a command for its runs.                                                                       |
| Logs     | Log lines by channel and level, with full-text search.                                                                                                 |

## Apdex

Apdex scores a route from 0 to 1 by how its requests felt. A request within 500 ms is
satisfied, within 2 s counts half, and slower or a 5xx counts nothing. Below 0.85 shows
in amber, below 0.7 in red. Sort by lowest Apdex to find the routes people wait on.

## Traces

Everything recorded during one request, job, command or scheduled task shares a trace
id. **Open trace** (on a request, a run or an occurrence) shows it on one time axis:

- the request or job, then
- queries,
- spans: cache reads and writes (hits and misses), outgoing HTTP calls, mails,
  notifications and Redis commands,
- log lines and exceptions.

Each step is a bar placed where it started, as long as it took. Which spans are
recorded is set in the package's `spans` config.

Paste a trace id in the [command palette](./finding-things) to open it.
