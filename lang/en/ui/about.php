<?php

declare(strict_types=1);

return [
    'title' => 'About Lumberjack',
    'description' => 'Which version this is, and where to read about it.',
    'revision' => 'Built from :revision',
    'release_notes' => 'Release notes',
    'changelog' => 'Changelog',
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
        'queue' => 'Queue',
        'cache' => 'Cache',
        'session' => 'Sessions',
        'environment' => 'Environment',
    ],
];
