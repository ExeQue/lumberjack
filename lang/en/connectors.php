<?php

declare(strict_types=1);

return [
    // A service connection whose settings were saved under another APP_KEY.
    'secret_unreadable' => 'The saved credentials cannot be decrypted (was APP_KEY changed?). Connect it again.',

    // The catalog of services a project can connect to, on the Connectors page.
    'catalog' => [
        'github' => [
            'label' => 'GitHub',
            'description' => 'Pull requests, commits, CI, releases and security alerts from a GitHub repository.',
        ],
        'mobilepay' => [
            'label' => 'Vipps MobilePay',
            'description' => 'Sales, refunds, fees and payouts from a Vipps MobilePay ledger.',
        ],
        'mosparo' => [
            'label' => 'mosparo',
            'description' => 'Valid and spam form submissions from a mosparo project.',
        ],
        'umami' => [
            'label' => 'Umami',
            'description' => 'Visitors, pages, sources and events from an Umami website.',
        ],
    ],

    // What a connector is about, as chips to filter the Connectors page by.
    'tags' => [
        'analytics' => 'Analytics',
        'commerce' => 'Commerce',
        'development' => 'Development',
        'forms' => 'Forms',
        'payments' => 'Payments',
        'security' => 'Security',
    ],

    // GitHub: a repository's pull requests, workflow runs and releases, read through GitHub's REST API.
    'github' => [
        // Flash message after connecting.
        'connected' => 'GitHub connected: :name. Its sources show up under Data sources within a minute.',
        'connected_missing' => 'GitHub connected: :name. Its sources show up under Data sources within a minute. The token cannot read :permissions, so their sources are left out; the Connection tab says what they add.',
        // Flash message after changing the branch on the Connection tab.
        'branch_changed' => 'Commits and CI are now read from :branch.',
        'branch_default' => 'Commits and CI are now read from the default branch.',
        // Flash message after saving the Connection tab without changing the branch.
        'settings_saved' => 'Connection settings saved.',

        // The optional read permissions a token may have, as GitHub's token form names them, and what each adds.
        'permissions' => [
            'issues' => 'Issues',
            'vulnerability_alerts' => 'Dependabot alerts',
            'security_events' => 'Code scanning alerts',
            'secret_scanning_alerts' => 'Secret scanning alerts',
            'unlocks' => [
                'issues' => 'Issues opened and closed, and open bugs.',
                'vulnerability_alerts' => 'Open Dependabot alerts, by severity.',
                'security_events' => 'Open code scanning alerts.',
                'secret_scanning_alerts' => 'Open secret scanning alerts.',
            ],
        ],

        // The data sources a GitHub repository offers screens.
        'sources' => [
            'open_pull_requests' => 'Open pull requests',
            'waiting_for_review' => 'Pull requests waiting for review',
            'merged' => 'Pull requests merged',
            'failed_runs' => 'Failed workflow runs',
            'open_issues' => 'Open issues',
            'ci_status' => 'CI on the default branch',
            'ci_status_branch' => 'CI on :branch',
            'latest_release' => 'Latest release',
            'commits' => 'Commits',
            'commits_series' => 'Commits over time',
            'authors' => 'Commit authors',
            'latest_commits' => 'Latest commits',
            'top_contributors' => 'Top contributors',
            'pull_request_list' => 'Newest open pull requests',
            'lead_time' => 'Pull request lead time (hours)',
            'stars' => 'Stars',
            'forks' => 'Forks',
            'watchers' => 'Watchers',
            'issues_opened' => 'Issues opened',
            'issues_closed' => 'Issues closed',
            'open_bugs' => 'Open bugs',
            'dependabot_alerts' => 'Open Dependabot alerts',
            'dependabot_by_severity' => 'Dependabot alerts by severity',
            'code_scanning_alerts' => 'Open code scanning alerts',
            'secret_scanning_alerts' => 'Open secret scanning alerts',
        ],

        // A Dependabot alert's severity, as a row of "Dependabot alerts by severity".
        'severities' => [
            'critical' => 'Critical',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
        ],

        // Marks a draft in the list of open pull requests, after its author: "#11 Try a theme (bo, draft)".
        'draft' => 'draft',

        // What a workflow run came to, or how far it got while it runs.
        'results' => [
            'success' => 'Passed',
            'failure' => 'Failed',
            'cancelled' => 'Cancelled',
            'skipped' => 'Skipped',
            'timed_out' => 'Timed out',
            'action_required' => 'Action required',
            'neutral' => 'Neutral',
            'stale' => 'Stale',
            'startup_failure' => 'Failed to start',
            'queued' => 'Queued',
            'in_progress' => 'Running',
            'waiting' => 'Waiting',
            'pending' => 'Pending',
            'requested' => 'Requested',
        ],

        // Why asking GitHub failed, shown in the connect dialog, on the connection, its sources and the GitHub page.
        'errors' => [
            'unauthorized' => 'GitHub refused the token. Check it has not expired or been revoked.',
            'not_found' => 'The token cannot read :repository. Check the name, and that the token\'s repository access includes it.',
            'permission' => 'The token cannot read the repository\'s :permission. Give it :permission: read-only.',
            'rate_limited' => 'GitHub asks to slow down. It is asked again shortly.',
            'status' => 'GitHub answered with HTTP :status.',
            'unreachable' => 'GitHub could not be reached.',
            'unknown_source' => 'GitHub has no such source.',
            // A security feature the repository has turned off, or a token without its permission; GitHub answers both alike.
            'unavailable' => ':permission are not enabled for the repository, or the token cannot read them.',
            'secret_unreadable' => 'The GitHub token cannot be decrypted (was APP_KEY changed?). Connect GitHub again.',
            'branch_not_found' => ':repository has no branch :branch. Check the name, or leave it empty for the default branch.',
            'not_github' => 'This connection does not read a GitHub repository.',
        ],
    ],

    // Vipps MobilePay: a sales unit's ledger, read through the Report API.
    'mobilepay' => [
        // Flash message after connecting.
        'connected' => 'Vipps MobilePay connected: :name. Its sources show up under Data sources within a minute.',

        // The data sources a ledger offers screens; :currency is the ledger's, such as NOK.
        'sources' => [
            'revenue' => 'Revenue (:currency)',
            'transactions' => 'Transactions',
            'refunds' => 'Refunds (:currency)',
            'fees' => 'Fees (:currency)',
            'payouts' => 'Payouts (:currency)',
            'revenue_series' => 'Revenue per day (:currency)',
            'average_order' => 'Average order value (:currency)',
            'refund_rate' => 'Refund rate (%)',
            'fee_rate' => 'Fee rate (%)',
            'balance' => 'Balance to be paid out (:currency)',
            'latest_transactions' => 'Latest transactions (:currency)',
            'largest_sales' => 'Largest sales (:currency)',
            'payout_list' => 'Latest payouts (:currency)',
            'sales_by_hour' => 'Sales by hour of day',
            'sales_by_weekday' => 'Sales by weekday',
            'revenue_by_unit' => 'Revenue per sales unit (:currency)',
        ],

        // A transaction's kind, in a row of "Latest transactions": "2026-11-10 14:05 Sale (order-123)".
        'types' => [
            'capture' => 'Sale',
            'refund' => 'Refund',
        ],

        // The rows of "Sales by weekday", and the weekday bars on the Vipps MobilePay page; 1 is Monday.
        'weekdays' => [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ],

        // Why asking Vipps MobilePay failed, shown in the connect dialog, on the connection, its sources and the Vipps MobilePay page.
        'errors' => [
            'busy' => 'This ledger is being connected already. Try again in a moment.',
            'unauthorized' => 'Vipps MobilePay refused the API keys. Check the client ID, client secret, subscription key and merchant serial number in the business portal.',
            'forbidden' => 'The API keys may not read the Report API. Check the sales unit\'s access in the business portal.',
            'not_found' => 'Vipps MobilePay no longer finds the ledger. Connect it again.',
            'no_ledger' => 'The API keys see no ledger for merchant serial number :msn. Check the number in the business portal.',
            'rate_limited' => 'Vipps MobilePay asks to slow down. It is asked again shortly.',
            'status' => 'Vipps MobilePay answered with HTTP :status.',
            'unreachable' => 'Vipps MobilePay could not be reached.',
            'unknown_source' => 'Vipps MobilePay has no such source.',
            'secret_unreadable' => 'The Vipps MobilePay keys cannot be decrypted (was APP_KEY changed?). Connect Vipps MobilePay again.',
        ],
    ],

    // mosparo: a project's form submissions, read through mosparo's statistic API.
    'mosparo' => [
        // Flash message after connecting.
        'connected' => 'mosparo connected: :name. Its sources show up under Data sources within a minute.',

        // The data sources a mosparo project offers screens.
        'sources' => [
            'valid' => 'Valid submissions',
            'spam' => 'Spam submissions',
            'spam_rate' => 'Spam rate (%)',
            'delayed' => 'Delayed requests',
            'blocked' => 'Blocked requests',
            'valid_series' => 'Valid submissions per day',
            'spam_series' => 'Spam submissions per day',
        ],

        // Why asking mosparo failed, shown on the connection, its sources and the mosparo page.
        'errors' => [
            'unauthorized' => 'mosparo refused the keys. Check the public and private key in the project\'s settings in mosparo.',
            'not_mosparo' => 'The address did not answer as mosparo. Check it is the address you open mosparo at.',
            'not_https' => 'mosparo is only asked over https, so the private key never travels in the clear.',
            'rate_limited' => 'mosparo asks to slow down. It is asked again shortly.',
            'status' => 'mosparo answered with HTTP :status.',
            'too_large' => 'mosparo\'s answer was larger than Lumberjack reads.',
            'unknown_source' => 'mosparo has no such source.',
            'secret_unreadable' => 'The mosparo keys cannot be decrypted (was APP_KEY changed?). Connect mosparo again.',
        ],
    ],

    // Umami: a website's visitors, read through Umami's API.
    'umami' => [
        // Flash message after connecting or changing the website.
        'connected' => 'Umami connected: :website. Its sources show up under Data sources within a minute.',
        // A list row without a value, such as direct traffic in the referrers.
        'none' => '(none)',

        // The data sources an Umami website offers screens.
        'sources' => [
            'visitors' => 'Visitors',
            'visits' => 'Visits',
            'pageviews' => 'Page views',
            'bounce_rate' => 'Bounce rate (%)',
            'visit_duration' => 'Time per visit (seconds)',
            'active' => 'Visitors right now',
            'visitors_series' => 'Visitors over time',
            'pageviews_series' => 'Page views over time',
            'pages' => 'Top pages',
            'entry_pages' => 'Top entry pages',
            'referrers' => 'Top referrers',
            'channels' => 'Top channels',
            'countries' => 'Top countries',
            'browsers' => 'Top browsers',
            'os' => 'Top operating systems',
            'devices' => 'Top devices',
            'languages' => 'Top languages',
            'events' => 'Top events',
            'utm_sources' => 'Top UTM sources',
            'utm_campaigns' => 'Top UTM campaigns',
            'event_group' => 'Events',
            'campaign_group' => 'Campaigns',
            'over_time' => ':name over time',
        ],

        // Why asking Umami failed, shown on the connection, its sources and the Umami page.
        'errors' => [
            'already_connected' => 'The project already reads this website.',
            'unauthorized' => 'Umami refused the credentials. Check the API key, or the user name and password.',
            'not_found' => 'Umami does not know this website, or the address is not an Umami API.',
            'rate_limited' => 'Umami asks to slow down (Umami Cloud allows 50 calls per 15 seconds per key). It is asked again shortly.',
            'status' => 'Umami answered HTTP :status.',
            'too_large' => 'Umami\'s answer is too large.',
            'not_umami' => 'The answer is not Umami\'s API. Check the address.',
            'not_https' => 'The address is not https. Umami is only asked over https, as the credentials go with every call.',
            'two_factor' => 'This user signs in with two-factor authentication. Create an API key in Umami and use that instead.',
            'unknown_type' => 'Umami has no such breakdown.',
            'unknown_website' => 'These credentials cannot read that website.',
            'secret_unreadable' => 'The Umami credentials cannot be decrypted (was APP_KEY changed?). Connect Umami again.',
        ],
    ],
];
