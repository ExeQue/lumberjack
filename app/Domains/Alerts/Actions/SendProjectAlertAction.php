<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Actions;

use App\Domains\Alerts\Contracts\Alert;
use App\Domains\Alerts\Drivers\MailDriver;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Support\ChannelDriverRegistry;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Mail\MailReason;
use App\Domains\Shared\Mail\MailRecipients;
use Closure;
use Throwable;

/**
 * An alert about the project rather than one issue (a queue backlog, a health check),
 * to every channel of its rule.
 */
class SendProjectAlertAction
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
    ) {
    }

    /**
     * @param  MailRecipients|null  $recipients  the event's mail recipients, when it spans several rules; none sends right away
     * @return int channels it reached, mail channels counted once sent
     */
    public function execute(AlertRule $rule, Project $project, Alert $alert, ?MailRecipients $recipients = null): int
    {
        $sent = 0;
        $own = $recipients === null;
        $recipients ??= new MailRecipients();

        foreach ($rule->channelsFor($project) as $channel) {
            $driver = $this->drivers->for($channel->type);

            if ($driver instanceof MailDriver) {
                $recipients->offer(MailDriver::address($channel), MailRecipients::CHANNEL, MailReason::rule($rule, $channel, $project), function (array $reasons) use ($driver, $channel, $alert, &$sent): void {
                    $sent += $this->record($channel, fn () => $driver->sendWithReasons($channel, $alert, $reasons));
                });

                continue;
            }

            $sent += $this->record($channel, fn () => $driver->send($channel, $alert));
        }

        if ($own) {
            $recipients->send();
        }

        return $sent;
    }

    /**
     * One channel, outside any rule: a message built for that channel alone, such as a
     * weekly summary of the projects routed to it.
     *
     * @param  list<MailReason>  $reasons  why a mail channel gets it, for the footer
     * @return int 1 when it reached the channel
     */
    public function sendTo(NotificationChannel $channel, Alert $alert, array $reasons = []): int
    {
        $driver = $this->drivers->for($channel->type);

        return $this->record($channel, fn () => $driver instanceof MailDriver && $reasons !== []
            ? $driver->sendWithReasons($channel, $alert, $reasons)
            : $driver->send($channel, $alert));
    }

    private function record(NotificationChannel $channel, Closure $send): int
    {
        $error = null;

        try {
            $send();
        } catch (Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        $channel->forceFill(['last_delivery_status' => $error === null ? 'sent' : 'failed', 'last_delivery_at' => now()])->save();

        return $error === null ? 1 : 0;
    }
}
