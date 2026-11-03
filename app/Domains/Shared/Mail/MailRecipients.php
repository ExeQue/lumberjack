<?php

declare(strict_types=1);

namespace App\Domains\Shared\Mail;

use Closure;
use Throwable;

/**
 * One mail per address for one event, however many paths lead to it.
 *
 * An event is one thing that happened, handled in one place: one alert dispatch (a new
 * issue, a regression, an escalation, a health check or queue backlog), one note, one
 * status change, one bulk action, one assignment action. That place makes one of these,
 * every mail path of the event offers its mail to it instead of sending, and send() then
 * sends each address the most specific mail offered to it: personal (a mention, an
 * assignment) over watching over a rule's mail channel. That mail's footer lists every
 * reason that led there. Addresses compare trimmed and case-insensitively.
 *
 * Separate events stay separate mails, even when one click causes several (resolving an
 * issue also takes it off its assignee). Chat channels never come through here.
 */
final class MailRecipients
{
    public const int CHANNEL = 1;

    public const int WATCHER = 2;

    public const int PERSONAL = 3;

    /** @var array<string, list<array{priority: int, reason: MailReason, send: Closure(list<MailReason>): void}>> */
    private array $offers = [];

    /**
     * @param  Closure(list<MailReason>): void  $send  sends the mail, given every reason it stands in for
     */
    public function offer(string $address, int $priority, MailReason $reason, Closure $send): void
    {
        $key = self::normalize($address);

        if ($key !== '') {
            $this->offers[$key][] = ['priority' => $priority, 'reason' => $reason, 'send' => $send];
        }
    }

    /**
     * Whether a mail at this priority or above is already offered to the address.
     */
    public function has(string $address, int $priority): bool
    {
        foreach ($this->offers[self::normalize($address)] ?? [] as $offer) {
            if ($offer['priority'] >= $priority) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends the winning mail of every address, once. A failing one does not stop the rest.
     */
    public function send(): void
    {
        $offers = $this->offers;
        $this->offers = [];

        foreach ($offers as $list) {
            // Stable, so the first offer of the highest priority wins.
            usort($list, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
            $reasons = [];

            foreach ($list as $offer) {
                if (! in_array($offer['reason'], $reasons)) {
                    $reasons[] = $offer['reason'];
                }
            }

            try {
                ($list[0]['send'])($reasons);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public static function normalize(string $address): string
    {
        return mb_strtolower(trim($address));
    }
}
