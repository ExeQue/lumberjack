<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\WeeklySummarySettings;
use Carbon\CarbonImmutable;

covers(WeeklySummarySettings::class);

// 2026-11-09 is a Monday.
it('is due from its day and time on, for a day, unless it sent in the last six days', function (array $settings, string $now, ?string $lastSentAt, bool $due) {
    $rule = WeeklySummarySettings::fromArray($settings);

    expect($rule->isDue($lastSentAt !== null ? CarbonImmutable::parse($lastSentAt) : null, CarbonImmutable::parse($now)))->toBe($due);
})->with([
    'the default, Monday at 08:00' => [[], '2026-11-09 08:00', null, true],
    'a minute before its time' => [[], '2026-11-09 07:59', null, false],
    'the day before' => [[], '2026-11-08 08:00', null, false],
    'later that day, catching up' => [[], '2026-11-09 23:59', null, true],
    'a day late' => [[], '2026-11-10 08:00', null, false],
    'its own day and time' => [['day' => 5, 'time' => '17:30'], '2026-11-13 17:30', null, true],
    'its own day at another time' => [['day' => 5, 'time' => '17:30'], '2026-11-13 08:00', null, false],
    'another day at its own time' => [['day' => 5, 'time' => '17:30'], '2026-11-09 17:30', null, false],
    'Sunday, the last day of the week' => [['day' => 7, 'time' => '00:00'], '2026-11-15 00:00', null, true],
    'already sent this week' => [[], '2026-11-09 08:05', '2026-11-09 08:00', false],
    'sent last week' => [[], '2026-11-09 08:00', '2026-11-02 08:00', true],
    'moved to later the same week after sending' => [['day' => 2, 'time' => '08:00'], '2026-11-10 08:00', '2026-11-09 08:00', false],
    'an unknown day and time, the default' => [['day' => 9, 'time' => '25:00'], '2026-11-09 08:00', null, true],
]);
