<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

class CountScreenViewersAction
{
    /**
     * How many kiosks have the screen open right now: the subscriptions to its channel,
     * asked from Reverb. Only kiosks subscribe (the designer does not), and each browser
     * tab counts, also two of one user. Null when that cannot be known: broadcasting is
     * off, or Reverb is not answering, which is not worth an error for a counter.
     */
    public function execute(Screen $screen): ?int
    {
        $broadcaster = Broadcast::connection();

        if (! $broadcaster instanceof PusherBroadcaster) {
            return null;
        }

        try {
            $info = $broadcaster->getPusher()->getChannelInfo('private-'.ScreenChannel::name($screen), ['info' => 'subscription_count']);
        } catch (Throwable) {
            return null;
        }

        return (int) ($info->subscription_count ?? 0);
    }
}
