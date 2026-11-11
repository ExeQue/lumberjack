<?php

declare(strict_types=1);

return [
    'ignored_until' => [
        'at' => 'Comes back :time',
        'count' => 'Comes back after :count more occurrences',
        'escalation' => 'Comes back if it escalates',
    ],
    'close' => [
        'resolve_title' => 'Resolve issue',
        'ignore_title' => 'Ignore issue',
        'description' => 'Add a comment if you like, for example what fixed it or why it does not matter. It is saved as a note.',
        'placeholder' => 'Optional comment. Type @ to tag, Ctrl+Enter to confirm.',
        'until' => 'Comes back',
        'choices' => [
            'forever' => 'Never, ignore it for good',
            '1h' => 'In 1 hour',
            '24h' => 'In 24 hours',
            '7d' => 'In 7 days',
            'count_10' => 'After 10 more occurrences',
            'count_100' => 'After 100 more occurrences',
            'count_1000' => 'After 1,000 more occurrences',
            'escalation' => 'When it escalates (10+ in 5 minutes, far above usual)',
        ],
    ],
    'labels' => [
        'title' => 'Labels',
        'none' => 'No labels yet.',
        'add' => 'Add a label',
        'remove' => 'Remove :label',
    ],
    'watching' => [
        'watch' => 'Watch',
        'unwatch' => 'Stop watching',
        'watchers' => 'Watching: :names',
    ],
    'actions' => [
        'assignee' => 'Assignee',
        // Buttons
        'start' => 'Start working',
        'stop' => 'Stop working',
        'resolve' => 'Resolve',
        'ignore' => 'Ignore',
        'reopen' => 'Re-open',
        'create_repo_issue' => 'Create GitHub issue',
        'open_repo_issue' => 'Open GitHub issue',
        'copy_markdown' => 'Copy as Markdown',
    ],
    'stats' => [
        'occurrences' => 'Occurrences',
        'users' => 'Users affected',
        'first_seen' => 'First seen',
        'last_seen' => 'Last seen',
        'releases' => 'Releases',
        'more_releases' => '+:count more',
        'hosts' => 'Hosts',
    ],
    'series' => [
        'title' => [
            '1h' => 'Occurrences per 2 minutes, last hour',
            '24h' => 'Occurrences per hour, 24 h',
            '7d' => 'Occurrences per 6 hours, 7 days',
            '30d' => 'Occurrences per day, 30 days',
        ],
        'in' => ['1h' => 'the last hour', '24h' => 'the last 24 hours', '7d' => 'the last 7 days', '30d' => 'the last 30 days'],
        'summary' => '{1} 1 occurrence in :period, peak :peak at :time|[0,*] :count occurrences in :period, peak :peak at :time',
        'empty' => 'No occurrences in :period',
        'regressed_at' => 'Regressed :time',
    ],
    'tabs' => [
        'label' => 'Issue details',
        'stacktrace' => 'Stacktrace',
        'occurrences' => 'Occurrences',
        'notes' => 'Notes',
        'history' => 'History',
    ],
    'history' => [
        'empty' => 'Nothing has happened to this issue yet.',
        'by' => 'by :actor',
        'created' => 'First seen',
        'regressed' => 'Regressed: happened again after it was resolved',
        'escalated' => 'Escalated: :burst occurrences in :minutes minutes',
        'status_changed' => 'Status changed from :from to :to',
        'status_set' => 'Marked :to',
        'assigned' => 'Assigned to :to',
        'unassigned' => 'Unassigned',
        'labels_added' => 'Labelled :labels',
        'labels_removed' => 'removed :labels',
        'repo_issue_created' => 'GitHub issue created',
        'note' => 'Note',
        'note_with_status' => 'Note on marking it :status',
        'returned_time' => 'Came back: the time it was ignored for is over',
        'returned_count' => 'Came back: it happened as many times again as it was ignored for',
        'returned_escalation' => 'Came back: :burst occurrences in :minutes minutes',
    ],
    'stack' => [
        'app' => 'app',
        'vendor' => 'vendor',
        'error_line' => 'Error line:',
        'empty' => 'No stacktrace recorded',
        // Under "empty" for a missed or failed scheduled task.
        'empty_schedule' => 'The scheduler reported this one, a missed run or an exit code, not a thrown exception, so it has no stacktrace.',
        // Button
        'show_vendor' => '{1} Show 1 vendor frame|[0,*] Show :count vendor frames',
        // Button
        'hide_vendor' => '{1} Hide 1 vendor frame|[0,*] Hide :count vendor frames',
        // Toggle button: browser frames as they were in the bundle, before the source map.
        'show_minified' => 'Show minified frames',
    ],
    'occurrences' => [
        'request' => 'Request',
        'user' => 'User id',
        'time' => 'Time',
        'empty' => 'No occurrences',
    ],
    'fields' => [
        'environment' => 'Environment',
        'release' => 'Release',
        'host' => 'Host',
        'trace_id' => 'Trace ID',
    ],
    'notes' => [
        'empty' => 'No notes yet.',
        'via_mcp' => 'Via MCP, token :token',
        'unknown_author' => 'Unknown',
        'new' => 'New note',
        'placeholder' => 'Write a note. Type @ to tag a team member or agent, Ctrl+Enter to save.',
        'mention' => 'Tag a team member or agent',
        // Button
        'save' => 'Save note',
    ],
    'side' => [
        'label' => 'Context',
        'context' => 'Request and context',
        'no_context' => 'No context recorded.',
        'general' => 'General',
        'filtered' => 'hidden',
        'environment' => 'Environment',
        'github' => 'GitHub',
    ],
];
