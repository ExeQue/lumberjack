<?php

declare(strict_types=1);

namespace App\Domains\Screens\Support;

use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\TvPairing;
use Illuminate\Support\Collection;

/**
 * A browser without a session, as the "tv" guard sees it: the displays and pairings its
 * window cookies prove. One computer may run several windows, so it may be several.
 */
final readonly class TvViewer
{
    /**
     * @param  Collection<int, Display>  $displays
     * @param  Collection<int, TvPairing>  $pairings
     */
    public function __construct(
        public Collection $displays,
        public Collection $pairings,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->displays->isEmpty() && $this->pairings->isEmpty();
    }

    public function isDisplay(Display $display): bool
    {
        return $this->displays->contains(fn (Display $d): bool => $d->is($display));
    }

    /** One of its displays shows the screen, alone or in turn. */
    public function showsScreen(Screen $screen): bool
    {
        return $this->displays->contains(fn (Display $d): bool => $d->team_id === $screen->team_id && in_array($screen->id, $d->screenIds(), true));
    }

    public function seesTeam(int $teamId): bool
    {
        return $this->displays->contains(fn (Display $d): bool => $d->team_id === $teamId);
    }

    public function holdsPairing(string $uuid): bool
    {
        return $this->pairings->contains(fn (TvPairing $p): bool => hash_equals($p->uuid, $uuid));
    }
}
