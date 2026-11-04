<?php

declare(strict_types=1);

return [
    'title' => 'Requests',
    'description' => '{1} 1 route, :requests requests. Select a route for details.|[2,*] :count routes, :requests requests. Select a route for details.',
    'search' => 'Search routes or names',
    'domain' => [
        'label' => 'Domain',
        'all' => 'All domains',
        'unknown' => 'unknown',
    ],
    'sort' => [
        'label' => 'Sort',
        'p95' => 'Highest p95',
        'count' => 'Most requests',
        'errors' => 'Highest error rate',
        'total' => 'Most total time',
        'apdex' => 'Lowest Apdex',
    ],
    'columns' => [
        'trend' => 'Requests over time',
        'route' => 'Route',
        'domain' => 'Domain',
        'requests' => 'Requests',
        'error_rate' => 'Error rate',
        'total' => 'Total time',
        'apdex' => 'Apdex',
        'apdex_help' => 'Share of satisfied requests: within :target is satisfied, within :tolerated counts half, slower or a 5xx counts nothing.',
    ],
    'empty_title' => 'No requests yet',
    'empty_body' => 'Nothing matches the filters in this period.',
    'detail' => [
        'summary' => ':requests requests · p95 :p95 · error rate :errors',
        'hourly' => 'Response time over the period (p50 and p95)',
        'chart_label' => 'Response time over the period. Highest p95 was :p95 at :time.',
        'slowest' => 'Slowest requests',
        'queries' => '{1} 1 query|[2,*] :count queries',
        'no_requests' => 'No requests in this period.',
        'statuses' => 'Status codes',
        'statuses_label' => ':ok 2xx, :redirect 3xx, :client 4xx, :server 5xx',
    ],
    'trend_label' => ':count requests in the period',
];
