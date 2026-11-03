<?php

declare(strict_types=1);

namespace App\Domains\Administration\Data;

/**
 * How someone hears about what concerns them personally: issues they watch, mentions and
 * assignments, each by mail and/or by a toast in an open Lumberjack. Everything is on
 * until they turn it off. Alerts a rule sends to a mail channel are not affected.
 */
final readonly class NotificationPreferences
{
    public const array KINDS = ['watching', 'mentions', 'assignments'];

    public function __construct(
        public bool $watchingMail = true,
        public bool $watchingToast = true,
        public bool $mentionsMail = true,
        public bool $mentionsToast = true,
        public bool $assignmentsMail = true,
        public bool $assignmentsToast = true,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $stored  {"watching": {"mail": true, "toast": false}, ...}
     */
    public static function fromArray(?array $stored): self
    {
        $get = fn (string $kind, string $via): bool => (bool) ($stored[$kind][$via] ?? true);

        return new self(
            $get('watching', 'mail'),
            $get('watching', 'toast'),
            $get('mentions', 'mail'),
            $get('mentions', 'toast'),
            $get('assignments', 'mail'),
            $get('assignments', 'toast'),
        );
    }

    /** @return array<string, array{mail: bool, toast: bool}> */
    public function toArray(): array
    {
        return [
            'watching' => ['mail' => $this->watchingMail, 'toast' => $this->watchingToast],
            'mentions' => ['mail' => $this->mentionsMail, 'toast' => $this->mentionsToast],
            'assignments' => ['mail' => $this->assignmentsMail, 'toast' => $this->assignmentsToast],
        ];
    }

    /**
     * @param  'watching'|'mentions'|'assignments'  $kind
     */
    public function mail(string $kind): bool
    {
        return $this->toArray()[$kind]['mail'];
    }

    /**
     * @param  'watching'|'mentions'|'assignments'  $kind
     */
    public function toast(string $kind): bool
    {
        return $this->toArray()[$kind]['toast'];
    }
}
