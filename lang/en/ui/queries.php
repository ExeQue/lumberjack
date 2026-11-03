<?php

declare(strict_types=1);

return [
    'title' => 'Queries',
    'stats' => [
        'calls' => 'Queries',
        'distinct' => ':count distinct, grouped by SQL',
        'total_time' => 'Total DB time',
        'slowest' => 'Slowest single query',
    ],
    'search' => 'Search SQL',
    'connection' => 'Connection',
    'all_connections' => 'All connections',
    'sort' => [
        'label' => 'Sort',
        'total' => 'Most total time',
        'p95' => 'Highest p95',
        'count' => 'Most calls',
    ],
    'n_plus_one' => 'N+1 · :count× per request',
    'n_plus_one_hint' => 'The same query :count times in one request or job. Load the data at once (eager loading).',
    'columns' => [
        'most_from' => 'Most from',
        'most_from_day' => 'Most from (last 24 h)',
        'sql' => 'SQL',
        'connection' => 'Connection',
        'calls' => 'Calls',
        'share' => 'Share of DB time',
        'max' => 'Max',
    ],
    'empty_title' => 'No queries yet',
    'empty_body' => 'Nothing matches the filters in this period.',
];
