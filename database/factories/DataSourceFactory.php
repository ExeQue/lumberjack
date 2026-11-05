<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\DataSources\Enums\DataSourceFormat;
use App\Domains\DataSources\Models\DataSource;
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
