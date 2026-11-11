<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a weekly summary rule sends: the ISO day of the week (1 is Monday) and the time,
 * in the app's time zone.
 */
final readonly class WeeklySummarySettings
{
    public const int DEFAULT_DAY = CarbonInterface::MONDAY;

    public const string DEFAULT_TIME = '08:00';

    public function __construct(
        public int $day = self::DEFAULT_DAY,
        public string $time = self::DEFAULT_TIME,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $settings  as stored on the rule
     */
    public static function fromArray(?array $settings): self
    {
        $day = is_numeric($settings['day'] ?? null) ? (int) $settings['day'] : self::DEFAULT_DAY;
        $time = is_string($settings['time'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $settings['time']) === 1 ? $settings['time'] : self::DEFAULT_TIME;

        return new self($day >= 1 && $day <= 7 ? $day : self::DEFAULT_DAY, $time);
    }

    /**
     * @return array{day: int, time: string}
     */
    public function toArray(): array
    {
        return ['day' => $this->day, 'time' => $this->time];
    }

    /**
     * The latest moment it was due at, at or before $now.
     */
    public function lastDue(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $this->time));
        $due = $now->startOfWeek(CarbonInterface::MONDAY)->addDays($this->day - 1)->setTime($hour, $minute);

        return $due->greaterThan($now) ? $due->subWeek() : $due;
    }

    /**
     * Due from its day and time on, for a day, so a scheduler that was down catches up
     * late rather than never. At most once in six days, so moving the day or time after
     * a send does not send the week twice.
     */
    public function isDue(?CarbonImmutable $lastSentAt, CarbonImmutable $now): bool
    {
        $due = $this->lastDue($now);

        return $now->lessThan($due->addDay()) && ($lastSentAt === null || $lastSentAt->lessThan($due->subDays(6)));
    }
}
