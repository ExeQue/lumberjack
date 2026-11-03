<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\DisplayUpdated;
use App\Domains\Screens\Models\Display;
use Illuminate\Support\Facades\RateLimiter;

class TouchDisplayAction
{
    /** At most one write per display this often, unless its window was resized. */
    public const int EVERY_SECONDS = 60;

    /** Writes a minute a resizing window gets besides that. */
    public const int RESIZES_PER_MINUTE = 20;

    /**
     * The display was just heard from: its last seen and the size of its window when it
     * says (written at once when that changed), and its device's last seen, browser and
     * address. And the window it runs in, which is what its token's cookie says: a token
     * restored into another window moves the display there.
     *
     * @param  array{w: int, h: int, dpr: float}|null  $viewport
     */
    public function execute(Display $display, int $slot, ?string $userAgent, ?string $ip, ?array $viewport = null): void
    {
        $resized = $viewport !== null
            && [$display->viewport_width, $display->viewport_height, $display->pixel_ratio] !== [$viewport['w'], $viewport['h'], $viewport['dpr']];

        if ($display->slot !== $slot) {
            $display->forceFill(['slot' => $slot])->saveQuietly();
        }

        $recent = $display->last_seen_at !== null && $display->last_seen_at->gt(now()->subSeconds(self::EVERY_SECONDS));

        // A window dragged to size reports often; past a few writes a minute it waits its turn.
        if ($recent && (! $resized || ! RateLimiter::attempt('display-resize:'.$display->id, self::RESIZES_PER_MINUTE, fn (): bool => true))) {
            return;
        }

        $display->forceFill([
            'last_seen_at' => now(),
            ...$viewport !== null ? ['viewport_width' => $viewport['w'], 'viewport_height' => $viewport['h'], 'pixel_ratio' => $viewport['dpr']] : [],
        ])->saveQuietly();
        $display->device()->update(array_filter([
            'last_seen_at' => now(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'ip' => $ip,
        ], fn (mixed $value): bool => $value !== null));
        DisplayUpdated::dispatch($display);
    }
}
