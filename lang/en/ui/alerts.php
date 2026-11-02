<?php

declare(strict_types=1);

return [
    'per_project' => [
        'title' => 'Rules and project channels',
        'description' => 'Each project picks which alerts go where, and can add channels of its own such as a webhook, in its settings.',
    ],
    'title' => 'Alerts',
    'types' => ['mail' => 'Mail', 'discord' => 'Discord', 'webhook' => 'Webhook'],
    'channels' => [
        'edit' => 'Edit',
        'keep_hint' => 'Leave empty to keep the current value.',
        'title' => 'Channels',
        'description' => 'Set up once for the team and reused in every project\'s rules.',
        'empty' => 'No channels yet',
        'never_delivered' => 'Not used yet',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        // Button
        'test' => 'Send test',
        'delete_question' => 'Delete :name? Rules stop sending to it.',
        'type' => 'Type',
        'name' => 'Name',
        'name_placeholder' => 'e.g. #deploys',
        'config' => [
            'webhook_url' => 'Webhook URL',
            'url' => 'Endpoint URL',
            'sender_name' => 'Sender name',
            'sender_name_hint' => 'The name the message comes from. Empty means Lumberjack.',
            'address' => 'Email address',
        ],
        // Button
        'add' => 'Add channel',
        // Button
        'add_new' => 'Add new channel',
    ],
    'rules' => [
        'title' => 'Rules',
        'project' => 'Project',
        'enable' => 'Enable :rule',
        'on' => 'On',
        'off' => 'Off',
        'channels' => 'Channels',
        'no_channels' => 'Add a channel first.',
        'environments' => 'Environments',
        'environments_hint' => 'Comma separated. Empty means all.',
        'empty' => 'This project has no rules.',
        'no_projects' => 'Create a project to set up rules.',
        'hint' => 'At most one message per issue per rule every 30 minutes. Ignored issues and browser errors never send.',
    ],
    'preview' => [
        'title' => 'Preview in Discord',
        'time' => 'Today 10:12',
        'heading' => 'New error in shop (production)',
        'exception' => 'Illuminate\Database\QueryException',
        'message' => 'SQLSTATE[40001]: Deadlock found when trying to get lock',
        'meta' => 'StoreOrderAction.php:88 · release a1f3c9e · 1 event',
        'link' => 'Open in Lumberjack',
        'hint' => 'Red edge for a new error, orange for a regression. If the issue has a triage note, its first line is included.',
    ],
];
