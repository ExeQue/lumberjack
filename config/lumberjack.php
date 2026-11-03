<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mail senders
    |--------------------------------------------------------------------------
    |
    | Alerts (what a rule sends to a mail channel) and personal mail (mentions,
    | assignments, watched issues, invitations, password resets) come from their
    | own addresses, so a mail client can filter them apart. Both default to
    | MAIL_FROM_ADDRESS. See docs/administration.md.
    |
    */

    'mail' => [
        'alerts_from' => [
            'address' => env('MAIL_ALERTS_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('MAIL_ALERTS_FROM_NAME', 'Lumberjack'),
        ],
        'notifications_from' => [
            'address' => env('MAIL_NOTIFICATIONS_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
            'name' => env('MAIL_NOTIFICATIONS_FROM_NAME', 'Lumberjack'),
        ],
    ],

];
