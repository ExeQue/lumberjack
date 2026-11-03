<?php

declare(strict_types=1);

namespace App\Domains\Screens\Eloquent\Builders;

use App\Domains\Screens\Models\TvPairing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<TvPairing>
 */
class TvPairingBuilder extends Builder
{
    /** Not confirmed yet and not expired: a code someone may still confirm. */
    public function wherePending(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where(fn (self $q) => $q->whereNull('confirmed_at')->where('expires_at', '>', $datetime));
    }

    public function orWherePending(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->orWhere(fn (self $q) => $q->whereNull('confirmed_at')->where('expires_at', '>', $datetime));
    }

    public function whereExpired(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->where('expires_at', '<=', $datetime);
    }

    public function orWhereExpired(?CarbonInterface $datetime = null): self
    {
        $datetime ??= now();

        return $this->orWhere('expires_at', '<=', $datetime);
    }
}
