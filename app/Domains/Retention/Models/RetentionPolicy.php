<?php

declare(strict_types=1);

namespace App\Domains\Retention\Models;

use App\Domains\Retention\Enums\RetentionCategory;
use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $team_id
 * @property int|null $project_id
 * @property RetentionCategory $category
 * @property string|null $job_class
 * @property int|null $log_channel_id
 * @property int $days
 */
#[Fillable(['team_id', 'project_id', 'category', 'job_class', 'log_channel_id', 'days'])]
#[UseFactory(RetentionPolicyFactory::class)]
class RetentionPolicy extends Model
{
    /** @use HasFactory<RetentionPolicyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => RetentionCategory::class,
            'days' => 'integer',
        ];
    }
}
