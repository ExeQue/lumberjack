<?php

declare(strict_types=1);

return [
    'title' => 'Jobs',
    'description' => '{1} :runs runs across 1 job class.|[2,*] :runs runs across :count job classes.',
    'queue' => 'Queue',
    'all_queues' => 'All queues',
    'sort' => [
        'label' => 'Sort',
        'errors' => 'Highest failure rate',
        'count' => 'Most runs',
        'duration' => 'Highest p95 duration',
        'wait' => 'Highest p95 wait',
    ],
    'stuck' => [
        'title' => '{1} 1 job is stuck.|[2,*] :count jobs are stuck.',
        'body' => 'They have been processing longer than their timeout plus a margin. The worker has probably died.',
        'link' => 'See stuck runs',
    ],
    'columns' => [
        'job' => 'Job class',
        'queue' => 'Queue',
        'runs' => 'Runs',
        'failure_rate' => 'Failure rate',
        'p95_duration' => 'p95 duration',
        'p95_wait' => 'p95 wait',
        'retries' => 'Retries',
        'outcome' => 'Outcome',
    ],
    'queues' => [
        'title' => 'Queues',
        'description' => 'Jobs waiting on each queue, as the agents report it every minute.',
        'sampled' => 'Reported :time',
        'trend_label' => 'Size of :queue over the period',
        'columns' => [
            'queue' => 'Queue',
            'size' => 'Waiting',
            'oldest' => 'Oldest pending',
            'p95_wait' => 'p95 wait',
            'trend' => 'Size',
        ],
    ],
    'outcome_label' => ':processed processed, :failed failed, :stuck stuck',
    'empty_title' => 'No jobs yet',
    'empty_body' => 'Nothing matches the filters in this period.',
    'runs' => [
        'title' => 'Latest failed, stuck and released runs',
        'title_job' => 'Runs of :job',
        'parameters' => 'Parameters',
        // Button
        'clear' => 'Show all jobs',
        'empty' => 'No runs to show',
        'view_issue' => 'Issue',
        'columns' => [
            'status' => 'Status',
            'job' => 'Job',
            'attempt' => 'Attempt',
            'queued' => 'Queued',
            'wait' => 'Wait',
            'duration' => 'Duration',
            'host' => 'Host',
            'issue' => 'Issue',
        ],
    ],
];
