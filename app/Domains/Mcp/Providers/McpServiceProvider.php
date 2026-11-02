<?php

declare(strict_types=1);

namespace App\Domains\Mcp\Providers;

use App\Domains\Mcp\Support\McpContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Override;

class McpServiceProvider extends ServiceProvider
{
    /**
     * Tool calls per minute per token.
     */
    public const int CALLS_PER_MINUTE = 120;

    #[Override]
    public function register(): void
    {
        $this->app->scoped(McpContext::class);
    }

    public function boot(): void
    {
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(self::CALLS_PER_MINUTE)->by('mcp:'.sha1((string) $request->bearerToken())));
    }
}
