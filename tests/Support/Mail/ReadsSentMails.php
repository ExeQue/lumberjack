<?php

declare(strict_types=1);

namespace Tests\Support\Mail;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Email;

/**
 * What actually went out, from the array mailer the tests run on: one Email per message,
 * with its recipients, sender, subject, headers and both parts.
 */
trait ReadsSentMails
{
    /** @return Collection<int, Email> */
    public function sentMails(): Collection
    {
        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();

        return $transport->messages()->map(fn ($message) => $message->getOriginalMessage())->values();
    }

    /** @return Collection<int, Email> */
    public function mailsTo(string $address): Collection
    {
        return $this->sentMails()->filter(fn (Email $mail): bool => collect($mail->getTo())->contains(fn ($to) => strcasecmp($to->getAddress(), $address) === 0))->values();
    }
}
