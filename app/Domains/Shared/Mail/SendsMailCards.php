<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Turns a MailCard into the mail a notification sends: the HTML card, its text twin,
 * and the sender: alerts for what a rule sends, notifications for personal mail.
 */
trait SendsMailCards
{
    protected function cardMail(MailCard $card, bool $personal): MailMessage
    {
        /** @var array{address: string, name: string} $from */
        $from = config($personal ? 'lumberjack.mail.notifications_from' : 'lumberjack.mail.alerts_from');

        return (new MailMessage())
            ->from($from['address'], $from['name'])
            ->subject($card->subject)
            ->view(['html' => 'mail.card', 'text' => 'mail.card-text'], ['card' => $card]);
    }
}
