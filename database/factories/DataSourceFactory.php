<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Orders API',
            'url' => 'https://example.com/stats.json',
            'headers' => [],
            'interval_minutes' => 5,
            'timeout_s' => 10,
        ];
    }

    /**
     * A source a connected app offers, on the project's connection (made when it has none).
     */
    public function connected(string $key = 'orders:total', DataSourceShape $shape = DataSourceShape::Number): static
    {
        return $this->state(fn (): array => [
            'name' => 'Orders',
            'url' => 'https://app.example.com/lumberjack/data-sources',
            'format' => DataSourceFormat::Connected,
            'key' => $key,
            'shape' => $shape,
            'environment' => 'production',
            'data_source_connection_id' => fn (array $attributes): int => (DataSourceConnection::query()->where('project_id', $attributes['project_id'])->first() ?? DataSourceConnection::factory()->create(['project_id' => $attributes['project_id']]))->id,
        ]);
    }

    /**
     * Filed under a group by its app, as the catalog stores it.
     */
    public function inGroup(string $path = 'Events > Storage'): static
    {
        return $this->state(fn (): array => ['group_path' => $path]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    /**
     * A calendar's secret address in iCal format, with the feed it last answered when given.
     */
    public function ical(?string $feed = null): static
    {
        return $this->state(fn (): array => [
            'name' => 'Team calendar',
            'url' => 'https://calendar.example.com/calendar/ical/team/private-s3cret/basic.ics',
            'format' => DataSourceFormat::Ical,
            'last_ok_at' => $feed !== null ? now() : null,
            'last_body' => $feed,
        ]);
    }
}
