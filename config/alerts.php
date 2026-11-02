<?php

declare(strict_types=1);

use App\Domains\Alerts\Drivers\DiscordDriver;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Drivers\NtfyDriver;
use App\Domains\Alerts\Drivers\PushoverDriver;
use App\Domains\Alerts\Drivers\SlackDriver;
use App\Domains\Alerts\Drivers\TeamsDriver;
use App\Domains\Alerts\Drivers\TelegramDriver;
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
        'slack' => SlackDriver::class,
        'teams' => TeamsDriver::class,
        'telegram' => TelegramDriver::class,
        'ntfy' => NtfyDriver::class,
        'pushover' => PushoverDriver::class,
    ],

    /*
    | Webhooks may not point at private or reserved addresses (this server, the
    | network behind it) unless this is on, e.g. for local development.
    */
    'webhooks' => [
        'allow_private' => (bool) env('ALERTS_WEBHOOKS_ALLOW_PRIVATE', false),
    ],

];
