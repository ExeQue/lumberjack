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
    'bulk' => [
        'resolved' => '{0} Nothing to resolve.|{1} Resolved 1 issue.|[2,*] Resolved :count issues.',
        'ignored' => '{0} Nothing to ignore.|{1} Ignored 1 issue.|[2,*] Ignored :count issues.',
        'open' => '{0} Nothing to re-open.|{1} Re-opened 1 issue.|[2,*] Re-opened :count issues.',
        'in_progress' => '{0} Already in progress.|{1} Started work on 1 issue.|[2,*] Started work on :count issues.',
        'assigned' => '{0} Already assigned to :name.|{1} Assigned 1 issue to :name.|[2,*] Assigned :count issues to :name.',
        'unassigned' => '{0} Already unassigned.|{1} Unassigned 1 issue.|[2,*] Unassigned :count issues.',
        'label_added' => '{0} Nothing to add :label to.|{1} Added :label to 1 issue.|[2,*] Added :label to :count issues.',
        'label_removed' => '{0} Nothing to remove :label from.|{1} Removed :label from 1 issue.|[2,*] Removed :label from :count issues.',
    ],
    'sources' => ['server' => 'Server', 'browser' => 'Browser', 'log' => 'Log'],
    'statuses' => ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'ignored' => 'Ignored'],
    'repo_issue_created' => 'Repository issue created.',
];
