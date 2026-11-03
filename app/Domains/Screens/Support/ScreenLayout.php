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
     * Indexes of widgets that cover a cell an earlier widget already covers.
     *
     * @param  list<array<string, mixed>>  $widgets  positioned
     * @return list<int>
     */
    public static function overlapping(array $widgets): array
    {
        $taken = [];
        $overlapping = [];

        foreach ($widgets as $i => $widget) {
            $x = (int) $widget['x'];
            $y = (int) $widget['y'];
            $w = max(1, (int) $widget['w']);
            $h = max(1, (int) $widget['h']);

            if (! self::free($taken, $x, $y, $w, $h)) {
                $overlapping[] = $i;

                continue;
            }

            self::take($taken, $x, $y, $w, $h);
        }

        return $overlapping;
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
