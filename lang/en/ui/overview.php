<?php

declare(strict_types=1);

return [
    'title' => 'Overview',
    'kpis' => [
        'requests' => 'Requests',
        'requests_note' => ':count requests in the period',
        'error_rate' => 'Error rate',
        'error_rate_note' => '{0} No exceptions recorded, 5xx share of requests|{1} 1 exception recorded, 5xx share of requests|[2,*] :count exceptions recorded, 5xx share of requests',
        'p95' => 'p95 response time',
        'p95_none' => 'No requests in the period.',
        'p95_note' => 'p50 :p50, p99 :p99',
        'failed_jobs' => 'Failed jobs',
        'failed_jobs_note' => '{1} :rate of 1 job run, :stuck stuck|[0,*] :rate of :count job runs, :stuck stuck',
        'open_issues' => 'Open issues',
        'open_issues_note' => 'All environments, regardless of period',
        'health' => 'Health checks',
        'health_value' => ':ok of :total ok',
        'health_note' => ':down down, :degraded degraded, :stale stale',
        'health_all_ok' => 'Every check passes',
    ],
    'health' => [
        'title' => 'Health checks',
        'open' => 'Open Health',
        'check_label' => ':name: :state',
    ],
    'charts' => [
        'requests' => 'Requests over time',
        'requests_unit' => 'requests',
        'errors' => 'Exceptions over time',
        'errors_unit' => 'exceptions',
        'latency' => 'Response times',
        'latency_summary' => 'Highest p95 :p95, highest p50 :p50 in the period.',
        'peak' => 'Peak :value, :time',
        'no_data' => 'No data in this period',
        'summary' => ':total :unit in the period. :peak',
    ],
    'latest_issues' => [
        'title' => 'Latest issues',
        // Link
        'all' => 'All issues',
        'empty' => 'No open issues',
        'occurrences' => '{1} 1 occurrence|[0,*] :count occurrences',
    ],
    'slowest_routes' => [
        'title' => 'Slowest routes (p95)',
        // Link
        'all' => 'All routes',
        'empty' => 'No requests in this period',
    ],
    'failed_jobs' => [
        'title' => 'Failed jobs',
        // Link
        'all' => 'All jobs',
        'empty' => 'No failed jobs in this period',
        'attempt_of' => 'Attempt :attempt of :max',
        'attempt' => 'Attempt :attempt',
    ],
    'trend' => [
        'up' => ':percent more than the period before',
        'down' => ':percent less than the period before',
        'same' => 'Same as the period before',
        'new' => 'None in the period before',
    ],
];
