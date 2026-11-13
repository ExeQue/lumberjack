<?php

declare(strict_types=1);

return [
    'conflict' => 'This screen was changed somewhere else. Reload the page to continue.',
    'restored' => 'Screen restored.',
    // Flash messages on the list of screens.
    'duplicated' => 'Screen duplicated.',
    'deleted' => 'Screen deleted.',
    'imported' => 'Screen imported.',
    'copied' => ':name copied to :team.',

    // Flash messages about the team's own templates.
    'templates' => [
        'saved' => 'Saved as the template :name; New screen offers it.',
        'updated' => 'Template saved.',
        'deleted' => 'Template :name deleted.',
    ],

    // Why a TV's code could not be confirmed.
    'pairing' => [
        'expired' => 'This code has expired or was already used. The TV shows a new one; enter that.',
        'not_admin' => 'Only team admins pair TVs. Pick a screen of a team you administer.',
        'name_required' => 'Name this device.',
        'default_name' => 'TV',
    ],

    // Flash messages of the share link panel.
    'share' => [
        'saved' => 'Share link saved.',
        'revoked' => 'Share link turned off; it no longer works.',
        'rotated' => 'New share link made; the old one no longer works.',
    ],

    // Flash messages on Team settings > Displays.
    // Asking what shows a screen, or one display, to load the page afresh.
    'identify' => [
        'sent' => ':name shows its name for :seconds seconds.',
    ],

    'reload' => [
        'sent' => '{0} Reload sent; no screen shows it right now|{1} Reload sent to 1 screen|[2,*] Reload sent to :count screens',
        'sent_unknown' => 'Reload sent.',
        'sent_display' => 'Reload sent to :name.',
        'sent_device' => '{1} Reload sent to :name.|[2,*] Reload sent to the :count windows of :name.',
        'too_soon' => 'A reload went out moments ago; try again in :seconds s.',
    ],

    'displays' => [
        'saved' => 'Display saved.',
        'removed' => 'Window removed; it shows a pairing code again.',
        'device_saved' => 'Device saved.',
        'device_removed' => '{1} :name removed; its window shows a pairing code again.|[2,*] :name removed; its :count windows show a pairing code again.',
        // What a window of a device is called: "Window 2", "Office PC, window 2".
        'window' => 'Window :slot',
        'full_name' => ':device, window :slot',
        'full_name_named' => ':device, :name',
    ],

    // Source names in the designer and as widget titles.
    'sources' => [
        'stat' => [
            'requests' => 'Requests',
            'requests_per_minute' => 'Requests per minute',
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'open_issues' => 'Open issues',
            'users_affected' => 'Users affected',
            'failed_jobs' => 'Failed jobs',
            'queue_size' => 'Queue size',
            'queue_workers' => 'Queue workers',
            'agents_online' => 'Agents online',
            'host_cpu' => 'Highest host CPU',
            'host_memory' => 'Highest host memory',
            'host_disk' => 'Highest host disk',
            'current_release' => 'Current release',
            'lcp_p75' => 'LCP p75',
            'db_size' => 'Database size',
            'db_connections' => 'Database connections',
            'activity' => 'Activity',
            'external_json' => 'Data source',
        ],
        'gauge' => [
            'requests_per_minute' => 'Requests per minute',
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'host_cpu' => 'Host CPU',
            'host_memory' => 'Host memory',
            'host_disk' => 'Host disk',
            'queue_size' => 'Queue size',
            'db_connections' => 'Database connections in use',
            'health_uptime' => 'Health uptime',
            'activity' => 'Activity',
            'external_json' => 'Data source',
        ],
        'chart' => [
            'requests_errors' => 'Requests and errors',
            'requests' => 'Requests',
            'requests_per_minute' => 'Requests per minute',
            'error_rate' => 'Error rate',
            'p95' => 'p95 response time',
            'apdex' => 'Apdex',
            'users_affected' => 'Users affected',
            'queue_size' => 'Queue size',
            'queue_workers' => 'Queue workers',
            'queue_wait' => 'Queue wait',
            'failed_jobs' => 'Failed jobs',
            'host_cpu' => 'Host CPU',
            'host_memory' => 'Host memory',
            'host_disk' => 'Host disk',
            'lcp_p75' => 'LCP p75',
            'db_size' => 'Database size',
            'db_connections' => 'Database connections',
            'activity' => 'Activity',
            'external_json' => 'Data source',
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
            'external_json' => 'Data source',
            'calendar' => 'Calendar events',
        ],
        'health' => [
            'checks' => 'Health checks',
        ],
        'overview' => [
            'projects' => 'Project overview',
        ],
        'slo' => [
            'availability' => 'Availability SLO',
            'latency' => 'Latency SLO',
        ],
    ],
];
