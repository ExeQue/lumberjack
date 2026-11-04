<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Broadcasting\ScreenChannel;
use App\Domains\Screens\Broadcasting\ScreenShareChannel;
use App\Domains\Screens\Models\Screen;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

class CountScreenViewersAction
{
    /**
     * How many kiosks, paired TVs and share links have the screen open right now: the
     * subscriptions to its channels, asked from Reverb. The designer does not subscribe,
     * and each browser tab counts, also two of one user. Null when that cannot be known: broadcasting is
     * off, or Reverb is not answering, which is not worth an error for a counter.
     */
    public function execute(Screen $screen): ?int
    {
        $broadcaster = Broadcast::connection();

        if (! $broadcaster instanceof PusherBroadcaster) {
            return null;
        }

        // Kiosks and paired TVs on the screen's own channel, share links on its public one.
        $channels = ['private-'.ScreenChannel::name($screen)];

        if ($screen->share_key_hash !== null) {
            $channels[] = ScreenShareChannel::name($screen->share_key_hash);
        }

        $count = 0;

        try {
            foreach ($channels as $channel) {
                $info = $broadcaster->getPusher()->getChannelInfo($channel, ['info' => 'subscription_count']);
                $count += (int) ($info->subscription_count ?? 0);
            }
        } catch (Throwable) {
            return null;
        }

        return $count;
    }
}
