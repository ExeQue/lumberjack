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

        RateLimiter::for('ingest-browser', fn (Request $request): Limit => Limit::perMinute(self::BROWSER_PER_MINUTE)
            ->by('ingest-browser:'.$request->query('key').':'.$request->ip()));
    }
}
