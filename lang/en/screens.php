<?php

declare(strict_types=1);

return [
    // Flash messages on the list of screens.
    'duplicated' => 'Screen duplicated.',
    'deleted' => 'Screen deleted.',

    // Why a TV's code could not be confirmed.
    'pairing' => [
        'expired' => 'This code has expired or was already used. The TV shows a new one; enter that.',
        'not_admin' => 'Only team admins pair TVs. Pick a screen of a team you administer.',
    ],

    // Flash messages of the share link panel.
    'share' => [
        'saved' => 'Share link saved.',
        'revoked' => 'Share link turned off; it no longer works.',
        'rotated' => 'New share link made; the old one no longer works.',
    ],

    // Flash messages on Team settings > Displays.
    'displays' => [
        'saved' => 'Display saved.',
        'removed' => 'Display removed; it shows a pairing code again.',
    ],

    // Source names in the designer and as widget titles.
    'sources' => [
        'stat' => [
            'requests' => 'Requests',
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'open_issues' => 'Open issues',
            'users_affected' => 'Users affected',
            'failed_jobs' => 'Failed jobs',
            'queue_size' => 'Queue size',
            'agents_online' => 'Agents online',
            'host_memory' => 'Highest host memory',
            'current_release' => 'Current release',
            'lcp_p75' => 'LCP p75',
        ],
        'gauge' => [
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'host_cpu' => 'Host CPU',
            'host_memory' => 'Host memory',
            'host_disk' => 'Host disk',
            'queue_size' => 'Queue size',
            'health_uptime' => 'Health uptime',
        ],
        'chart' => [
            'requests_errors' => 'Requests and errors',
            'requests' => 'Requests',
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'users_affected' => 'Users affected',
            'queue_size' => 'Queue size',
            'queue_wait' => 'Queue wait',
            'failed_jobs' => 'Failed jobs',
            'host_cpu' => 'Host CPU',
            'host_memory' => 'Host memory',
            'lcp_p75' => 'LCP p75',
        ],
        'list' => [
            'latest_issues' => 'Latest issues',
            'alert_feed' => 'Alert feed',
            'failed_jobs' => 'Failed jobs',
            'scheduled_tasks' => 'Scheduled tasks',
            'slowest_routes' => 'Slowest routes',
            'queues' => 'Queues',
            'hosts' => 'Hosts',
            'agents' => 'Agents',
        ],
        'health' => [
            'checks' => 'Health checks',
        ],
    ],
];
