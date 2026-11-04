<?php

declare(strict_types=1);

namespace App\Domains\Administration\Data;

use JsonSerializable;
use Override;

/**
 * How someone hears about what concerns them: about them personally (issues they watch,
 * mentions, assignments) and about the projects they can view (new issues, health
 * checks, queue backlogs, scheduled tasks, agents, releases), each by mail, by a toast
 * in an open Lumberjack and by a browser push notification. DEFAULTS holds what counts
 * until someone saves their own choice. Alerts a rule sends to a team channel are not
 * affected.
 */
final readonly class NotificationPreferences implements JsonSerializable
{
    /** About you. */
    public const array PERSONAL = ['watching', 'mentions', 'assignments'];

    /** Your projects. */
    public const array PROJECT = ['new_issues', 'health', 'queue_backlogs', 'scheduled_tasks', 'agents', 'releases'];

    public const array KINDS = [...self::PERSONAL, ...self::PROJECT];

    /**
     * Every kind with its three channels, and whether each is on until changed.
     *
     * @var array<string, array<string, bool>>
     */
    public const array DEFAULTS = [
        // The grouped digest mail stays; a push for every update would be a lot.
        'watching' => ['mail' => true, 'toast' => true, 'push' => false],
        'mentions' => ['mail' => true, 'toast' => true, 'push' => true],
        'assignments' => ['mail' => true, 'toast' => true, 'push' => true],
        // A project can be busy: in app only, until someone asks for more.
        'new_issues' => ['mail' => false, 'toast' => true, 'push' => false],
        'health' => ['mail' => false, 'toast' => true, 'push' => false],
        'queue_backlogs' => ['mail' => false, 'toast' => true, 'push' => false],
        'scheduled_tasks' => ['mail' => false, 'toast' => true, 'push' => false],
        'agents' => ['mail' => false, 'toast' => true, 'push' => false],
        'releases' => ['mail' => false, 'toast' => true, 'push' => false],
    ];

    /**
     * @param  array<string, array<string, bool>>  $channels  as DEFAULTS
     */
    private function __construct(
        private array $channels,
    ) {
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULTS);
    }

    /**
     * Tolerant of what older versions stored: a kind or channel missing takes its default,
     * one no longer known is dropped.
     *
     * @param  array<string, mixed>|null  $stored  {"watching": {"mail": true, "toast": false}, ...}
     */
    public static function fromArray(?array $stored): self
    {
        $channels = [];

        foreach (self::DEFAULTS as $kind => $vias) {
            foreach ($vias as $via => $default) {
                $value = is_array($stored[$kind] ?? null) ? ($stored[$kind][$via] ?? $default) : $default;
                $channels[$kind][$via] = (bool) $value;
            }
        }

        return new self($channels);
    }

    /** @return array<string, array<string, bool>> */
    public function toArray(): array
    {
        return $this->channels;
    }

    /** @return array<string, array<string, bool>> */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->channels;
    }

    public function mail(string $kind): bool
    {
        return $this->channels[$kind]['mail'] ?? false;
    }

    public function toast(string $kind): bool
    {
        return $this->channels[$kind]['toast'] ?? false;
    }

    public function push(string $kind): bool
    {
        return $this->channels[$kind]['push'] ?? false;
    }
}
