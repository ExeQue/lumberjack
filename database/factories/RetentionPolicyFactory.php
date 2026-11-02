<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Retention\Enums\RetentionCategory;
use App\Domains\Retention\Models\RetentionPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RetentionPolicy>
 */
class RetentionPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category' => RetentionCategory::Errors,
            'days' => 30,
        ];
    }
}
