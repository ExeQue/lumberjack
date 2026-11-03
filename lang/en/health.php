<?php

declare(strict_types=1);

return [
    'saved' => ':check is saved.',
    'deleted' => ':check is deleted.',
    'too_many_checks' => 'A project can have at most :max checks that Lumberjack runs.',
    'down_after_too_low' => 'Down after must be at least degraded after (:degrade); set them the same to go straight to down.',
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
