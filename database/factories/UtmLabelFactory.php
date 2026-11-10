<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Models\UtmLabel;
use App\Domains\DataSources\Models\DataSourceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UtmLabel>
 */
class UtmLabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'data_source_connection_id' => DataSourceConnection::factory()->umami(),
            'dimension' => UtmDimension::Campaign,
            'value' => '120210000000',
            'label' => 'Black Friday',
        ];
    }
}
