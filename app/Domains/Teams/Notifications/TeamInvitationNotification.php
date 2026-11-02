<?php

declare(strict_types=1);

namespace App\Domains\Teams\Notifications;

use App\Domains\Teams\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $token,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(__('teams.invitation.subject', ['team' => $this->invitation->team->name]))
            ->line(__('teams.invitation.body', ['team' => $this->invitation->team->name, 'role' => $this->invitation->role->label()]))
            ->action(__('teams.invitation.action'), route('invitations.show', $this->token))
            ->line(__('teams.invitation.expires', ['days' => 7]));
    }
}
