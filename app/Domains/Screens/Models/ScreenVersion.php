<?php

declare(strict_types=1);

namespace App\Domains\Screens\Models;

use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ScreenVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * A screen as it was before a change, to go back to.
 *
 * @property int $id
 * @property string $uuid
 * @property int $screen_id
 * @property int $revision the screen's revision this was
 * @property array<string, mixed> $snapshot
 * @property int|null $user_id
 * @property int|null $mcp_token_id
 * @property string|null $author who made the change that replaced this
 * @property CarbonImmutable $created_at
 * @property-read Screen $screen
 */
#[Fillable(['screen_id', 'revision', 'snapshot', 'user_id', 'mcp_token_id', 'author', 'created_at'])]
#[UseFactory(ScreenVersionFactory::class)]
class ScreenVersion extends Model
{
    /** @use HasFactory<ScreenVersionFactory> */
    use HasFactory;
    use HasUuids;

    public $timestamps = false;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The screen as this version had it, unsaved. */
    public function applied(): Screen
    {
        return (clone $this->screen)->fill(Arr::only($this->snapshot, Screen::DESIGN));
    }

    /** @return BelongsTo<Screen, $this> */
    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    /** @return BelongsTo<McpToken, $this> */
    public function mcpToken(): BelongsTo
    {
        return $this->belongsTo(McpToken::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
