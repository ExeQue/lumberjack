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
            'address' => env('MAIL_ALERTS_FROM_ADDRESS') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => env('MAIL_ALERTS_FROM_NAME') ?: 'Lumberjack',
        ],
        'notifications_from' => [
            'address' => env('MAIL_NOTIFICATIONS_FROM_ADDRESS') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
            'name' => env('MAIL_NOTIFICATIONS_FROM_NAME') ?: 'Lumberjack',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrubbing
    |--------------------------------------------------------------------------
    |
    | Values that look like secrets (bearer tokens, token= and signature= in
    | URLs, JWTs, AWS, Stripe, GitHub and Slack keys) are masked in stored
    | context and request URLs. Add patterns of your own here, as regular
    | expression => replacement, e.g. '/\bACME-[0-9]{12}\b/' => '[filtered]'.
    |
    */

    'scrubbing' => [
        'value_patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | Who may create a team from the team page: "everyone" (any signed-in user,
    | who becomes its owner) or "admins" (system administrators only).
    |
    */

    'teams' => [
        'allow_creation' => env('LUMBERJACK_TEAM_CREATION', 'everyone'),
    ],

];
