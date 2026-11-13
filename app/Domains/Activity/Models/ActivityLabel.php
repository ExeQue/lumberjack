<?php

declare(strict_types=1);

namespace App\Domains\Activity\Models;

use App\Domains\Activity\Eloquent\Builders\ActivityLabelBuilder;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The name a team gives an activity group (name empty) or one activity of it.
 *
 * @property int $id
 * @property int $project_id
 * @property string $group
 * @property string $name empty for the group itself
 * @property string $label
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['project_id', 'group', 'name', 'label'])]
#[UseEloquentBuilder(ActivityLabelBuilder::class)]
#[UseFactory(ActivityLabelFactory::class)]
class ActivityLabel extends Model
{
    /** @use HasFactory<ActivityLabelFactory> */
    use HasFactory;

    /**
     * What a group or activity is called without a name of the team's: "order.placed" reads as
     * "Order placed", "api" as "API", as health check slugs do.
     */
    public static function readable(string $value): string
    {
        /** @var array<string, string> $spelled */
        $spelled = config('health.spelled_words');
        $words = preg_split('/[\s._:-]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [$value];

        return ucfirst(implode(' ', array_map(fn (string $word): string => $spelled[mb_strtolower($word)] ?? $word, $words)));
    }

    /**
     * What a group (no name) or activity is shown as: the team's name for it, or a readable one.
     *
     * @param  array{groups: array<string, string>, activities: array<string, string>}  $labels  from toMap()
     */
    public static function shown(array $labels, string $group, ?string $name = null): string
    {
        return $name === null
            ? $labels['groups'][$group] ?? self::readable($group)
            : $labels['activities'][$group.':'.$name] ?? self::readable($name);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
