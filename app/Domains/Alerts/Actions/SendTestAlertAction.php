<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Exceptions\AlertExceptionInterface;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use Throwable;

class SendTestAlertAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
    ) {
    }

    /**
     * Null when it went through; otherwise why not, worded for the person who set the
     * channel up (an HTTP status, an unreachable address). Other errors stay generic.
     */
    public function execute(NotificationChannel $channel): ?string
    {
        $failure = null;

        try {
            $this->drivers->for($channel->type)->sendTest($channel);
        } catch (AlertExceptionInterface $e) {
            $failure = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $failure = (string) __('alerts.test_failed');
        }

        $channel->forceFill(['last_delivery_status' => $failure === null ? 'sent' : 'failed', 'last_delivery_at' => now()])->save();

        return $failure;
    }
}
