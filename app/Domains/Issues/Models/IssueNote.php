<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Domains\Issues\Enums\IssueStatus;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\IssueNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $issue_id
 * @property int|null $user_id
 * @property int|null $mcp_token_id
 * @property string $body
 * @property IssueStatus|null $status
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['issue_id', 'user_id', 'mcp_token_id', 'body', 'status'])]
#[UseFactory(IssueNoteFactory::class)]
class IssueNote extends Model
{
    /** @use HasFactory<IssueNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => IssueStatus::class];
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

    /** @return BelongsTo<McpToken, $this> */
    public function mcpToken(): BelongsTo
    {
        return $this->belongsTo(McpToken::class);
    }
}
