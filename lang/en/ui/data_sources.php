<?php

declare(strict_types=1);

return [
    'title' => 'Data sources',
    'description' => '{0} No data sources yet.|{1} 1 data source.|[2,*] :count data sources.',
    'add' => 'Add data source',
    'list_title' => 'External endpoints',
    'list_hint' => 'JSON, Prometheus or iCalendar endpoints Lumberjack fetches on their interval. Screen widgets read a number or rows from the latest response, with JSONPath or a Prometheus selector, charts from the history, and a calendar list its events.',
    'empty_title' => 'No data sources yet',
    'empty_body' => 'Add a JSON or Prometheus endpoint to show its figures on a screen: a stat, a gauge, a chart or a list.',
    'formats' => ['json' => 'JSON', 'prometheus' => 'Prometheus', 'ical' => 'iCalendar (.ics)', 'connected' => 'Connected'],
    'connectors' => [
        'title' => 'Connectors',
        'hint' => 'Services Lumberjack reads for this project. Each has a page of its own, and offers its figures to screens as data sources.',
        'connected' => ':count connected',
        // Buttons.
        'connect' => 'Connect',
        'open' => 'Open',
    ],
    'connected' => [
        // Button: open the Umami page of a connected website.
        'open_umami' => 'Open Umami',
        // The heading of an Umami connection in the list.
        'umami' => 'Umami: :website',
        'title' => 'From your apps',
        'hint' => 'Apps using the Laravel package, and an Umami website. Lumberjack asks them which sources they offer, and pulls a source only while a screen shows it.',
        'empty_title' => 'No apps connected',
        'empty_body' => 'Connect an app to show its figures on a screen.',
        'no_sources' => 'No sources yet. They appear here once the app answers.',
        // Button
        'connect' => 'Connect app',
        'form_title' => 'Connect an app',
        'form_setup' => 'A pull token is shown once when you connect. Set it as LUMBERJACK_PULL_TOKEN in the app.',
        'environment' => 'Environment',
        'url' => 'Address',
        'url_hint' => 'The APP_URL of the app followed by the path of the endpoint, by default /lumberjack/datasources.',
        // Button
        'timezone' => 'Time zone',
        'timezone_hint' => 'Where a day begins, for sources that come in days.',
        'change_url' => 'Edit connection',
        'change_url_body' => 'For an app that moved or works in another time zone. Its token and sources stay, and so do the widgets that show them.',
        // Button
        'save_url' => 'Save',
        // Button
        'rotate' => 'Rotate token',
        'rotate_question' => 'Make a new pull token? Pulls go on with the current one until the app has the new one.',
        // Button
        'disconnect' => 'Disconnect',
        'disconnect_question' => 'Disconnect :environment? Its sources are removed, and widgets that show them go empty.',
        // Button
        'remove' => 'Remove',
        'remove_question' => 'Remove :source? Widgets that show it go empty.',
        'columns' => ['key' => 'Key', 'shape' => 'Shape', 'environment' => 'Environment'],
        'shapes' => ['number' => 'Number', 'series' => 'Series', 'list' => 'List', 'table' => 'Table'],
        'statuses' => [
            'connected' => 'Connected',
            'waiting' => 'Waiting for the app',
            'failing' => 'Failing',
            'archived' => 'No longer offered',
        ],
    ],
    'never' => 'Never',
    'last_ok' => 'Last OK',
    'every' => '{1} Every minute|[2,*] Every :count min',
    'preview' => 'Latest response',
    'fetch_now' => 'Fetch now',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'delete_question' => 'Delete :source? Widgets that show it go empty.',
    'statuses' => ['ok' => 'OK', 'failed' => 'Failed', 'pending' => 'Pending'],
    'columns' => [
        'name' => 'Source',
        'status' => 'Status',
        'last_fetch' => 'Last fetch',
        'every' => 'Interval',
        'actions' => 'Actions',
    ],
    'form' => [
        'add_title' => 'Add data source',
        'edit_title' => 'Edit :source',
        'setup' => 'Lumberjack sends a GET request to the URL on every interval and keeps what it answers.',
        'format' => 'Format',
        'format_hint' => 'Prometheus: the text exposition format, as served on /metrics. Counters are shown per minute. iCalendar: a calendar\'s secret address in iCal format (Google, Outlook, iCloud), for a calendar list; its path is never shown again.',
        'name' => 'Name',
        'url' => 'URL',
        'url_hint' => 'Must reach a public address.',
        'interval' => 'Interval',
        'minutes' => 'Minutes, at least 1.',
        'timeout' => 'Timeout',
        'seconds' => 'Seconds, at most 15.',
        'headers' => 'Request headers',
        'header_name' => 'Header name',
        'header_value' => 'Header value',
        'keep_value' => 'Leave empty to keep',
        'headers_hint' => 'Values are stored encrypted and never shown again; leave one empty to keep it.',
        'add_header' => 'Add header',
        'remove_header' => 'Remove',
        'save' => 'Save',
        'add' => 'Add',
    ],
];
