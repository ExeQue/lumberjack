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
    // Asking what shows a screen, or one display, to load the page afresh.
    'reload' => [
        'sent' => '{0} Reload sent; no screen shows it right now|{1} Reload sent to 1 screen|[2,*] Reload sent to :count screens',
        'sent_unknown' => 'Reload sent.',
        'sent_display' => 'Reload sent to :name.',
        'too_soon' => 'A reload went out moments ago; try again in :seconds s.',
    ],

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
            'host_cpu' => 'Highest host CPU',
            'host_memory' => 'Highest host memory',
            'host_disk' => 'Highest host disk',
            'current_release' => 'Current release',
            'lcp_p75' => 'LCP p75',
            'db_size' => 'Database size',
            'db_connections' => 'Database connections',
        ],
        'gauge' => [
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'host_cpu' => 'Host CPU',
            'host_memory' => 'Host memory',
            'host_disk' => 'Host disk',
            'queue_size' => 'Queue size',
            'db_connections' => 'Database connections in use',
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
            'host_disk' => 'Host disk',
            'lcp_p75' => 'LCP p75',
            'db_size' => 'Database size',
            'db_connections' => 'Database connections',
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
