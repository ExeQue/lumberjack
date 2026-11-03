<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Enums\DisplayTheme;
use App\Domains\Screens\Events\DisplayChanged;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;

class UpdateDisplayAction
{
    /**
     * Its own name, what it shows (one of its team's screens, several in turn, or none), how
     * long each shows, and its theme; the TV switches at once. Screens of other teams are
     * left out.
     *
     * @param  array{name?: string|null, screen_id?: int|null, playlist?: list<int>, rotate_seconds?: int, hold_on_alert?: bool, theme?: DisplayTheme}  $settings
     */
    public function execute(Display $display, array $settings): Display
    {
        $display->fill(array_intersect_key($settings, array_flip(['name', 'theme', 'rotate_seconds', 'hold_on_alert'])));

        if (array_key_exists('playlist', $settings)) {
            $own = Screen::query()->whereTeam($display->team)->whereIn('id', $settings['playlist'])->pluck('id')->all();
            $playlist = array_values(array_filter($settings['playlist'], fn (int $id): bool => in_array($id, $own, true)));
            // One screen is no playlist.
            $display->playlist = count($playlist) > 1 ? $playlist : null;
            $display->screen_id = $playlist[0] ?? null;
        } elseif (array_key_exists('screen_id', $settings)) {
            $own = $settings['screen_id'] === null || Screen::query()->whereTeam($display->team)->whereKey($settings['screen_id'])->exists();

            if ($own) {
                $display->screen_id = $settings['screen_id'];
                $display->playlist = null;
            }
        }

        if ($display->isDirty()) {
            $display->save();
            $display->unsetRelation('screen');
            DisplayChanged::dispatch($display);
        }

        return $display;
    }
}
