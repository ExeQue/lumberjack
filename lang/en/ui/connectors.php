<?php

declare(strict_types=1);

return [
    // The Connection card and tab every connector page has.
    'connection' => [
        'title' => 'Connection',
        // Buttons.
        'disconnect' => 'Disconnect',
    ],

    // GitHub: a repository's pull requests, workflow runs and releases.
    'github' => [
        'title' => 'GitHub',
        'description' => 'Pull requests, workflow runs and releases of :name.',
        'error_title' => 'GitHub could not be read',
        // The picker of which connected repository the page shows.
        'repository' => 'Repository',
        'never' => 'None yet',

        'cards' => [
            'open_pull_requests' => 'Open pull requests',
            'waiting_for_review' => 'Waiting for review',
            'merged' => 'Merged',
            'failed_runs' => 'Failed runs',
            'open_issues' => 'Open issues',
            'ci' => 'CI on :branch',
            'ci_none' => 'CI',
            'latest_release' => 'Latest release',
        ],

        'tabs' => [
            'label' => 'GitHub sections',
            'pull_requests' => 'Pull requests',
            'actions' => 'Actions',
            'releases' => 'Releases',
            'connection' => 'Connection',
        ],

        'pull_requests' => [
            'title' => 'Open pull requests',
            'description' => 'The newest first.',
            'pull_request' => 'Pull request',
            'author' => 'Author',
            'opened' => 'Opened',
            'draft' => 'Draft',
            'empty' => 'No open pull requests.',
        ],

        'actions' => [
            'title' => 'Workflow runs',
            'description' => 'The latest runs, on every branch.',
            'workflow' => 'Workflow',
            'branch' => 'Branch',
            'event' => 'Event',
            'result' => 'Result',
            'started' => 'Started',
            'empty' => 'No workflow runs.',
        ],

        'releases' => [
            'title' => 'Releases',
            'description' => 'The newest first.',
            'release' => 'Release',
            'tag' => 'Tag',
            'published' => 'Published',
            'prerelease' => 'Pre-release',
            'empty' => 'No releases.',
        ],

        'not_connected' => [
            'description' => 'Pull requests, workflow runs and releases of a GitHub repository.',
            'title' => 'Connect GitHub',
            'body' => 'Give Lumberjack a repository and a read-only token for it. Its figures show here and as data sources for screens.',
            'members' => 'No repository is connected. A team owner or admin can connect one.',
        ],

        'connect' => [
            'repository' => 'Repository',
            'repository_hint' => 'As owner/name, such as acme/shop, or its address on github.com.',
            'token' => 'Token',
            'token_hint' => 'A fine-grained token for this repository with read-only Metadata, Pull requests, Actions and Contents. Not the token of the repository integration.',
            // Buttons.
            'submit' => 'Connect',
            'cancel' => 'Cancel',
        ],

        'settings' => [
            'body' => 'Reads :url.',
            // Buttons.
            'add' => 'Connect another repository',
            'disconnect_question' => 'Disconnect this repository? Its data sources are removed, and widgets showing them go empty.',
        ],
    ],

    // mosparo: a project's form submissions, read through mosparo's statistic API.
    'mosparo' => [
        'title' => 'mosparo',
        'description' => 'Form submissions to :name as mosparo judged them, per day.',
        'error_title' => 'mosparo could not be read',
        // The picker of which connected mosparo project the page shows.
        'project' => 'mosparo project',
        'never' => 'No submissions yet',

        'cards' => [
            'valid' => 'Valid',
            'spam' => 'Spam',
            'spam_rate' => 'Spam rate',
            'blocked' => 'Blocked requests',
            'delayed' => 'Delayed requests',
            'last_submission' => 'Last submission',
        ],

        'series' => [
            'title' => 'Submissions',
            'description' => 'Per day; days mosparo no longer keeps show none.',
            'summary' => ':valid valid and :spam spam submissions over the period',
        ],

        'not_connected' => [
            'description' => 'Valid and spam submissions from a mosparo project.',
            'title' => 'Connect mosparo',
            'body' => 'Give Lumberjack the API keys of a mosparo project. Its figures show here and as data sources for screens.',
            'members' => 'No mosparo project is connected. A team owner or admin can connect one.',
        ],

        'connect' => [
            'name' => 'Name',
            'name_hint' => 'What it is shown as here, such as the form it protects.',
            'url' => 'Address',
            'url_hint' => 'The https address you open mosparo at, such as https://mosparo.example.com.',
            'public_key' => 'Public key',
            'private_key' => 'Private key',
            'keys_hint' => 'In mosparo, under the project\'s settings > General settings > API keys.',
            'timezone' => 'Days begin in',
            'timezone_hint' => 'The time zone mosparo runs in, as it counts its days there.',
            // Buttons.
            'submit' => 'Connect',
            'cancel' => 'Cancel',
        ],

        // mosparo counts whole days: 24 hours is today.
        'periods' => ['24h' => '1 d', '7d' => '7 d', '30d' => '30 d'],
        'tabs' => [
            'label' => 'mosparo sections',
            'overview' => 'Overview',
            'connection' => 'Connection',
        ],
        'settings' => [
            'body' => ':instance; days begin in :timezone.',
            // Buttons.
            'add' => 'Connect another mosparo project',
            'disconnect_question' => 'Disconnect this mosparo project? Its data sources are removed, and widgets showing them go empty.',
        ],
    ],

    // Umami: a website's visitors, read through Umami's API.
    'umami' => [
        'title' => 'Umami',
        // Button below a breakdown: show ten more rows.
        'load_more' => 'Load more',
        // Placeholder of the search above each breakdown.
        'search' => 'Search',
        // The picker of which connected website the page shows.
        'website' => 'Website',
        'description' => 'Visitors to :website as Umami counted them, beside what Lumberjack saw of the same pages. Click a row to filter by it.',
        // Shown above a UTM breakdown to those who may name its values.
        'utm_hint' => 'Name a UTM value, such as a campaign id, with the pencil beside it. The name shows here, on screens and as the campaign\'s data source.',
        'active' => '{0} Nobody on the site right now|{1} 1 visitor right now|[2,*] :count visitors right now',
        'error_title' => 'Umami could not be read',
        'empty' => 'Nothing in this period.',
        // A row without a value, such as direct traffic among the referrers.
        'none' => '(none)',

        'not_connected' => [
            'description' => 'Visitors, pages, sources and events from an Umami website.',
            'title' => 'Connect Umami',
            'body' => 'Give Lumberjack access to your Umami and pick the website of this project. Its figures show here and as data sources for screens.',
            'members' => 'No Umami website is connected. A team owner or admin can connect one.',
        ],

        'connect' => [
            'hosting' => 'Where Umami runs',
            'cloud' => 'Umami Cloud',
            'self_hosted' => 'Self-hosted',
            'url' => 'Address',
            'url_hint' => 'The https address you open Umami at, such as https://umami.example.com.',
            'auth' => 'Sign in with',
            'api_key' => 'API key',
            'login' => 'User name and password',
            'username' => 'User name',
            'password' => 'Password',
            'cloud_key_hint' => 'Create one in Umami Cloud under Settings, API keys. It is stored encrypted and never shown again.',
            'secret_hint' => 'Stored encrypted and never shown again. A user who signs in with two-factor authentication needs an API key.',
            'website' => 'Website',
            'website_hint' => 'Your own websites, and those of each of your teams in Umami.',
            // The group of the user's own websites in the list, beside each team's.
            'personal' => 'Personal',
            'no_websites' => 'Umami lists no website for these credentials. Give the website ID instead: it is in the address of the website\'s page in Umami, after /websites/.',
            'timezone' => 'Time zone',
            'timezone_hint' => 'Where the days of a chart begin.',
            // Buttons.
            'open' => 'Connect Umami',
            'find' => 'Find websites',
            'find_again' => 'Find again',
            'submit' => 'Connect',
            'cancel' => 'Cancel',
        ],

        'tabs' => [
            'label' => 'Umami sections',
            'overview' => 'Overview',
            'pages' => 'Pages',
            'sources' => 'Sources',
            'audience' => 'Audience',
            'events' => 'Events',
            'connection' => 'Connection',
        ],
        'notes' => [
            'title' => 'Notes',
            'empty' => 'No notes in this period. Mark a campaign\'s launch or a change to the site, and it shows on the chart here and in Umami.',
            'date' => 'Date',
            'time' => 'Time (optional)',
            'note' => 'Note',
            'placeholder' => 'Black Friday campaign live',
            // Buttons.
            'add' => 'Add note',
            'save' => 'Save note',
            'delete' => 'Delete',
            'delete_question' => 'Delete the note ":note"? It is removed from Umami too.',
        ],
        'heatmap' => [
            'title' => 'Busiest hours',
            'description' => 'Visitors per hour of the week over the last four weeks, in the connection\'s time zone.',
            'summary' => 'Busiest at :day :hour:00, with :count visitors.',
            'cell' => ':day :hour:00: :count visitors',
        ],
        'labels' => [
            // Buttons beside a UTM value: give it a name, or change the one it has.
            'name' => 'Name',
            'rename' => 'Rename',
            'save' => 'Save',
            'field' => 'Name for :value; leave empty to forget the name',
        ],
        'filters' => [
            'label' => 'Filters',
            'add' => 'Show only :value',
            'remove' => 'Remove filter :filter',
        ],

        'cards' => [
            'visitors' => 'Visitors',
            'visits' => 'Visits',
            'pageviews' => 'Page views',
            'per_visit' => 'Views per visit',
            'bounce_rate' => 'Bounce rate',
            'visit_duration' => 'Time per visit',
        ],

        'traffic' => [
            'title' => 'Traffic',
            'description' => 'Per bucket of the period. A dashed line marks a release Lumberjack saw go out (amber) or a note (green).',
            'metric' => 'Show',
            'summary' => ':metric per bucket over the period; :total in all.',
            'release' => 'Release :release, first seen :at',
            'note' => 'Note :note, :at',
        ],

        'cards_titles' => [
            'pages' => 'Pages',
            'sources' => 'Sources',
            'audience' => 'Audience',
            'events' => 'Events',
        ],

        'dimensions' => [
            'path' => 'Page',
            'entry' => 'Entry',
            'exit' => 'Exit',
            'title' => 'Title',
            'referrer' => 'Referrer',
            'channel' => 'Channel',
            'utmSource' => 'UTM source',
            'utmMedium' => 'UTM medium',
            'utmCampaign' => 'UTM campaign',
            'country' => 'Country',
            'region' => 'Region',
            'city' => 'City',
            'language' => 'Language',
            'browser' => 'Browser',
            'os' => 'OS',
            'device' => 'Device',
            'screen' => 'Screen',
            'event' => 'Event',
            'hostname' => 'Domain',
        ],

        'columns' => [
            'visitors' => 'Visitors',
            'bounce_rate' => 'Bounce',
            'visit_duration' => 'Time',
            'conversion' => 'Of visitors',
            'conversion_help' => 'The share of the period\'s visitors who triggered the event.',
            'count' => 'Count',
            'lcp' => 'LCP',
            'lcp_help' => 'Largest Contentful Paint p75 of the page, as Lumberjack\'s browser script measured it. Ids in the path are folded, so /orders/12 is /orders/{id}.',
        ],

        'settings' => [
            'cloud' => 'Umami Cloud; days begin in :timezone.',
            'self_hosted' => ':instance; days begin in :timezone.',
            'add_hint' => 'Read another website with the same credentials.',
            // Buttons.
            'add' => 'Connect another Umami',
            'change_website' => 'Change website',
            'save_website' => 'Use this website',
            'add_website' => 'Add a website',
            'connect_website' => 'Connect this website',
            'disconnect_question' => 'Disconnect this website? Its data sources are removed, and widgets showing them go empty.',
            'failed' => 'The websites could not be listed.',
        ],
    ],
];
