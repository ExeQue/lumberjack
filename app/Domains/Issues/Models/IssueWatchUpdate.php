<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a watched issue, waiting for the watcher's grouped mail
 * (SendWatchDigestsAction). Deleted once that mail is out.
 *
 * @property int $id
 * @property int $issue_id
 * @property int $user_id
 * @property string $kind  note, status or assignee
 * @property string $actor
 * @property array<string, string|null>|null $data
 * @property string|null $body
 * @property list<array<string, mixed>>|null $reasons  other reasons the watcher would have heard of it
 * @property CarbonImmutable $created_at
 */
#[Fillable(['issue_id', 'user_id', 'kind', 'actor', 'data', 'body', 'reasons', 'created_at'])]
class IssueWatchUpdate extends Model
{
    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return ['data' => 'array', 'reasons' => 'array', 'created_at' => 'datetime'];
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
