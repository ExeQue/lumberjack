<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Umami\Actions;

use App\Domains\Connectors\Umami\Enums\UtmDimension;
use App\Domains\Connectors\Umami\Models\UtmLabel;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * Names a UTM value of an Umami website, or forgets the name when given none. A named
 * campaign is offered to screens as a source under its name from the next sync, which
 * this asks for at once.
 */
class SaveUtmLabelAction
{
    public function execute(DataSourceConnection $connection, UtmDimension $dimension, string $value, ?string $label): ?UtmLabel
    {
        if (! $connection->isFor(ConnectionKind::Umami)) {
            return null;
        }

        $label = trim((string) $label);
        $match = ['data_source_connection_id' => $connection->id, 'dimension' => $dimension, 'value' => $value];

        if ($label === '') {
            UtmLabel::query()->where($match)->delete();
            $saved = null;
        } else {
            $saved = UtmLabel::query()->updateOrCreate($match, ['label' => mb_substr($label, 0, 100)]);
        }

        $connection->update(['next_check_at' => null]);

        return $saved;
    }
}
