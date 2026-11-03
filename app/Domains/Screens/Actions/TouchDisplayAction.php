<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Display;

class TouchDisplayAction
{
    /** At most one write per display this often. */
    public const int EVERY_SECONDS = 60;

    /**
     * The display was just heard from: last seen, the browser and address it uses, and
     * the size of its window when it says (written at once when that changed).
     *
     * @param  array{w: int, h: int, dpr: float}|null  $viewport
     */
    public function execute(Display $display, ?string $userAgent, ?string $ip, ?array $viewport = null): void
    {
        $resized = $viewport !== null
            && [$display->viewport_width, $display->viewport_height, $display->pixel_ratio] !== [$viewport['w'], $viewport['h'], $viewport['dpr']];

        if (! $resized && $display->last_seen_at !== null && $display->last_seen_at->gt(now()->subSeconds(self::EVERY_SECONDS))) {
            return;
        }

        $display->forceFill([
            'last_seen_at' => now(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : $display->user_agent,
            'ip' => $ip ?? $display->ip,
            ...$viewport !== null ? ['viewport_width' => $viewport['w'], 'viewport_height' => $viewport['h'], 'pixel_ratio' => $viewport['dpr']] : [],
        ])->saveQuietly();
    }
}
