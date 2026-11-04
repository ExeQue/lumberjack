<?php

declare(strict_types=1);

return [
    'title' => 'Welcome to Lumberjack',
    'step' => 'Step :step of :steps',
    // Button
    'back' => 'Back',
    // Button
    'next' => 'Next',
    // Button
    'skip' => 'Skip',
    // Button
    'done' => 'Done',
    // Button
    'not_now' => 'Not now',
    'welcome' => [
        'title' => 'Welcome, :name',
        'intro' => 'A quick look around, then two settings. You can change everything later under your name in the sidebar.',
        'teams' => 'Your teams',
        'projects' => '{0} No projects yet|{1} 1 project|[2,*] :count projects',
        'more' => 'and :count more',
        'no_team' => 'You are not in a team yet. Create one on the Team page, or ask a team owner to invite you.',
        // Link
        'team_page' => 'Open the Team page',
        'where' => 'Where things are',
        'sidebar' => 'The sidebar: your projects and their pages, the team, and your account at the bottom.',
        'palette' => 'The command palette: press :keys to jump to a page, project or team, or to search the project you are in.',
        'bell' => 'The bell at the top of the sidebar: your notifications.',
    ],
    'notifications' => [
        'title' => 'How do you want to hear about things?',
        'intro' => 'Pick a starting point, then change any cell. Balanced is what everyone starts with.',
    ],
    'push' => [
        'title' => 'Notifications in this browser',
        'intro' => 'Browser notifications also reach you while Lumberjack is closed. Turn them on here if you want them on this device; each browser is turned on by itself.',
    ],
];
