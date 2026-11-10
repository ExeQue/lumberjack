<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Models\Umami;

use App\Domains\Connectors\Enums\Umami\UtmDimension;
use App\Domains\DataSources\Models\DataSourceConnection;
use Carbon\CarbonImmutable;
use Database\Factories\UtmLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A readable name for a UTM value of an Umami website, such as a Meta campaign's numeric
 * id; shown wherever the value is, on the Umami page and in the sources screens read.
 *
 * @property int $id
 * @property int $data_source_connection_id
 * @property UtmDimension $dimension
 * @property string $value
 * @property string $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['data_source_connection_id', 'dimension', 'value', 'label'])]
#[UseFactory(UtmLabelFactory::class)]
class UtmLabel extends Model
{
    /** @use HasFactory<UtmLabelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dimension' => UtmDimension::class,
        ];
    }

    /** @return BelongsTo<DataSourceConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(DataSourceConnection::class, 'data_source_connection_id');
    }
}
