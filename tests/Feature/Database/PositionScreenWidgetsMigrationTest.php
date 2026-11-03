<?php

declare(strict_types=1);

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Support\ScreenLayout;
use Illuminate\Support\Facades\DB;

// The migration is an anonymous class; this is what it positions widgets with.
covers(ScreenLayout::class);

describe('positioning stored widgets', function () {
    it('gives the widgets of stored screens the cells they were drawn in', function () {
        $legacy = fn (string $id, int $w, int $h): array => ['id' => $id, 'type' => 'stat', 'source' => 'requests', 'rotate' => [], 'w' => $w, 'h' => $h, 'title' => null, 'options' => [], 'scope' => []];
        $screen = Screen::factory()->create(['cols' => 3, 'rows' => 2]);
        DB::table('screens')->where('id', $screen->id)->update(['widgets' => json_encode([$legacy('a', 2, 1), $legacy('b', 2, 1), $legacy('c', 1, 1), $legacy('d', 3, 1)])]);
        $empty = Screen::factory()->create(['widgets' => []]);

        (require database_path('migrations/2026_11_07_100000_position_screen_widgets.php'))->up();

        $widgets = $screen->refresh()->widgets;
        expect(array_map(fn (array $w): array => [$w['id'], $w['x'], $w['y']], $widgets))->toBe([['a', 0, 0], ['b', 0, 1], ['c', 2, 1], ['d', 0, 2]])
            ->and($widgets[0])->toMatchArray($legacy('a', 2, 1))
            ->and($empty->refresh()->widgets)->toBe([]);
    });
});
