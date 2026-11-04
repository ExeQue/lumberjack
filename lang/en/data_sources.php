<?php

declare(strict_types=1);

return [
    // Flash messages on the data sources page.
    'saved' => ':source saved; it is fetched within a minute.',
    'deleted' => ':source deleted.',
    'fetch_queued' => ':source is being fetched.',
    'header_value_required' => 'Give the header a value.',
    'header_value_new_origin' => 'The URL points at another server now: give :header its value again.',
    'url_credentials' => 'Leave the user name and password out of the URL; send them in a header.',

    // Why a fetch failed, shown as the source's last error.
    'errors' => [
        'status' => 'The server answered HTTP :status.',
        'too_large' => 'The response is larger than :max KB.',
        'not_json' => 'The response is not valid JSON.',
        'no_samples' => 'The response has no Prometheus samples (expected the text format, lines like name{label="value"} 1).',
        'headers_unreadable' => 'Its header values cannot be decrypted (was APP_KEY changed?); save them again.',
    ],
];
