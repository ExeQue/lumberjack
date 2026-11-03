<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Enums\SpanKind;
use App\Domains\Performance\Models\SpanRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpanRun>
 */
class SpanRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'kind' => SpanKind::Http,
            'name' => 'GET',
            'target' => 'api.stripe.com/v1/payment_intents',
            'duration_us' => fake()->numberBetween(40_000, 400_000),
            'attributes' => ['status' => 200],
            'occurred_at' => now(),
        ];
    }

    /** An outgoing HTTP call answered with this status. */
    public function http(string $method, string $target, int $status = 200): static
    {
        return $this->state(fn () => ['kind' => SpanKind::Http, 'name' => $method, 'target' => $target, 'attributes' => ['status' => $status]]);
    }

    /** A cache lookup that found its key, or did not. */
    public function cache(string $key, bool $hit = true): static
    {
        return $this->state(fn () => ['kind' => SpanKind::Cache, 'name' => $hit ? 'hit' : 'miss', 'target' => $key, 'duration_us' => fake()->numberBetween(200, 2_000), 'attributes' => null]);
    }

    public function mail(int $recipients = 1): static
    {
        return $this->state(fn () => ['kind' => SpanKind::Mail, 'name' => 'sent', 'target' => 'smtp', 'duration_us' => null, 'attributes' => ['recipients' => $recipients]]);
    }

    /** @param  class-string|string  $notification */
    public function notification(string $notification, string $channel = 'mail'): static
    {
        return $this->state(fn () => ['kind' => SpanKind::Notification, 'name' => $channel, 'target' => $notification, 'duration_us' => null, 'attributes' => null]);
    }
}
