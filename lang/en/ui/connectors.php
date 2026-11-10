<?php

declare(strict_types=1);

return [
    // Umami: a website's visitors, read through Umami's API.
    'umami' => [
        'title' => 'Umami',
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
            'hostname' => 'Host',
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
            'title' => 'Connection',
            'cloud' => 'Umami Cloud; days begin in :timezone.',
            'self_hosted' => ':instance; days begin in :timezone.',
            'sources' => 'Its data sources',
            'add_hint' => 'Read another website with the same credentials. A website on another Umami is connected from the data sources page.',
            // Buttons.
            'change_website' => 'Change website',
            'save_website' => 'Use this website',
            'add_website' => 'Add a website',
            'connect_website' => 'Connect this website',
            'disconnect' => 'Disconnect',
            'disconnect_question' => 'Disconnect this website? Its data sources are removed, and widgets showing them go empty.',
            'failed' => 'The websites could not be listed.',
        ],
    ],
];
