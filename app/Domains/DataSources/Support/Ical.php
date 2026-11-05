<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Support;

use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Settings;
use Throwable;

/**
 * What a calendar widget reads from an iCalendar source (a Google, Outlook or iCloud
 * address in iCal format, any .ics): the events of a window, recurrences expanded with
 * their RRULE, RDATE, EXDATE and RECURRENCE-ID overrides. Dates without a time (all-day
 * events) and floating times are read in the window's time zone.
 */
final class Ical
{
    /**
     * Instances one recurring event may step through from its first, to the window: a
     * daily event since 2010 is past the library's 3500.
     */
    public const int MAX_RECURRENCES = 50_000;

    /** The most events a window keeps, starting first. */
    public const int MAX_EVENTS = 500;

    /**
     * Whether the text is an iCalendar feed: a VCALENDAR that parses.
     */
    public static function parses(string $text): bool
    {
        // Only what the reader says about the text: anything else is a fault to hear about.
        try {
            return Reader::read($text, Reader::OPTION_FORGIVING) instanceof VCalendar;
        } catch (ParseException) {
            return false;
        }
    }

    /**
     * The events overlapping the window, earliest start first; cancelled ones are left
     * out, and so is an event that does not parse, alone. Times are Unix timestamps.
     *
     * @return list<array{title: string, location: string|null, start: int, end: int, all_day: bool}>
     */
    public static function events(string $text, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendar = Reader::read($text, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            return [];
        }

        // ponytail: a process-wide setting; nothing else in Lumberjack reads recurrences.
        Settings::$maxRecurrences = self::MAX_RECURRENCES;
        $timeZone = $from->getTimezone();
        $groups = [];

        // An event's instances and the overrides of some of them share its UID.
        foreach ($calendar->select('VEVENT') as $i => $event) {
            $uid = (string) $event->UID;
            $groups[$uid !== '' ? $uid : "#{$i}"][] = $event;
        }

        $events = [];

        foreach ($groups as $group) {
            try {
                $instances = new EventIterator($group, null, $timeZone);
                $instances->fastForward($from);

                for (; $instances->valid() && $instances->getDtStart() < $to; $instances->next()) {
                    $event = $instances->getEventObject();

                    if ($instances->getDtEnd() <= $from || strtoupper(self::text($event->select('STATUS'))) === 'CANCELLED') {
                        continue;
                    }

                    $location = self::text($event->select('LOCATION'));
                    $start = (int) $instances->getDtStart()?->getTimestamp();
                    $events[] = [
                        'title' => self::text($event->select('SUMMARY')),
                        'location' => $location !== '' ? $location : null,
                        'start' => $start,
                        'end' => max($start, $instances->getDtEnd()->getTimestamp()),
                        'all_day' => isset($event->DTSTART) && ! $event->DTSTART->hasTime(),
                    ];
                }
            } catch (Throwable) {
                // One broken event (no start, a rule without instances) leaves the rest.
                continue;
            }
        }

        usort($events, fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        return array_slice($events, 0, self::MAX_EVENTS);
    }

    /**
     * The text of a property, the first where it repeats.
     *
     * @param  array<int, mixed>  $properties
     */
    private static function text(array $properties): string
    {
        $property = $properties[0] ?? null;

        return $property instanceof Property ? trim($property->__toString()) : '';
    }
}
