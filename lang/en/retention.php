<?php

declare(strict_types=1);

return [
    'categories' => [
        'occurrences' => ['label' => 'Occurrences', 'description' => 'Each time an error happened, with its stack trace and context. Issues themselves are kept.'],
        'requests' => ['label' => 'Requests', 'description' => 'One row per HTTP request: route, status and duration.'],
        'queries' => ['label' => 'Queries', 'description' => 'Database queries recorded with their requests and jobs.'],
        'spans' => ['label' => 'Spans', 'description' => 'Trace spans: outgoing HTTP, cache and custom timings.'],
        'web_vitals' => ['label' => 'Web vitals', 'description' => 'Page load metrics from browsers: LCP, INP, CLS and more.'],
        'job_runs' => ['label' => 'Job runs', 'description' => 'Each queued job attempt. Failed runs stay at least as long as occurrences.'],
        'scheduled_task_runs' => ['label' => 'Scheduled task runs', 'description' => 'Each run of a scheduled task. Failed and missed runs stay at least as long as occurrences.'],
        'command_runs' => ['label' => 'Command runs', 'description' => 'Each Artisan command run. Failed runs stay at least as long as occurrences.'],
        'logs' => ['label' => 'Logs', 'description' => 'Log lines, per channel.'],
        'health_results' => ['label' => 'Health results', 'description' => 'Each health check probe and its latency.'],
        'health_outages' => ['label' => 'Health outages', 'description' => 'Periods a check was down or degraded; uptime is counted from them.'],
        'queue_samples' => ['label' => 'Queue size samples', 'description' => 'Queue length and oldest job age, sampled by the agent.'],
        'host_samples' => ['label' => 'Host metrics', 'description' => 'CPU, memory and disk samples from the agent.'],
        'alert_sends' => ['label' => 'Alert history', 'description' => 'Every alert sent, to which channel, and whether it got through.'],
        'audit_log' => ['label' => 'Audit log', 'description' => 'What administrators did on this Lumberjack.'],
    ],
    'levels' => [
        'default' => 'built-in default',
        'application' => 'application',
        'team' => 'team',
        'project' => 'project',
        'override' => 'override',
    ],
    'above_cap' => 'At most :days days: the :level allows no more.',
    'saved' => 'Retention saved.',
];
