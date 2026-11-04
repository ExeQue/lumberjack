<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Providers;

use App\Domains\Notifications\Support\VapidKeys;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\WebPushChannel;
use Override;

class NotificationsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->scoped(VapidKeys::class);
    }

    public function boot(): void
    {
        // The channel builds its WebPush from config: the keys have to be there first,
        // also in a queue worker that never asked for them before.
        $this->app->beforeResolving(WebPushChannel::class, fn () => $this->app->make(VapidKeys::class)->ensure());
    }
}
