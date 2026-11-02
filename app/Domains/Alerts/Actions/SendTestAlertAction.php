<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use Throwable;

class SendTestAlertAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
    ) {
    }

    public function execute(NotificationChannel $channel): bool
    {
        try {
            $this->drivers->for($channel->type)->sendTest($channel);
            $ok = true;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }

        $channel->forceFill(['last_delivery_status' => $ok ? 'sent' : 'failed', 'last_delivery_at' => now()])->save();

        return $ok;
    }
}
