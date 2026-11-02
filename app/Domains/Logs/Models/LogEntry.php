<?php

declare(strict_types=1);

namespace App\Domains\Logs\Models;

use App\Domains\Logs\Eloquent\Builders\LogEntryBuilder;
use App\Domains\Logs\Enums\LogLevel;
use Carbon\CarbonImmutable;
use Database\Factories\LogEntryFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $log_channel_id
 * @property string|null $sent_channel
 * @property LogLevel $level
 * @property string $message
 * @property array<string, mixed>|null $context
 * @property string|null $trace_id
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $host
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'log_channel_id', 'sent_channel', 'level', 'message', 'context', 'trace_id', 'environment', 'release', 'host', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(LogEntryBuilder::class)]
#[UseFactory(LogEntryFactory::class)]
class LogEntry extends Model
{
    /** @use HasFactory<LogEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'level' => LogLevel::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LogChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(LogChannel::class, 'log_channel_id');
    }
}
