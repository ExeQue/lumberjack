<?php

declare(strict_types=1);

use App\Domains\DataSources\Support\Ical;
use Carbon\CarbonImmutable;

covers(Ical::class);

beforeEach(function () {
    $this->feed = (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/DataSources/calendar.ics');
    $this->events = fn (string $from, string $to, string $timezone = 'Europe/Copenhagen'): array => Ical::events($this->feed, CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone));
});

it('expands a weekly event in the window, skipping its excluded date and moving its overridden one', function () {
    $standups = array_values(array_filter(($this->events)('2026-11-02', '2026-11-30'), fn (array $e): bool => str_starts_with($e['title'], 'Standup')));

    expect(array_map(fn (array $e): array => [$e['title'], gmdate('Y-m-d H:i', $e['start'])], $standups))->toBe([
        ['Standup', '2026-11-02 08:30'],
        ['Standup (moved)', '2026-11-17 13:00'],
        ['Standup', '2026-11-23 08:30'],
    ])->and($standups[0]['location'])->toBe('Room 2');
});

it('keeps what overlaps the window, earliest first, and leaves cancelled events out', function () {
    expect(array_column(($this->events)('2026-11-04', '2026-11-06'), 'title'))->toBe(['Early sync', 'Lunch talk', 'Release day', 'Deploy']);
});

it('reads an all-day event from midnight in the time zone of the window', function (string $timezone, string $start) {
    $release = collect(($this->events)('2026-11-05', '2026-11-06', $timezone))->firstWhere('title', 'Release day');

    expect($release['all_day'])->toBeTrue()
        ->and(gmdate('Y-m-d H:i', $release['start']))->toBe($start)
        ->and($release['end'] - $release['start'])->toBe(86400);
})->with([
    'Copenhagen' => ['Europe/Copenhagen', '2026-11-04 23:00'],
    'Los Angeles' => ['America/Los_Angeles', '2026-11-05 08:00'],
]);

it('tells a feed from what is not one', function (string $text, bool $parses) {
    expect(Ical::parses($text))->toBe($parses);
})->with([
    'a feed' => [(string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/DataSources/calendar.ics'), true],
    'an empty calendar' => ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", true],
    'a web page' => ['<html><body>Sign in</body></html>', false],
    'JSON' => ['{"events": []}', false],
]);
