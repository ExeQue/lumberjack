<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Support\Facades\DB;

/**
 * An app that moved or changed time zone. Pulls go out anew at once, with any backing off forgotten.
 */
class ChangeDataSourceConnectionUrlAction
{
    public function execute(DataSourceConnection $connection, string $url, ?string $timezone = null): void
    {
        DB::transaction(function () use ($connection, $url, $timezone): void {
            $connection->update(['url' => $url, 'timezone' => $timezone ?? $connection->timezone, 'last_error' => null, 'next_check_at' => null]);
            $connection->sources()->update(['url' => $url, 'last_error' => null]);
            DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->update(['error' => null, 'failures' => 0, 'next_fetch_at' => null]);
        });
    }
}
