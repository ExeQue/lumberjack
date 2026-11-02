<?php

declare(strict_types=1);

namespace App\Domains\Shared\Http\Concerns;

use App\Domains\Shared\Enums\Period;
use Illuminate\Http\Request;

/**
 * The period and environment every project page shares via the query string.
 */
trait ReadsDashboardFilters
{
    protected function period(Request $request, Period $default = Period::Day): Period
    {
        return Period::tryFrom((string) $request->query('period')) ?? $default;
    }

    protected function environment(Request $request): ?string
    {
        $environment = $request->query('environment');

        return is_string($environment) && $environment !== '' ? mb_substr($environment, 0, 64) : null;
    }

    /**
     * @return array{period: string, environment: string|null}
     */
    protected function filters(Request $request, Period $default = Period::Day): array
    {
        return ['period' => $this->period($request, $default)->value, 'environment' => $this->environment($request)];
    }
}
