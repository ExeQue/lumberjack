<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\Eloquent\Builders\AiKeyBuilder;
use App\Domains\Ai\Enums\AiPayer;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\AiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An API key an app made AI calls with, known only by its fingerprint: an HMAC of the hash
 * the app sent, with the project's secret. Neither the key nor that hash is stored.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $fingerprint
 * @property string|null $provider
 * @property string|null $driver the driver its hash was made with
 * @property string|null $label as the app reported it
 * @property string|null $name given in Lumberjack, shown before the label
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property AiPayer|null $payer null: inherited, see payer()
 */
#[Fillable(['project_id', 'fingerprint', 'provider', 'driver', 'label', 'name', 'owner_type', 'owner_id', 'first_seen_at', 'last_seen_at', 'payer'])]
#[WithoutTimestamps]
#[UseEloquentBuilder(AiKeyBuilder::class)]
#[UseFactory(AiKeyFactory::class)]
class AiKey extends Model
{
    /** @use HasFactory<AiKeyFactory> */
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
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'payer' => AiPayer::class,
        ];
    }

    /**
     * Who pays for its tokens: as set on the key, else the customer for a key with an
     * owner (one a tenant brought), else the project's default.
     */
    public function effectivePayer(AiPayer $projectDefault): AiPayer
    {
        return $this->payer ?? ($this->owner_type !== null ? AiPayer::Customer : $projectDefault);
    }

    /** What the key is shown as: its name, else its label, else its short id. */
    public function displayName(): string
    {
        return $this->name ?? $this->label ?? $this->shortId();
    }

    /**
     * What the key is shown as when it has no name or label.
     */
    public function shortId(): string
    {
        return substr($this->fingerprint, 0, 8);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
