<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Domains\Issues\Enums\WatchReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone watching an issue, or someone who stopped (unwatched_at), which keeps them
 * from being made a watcher of it again without asking.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $user_id
 * @property WatchReason $reason
 * @property CarbonImmutable|null $unwatched_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['issue_id', 'user_id', 'reason', 'unwatched_at'])]
class IssueWatcher extends Model
{
    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return ['reason' => WatchReason::class, 'unwatched_at' => 'datetime'];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
