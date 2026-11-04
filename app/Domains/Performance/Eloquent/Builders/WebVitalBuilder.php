<?php

declare(strict_types=1);

namespace App\Domains\Performance\Eloquent\Builders;

use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Performance\Models\WebVital;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends Builder<WebVital>
 */
class WebVitalBuilder extends Builder
{
    /**
     * Page views from $from on, up to (not including) $until.
     */
    public function whereOccurredBetween(CarbonInterface $from, ?CarbonInterface $until = null): self
    {
        return $this->where(fn (self $q) => $q->where('occurred_at', '>=', $from)->when($until !== null)->where('occurred_at', '<', $until));
    }

    public function orWhereOccurredBetween(CarbonInterface $from, ?CarbonInterface $until = null): self
    {
        return $this->orWhere(fn (self $q) => $q->where('occurred_at', '>=', $from)->when($until !== null)->where('occurred_at', '<', $until));
    }

    /**
     * Every environment when null.
     */
    public function whereEnvironment(?string $environment): self
    {
        return $this->when($environment !== null)->where('environment', $environment);
    }

    public function orWhereEnvironment(?string $environment): self
    {
        return $this->when($environment !== null)->orWhere('environment', $environment);
    }

    /**
     * Every device when null, page views without a device class included.
     */
    public function whereDevice(?DeviceClass $device): self
    {
        return $this->when($device !== null)->where('device', $device?->value);
    }

    public function orWhereDevice(?DeviceClass $device): self
    {
        return $this->when($device !== null)->orWhere('device', $device?->value);
    }
}
