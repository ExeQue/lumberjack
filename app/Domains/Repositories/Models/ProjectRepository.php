<?php

declare(strict_types=1);

namespace App\Domains\Repositories\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Repositories\Enums\RepositoryProvider;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectRepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property RepositoryProvider $provider
 * @property string $repository
 * @property string $token
 * @property CarbonImmutable|null $token_expires_at
 * @property list<string>|null $labels
 * @property bool $allow_public
 */
#[Fillable(['project_id', 'provider', 'repository', 'token', 'token_expires_at', 'labels', 'allow_public'])]
#[Hidden(['token'])]
#[UseFactory(ProjectRepositoryFactory::class)]
class ProjectRepository extends Model
{
    /** @use HasFactory<ProjectRepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => RepositoryProvider::class,
            'token' => 'encrypted',
            'token_expires_at' => 'date',
            'labels' => 'array',
            'allow_public' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
