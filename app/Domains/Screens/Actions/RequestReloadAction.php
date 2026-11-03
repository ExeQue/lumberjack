<?php

declare(strict_types=1);

namespace App\Domains\Screens\Actions;

use App\Domains\Screens\Events\ReloadRequested;
use App\Domains\Screens\Exceptions\ReloadTooSoonException;
use App\Domains\Screens\Models\Device;
use App\Domains\Screens\Models\Display;
use App\Domains\Screens\Models\Screen;
use Illuminate\Support\Facades\RateLimiter;

class RequestReloadAction
{
    /** One reload per screen, device or display this often. */
    public const int EVERY_SECONDS = 10;

    public function __construct(
        private readonly CountScreenViewersAction $countViewers,
    ) {
    }

    /**
     * Everything showing the screen, every window of the device, or the one display, loads
     * the page afresh. Answers how many kiosks heard it for a screen (null when that cannot
     * be known), the number of windows for a device, 1 for a display.
     *
     * @throws ReloadTooSoonException
     */
    public function execute(Screen|Device|Display $target): ?int
    {
        $key = 'screen-reload:'.$target::class.':'.$target->getKey();

        if (! RateLimiter::attempt($key, 1, fn () => broadcast(new ReloadRequested($target)), self::EVERY_SECONDS)) {
            throw new ReloadTooSoonException(RateLimiter::availableIn($key));
        }

        return match (true) {
            $target instanceof Screen => $this->countViewers->execute($target),
            $target instanceof Device => $target->displays()->count(),
            default => 1,
        };
    }
}
