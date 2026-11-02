<?php

declare(strict_types=1);

namespace App\Domains\Administration\Models;

use App\Domains\Administration\Enums\AdminAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a system administrator did, and to what.
 *
 * @property int $id
 * @property int|null $user_id
 * @property AdminAction $action
 * @property string|null $subject
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'action', 'subject', 'data'])]
class AdminAuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['action' => AdminAction::class, 'data' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
