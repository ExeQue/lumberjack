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

    'pull_token' => 'Pull token: set it as LUMBERJACK_PULL_TOKEN in your app',
    'connection_updated' => 'The address of :environment is changed.',
    'connection_removed' => ':environment disconnected, and its sources removed.',
    'url_plain' => 'Give the address of the endpoint alone, with no password, query or fragment.',
    'too_many_connections' => 'A project can connect at most :max apps.',

    // Why a fetch failed, shown as the source's last error.
    'errors' => [
        'status' => 'The server answered HTTP :status.',
        'too_large' => 'The response is larger than :max KB.',
        'not_json' => 'The response is not valid JSON.',
        'no_samples' => 'The response has no Prometheus samples (expected the text format, lines like name{label="value"} 1).',
        'not_ical' => 'The response is not an iCalendar feed (expected text starting with BEGIN:VCALENDAR, as an .ics address answers).',
        'not_https' => 'The address is not https. Connected sources are only pulled over https.',
        'not_answering' => 'The app answers 404: it is not deployed with this token yet, or the address is wrong.',
        'token_unreadable' => 'The pull token cannot be decrypted (was APP_KEY changed?). Rotate it.',
        'bad_index' => 'The app\'s list of sources could not be read.',
        'too_many_sources' => 'Some sources were left out: an app can offer :listed, and a project hold :held, counting those no longer offered until they are removed.',
        'no_answer' => 'The app did not answer for this source.',
        'app' => 'The app answered: :error',
        'bad_shape' => 'The app\'s answer is not in the form this source declares.',
        'headers_unreadable' => 'Its header values cannot be decrypted (was APP_KEY changed?); save them again.',
    ],
];
