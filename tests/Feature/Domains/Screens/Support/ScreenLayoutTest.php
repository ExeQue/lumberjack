<?php

declare(strict_types=1);

use App\Domains\Screens\Enums\ScreenTemplate;
use App\Domains\Screens\Support\ScreenLayout;

covers(ScreenLayout::class, ScreenTemplate::class);

$box = fn (int $w, int $h, ?int $x = null, ?int $y = null): array => ['id' => uniqid(), 'w' => $w, 'h' => $h, ...($x === null ? [] : ['x' => $x, 'y' => $y])];
$cells = fn (array $widgets): array => array_map(fn (array $w): array => [$w['x'], $w['y']], $widgets);

describe('widgets stored before positions', function () use ($box, $cells) {
    it('get the cells the row by row flow drew them in', function () use ($box, $cells) {
        // On 4 columns: the third goes right of the 2x2, the fourth below the first.
        $widgets = ScreenLayout::positioned([$box(1, 1), $box(2, 2), $box(1, 1), $box(1, 1), $box(4, 1), $box(2, 1)], 4, 4);

        expect($cells($widgets))->toBe([[0, 0], [1, 0], [3, 0], [0, 1], [0, 2], [0, 3]]);
    });

    it('do not fill gaps behind the last one placed, like the CSS grid', function () use ($box, $cells) {
        expect($cells(ScreenLayout::positioned([$box(3, 1), $box(2, 1), $box(1, 1)], 4, 4)))->toBe([[0, 0], [0, 1], [2, 1]]);
    });

    it('land below the grid when they did not fit, and are clamped to its width', function () use ($box, $cells) {
        $widgets = ScreenLayout::positioned([$box(2, 2), $box(2, 2), $box(6, 1)], 2, 2);

        expect($cells($widgets))->toBe([[0, 0], [0, 2], [0, 4]]);
    });

    it('flow around the ones that have a position', function () use ($box, $cells) {
        expect($cells(ScreenLayout::positioned([$box(1, 1, 0, 0), $box(2, 1), $box(1, 1, 3, 0)], 4, 4)))->toBe([[0, 0], [1, 0], [3, 0]]);
    });
});

describe('overlaps', function () use ($box) {
    it('names every widget on a cell an earlier one covers', function () use ($box) {
        expect(ScreenLayout::overlapping([$box(2, 2, 0, 0), $box(1, 1, 1, 1), $box(1, 1, 2, 0), $box(2, 1, 1, 0)]))->toBe([1, 3]);
    });

    it('lets widgets touch', function () use ($box) {
        expect(ScreenLayout::overlapping([$box(2, 2, 0, 0), $box(2, 2, 2, 0), $box(4, 1, 0, 2)]))->toBe([]);
    });
});

describe('templates', function () {
    it('place every widget inside the grid on its own cells, where the old flow drew it', function (ScreenTemplate $template) {
        [$cols, $rows] = $template->grid();
        $widgets = $template->widgets();
        $flowed = ScreenLayout::positioned(array_map(fn (array $w): array => array_diff_key($w, ['x' => 0, 'y' => 0]), $widgets), $cols, $rows);

        expect(ScreenLayout::overlapping($widgets))->toBe([])
            ->and($flowed)->toEqual($widgets);

        foreach ($widgets as $widget) {
            expect($widget['x'] + $widget['w'])->toBeLessThanOrEqual($cols)
                ->and($widget['y'] + $widget['h'])->toBeLessThanOrEqual($rows);
        }
    })->with(ScreenTemplate::cases());
});
