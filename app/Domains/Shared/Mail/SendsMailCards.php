<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

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

        $mail = (new MailMessage())
            ->from($from['address'], $from['name'])
            ->subject($card->subject)
            ->view(['html' => 'mail.card', 'text' => 'mail.card-text'], ['card' => $card]);

        // A mail about a watched issue can be stopped in one click from the mail client.
        $first = $card->reasons[0] ?? null;

        if ($first?->kind === MailReason::WATCHING && $first->changeUrl !== null) {
            $url = $first->changeUrl;
            $mail->withSymfonyMessage(function (Email $message) use ($url): void {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
