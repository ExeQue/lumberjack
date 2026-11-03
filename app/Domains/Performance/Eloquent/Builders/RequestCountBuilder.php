<?php

declare(strict_types=1);

namespace App\Domains\Performance\Eloquent\Builders;

use App\Domains\Performance\Models\RequestCount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<RequestCount>
 */
class RequestCountBuilder extends Builder
{
    /**
     * Minutes from $from on, up to (not including) $until.
     */
    public function whereMinuteBetween(CarbonInterface $from, ?CarbonInterface $until = null): self
    {
        return $this->where(fn (self $q) => $q->where('minute', '>=', $from)->when($until !== null)->where('minute', '<', $until));
    }

    public function orWhereMinuteBetween(CarbonInterface $from, ?CarbonInterface $until = null): self
    {
        return $this->orWhere(fn (self $q) => $q->where('minute', '>=', $from)->when($until !== null)->where('minute', '<', $until));
    }

    /**
     * Every environment when null; the counts of a batch without one are stored as ''.
     */
    public function whereEnvironment(?string $environment): self
    {
        return $this->when($environment !== null)->where('environment', $environment);
    }

    public function orWhereEnvironment(?string $environment): self
    {
        return $this->when($environment !== null)->orWhere('environment', $environment);
    }
}
