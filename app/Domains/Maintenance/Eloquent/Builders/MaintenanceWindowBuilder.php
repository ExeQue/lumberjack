<?php

declare(strict_types=1);

namespace App\Domains\Maintenance\Eloquent\Builders;

use App\Domains\Maintenance\Models\MaintenanceWindow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<MaintenanceWindow>
 */
class MaintenanceWindowBuilder extends Builder
{
    /**
     * Started and not ended at the moment.
     */
    public function whereOpen(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where(fn (self $q) => $q->where('starts_at', '<=', $datetime)->where(fn (self $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $datetime)));
    }

    public function orWhereOpen(?CarbonInterface $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereOpen($datetime));
    }

    /**
     * Not ended yet, whether it started or is still planned.
     */
    public function whereNotEnded(): self
    {
        return $this->whereNull('ended_at');
    }

    public function orWhereNotEnded(): self
    {
        return $this->orWhereNull('ended_at');
    }

    /**
     * A window of the environment, or one for the whole project. Without an environment,
     * whatever it is about (a queue, a check without one) is covered by any window.
     */
    public function whereCovers(?string $environment): self
    {
        return $environment === null || $environment === '' ? $this : $this->whereIn('environment', [$environment, '']);
    }

    public function orWhereCovers(?string $environment): self
    {
        return $this->orWhere(fn (self $q) => $q->whereCovers($environment));
    }

    /**
     * Open past its expected end.
     */
    public function whereOverrun(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->whereNull('ended_at')->where('expected_end_at', '<=', $datetime);
    }

    public function orWhereOverrun(?CarbonInterface $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereOverrun($datetime));
    }

    /**
     * Any part of it inside the stretch from $from to now.
     */
    public function whereOverlaps(CarbonInterface $from, ?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where('starts_at', '<=', $datetime)->where(fn (self $q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from));
    }

    public function orWhereOverlaps(CarbonInterface $from, ?CarbonInterface $datetime = null): self
    {
        return $this->orWhere(fn (self $q) => $q->whereOverlaps($from, $datetime));
    }
}
