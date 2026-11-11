<?php

declare(strict_types=1);

return [
    'token_created' => 'MCP token ":name"',
    'token_updated' => 'Token saved.',
    'token_revoked' => 'Token revoked.',

    // How much a token may do in an area; each includes the ones before it
    'levels' => [
        'none' => 'None',
        'read' => 'Read',
        'write' => 'Write',
        'manage' => 'Manage',
    ],

    // Rows of the token permission picker
    'areas' => [
        'issues' => ['label' => 'Issues', 'description' => 'Triage, status, labels, assignees and notes.'],
        'telemetry' => ['label' => 'Telemetry', 'description' => 'Traces, requests, queries, jobs, logs, releases and metrics. Read only.'],
        'health' => ['label' => 'Health checks', 'description' => 'Checks Lumberjack runs (http, ssl, tcp, dns, heartbeat), those the app reports, and maintenance windows.'],
        'schedule' => ['label' => 'Scheduled tasks', 'description' => 'Tasks the app reports. Stale tasks can be removed.'],
        'alerts' => ['label' => 'Alerts', 'description' => 'Alert rules, topics and queue thresholds. Channels stay in the UI.'],
        'data_sources' => ['label' => 'Data sources', 'description' => 'Pulled sources and app connections that feed screens.'],
        'screens' => ['label' => 'Screens', 'description' => 'Screen design, versions, displays and sharing.'],
        'project' => ['label' => 'Project settings', 'description' => 'Name, environments, log channels, retention, public stats. No secrets.'],
        'team' => ['label' => 'Team', 'description' => 'Who is on the team, for assigning and mentions. Members are managed in the UI.'],
    ],

    // One line per MCP tool, shown under "Tools" in the permission picker
    'tools' => [
        // Always available
        'list_projects' => 'Projects the token can see',
        'list_my_permissions' => 'What the token may do, per project',

        // Issues
        'triage_inbox' => 'What needs attention across the token\'s projects',
        'list_issues' => 'Filter and page issues',
        'get_issue' => 'One issue with stack, context and timeline',
        'list_occurrences' => 'Occurrences of an issue',
        'set_issue_status' => 'Resolve, ignore, reopen',
        'set_issue_labels' => 'Set labels on an issue',
        'assign_issue' => 'Assign to a member',
        'watch_issue' => 'Watch an issue',
        'unwatch_issue' => 'Stop watching an issue',
        'add_issue_note' => 'Add a note, with mentions',
        'create_repo_issue' => 'Open a GitHub issue from an issue',
        'list_labels' => 'The project\'s labels',
        'bulk_update_issues' => 'Status, assignee or label on many issues',
        'update_label' => 'Rename or recolor a label',
        'delete_label' => 'Delete a label',

        // Telemetry
        'get_trace' => 'One trace with spans',
        'list_routes' => 'Routes with timings',
        'list_queries' => 'Slow and frequent queries',
        'list_jobs' => 'Jobs and failures',
        'get_job' => 'One job run',
        'list_commands' => 'Artisan command runs',
        'get_command' => 'One command run',
        'search_logs' => 'Search log lines',
        'list_browser_errors' => 'Frontend errors',
        'list_releases' => 'Releases with health',
        'get_release' => 'One release with its issues',
        'get_metric_series' => 'A metric over time',
        'list_queues' => 'Queue depth and wait',
        'list_hosts' => 'Hosts and their load',
        'search_project' => 'Search a project',

        // Health checks
        'list_health_checks' => 'Checks with state and uptime',
        'get_health_check' => 'One check with settings and results',
        'create_health_check' => 'Add a check Lumberjack runs',
        'update_health_check' => 'Change a check',
        'run_health_check' => 'Run a check now',
        'archive_health_check' => 'Archive or unarchive a check',
        'delete_health_check' => 'Delete a check and its history',

        // Maintenance windows
        'list_maintenance_windows' => 'Maintenance windows with state',
        'create_maintenance_window' => 'Plan a maintenance window',
        'update_maintenance_window' => 'Change a maintenance window',
        'end_maintenance_window' => 'End a maintenance window now',

        // Scheduled tasks
        'list_scheduled_tasks' => 'Tasks with last run and state',
        'get_scheduled_task' => 'One task with its runs',
        'delete_scheduled_task' => 'Forget a task and its runs',

        // Alerts
        'list_alert_rules' => 'Team rules and a project\'s overrides',
        'list_notification_channels' => 'Channels and what sends to them',
        'list_alert_topics' => 'Custom alert topics',
        'update_alert_rule' => 'Customize a rule for a project',
        'reset_alert_rule' => 'Back to the team\'s rule',
        'set_alert_rule_add_ons' => 'Add channels to a team rule',
        'set_queue_alert_threshold' => 'Thresholds for one queue',
        'create_alert_topic' => 'New custom alert topic',
        'send_project_alert' => 'Send a custom alert',
        'send_test_alert' => 'Test a channel',
        'update_team_alert_rule' => 'Change a team rule',
        'delete_alert_topic' => 'Delete a custom alert topic',

        // Data sources
        'list_data_sources' => 'Sources with group and last fetch',
        'preview_data_source' => 'Latest answer and readable paths',
        'list_data_source_connections' => 'Connected apps and their status',
        'create_data_source' => 'Add a fetched source',
        'update_data_source' => 'Change a fetched source',
        'fetch_data_source' => 'Fetch a source now',
        'create_data_source_connection' => 'Connect an app',
        'update_data_source_connection' => 'Change an app\'s address or time zone',
        'delete_data_source' => 'Delete a source',
        'delete_data_source_connection' => 'Disconnect an app',

        // Screens
        'list_screens' => 'Screens in the team',
        'get_screen' => 'Layout and widgets',
        'get_screen_data' => 'Widget data as shown',
        'list_widget_types' => 'Widgets and their options',
        'list_screen_versions' => 'Saved versions',
        'preview_screen' => 'Signed preview link',
        'get_screen_design_guide' => 'Design guide for agents',
        'create_screen' => 'New screen',
        'update_screen' => 'Name, theme, settings',
        'update_screen_layout' => 'Grid layout',
        'update_screen_widget' => 'One widget',
        'restore_screen_version' => 'Restore a version',
        'export_screen' => 'Screen as a file',
        'list_displays' => 'Paired displays',
        'list_screen_viewers' => 'Who has it open',
        'duplicate_screen' => 'Copy a screen',
        'reload_screen' => 'Reload its TVs',
        'import_screen' => 'Screen from a file',
        'assign_display' => 'What a display shows',
        'set_screen_share' => 'Public share link',
        'delete_screen' => 'Delete a screen',

        // Project settings
        'get_project_overview' => 'Health of the project at a glance',
        'get_project_settings' => 'Settings without secrets',
        'update_project' => 'Name, slug, prefix, origins, scrubbed keys',
        'create_log_channel' => 'Add a log channel',
        'delete_log_channel' => 'Delete a log channel and its lines',
        'set_project_retention' => 'Days kept per data type',
        'set_public_stats' => 'Public stats link on or off',

        // Team
        'list_members' => 'Members and roles',
    ],

    'validation' => [
        'unknown_area' => 'Unknown permission area.',
        'level' => 'Pick a level this area offers.',
        'tools' => 'Only tools of this area can be added or taken away.',
        'change_outside_projects' => 'Changes are only for projects the token covers.',
    ],
];
