<?php

declare(strict_types=1);

return [
    // The Connectors page: every connector, and what the project has connected of each.
    'index' => [
        'title' => 'Connectors',
        'description' => 'Connect the services this project uses. Each gets a page of its own, and its figures can go on your screens.',
        'search' => 'Search connectors',
        'search_placeholder' => 'Search by name, description or tag',
        // The group of tag chips; each chip filters on its tag.
        'tags' => 'Tags',
        // Read out after the list changes.
        'results' => '{0} No connectors match|{1} 1 connector|[2,*] :count connectors',
        'connected' => 'Connected',
        'not_connected' => 'Not connected',
        // Before the names of the connected websites, repositories or ledgers, which link to them.
        'connections' => '{1} 1 connection|[2,*] :count connections',
        // Buttons.
        'connect' => 'Connect',
        'connect_another' => 'Connect another',
        'open' => 'Open',
        'open_label' => 'Open :connector',
        'connect_label' => 'Connect :connector',
        'connect_another_label' => 'Connect another :connector',
        'empty_title' => 'No connectors match',
        'clear' => 'Clear filters',
        // The pointer below the list, as in docs/connectors/index.md, followed by two links.
        'missing' => 'Missing a service? Ask for it, or build it.',
        // Links to the issues and the pull requests on GitHub.
        'missing_issue' => 'Open an issue',
        'missing_pull' => 'Submit a pull request',
    ],

    // The Connection card and tab every connector page has.
    'connection' => [
        'title' => 'Connection',
        // Buttons.
        'disconnect' => 'Disconnect',
    ],

    // GitHub: a repository's pull requests, workflow runs and releases.
    'github' => [
        'title' => 'GitHub',
        'description' => 'Pull requests, commits, workflow runs, releases and security alerts of :name.',
        'error_title' => 'GitHub could not be read',
        // The picker of which connected repository the page shows.
        'repository' => 'Repository',
        'never' => 'None yet',
        // Button below a list: show twenty more rows (ten more of the top contributors).
        'load_more' => 'Load more',

        'cards' => [
            'open_pull_requests' => 'Open pull requests',
            'waiting_for_review' => 'Waiting for review',
            'merged' => 'Merged',
            'failed_runs' => 'Failed runs',
            'open_issues' => 'Open issues',
            'ci' => 'CI on :branch',
            'ci_none' => 'CI',
            'latest_release' => 'Latest release',
            'commits' => 'Commits',
            'authors' => 'Authors',
            'lead_time' => 'Lead time',
            // A duration in hours, such as "4.5 h".
            'hours' => ':hours h',
            'stars' => 'Stars',
            'forks_watchers' => ':forks forks, :watchers watchers',
            'issues_opened' => 'Issues opened',
            'issues_closed' => 'Issues closed',
            'open_bugs' => 'Open bugs',
        ],

        'tabs' => [
            'label' => 'GitHub sections',
            'pull_requests' => 'Pull requests',
            'commits' => 'Commits',
            'actions' => 'Actions',
            'releases' => 'Releases',
            'security' => 'Security',
            'connection' => 'Connection',
        ],

        'commits' => [
            'title' => 'Commits',
            'description' => 'On the default branch, by the time they were committed.',
            'description_branch' => 'On :branch, by the time they were committed.',
            'summary' => ':commits commits by :authors authors over the period',
            // Shown when the period has more commits than are read.
            'capped' => 'Only the newest :max commits of the period are read.',
            'contributors' => 'Top contributors',
            'contributors_description' => 'The most commits over the period.',
            'author' => 'Author',
            'count' => 'Commits',
            'latest' => 'Latest commits',
            'latest_description' => 'The newest first.',
            'commit' => 'Commit',
            'committed' => 'Committed',
            'empty' => 'No commits over the period.',
        ],

        'security' => [
            'title' => 'Open security alerts',
            'description' => 'As they are now.',
            'kinds' => [
                'vulnerability_alerts' => 'Dependabot',
                'security_events' => 'Code scanning',
                'secret_scanning_alerts' => 'Secret scanning',
            ],
            'severities' => [
                'critical' => 'Critical',
                'high' => 'High',
                'medium' => 'Medium',
                'low' => 'Low',
            ],
            'no_permission' => 'The token cannot read :permission. Connect the repository again with a token that has :permission: read-only.',
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
            // Name of the icon button that shows or hides a release's notes.
            'notes' => 'Notes of :name',
        ],

        'not_connected' => [
            'description' => 'Pull requests, workflow runs and releases of a GitHub repository.',
            'title' => 'Connect GitHub',
            'body' => 'Give Lumberjack a repository and a read-only token for it. Its figures show here and as data sources for screens.',
            'members' => 'No repository is connected. A team owner or admin can connect one.',
        ],

        'connect' => [
            'repository' => 'Repository',
            'repository_hint' => 'As owner/name, such as acme/shop, or any address on github.com under the repository.',
            'token' => 'Token',
            'token_hint' => 'A fine-grained token for this repository with read-only Metadata, Pull requests, Actions and Contents. Optionally also Issues, Dependabot alerts, Code scanning alerts and Secret scanning alerts, for their figures. Not the token of the repository integration.',
            'create_token' => 'Create token on GitHub',
            'opens_new_tab' => '(opens in a new tab)',
            'token_description' => 'Lets Lumberjack read pull requests, Actions and releases of :repository.',
            'branch' => 'Branch (optional)',
            'branch_hint' => 'Commits, the CI status and failed runs are read from it. Empty for the repository\'s default branch.',
            'branch_placeholder' => 'Default branch',
            // Buttons.
            'submit' => 'Connect',
            'cancel' => 'Cancel',
        ],

        'settings' => [
            'body' => 'Reads :url.',
            // Buttons.
            'add' => 'Connect another repository',
            'disconnect_question' => 'Disconnect this repository? Its data sources are removed, and widgets showing them go empty.',
            'optional' => 'Optional permissions',
            'optional_hint' => 'Read when the token was last connected. Connect the repository again with a new token to add one.',
            'granted' => 'Granted',
            'missing' => 'Missing',
            'branch' => 'Branch',
            'branch_hint' => 'Commits, the CI status and failed runs are read from it; pull requests, releases, issues and security stay repository-wide. Empty for the default branch.',
            // Button.
            'save_branch' => 'Save branch',
        ],
    ],

    // Vipps MobilePay: a sales unit's ledger, read through the Report API.
    'mobilepay' => [
        'title' => 'Vipps MobilePay',
        'description' => 'What :name booked in Vipps MobilePay, per day.',
        'error_title' => 'Vipps MobilePay could not be read',
        // The picker of which connected ledger the page shows.
        'ledger' => 'Ledger',

        'cards' => [
            'revenue' => 'Revenue',
            'transactions' => 'Transactions',
            'refunds' => 'Refunds',
            'fees' => 'Fees',
            'payouts' => 'Payouts',
            'average_order' => 'Average order',
            'refund_rate' => 'Refund rate',
            'fee_rate' => 'Fee rate',
            'balance' => 'Balance',
            // Under the balance: what Vipps MobilePay holds for the merchant until the next payout.
            'balance_note' => 'To be paid out',
        ],

        'series' => [
            'title' => 'Revenue',
            'description' => 'Per ledger date. Today fills in as Vipps MobilePay books it, and is complete once the day closes.',
            'summary' => ':revenue from :transactions transactions over the period',
        ],

        // Button below a list: show more rows.
        'load_more' => 'Load more',
        'time' => 'Time',
        'amount' => 'Amount',
        'reference' => 'Reference',

        'transactions' => [
            'title' => 'Latest transactions',
            'description' => 'Sales and refunds over the period, the newest first.',
            'type' => 'Type',
            'capture' => 'Sale',
            'refund' => 'Refund',
            'empty' => 'No sales or refunds over the period.',
        ],

        'largest' => [
            'title' => 'Largest sales',
            'description' => 'The period\'s largest captures.',
            'empty' => 'No sales over the period.',
        ],

        'payouts' => [
            'title' => 'Payouts',
            'description' => 'What was scheduled to be paid out to the bank account, per ledger date, the newest first.',
            'date' => 'Ledger date',
            'empty' => 'No payouts over the period.',
        ],

        'hours' => [
            'title' => 'Sales by hour of day',
            'description' => 'Captures per hour over the period, in :timezone.',
            // Screen reader summary of the bars.
            'summary' => '{0} No sales over the period|{1} Sales per hour of the day, the busiest :hour with 1 sale|[2,*] Sales per hour of the day, the busiest :hour with :count sales',
            // Hover text of a bar: "14:00 · 12 sales · 1 200,00 kr".
            'tip' => '{1} :label · 1 sale · :revenue|[0,*] :label · :count sales · :revenue',
        ],

        'weekdays' => [
            'title' => 'Sales by weekday',
            'description' => 'Captures per weekday over the period, in :timezone.',
            'summary' => '{0} No sales over the period|{1} Sales per weekday, the busiest :weekday with 1 sale|[2,*] Sales per weekday, the busiest :weekday with :count sales',
        ],

        'units' => [
            'title' => 'Revenue per sales unit',
            'description' => 'The captures of each sales unit settling to the ledger, the most first.',
            'unit' => 'Sales unit',
            'revenue' => 'Revenue',
            'empty' => 'No sales over the period.',
        ],

        'not_connected' => [
            'description' => 'Revenue, refunds, fees and payouts of a Vipps MobilePay ledger.',
            'title' => 'Connect Vipps MobilePay',
            'body' => 'Give Lumberjack the API keys of a sales unit. Its ledger\'s figures show here and as data sources for screens.',
            'members' => 'No Vipps MobilePay ledger is connected. A team owner or admin can connect one.',
        ],

        'connect' => [
            'client_id' => 'Client ID',
            'client_secret' => 'Client secret',
            'subscription_key' => 'Subscription key',
            'msn' => 'Merchant serial number',
            'keys_hint' => 'In the business portal, under For developers > Production: Show keys beside the sales unit. Either subscription key works.',
            'timezone' => 'Days begin in',
            'timezone_hint' => 'The time zone the ledger\'s days run in, normally the merchant\'s own.',
            // Buttons.
            'submit' => 'Connect',
            'cancel' => 'Cancel',
        ],

        // The ledger is booked per day: 24 hours is today.
        'periods' => ['24h' => '1 d', '7d' => '7 d', '30d' => '30 d'],
        'tabs' => [
            'label' => 'Vipps MobilePay sections',
            'overview' => 'Overview',
            'connection' => 'Connection',
        ],
        'settings' => [
            'body' => 'Reads the ledger of :name; days begin in :timezone.',
            // Buttons.
            'add' => 'Connect another ledger',
            'disconnect_question' => 'Disconnect this ledger? Its data sources are removed, and widgets showing them go empty.',
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
