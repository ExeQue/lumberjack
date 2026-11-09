<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Screens\Models\Screen;
use App\Domains\Screens\Models\ScreenVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScreenVersion>
 */
class ScreenVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'screen_id' => Screen::factory(),
            'revision' => 0,
            'snapshot' => ['name' => 'Before', 'cols' => 4, 'rows' => 4, 'widgets' => []],
            'author' => 'Someone',
            'created_at' => now(),
        ];
    }
}
