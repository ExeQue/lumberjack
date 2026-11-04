<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait ReadsQueryCounts
{
    /**
     * How many queries the callback runs from a cold start: nothing cached, no scoped instances.
     * Compare a run over one item with a run over several to catch a query per item. It runs
     * once first, so what only a first run does (a session started, a visit recorded) is left out.
     */
    public function queriesRunBy(Closure $callback): int
    {
        $callback();
        Cache::flush();
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
