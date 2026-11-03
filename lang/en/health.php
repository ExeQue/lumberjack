<?php

declare(strict_types=1);

return [
    'saved' => ':check is saved.',
    'deleted' => ':check is deleted.',
    'results' => [
        'bad_url' => 'The URL is not an http or https URL.',
        'too_many_redirects' => 'More than :max redirects.',
        'http_status' => 'Expected status :expected, got :got.',
        'http_body' => 'The response does not contain ":text".',
        'http_slow' => 'Took :ms ms, over the :max ms allowed.',
        'http_ok' => ':status in :ms ms.',
        'ssl_expires' => 'The certificate expires in :days days (:date).',
        'tcp_ok' => 'Port :port answered in :ms ms.',
        'dns_none' => 'No :type records.',
        'dns_mismatch' => 'Expected :expected, found :found.',
        'heartbeat_ok' => 'Ping received.',
        'heartbeat_late' => 'No ping since :since.',
        'heartbeat_never' => 'No ping yet.',
    ],
];
