<?php

declare(strict_types=1);

return [
    // A service connection whose settings were saved under another APP_KEY.
    'secret_unreadable' => 'The saved credentials cannot be decrypted (was APP_KEY changed?). Connect it again.',

    // The catalog of services a project can connect to, on the data sources page.
    'catalog' => [
        'mosparo' => [
            'label' => 'mosparo',
            'description' => 'Valid and spam form submissions of a mosparo project, on a page of their own and as data sources for screens.',
        ],
        'umami' => [
            'label' => 'Umami',
            'description' => 'Visitors, pages, sources and events of an Umami website, on a page of their own and as data sources for screens.',
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
