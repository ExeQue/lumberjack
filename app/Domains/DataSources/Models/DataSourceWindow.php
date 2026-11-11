<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Models;

use App\Domains\DataSources\Eloquent\Builders\DataSourceWindowBuilder;
use Carbon\CarbonImmutable;
use Database\Factories\DataSourceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a connected source answered for one period, kept for as long as a screen shows it.
 *
 * @property int $id
 * @property int $data_source_id
 * @property string $period
 * @property int $bucket the seconds one point of a series covers
 * @property mixed $data
 * @property bool $has_data
 * @property string|null $error
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $wanted_since since when the period has been asked for without a break
 * @property CarbonImmutable|null $fetched_at
 * @property int $failures
 * @property CarbonImmutable|null $next_fetch_at
 */
#[Fillable(['data_source_id', 'period', 'bucket', 'data', 'has_data', 'error', 'failures', 'requested_at', 'wanted_since', 'fetched_at', 'next_fetch_at'])]
#[WithoutTimestamps]
#[UseFactory(DataSourceWindowFactory::class)]
#[UseEloquentBuilder(DataSourceWindowBuilder::class)]
class DataSourceWindow extends Model
{
    /** @use HasFactory<DataSourceWindowFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'has_data' => 'boolean',
            'requested_at' => 'datetime',
            'wanted_since' => 'datetime',
            'fetched_at' => 'datetime',
            'next_fetch_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DataSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }
}
