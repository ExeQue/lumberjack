<?php

declare(strict_types=1);

return [
    'title' => 'Schedule',
    'description' => '{0} No scheduled tasks yet.|{1} 1 scheduled task.|[2,*] :count scheduled tasks.',
    'attention' => '{1} 1 task failed or was missed.|[2,*] :count tasks failed or were missed.',
    'statuses' => ['running' => 'Running', 'finished' => 'Finished', 'failed' => 'Failed', 'skipped' => 'Skipped', 'missed' => 'Missed'],
    'sort' => ['label' => 'Sort', 'name' => 'Name', 'next' => 'Next run first'],
    'late' => 'Late',
    'never' => 'Never',
    'columns' => [
        'task' => 'Task',
        'expression' => 'Cron',
        'last_run' => 'Last run',
        'duration' => 'Duration',
        'next_run' => 'Next run',
        'status' => 'Status',
        'recent' => 'Recent runs',
    ],
    'recent_label' => 'The last :count runs, oldest first',
    'empty_title' => 'No scheduled tasks yet',
    'empty_body' => 'The Lumberjack package reports tasks when the app\'s scheduler runs them (php artisan schedule:work, or schedule:run every minute).',
    'runs' => [
        'title' => 'Runs of :task',
        'empty' => 'No runs yet',
        'started' => 'Started',
        'exit_code' => 'Exit code',
        'host' => 'Host',
        'view_issue' => 'View issue',
    ],
    // Button
    'forget' => 'Forget task',
    'forget_question' => 'Forget :task and its runs? If the app still runs it, it comes back.',
    'hint' => 'A task that has not run :grace minutes after its time is marked missed and becomes an issue.',
];
