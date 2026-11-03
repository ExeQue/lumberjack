<?php

declare(strict_types=1);

namespace App\Domains\Issues\Models;

use App\Domains\Issues\Enums\IssueEventType;
use App\Domains\Mcp\Models\McpToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\IssueEventFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an issue: it was created, regressed, escalated,
 * changed status, assignee or labels, or got a GitHub issue. Notes are their
 * own table and join this one on the issue's history.
 *
 * @property int $id
 * @property int $issue_id
 * @property IssueEventType $type
 * @property int|null $user_id
 * @property int|null $mcp_token_id
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['issue_id', 'type', 'user_id', 'mcp_token_id', 'data', 'occurred_at'])]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseFactory(IssueEventFactory::class)]
class IssueEvent extends Model
{
    /** @use HasFactory<IssueEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => IssueEventType::class,
            'data' => 'array',
            'occurred_at' => 'datetime',
        ];
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
