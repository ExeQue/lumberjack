<?php

declare(strict_types=1);

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID keys
    |--------------------------------------------------------------------------
    |
    | The key pair that signs browser push notifications, made once with
    | `php artisan webpush:vapid`. Without both keys nobody is offered browser
    | notifications. A new pair stops every browser subscribed with the old
    | one. Left unset, Lumberjack makes a pair on first use and keeps it in the
    | database encrypted with APP_KEY (VapidKeys). The subject tells push
    | services who runs this server: a mailto: address or an https:// URL;
    | left empty it is APP_URL.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT') ?: env('APP_URL'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription model
    |--------------------------------------------------------------------------
    |
    | The model a browser's push subscription is stored as.
    |
    */

    'model' => PushSubscription::class,

    /*
    |--------------------------------------------------------------------------
    | Table and connection
    |--------------------------------------------------------------------------
    |
    | Where the subscriptions live: the app's own database.
    |
    */

    'table_name' => 'push_subscriptions',

    'database_connection' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client options
    |--------------------------------------------------------------------------
    |
    | Guzzle options for the requests to the browsers' push services.
    |
    */

    'client_options' => [],

    /*
    |--------------------------------------------------------------------------
    | Automatic padding
    |--------------------------------------------------------------------------
    |
    | Pads every payload to the same size, so its length says nothing about
    | its content.
    |
    */

    'automatic_padding' => true,

];
