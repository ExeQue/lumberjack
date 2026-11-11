<?php

declare(strict_types=1);

return [
    // Error messages in the answers of the OTLP endpoints, read by an exporter's log.
    'errors' => [
        'content_type' => 'Send application/x-protobuf or application/json.',
        'gzip' => 'Body is not valid gzip.',
        'too_large' => 'Body too large.',
        'too_many_events' => 'More than :max events in one export; export smaller batches.',
        'undecodable' => 'Body is not an OTLP export request.',
    ],
];
