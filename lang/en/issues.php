<?php

declare(strict_types=1);

return [
    'label_taken' => 'The project already has a label called :name.',
    'label_saved' => 'Label saved.',
    'label_deleted' => 'Label :name deleted.',
    'mentioned' => [
        'subject' => ':author mentioned you on :title',
        'line' => ':author mentioned you in a note on an issue in :project:',
    ],
    'assignment' => [
        'assigned' => '{1} :actor assigned you to :title|[2,*] :actor assigned you to :count issues in :project',
        'unassigned' => '{1} :actor took you off :title|[2,*] :actor took you off :count issues in :project',
        'resolved' => '{1} :actor resolved :title and took you off it|[2,*] :actor resolved :count issues in :project and took you off them',
        'line' => 'In :project:',
        'more' => '+:count more',
    ],
    'sources' => ['server' => 'Server', 'browser' => 'Browser', 'log' => 'Log'],
    'statuses' => ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'],
    'repo_issue_created' => 'Repository issue created.',
];
