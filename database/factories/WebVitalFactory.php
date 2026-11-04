<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Performance\Models\WebVital;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebVital>
 */
class WebVitalFactory extends Factory
{
    /**
     * A page view of the home page a few minutes ago, every vital good.
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'release' => null,
            'route' => '/',
            'lcp_us' => 1_200_000,
            'inp_us' => 80_000,
            'ttfb_us' => 150_000,
            'fcp_us' => 700_000,
            'cls' => 0.02,
            'device' => DeviceClass::Desktop,
            'occurred_at' => now()->subMinutes(5),
        ];
    }

    public function forRoute(string $route): static
    {
        return $this->state(fn () => ['route' => $route]);
    }

    public function onDevice(DeviceClass $device): static
    {
        return $this->state(fn () => ['device' => $device]);
    }

    /** Sent by a browser script too old to tell the device class. */
    public function withoutDevice(): static
    {
        return $this->state(fn () => ['device' => null]);
    }

    /** Every vital poor by Google's thresholds. */
    public function poor(): static
    {
        return $this->state(fn () => ['lcp_us' => 5_000_000, 'inp_us' => 600_000, 'cls' => 0.4]);
    }
}
