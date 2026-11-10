<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;
use App\Domains\Connectors\Mosparo\Actions\BuildMosparoCatalogAction;
use App\Domains\Connectors\Umami\Actions\BuildUmamiCatalogAction;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Exceptions\ProbeFailedException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Asks an app which sources it has. A 404 is what an app answers until it has the token, so
 * that is waiting rather than failing. During a rotation the new token is tried first, and
 * from the first answer to it the old one is forgotten.
 */
class SyncDataSourceConnectionAction
{
    public function __construct(
        private readonly SendSignedPullAction $send,
        private readonly RecordDataSourceCatalogAction $record,
        private readonly BuildUmamiCatalogAction $umamiCatalog,
        private readonly BuildMosparoCatalogAction $mosparoCatalog,
    ) {
    }

    public function execute(int $id): void
    {
        $connection = DataSourceConnection::query()->whereKey($id)->with('project')->first();

        if ($connection === null) {
            return;
        }

        try {
            $connection->isFor(ConnectionKind::App) ? $this->sync($connection) : $this->syncService($connection);
            $connection->update(['last_checked_at' => now(), 'last_ok_at' => now(), 'last_error' => null]);
        } catch (HealthExceptionInterface|ConnectorsExceptionInterface|ConnectionException $e) {
            $this->failed($connection, $e->getMessage());
        } catch (DecryptException) {
            $this->failed($connection, __('connectors.secret_unreadable'));
        } catch (Throwable $e) {
            report($e);
            $this->failed($connection, __('data_sources.errors.bad_index'));
        }
    }

    private function sync(DataSourceConnection $connection): void
    {
        $tokens = $connection->readableTokens();

        if ($tokens === []) {
            throw new ProbeFailedException(__('data_sources.errors.token_unreadable'));
        }

        foreach ($tokens as $name => $token) {
            $response = $this->send->execute($connection->url, $token, []);

            if ($response->status() === 404) {
                continue;
            }

            $index = $this->send->json($response);

            // A proxy or catch-all can answer 200 too, and must not end a rotation.
            if (($index['version'] ?? null) !== 1 || ! is_array($index['sources'] ?? null)) {
                throw new ProbeFailedException(__('data_sources.errors.bad_index'));
            }

            $this->remember($connection, $name);
            $connection->update(['last_ok_at' => now()]);
            $this->record->execute($connection, $index);

            return;
        }

        throw new ProbeFailedException(__('data_sources.errors.not_answering'));
    }

    /** A service's list is made here, from what its API says it has. */
    private function syncService(DataSourceConnection $connection): void
    {
        $this->record->execute($connection, match ($connection->kind) {
            ConnectionKind::Umami => $this->umamiCatalog->execute($connection),
            ConnectionKind::Mosparo => $this->mosparoCatalog->execute($connection),
            ConnectionKind::App => throw new ProbeFailedException(__('data_sources.errors.bad_index')),
        });
    }

    /**
     * @param  'current'|'previous'  $name
     */
    private function remember(DataSourceConnection $connection, string $name): void
    {
        // Only if the token is still the one asked with: a rotation may have come meanwhile.
        DataSourceConnection::query()->whereKey($connection->id)->where('pull_token', $connection->getRawOriginal('pull_token'))
            ->update(['uses_previous' => $name === 'previous', ...($name === 'current' ? ['pull_token_previous' => null] : [])]);
        $connection->refresh();
    }

    private function failed(DataSourceConnection $connection, string $message): void
    {
        $connection->update(['last_checked_at' => now(), 'last_error' => SendSignedPullAction::cleanMessage($message)]);
    }
}
