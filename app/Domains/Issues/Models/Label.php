<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Domains\Issues\Enums\LabelColor;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property LabelColor $color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $issues_count
 */
#[Fillable(['project_id', 'name', 'color'])]
#[UseFactory(LabelFactory::class)]
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    public const int MAX_NAME = 32;

    public const int MAX_PER_ISSUE = 10;

    protected function casts(): array
    {
        return ['color' => LabelColor::class];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<Issue, $this> */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class);
    }

    /**
     * @return array{id: int, name: string, color: string}
     */
    public function toSummary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color->value];
    }
}
