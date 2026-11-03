<?php

declare(strict_types=1);

return [
    'title' => 'Releases',
    'description' => '{1} 1 release seen in this period, newest first. Select a release for the issues it brought.|[2,*] :count releases seen in this period, newest first. Select a release for the issues it brought.',
    'columns' => [
        'release' => 'Release',
        'first_seen' => 'First seen',
        'last_seen' => 'Last seen',
        'requests' => 'Requests',
        'error_rate' => '5xx rate',
        'p95' => 'p95',
        'occurrences' => 'Errors',
        'new_issues' => 'New issues',
        'regressions' => 'Regressions',
    ],
    'change' => [
        'worse' => ':change worse than :release',
        'better' => ':change better than :release',
        'from_zero' => 'None in :release',
    ],
    'empty_title' => 'No releases yet',
    'empty_body' => 'Nothing in this period carries a release. Send one with your events to compare deploys.',
    'detail' => [
        'title' => 'New issues in :release',
        'empty' => 'This release brought no new issues.',
        'all' => 'Issues last seen in this release',
        'occurrences' => '{1} 1 error|[2,*] :count errors',
    ],
];
