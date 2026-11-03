<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Performance\Models\RequestRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestRun>
 */
class RequestRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment' => 'production',
            'method' => 'GET',
            'route' => '/dashboard',
            'domain' => 'example.test',
            'url' => 'https://example.test/dashboard',
            'status_code' => 200,
            'duration_us' => fake()->numberBetween(10_000, 500_000),
            'occurred_at' => now(),
        ];
    }

    /**
     * A request to this route, its url the route with each {parameter} filled in.
     */
    public function forRoute(string $method, string $route, ?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => $method,
            'route' => $route,
            'route_name' => $name,
            'url' => self::url($attributes['domain'] ?? null, $route),
        ]);
    }

    public function onDomain(string $domain): static
    {
        return $this->state(fn (array $attributes) => [
            'domain' => $domain,
            'url' => self::url($domain, (string) ($attributes['route'] ?? '/')),
        ]);
    }

    /** Seconds, not milliseconds. */
    public function slow(): static
    {
        return $this->state(fn () => ['duration_us' => fake()->numberBetween(2_000_000, 6_000_000)]);
    }

    public function serverError(): static
    {
        return $this->state(fn () => ['status_code' => 500]);
    }

    /** Kept by the SDK at this rate, so it stands for 1 / rate requests. */
    public function sampledAt(float $rate): static
    {
        return $this->state(fn () => ['sample_rate' => $rate, 'sampled' => true]);
    }

    private static function url(?string $domain, string $route): string
    {
        $path = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m): string => match ($m[1]) {
            'slug' => 'oak-desk-lamp',
            'token' => 'f3a9c2',
            default => '4821',
        }, $route) ?? $route;

        return 'https://'.($domain ?: 'example.test').$path;
    }
}
