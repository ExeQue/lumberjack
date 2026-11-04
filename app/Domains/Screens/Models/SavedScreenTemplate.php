<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Teams\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SavedScreenTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A template a team saved from one of its screens (the built-in ones are ScreenTemplate):
 * the screen's portable file, made into a new screen the way an import is.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property array{format: string, version: int, exported_at?: string, screen: array<string, mixed>} $definition
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('screen_templates')]
#[Fillable(['team_id', 'name', 'description', 'definition', 'created_by'])]
#[UseFactory(SavedScreenTemplateFactory::class)]
class SavedScreenTemplate extends Model
{
    /** @use HasFactory<SavedScreenTemplateFactory> */
    use HasFactory;
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * For the New screen dialog: like ScreenTemplate::toArray(), the grid and where each
     * widget sits for its thumbnail.
     *
     * @return array{uuid: string, label: string, description: string, cols: int, rows: int, widgets: list<array{type: string, x: int, y: int, w: int, h: int}>, creator: string|null}
     */
    public function toPicker(): array
    {
        $screen = $this->definition['screen'];

        return [
            'uuid' => $this->uuid,
            'label' => $this->name,
            'description' => (string) $this->description,
            'cols' => (int) ($screen['cols'] ?? 4),
            'rows' => (int) ($screen['rows'] ?? 4),
            'widgets' => array_values(array_map(
                fn (array $w): array => ['type' => (string) ($w['type'] ?? ''), 'x' => (int) ($w['x'] ?? 0), 'y' => (int) ($w['y'] ?? 0), 'w' => (int) ($w['w'] ?? 1), 'h' => (int) ($w['h'] ?? 1)],
                array_filter((array) ($screen['widgets'] ?? []), 'is_array'),
            )),
            'creator' => $this->creator?->name,
        ];
    }
}
