<?php

declare(strict_types=1);

namespace Tests\Support\Live;

use App\Domains\Live\Events\AdminChanged;
use App\Domains\Live\Events\ProjectChanged;
use App\Domains\Live\Events\TeamChanged;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

trait ChecksLiveBroadcasts
{
    /** From here on, broadcasts are recorded; what building the fixtures sent is forgotten. */
    public function listenForBroadcasts(): void
    {
        $this->app->instance(DeferredCallbackCollection::class, new DeferredCallbackCollection());
        // The fixtures' own broadcasts would otherwise throttle the one under test.
        Cache::flush();
        Event::fake([ProjectChanged::class, TeamChanged::class, AdminChanged::class]);
    }

    /**
     * What was broadcast, once the deferred team and admin broadcasts ran, as "channel event" strings.
     *
     * @return list<string>
     */
    public function broadcasts(): array
    {
        app(DeferredCallbackCollection::class)->invoke();

        return collect([ProjectChanged::class, TeamChanged::class, AdminChanged::class])
            ->flatMap(fn (string $class) => Event::dispatched($class)->map(fn (array $args): string => $args[0]->broadcastOn()->name.' '.$args[0]->broadcastAs()))
            ->unique()->sort()->values()->all();
    }
}
