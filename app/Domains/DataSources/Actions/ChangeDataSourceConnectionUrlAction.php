<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Models\DataSourceWindow;
use Illuminate\Support\Facades\DB;

/**
 * An app that moved. Pulls go to the new address at once, with any backing off forgotten.
 */
class ChangeDataSourceConnectionUrlAction
{
    public function execute(DataSourceConnection $connection, string $url): void
    {
        DB::transaction(function () use ($connection, $url): void {
            $connection->update(['url' => $url, 'last_error' => null, 'next_check_at' => null]);
            $connection->sources()->update(['url' => $url, 'last_error' => null]);
            DataSourceWindow::query()->whereIn('data_source_id', $connection->sources()->select('id'))->update(['error' => null, 'failures' => 0, 'next_fetch_at' => null]);
        });
    }
}
