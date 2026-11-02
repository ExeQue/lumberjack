<?php

declare(strict_types=1);

return [
    'title' => 'Commands',
    'description' => '{0} No artisan commands in the period.|{1} 1 artisan command in the period.|[2,*] :count artisan commands in the period.',
    'statuses' => ['running' => 'Running', 'finished' => 'Finished', 'failed' => 'Failed'],
    'columns' => [
        'command' => 'Command',
        'runs' => 'Runs',
        'failed' => 'Failed',
        'avg' => 'Avg. duration',
        'max' => 'Longest',
        'last_run' => 'Last run',
        'status' => 'Status',
        'input' => 'Arguments and options',
        'started' => 'Started',
        'duration' => 'Duration',
        'exit_code' => 'Exit code',
        'host' => 'Host',
    ],
    'empty_title' => 'No commands yet',
    'empty_body' => 'The Lumberjack package reports every artisan command with the arguments and options it got. Worker commands such as queue:work and horizon are left out.',
    'runs' => [
        'title' => 'Latest runs',
        'title_command' => 'Runs of :command',
        // Button
        'clear' => 'Show all commands',
        'empty' => 'No runs in the period',
        'view_issue' => 'View issue',
        'no_input' => 'None',
    ],
];
