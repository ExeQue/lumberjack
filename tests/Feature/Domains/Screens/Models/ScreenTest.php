<?php

declare(strict_types=1);

use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Screen;
use Carbon\CarbonImmutable;

covers(Screen::class, ScreenTheme::class);

describe('the theme', function () {
    it('is what was picked unless it follows the time of day', function (ScreenTheme $theme, ScreenTheme $now) {
        expect(Screen::factory()->make(['theme' => $theme])->currentTheme(CarbonImmutable::parse('2026-11-03 23:00', 'UTC')))->toBe($now);
    })->with([
        'dark' => [ScreenTheme::Dark, ScreenTheme::Dark],
        'light' => [ScreenTheme::Light, ScreenTheme::Light],
    ]);

    it('is light between the two times in the screen\'s time zone', function (string $utc, ScreenTheme $expected) {
        $screen = Screen::factory()->scheduled()->make();

        expect($screen->currentTheme(CarbonImmutable::parse($utc, 'UTC')))->toBe($expected);
    })->with([
        // Copenhagen is UTC+1 in November.
        'just before the light starts' => ['2026-11-03 05:59', ScreenTheme::Dark],
        'when the light starts' => ['2026-11-03 06:00', ScreenTheme::Light],
        'midday' => ['2026-11-03 11:00', ScreenTheme::Light],
        'when the light ends' => ['2026-11-03 18:00', ScreenTheme::Dark],
        'at night' => ['2026-11-03 23:30', ScreenTheme::Dark],
    ]);

    it('wraps past midnight when the light ends before it starts', function (string $local, ScreenTheme $expected) {
        $screen = Screen::factory()->make(['theme' => ScreenTheme::Schedule, 'light_from' => '22:00', 'light_to' => '02:00', 'timezone' => 'UTC']);

        expect($screen->currentTheme(CarbonImmutable::parse($local, 'UTC')))->toBe($expected);
    })->with([
        'late evening' => ['2026-11-03 23:00', ScreenTheme::Light],
        'after midnight' => ['2026-11-04 01:59', ScreenTheme::Light],
        'morning' => ['2026-11-04 08:00', ScreenTheme::Dark],
    ]);

    it('has a label for every case', function (ScreenTheme $theme) {
        expect($theme->label())->not->toStartWith('ui/');
    })->with(ScreenTheme::cases());
});

describe('a new screen', function () {
    it('starts empty, 4 by 4, over every project for a day', function () {
        $screen = Screen::query()->create(['team_id' => App\Domains\Teams\Models\Team::factory()->create()->id, 'name' => 'Ops'])->refresh();

        expect($screen->widgets)->toBe([])
            ->and($screen->scope)->toBe(['projects' => [], 'environment' => null, 'period' => '24h'])
            ->and([$screen->cols, $screen->rows])->toBe([4, 4])
            ->and($screen->getRouteKey())->toBe($screen->uuid);
    });
});
