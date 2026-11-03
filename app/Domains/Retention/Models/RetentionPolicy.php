<?php

declare(strict_types=1);

namespace App\Domains\Retention\Models;

use App\Domains\Retention\Eloquent\Builders\RetentionPolicyBuilder;
use App\Domains\Retention\Enums\RetentionCategory;
use Database\Factories\RetentionPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored retention. No team and no project: the application's. A team and no project: the
 * team's default. A project: the project's, or with a job class or log channel, that one's.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $project_id
 * @property RetentionCategory $category
 * @property string|null $job_class
 * @property int|null $log_channel_id
 * @property int|null $days null keeps the data forever
 */
#[Fillable(['team_id', 'project_id', 'category', 'job_class', 'log_channel_id', 'days'])]
#[UseFactory(RetentionPolicyFactory::class)]
#[UseEloquentBuilder(RetentionPolicyBuilder::class)]
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
