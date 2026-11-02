<?php

declare(strict_types=1);

return [
    'title' => 'Routes',
    'description' => '{1} 1 route, :requests requests. Select a route for details.|[2,*] :count routes, :requests requests. Select a route for details.',
    'search' => 'Search routes',
    'sort' => [
        'label' => 'Sort',
        'p95' => 'Highest p95',
        'count' => 'Most requests',
        'errors' => 'Highest error rate',
        'total' => 'Most total time',
    ],
    'columns' => [
        'trend' => 'Requests over time',
        'route' => 'Route',
        'requests' => 'Requests',
        'error_rate' => 'Error rate',
        'total' => 'Total time',
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
    ],
    'trend_label' => ':count requests in the period',
];
