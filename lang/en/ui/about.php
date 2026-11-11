<?php

declare(strict_types=1);

return [
    'title' => 'About Lumberjack',
    'description' => 'Which version this is, and where to read about it.',
    'revision' => 'Built from :revision',
    'release_notes' => 'Release notes',
    'changelog' => 'Changelog',
    'notes' => [
        'title' => 'What\'s new',
        'description' => 'The changelog this version was built with, newest first.',
        'jump' => 'Releases',
        'unreleased' => 'Unreleased',
        'running' => 'running',
        // A button after the release chips.
        'more' => 'Show :count more',
        'empty' => 'This build has no changelog.',
    ],
    'docs' => 'Documentation',
    'update' => [
        'current' => 'You are on the latest release.',
        'available' => 'Lumberjack :version is available.',
        'latest' => 'Latest release: :version.',
        'never_checked' => 'Not checked for a newer release yet.',
        'checked' => 'Checked',
        'sidebar' => 'Update available: :version',
        // Button
        'check' => 'Check now',
    ],
    'system' => [
        'title' => 'System',
        'description' => 'What this Lumberjack runs on; handy in a bug report. Only system administrators see it.',
        'php' => 'PHP',
        'laravel' => 'Laravel',
        'database' => 'Database',
        'database_size' => 'Database size',
        'queue' => 'Queue',
        'cache' => 'Cache',
        'session' => 'Sessions',
        'environment' => 'Environment',
    ],
];
