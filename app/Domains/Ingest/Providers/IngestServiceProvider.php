<?php

declare(strict_types=1);

namespace App\Domains\Ingest\Providers;

use App\Domains\Projects\Models\Project;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Override;

class IngestServiceProvider extends ServiceProvider
{
    /**
     * Batches per minute per project. An agent flushes every 5 seconds per host,
     * so this leaves room for a couple of dozen hosts.
     */
    public const int BATCHES_PER_MINUTE = 600;

    /**
     * Browser batches per minute per IP and project.
     */
    public const int BROWSER_PER_MINUTE = 30;

    /**
     * Browser batches per minute per IP over all keys, e.g. an office behind one address.
     */
    public const int BROWSER_PER_IP_PER_MINUTE = 300;

    public const int HEALTH_STATES_PER_MINUTE = 120;

    /**
     * Source map uploads per minute per project: a build uploads one per bundle, each up to 20 MB.
     */
    public const int SOURCEMAPS_PER_MINUTE = 120;

    #[Override]
    public function register(): void
    {
    }

    public function boot(): void
    {
        RateLimiter::for('ingest', function (Request $request): Limit {
            $project = $request->attributes->get('project');

            return Limit::perMinute(self::BATCHES_PER_MINUTE)->by('ingest:'.($project instanceof Project ? $project->id : $request->ip()));
        });

        // GET /api/health with the server token: an app asks at most every few seconds.
        RateLimiter::for('health-states', function (Request $request): Limit {
            $project = $request->attributes->get('project');

            return Limit::perMinute(self::HEALTH_STATES_PER_MINUTE)->by('health-states:'.($project instanceof Project ? $project->id : $request->ip()));
        });

        RateLimiter::for('sourcemaps', function (Request $request): Limit {
            $project = $request->attributes->get('project');

            return Limit::perMinute(self::SOURCEMAPS_PER_MINUTE)->by('sourcemaps:'.($project instanceof Project ? $project->id : $request->ip()));
        });

        // Per key and IP, and per IP whatever the key: a made-up key per request gets no fresh bucket.
        RateLimiter::for('ingest-browser', fn (Request $request): array => [
            Limit::perMinute(self::BROWSER_PER_MINUTE)->by('ingest-browser:'.$request->query('key').':'.$request->ip()),
            Limit::perMinute(self::BROWSER_PER_IP_PER_MINUTE)->by('ingest-browser-ip:'.$request->ip()),
        ]);
    }
}
