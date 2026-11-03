<?php

declare(strict_types=1);

use App\Domains\Screens\Eloquent\Builders\DisplayBuilder;
use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Enums\ScreenTheme;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\TvCredentials;

covers(Display::class, DisplayBuilder::class, DisplayTheme::class);

describe('a display', function () {
    it('is online while it was heard from in the last three minutes', function (?int $secondsAgo, bool $online) {
        $display = Display::factory()->make(['last_seen_at' => $secondsAgo === null ? null : now()->subSeconds($secondsAgo)]);

        expect($display->isOnline())->toBe($online);
    })->with([
        'just now' => [10, true],
        'almost three minutes ago' => [170, true],
        'four minutes ago' => [240, false],
        'never' => [null, false],
    ]);

    it('binds by uuid and knows its screen', function () {
        $display = Display::factory()->showing($screen = Screen::factory()->create())->create();

        expect($display->getRouteKeyName())->toBe('uuid')->and($display->screen->is($screen))->toBeTrue();
    });

    it('is found by team or by its token hash', function () {
        $a = Display::factory()->withToken('ljd_a')->create();
        $b = Display::factory()->withToken('ljd_b')->create();
        Display::factory()->create();

        expect(Display::query()->whereTeam($a->team)->orWhereTeam($b->team)->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id])
            ->and(Display::query()->whereTokenHash(TvCredentials::hash('ljd_a'))->orWhereTokenHash(TvCredentials::hash('ljd_b'))->count())->toBe(2);
    });

    it('forces a screen theme, or keeps the screen\'s own', function (DisplayTheme $theme, ?ScreenTheme $screen) {
        expect($theme->screenTheme())->toBe($screen)
            ->and($theme->label())->toBe(__('ui/screens.display_theme.'.$theme->value));
    })->with([
        [DisplayTheme::Default, null],
        [DisplayTheme::Dark, ScreenTheme::Dark],
        [DisplayTheme::Light, ScreenTheme::Light],
        [DisplayTheme::Schedule, ScreenTheme::Schedule],
    ]);

    test('every theme is answered for', function () {
        expect(DisplayTheme::cases())->toHaveCount(4);
    });
});
