<?php

declare(strict_types=1);

namespace App\Domains\Teams\Notifications;

use App\Domains\Shared\Mail\MailCard;
use App\Domains\Shared\Mail\SendsMailCards;
use App\Domains\Teams\Actions\InviteMemberAction;
use App\Domains\Teams\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SendsMailCards;

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
        return $this->cardMail($this->card(), true);
    }

    public function card(): MailCard
    {
        $invitation = $this->invitation;
        $team = $invitation->team;
        $inviter = $invitation->invitedBy;
        $role = (string) __('mail.invitation.role', ['role' => mb_strtolower($invitation->role->label())]);
        $days = InviteMemberAction::EXPIRES_AFTER_DAYS;
        $projects = $team->projects->sortBy('name')->pluck('slug')->values()->all();
        $short = $inviter !== null ? explode(' ', $inviter->name)[0] : null;

        return new MailCard(
            subject: $short !== null
                ? __('mail.invitation.subject', ['inviter' => $short, 'team' => $team->name])
                : __('mail.invitation.subject_anonymous', ['team' => $team->name]),
            preheader: trans_choice('mail.invitation.preheader', $days, ['role' => $role]),
            title: (string) __('mail.invitation.title', ['team' => $team->name]),
            lead: new HtmlString((string) __('mail.invitation.lead', [
                'inviter' => e($inviter->name ?? __('mail.invitation.someone')),
                'team' => e($team->name),
                'role' => '<strong class="lj-ink" style="color:#15181c;">'.e($role).'</strong>',
            ])),
            facts: $projects !== [] ? [(string) __('mail.invitation.projects') => ['value' => implode(', ', $projects), 'mono' => true]] : [],
            button: ['label' => (string) __('mail.invitation.accept'), 'url' => route('invitations.show', $this->token)],
            after: trans_choice('mail.invitation.after', $days, ['date' => $invitation->expires_at->format(__('mail.invitation.date'))]),
            footer: (string) __('mail.invitation.footer', ['inviter' => $short ?? __('mail.invitation.someone'), 'email' => $invitation->email]),
        );
    }
}
