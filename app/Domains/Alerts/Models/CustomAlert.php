<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Alerts\Enums\CustomAlertLevel;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\CustomAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alert an app sent, kept whether or not it reached a channel.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $alert_rule_id
 * @property string $topic
 * @property CustomAlertLevel $level
 * @property string $title
 * @property string|null $message
 * @property array{url: ?string, fields: list<array{name: string, value: string, inline: bool}>, tables: list<array{title: ?string, headers: list<string>, rows: list<list<string>>, omitted: int}>, links: list<array{label: string, url: string, style: ?string}>} $content
 * @property string|null $environment
 * @property string|null $host
 * @property string|null $release
 * @property string|null $suppressed  "dedupe" or "rate_limit" when it was kept but not sent
 * @property list<array{channel: string, type: string, delivered: bool}>|null $deliveries
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'alert_rule_id', 'topic', 'level', 'title', 'message', 'content', 'environment', 'host', 'release', 'suppressed', 'deliveries', 'occurred_at'])]
#[WithoutTimestamps]
#[UseFactory(CustomAlertFactory::class)]
class CustomAlert extends Model
{
    /** @use HasFactory<CustomAlertFactory> */
    use HasFactory;

    public const string SUPPRESSED_DEDUPE = 'dedupe';

    public const string SUPPRESSED_RATE_LIMIT = 'rate_limit';

    protected function casts(): array
    {
        return [
            'level' => CustomAlertLevel::class,
            'content' => 'array',
            'deliveries' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }
}
