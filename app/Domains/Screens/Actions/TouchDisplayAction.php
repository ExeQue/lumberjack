<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Models\Display;

class TouchDisplayAction
{
    /** At most one write per display this often. */
    public const int EVERY_SECONDS = 60;

    /**
     * The display was just heard from: last seen, and the browser and address it uses.
     */
    public function execute(Display $display, ?string $userAgent, ?string $ip): void
    {
        if ($display->last_seen_at !== null && $display->last_seen_at->gt(now()->subSeconds(self::EVERY_SECONDS))) {
            return;
        }

        $display->forceFill([
            'last_seen_at' => now(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : $display->user_agent,
            'ip' => $ip ?? $display->ip,
        ])->saveQuietly();
    }
}
