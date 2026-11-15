<?php

declare(strict_types=1);

namespace App\Domains\Administration\Notifications;

use App\Domains\Administration\Models\SystemSample;
use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\SendsMailCards;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * The disk of the server Lumberjack runs on is nearly full; to its administrators.
 */
class SystemDiskNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsMailCards;

    public function __construct(
        public readonly SystemSample $sample,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->cardMail($this->card(), true);
    }

    public function card(): MailCard
    {
        $percent = (int) floor(($this->sample->diskUsed() ?? 0) * 100);
        $free = Number::fileSize(max(0, (int) $this->sample->disk_total_bytes - (int) $this->sample->disk_used_bytes), 1);

        return new MailCard(
            subject: (string) __('mail.system_disk.subject', ['percent' => $percent]),
            preheader: (string) __('mail.system_disk.preheader', ['free' => $free]),
            title: (string) __('mail.system_disk.title'),
            tone: 'red',
            lead: (string) __('mail.system_disk.lead', ['percent' => $percent, 'free' => $free]),
            stat: ['value' => $percent.'%', 'text' => (string) __('mail.system_disk.stat')],
            button: ['label' => (string) __('mail.system_disk.button'), 'url' => route('admin.system')],
            link: ['label' => (string) __('mail.system_disk.retention'), 'url' => route('admin.retention')],
            footer: (string) __('mail.system_disk.footer'),
        );
    }
}
