<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSourceWindow>
 */
class DataSourceWindowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'data_source_id' => DataSource::factory()->connected(),
            'period' => '24h',
            'bucket' => 3600,
            'requested_at' => now(),
        ];
    }

    public function forSource(DataSource $source): static
    {
        return $this->state(fn (): array => ['data_source_id' => $source->id]);
    }

    public function answered(mixed $data): static
    {
        return $this->state(fn (): array => ['data' => $data, 'has_data' => true, 'fetched_at' => now()]);
    }
}
