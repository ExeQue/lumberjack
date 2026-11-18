<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Screens\Models\Screen;

/**
 * Where widgets sit on a screen's grid: each has x and y (0-based column and row) besides
 * its w and h, and cells no widget covers stay empty. A widget outside the grid (after the
 * grid got smaller) is kept but does not show.
 *
 * Widgets stored before positions existed flowed row by row in order, the way a CSS grid
 * places them; positioned() gives those the cells they were drawn in, so nothing moves.
 */
final class ScreenLayout
{
    /**
     * The farthest row a widget can be on: below a full grid, every widget full height
     * one under the other (where the designer keeps widgets that no longer fit).
     */
    public const int MAX_Y = (WidgetSchema::MAX_WIDGETS + 1) * Screen::MAX_ROWS;

    /**
     * The widgets with x and y: kept where given, the others flowed around them row by row
     * in order, sizes clamped to the grid as the CSS grid did.
     *
     * @param  list<array<string, mixed>>  $widgets
     * @return list<array<string, mixed>>
     */
    public static function positioned(array $widgets, int $cols, int $rows): array
    {
        $taken = [];

        foreach ($widgets as $widget) {
            if (self::hasPosition($widget)) {
                self::take($taken, (int) $widget['x'], (int) $widget['y'], self::w($widget, $cols), self::h($widget, $rows));
            }
        }

        $row = 0;
        $col = 0;

        foreach ($widgets as $i => $widget) {
            if (self::hasPosition($widget)) {
                continue;
            }

            $w = self::w($widget, $cols);
            $h = self::h($widget, $rows);

            // ponytail: cell by cell scan, at most 48 widgets on 12 x 288 cells
            while ($col + $w > $cols || ! self::free($taken, $col, $row, $w, $h)) {
                $col++;

                if ($col + $w > $cols) {
                    $col = 0;
                    $row++;
                }
            }

            self::take($taken, $col, $row, $w, $h);
            $widgets[$i] = [...$widget, 'x' => $col, 'y' => $row];
            $col += $w;
        }

        return $widgets;
    }

    /**
     * Indexes of widgets that cover a cell an earlier widget already covers, besides a
     * widget lying wholly inside a group.
     *
     * @param  list<array<string, mixed>>  $widgets  positioned
     * @return list<int>
     */
    public static function overlapping(array $widgets): array
    {
        $kept = [];
        $overlapping = [];

        foreach ($widgets as $i => $widget) {
            if (collect($kept)->contains(fn (array $other): bool => self::clash($widget, $other))) {
                $overlapping[] = $i;

                continue;
            }

            $kept[] = $widget;
        }

        return $overlapping;
    }

    /**
     * Whether a group holds a widget, a group too: one wholly inside it, on fewer cells (a
     * 1 x 1 group holds nothing). The group draws one card behind the widgets it holds.
     *
     * @param  array<string, mixed>  $group  positioned
     * @param  array<string, mixed>  $widget  positioned
     */
    public static function holds(array $group, array $widget): bool
    {
        [$g, $w] = [self::box($group), self::box($widget)];

        return ($group['type'] ?? null) === 'group' && $w !== $g
            && $w['x'] >= $g['x'] && $w['y'] >= $g['y']
            && $w['x'] + $w['w'] <= $g['x'] + $g['w'] && $w['y'] + $w['h'] <= $g['y'] + $g['h'];
    }

    /**
     * Whether two widgets cover a common cell, unless one is a group holding the other.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function clash(array $a, array $b): bool
    {
        [$p, $q] = [self::box($a), self::box($b)];
        $apart = $p['x'] >= $q['x'] + $q['w'] || $q['x'] >= $p['x'] + $p['w']
            || $p['y'] >= $q['y'] + $q['h'] || $q['y'] >= $p['y'] + $p['h'];

        return ! $apart && ! self::holds($a, $b) && ! self::holds($b, $a);
    }

    /**
     * @param  array<string, mixed>  $widget  positioned
     * @return array{x: int, y: int, w: int, h: int}
     */
    private static function box(array $widget): array
    {
        return ['x' => (int) $widget['x'], 'y' => (int) $widget['y'], 'w' => max(1, (int) $widget['w']), 'h' => max(1, (int) $widget['h'])];
    }

    /** @param  array<string, mixed>  $widget */
    private static function hasPosition(array $widget): bool
    {
        return is_numeric($widget['x'] ?? null) && is_numeric($widget['y'] ?? null);
    }

    /** @param  array<string, mixed>  $widget */
    private static function w(array $widget, int $cols): int
    {
        return max(1, min($cols, (int) ($widget['w'] ?? 1)));
    }

    /** @param  array<string, mixed>  $widget */
    private static function h(array $widget, int $rows): int
    {
        return max(1, min($rows, (int) ($widget['h'] ?? 1)));
    }

    /** @param  array<string, true>  $taken */
    private static function free(array $taken, int $x, int $y, int $w, int $h): bool
    {
        for ($r = $y; $r < $y + $h; $r++) {
            for ($c = $x; $c < $x + $w; $c++) {
                if (isset($taken["{$r}:{$c}"])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param  array<string, true>  $taken */
    private static function take(array &$taken, int $x, int $y, int $w, int $h): void
    {
        for ($r = $y; $r < $y + $h; $r++) {
            for ($c = $x; $c < $x + $w; $c++) {
                $taken["{$r}:{$c}"] = true;
            }
        }
    }
}
