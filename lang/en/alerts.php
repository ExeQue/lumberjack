<?php

declare(strict_types=1);

return [
    'channel_types' => ['mail' => 'Mail', 'discord' => 'Discord', 'webhook' => 'Webhook', 'slack' => 'Slack', 'teams' => 'Microsoft Teams', 'telegram' => 'Telegram', 'ntfy' => 'ntfy', 'pushover' => 'Pushover'],
    'webhook_secret' => 'Webhook signing secret',
    'webhook_needs_project' => 'A webhook belongs to one project; add it in that project\'s settings.',
    'webhook_not_public' => 'The URL must be http or https and point at a public address.',
    'rule_types' => [
        'new_issue' => ['label' => 'New issue', 'description' => 'The first occurrence of a new issue, from server, browser or log.'],
        'regression' => ['label' => 'Regression', 'description' => 'A new occurrence of an issue that was resolved.'],
        'escalation' => ['label' => 'Escalation', 'description' => 'An open issue suddenly happening far more often than usual: at least 10 occurrences in 5 minutes.'],
        'status_change' => ['label' => 'Resolved or ignored', 'description' => 'Every issue someone resolves or ignores, with who did it and their comment. Bulk changes on the issues list are left out.'],
        'note' => ['label' => 'Note', 'description' => 'Every note written on an issue, by a person or an agent over MCP. Not throttled.'],
    ],
    'messages' => [
        'new_issue' => 'New error in :project (:environment)',
        'regression' => 'Regression in :project (:environment)',
        'unknown_environment' => 'unknown environment',
        'note' => 'Note on an issue in :project (:environment)',
        'status' => [
            'resolved' => 'Resolved by :actor in :project',
            'ignored' => 'Ignored by :actor in :project',
            'open' => 'Re-opened by :actor in :project',
            'in_progress' => 'Taken in progress by :actor in :project',
        ],
        'escalation' => 'Escalating in :project (:environment)',
        'burst' => '{1} :count occurrence in :minutes minutes|[2,*] :count occurrences in :minutes minutes',
        'note_by' => 'Note by :author:',
        'via_mcp' => ':token (MCP)',
        'unknown_author' => 'Someone',
    ],
    'discord' => ['location' => 'Location', 'release' => 'Release', 'occurrences' => 'Occurrences'],
    'open_issue' => 'Open in Lumberjack',
    'test_subject' => 'Lumberjack test alert',
    'test_message' => 'Test message from Lumberjack to :channel. Alerts will arrive here.',
    'channel_saved' => 'Channel saved.',
    'test_sent' => 'Test message sent.',
    'test_failed' => 'The test message could not be delivered.',
];
