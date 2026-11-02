<?php

declare(strict_types=1);

use App\Domains\Alerts\Drivers\DiscordDriver;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Drivers\WebhookDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Channel drivers
    |--------------------------------------------------------------------------
    |
    | One driver class per alert channel type, keyed by the ChannelType value.
    | Adding a channel type is a new enum case plus a driver registered here.
    |
    */

    'drivers' => [
        'mail' => MailDriver::class,
        'discord' => DiscordDriver::class,
        'webhook' => WebhookDriver::class,
    ],

    /*
    | Webhooks may not point at private or reserved addresses (this server, the
    | network behind it) unless this is on, e.g. for local development.
    */
    'webhooks' => [
        'allow_private' => (bool) env('ALERTS_WEBHOOKS_ALLOW_PRIVATE', false),
    ],

];
