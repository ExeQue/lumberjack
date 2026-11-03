<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Providers;

use App\Domains\Metrics\Support\MetricsCredential;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Override;

class MetricsServiceProvider extends ServiceProvider
{
    /**
     * Metrics API requests per minute per token or public stats key.
     */
    public const int REQUESTS_PER_MINUTE = 120;

    #[Override]
    public function register(): void
    {
        $this->app->scoped(MetricsCredential::class);
    }

    public function boot(): void
    {
        // By the token or key as sent, so the limit holds whichever middleware runs first.
        RateLimiter::for('metrics', fn (Request $request): Limit => Limit::perMinute(self::REQUESTS_PER_MINUTE)
            ->by('metrics:'.sha1((string) ($request->route()?->originalParameter('key') ?? $request->bearerToken()))));
    }
}
