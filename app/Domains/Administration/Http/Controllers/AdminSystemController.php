<?php

declare(strict_types=1);

namespace App\Domains\Administration\Http\Controllers;

use App\Domains\Administration\Queries\SystemStatusQuery;
use App\Domains\Shared\Enums\Period;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSystemController
{
    /** The samples are kept a week unless the retention page says otherwise. */
    private const array PERIODS = [Period::Hour, Period::Day, Period::Week];

    public function __invoke(Request $request, SystemStatusQuery $status): Response
    {
        $period = Period::tryFrom((string) $request->query('period'));
        $period = in_array($period, self::PERIODS, true) ? $period : Period::Day;

        return Inertia::render('admin/system', [
            'period' => $period->value,
            'latest' => $status->latest(),
            'charts' => $status->charts($period),
            'database' => $status->database(),
            'queues' => $status->queues(),
            'redis' => $status->redis(),
        ]);
    }
}
