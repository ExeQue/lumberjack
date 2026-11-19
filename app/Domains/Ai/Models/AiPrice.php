<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiPriceBuilder;
use App\Domains\Ai\Enums\AiPriceScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Database\Factories\AiPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What a model's tokens cost per million, in the team's currency, from a moment on: for
 * every project of a team, one project, one key owner's keys or one key. Several can
 * apply to a call; AiPriceBook picks the one that does.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int|null $project_id null for every project of the team
 * @property AiPriceScope $scope
 * @property string|null $scope_type the owner's type
 * @property string|null $scope_id the owner's key, or the key's fingerprint
 * @property string|null $provider null for any
 * @property string $model_pattern a model name, or a glob with *
 * @property CarbonImmutable $valid_from
 * @property float $input
 * @property float $output
 * @property float|null $cache_read null: the input price
 * @property float|null $cache_write null: the input price
 * @property float|null $reasoning null: billed as output
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['team_id', 'project_id', 'scope', 'scope_type', 'scope_id', 'provider', 'model_pattern', 'valid_from', 'input', 'output', 'cache_read', 'cache_write', 'reasoning'])]
#[UseEloquentBuilder(AiPriceBuilder::class)]
#[UseFactory(AiPriceFactory::class)]
class AiPrice extends Model
{
    /** @use HasFactory<AiPriceFactory> */
    use HasFactory;
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'scope' => AiPriceScope::class,
            'valid_from' => 'datetime',
            'input' => 'float',
            'output' => 'float',
            'cache_read' => 'float',
            'cache_write' => 'float',
            'reasoning' => 'float',
        ];
    }

    /**
     * How narrow it is, narrowest first: a key, an owner, a project, the team.
     */
    public function rank(): int
    {
        return match ($this->scope) {
            AiPriceScope::Key => 0,
            AiPriceScope::Owner => 1,
            AiPriceScope::Project => $this->project_id !== null ? 2 : 3,
        };
    }

    public function isGlob(): bool
    {
        return str_contains($this->model_pattern, '*');
    }

    public function matchesModel(?string $provider, string $model): bool
    {
        if ($this->provider !== null && strcasecmp($this->provider, (string) $provider) !== 0) {
            return false;
        }

        return $this->isGlob() ? Str::is($this->model_pattern, $model) : $this->model_pattern === $model;
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
