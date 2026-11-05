# Watch queues, jobs, scheduled tasks and commands

Know when background work fails, gets stuck, piles up or does not run at all. The
Laravel SDK records jobs, scheduled tasks and commands by itself. This guide shows
where to look and how to be told.

## Failed and stuck jobs

Open **Jobs** in the sidebar. Each job class shows its runs, failure rate, p95
duration and p95 wait.

![The Jobs page](../screenshots/jobs.png)

1. Sort by **Highest failure rate** to find the jobs that fail most.
2. Below the job classes, **Latest failed, stuck and released runs** lists the bad
   runs with their parameters.
3. Click **Open trace** on a run to see its queries, log lines and exception, or
   **Issue** to open the error it became.

A job is **Stuck** when it has been processing far longer than its timeout. A banner
at the top says how many are stuck. Stuck jobs usually mean a worker died mid-job.

A failed job's exception is an issue like any other, so the **New issue** and
**Regression** alert rules tell you about it.

## Queue backlog and dead workers

The SDK's agent reports the size of your queues every minute. Tell it which queues to
watch in the app's environment, as `connection:queue` or a queue on the default
connection:

```dotenv
LUMBERJACK_QUEUES=default,redis:emails
```

Without it, the agent watches the default connection's default queue.

The **Queues** card on the Jobs page then shows, per queue: the jobs **Waiting**, the
**Workers** running on it, the **Oldest pending** job and the **p95 wait**. Workers turn
red when jobs wait and no worker runs.

**Get told:** the **Queue backlog** alert rule fires when a queue has more than 1,000
jobs waiting, or its oldest job has waited more than 10 minutes, for 5 minutes. It
sends again when the queue is back under. This also catches dead workers: no job runs,
but the queue keeps growing.

To set other limits for one queue, click **Alert** next to it in the Queues card. Leave
a field empty to keep the rule's value, or tick **Mute: never alert for this queue**.

## Missed and failed scheduled tasks

Open **Schedule** in the sidebar. Each task shows its cron, its latest status, its last
and next run, and a strip of its last 20 runs. Click a task for its runs with their
exit code and host.

![The Schedule page](../screenshots/schedule.png)

- **Failed**: the task threw an exception or ended with a non-zero exit code.
- **Missed**: the task had not started 5 minutes after its time. The scheduler may
  not be running at all.

A failed or missed task becomes an issue, so the **New issue** and **Regression**
rules send it to your channels. For yourself, turn on the **Scheduled tasks** row in
your notifications.

When a task is gone from the app, **Forget task** removes it from the list. Only
owners and admins can do this.

## Commands

Open **Commands** in the sidebar for every Artisan command the app ran: runs, failures,
duration, and the latest runs with their arguments, exit code and host. Queue workers
are left out.

![The Commands page](../screenshots/commands.png)

## Health checks for queues and cron jobs

Health checks show the state of your queues next to your database and disks, and alert
through the **Health check** rules.

**From the app.** Register a queue check in a service provider. It warns and fails at
the sizes you give, and fails when jobs wait with no worker:

```php
Lumberjack::healthChecks()
    ->queue('redis', queue: 'emails', warnAt: 500, failAt: 2000)
    ->failedJobs(withinMinutes: 60);
```

**From Lumberjack.** For a cron job or backup that runs outside Laravel's scheduler,
add a **Heartbeat** check on the project's **Health** page with **Add check**. Call its
ping URL at the end of the job:

```bash
php artisan backup:run && curl -fsS -m 10 https://lumberjack.example.com/api/health/ping/...
```

The check fails when no ping came within its interval plus the grace.

**When it works:** the checks show on the **Health** page, green. Stop a worker or skip
a ping and the check turns degraded, then down, and the alert rules send a message.

## Your own notifications

Click your name at the bottom of the sidebar, then **Profile and settings**, then
**Notifications**. The **Queue backlogs**, **Scheduled tasks**, **Health checks** and
**Agents** rows decide how you hear of each. **Agents** tells you when a server's agent
stops sending its heartbeat.

## Reference

- [Performance and traces](../performance.md): the Jobs, Queues, Schedule and Commands
  pages.
- [Alerts](../alerts.md): the queue backlog rule and per-queue thresholds.
- [Health](../health.md): check types, thresholds and states.
