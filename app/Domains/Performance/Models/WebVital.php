<?php

declare(strict_types=1);

namespace App\Domains\Performance\Models;

use App\Domains\Performance\Eloquent\Builders\WebVitalBuilder;
use App\Domains\Performance\Enums\DeviceClass;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Factories\WebVitalFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser page view's Core Web Vitals. Ingest writes the rows in bulk, without the model.
 *
 * @property int $id
 * @property int $project_id
 * @property string|null $environment
 * @property string|null $release
 * @property string $route the path, ids grouped as {id}
 * @property int|null $lcp_us
 * @property int|null $inp_us
 * @property int|null $ttfb_us
 * @property int|null $fcp_us
 * @property float|null $cls
 * @property DeviceClass|null $device
 * @property CarbonImmutable $occurred_at
 */
#[Fillable(['project_id', 'environment', 'release', 'route', 'lcp_us', 'inp_us', 'ttfb_us', 'fcp_us', 'cls', 'device', 'occurred_at'])]
#[WithoutTimestamps]
#[DateFormat('Y-m-d H:i:s.v')]
#[UseEloquentBuilder(WebVitalBuilder::class)]
#[UseFactory(WebVitalFactory::class)]
class WebVital extends Model
{
    /** @use HasFactory<WebVitalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cls' => 'float',
            'device' => DeviceClass::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
