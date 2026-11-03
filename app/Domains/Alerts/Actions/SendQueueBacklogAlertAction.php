<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Data\QueueBacklogAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use Throwable;

class SendQueueBacklogAlertAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
    ) {
    }

    /**
     * @return int channels it reached
     */
    public function execute(AlertRule $rule, QueueBacklogAlert $alert): int
    {
        $sent = 0;

        foreach ($rule->channels as $channel) {
            $error = null;

            try {
                $this->drivers->for($channel->type)->send($channel, $alert);
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $error = $e->getMessage();
            }

            $channel->forceFill(['last_delivery_status' => $error === null ? 'sent' : 'failed', 'last_delivery_at' => now()])->save();
        }

        return $sent;
    }
}
