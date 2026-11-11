<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Actions;

use App\Domains\Connectors\MobilePay\Data\MobilePayConfig;
use App\Domains\Connectors\MobilePay\Data\MobilePayCredentials;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Projects\Models\Project;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;

/**
 * Connects a project to the Vipps MobilePay ledger a sales unit settles to, once its keys
 * find it. A project reads as many ledgers as it likes, one connection each; connecting a
 * ledger it already reads renews that connection's keys, also one whose keys can no longer
 * be read (another APP_KEY) when its name is the ledger's.
 */
class ConnectMobilePayAction
{
    /** How long a connect holds its ledger, and how long another waits for it. */
    public const int LOCK_SECONDS = 10;

    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * @throws MobilePayRequestFailedException when the keys are refused or settle to no ledger
     */
    public function execute(Project $project, MobilePayCredentials $credentials, string $timezone = 'UTC'): DataSourceConnection
    {
        $ledger = $this->client->ledger($credentials);

        // Two connects of the same ledger at once would both find none and add two.
        return Cache::lock('connect-mobilepay:'.$project->id.':'.$ledger['ledger_id'], self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, fn (): DataSourceConnection => $this->store($project, $credentials, $ledger, $timezone));
    }

    /**
     * @param  array{ledger_id: string, currency: string, name: string}  $ledger
     */
    private function store(Project $project, MobilePayCredentials $credentials, array $ledger, string $timezone): DataSourceConnection
    {
        $name = mb_substr($ledger['name'], 0, 200);
        $connections = $project->mobilePayConnections()->get();

        // One saved under another APP_KEY cannot say its ledger; one of the same name is renewed.
        $connection = $connections->first(fn (DataSourceConnection $c): bool => $this->readsLedger($c, $ledger['ledger_id']) === true)
            ?? $connections->first(fn (DataSourceConnection $c): bool => $this->readsLedger($c, $ledger['ledger_id']) === null && $c->name === $name)
            ?? new DataSourceConnection([
                'project_id' => $project->id,
                'kind' => ConnectionKind::MobilePay,
                'environment' => ConnectionKind::MobilePay->environment(),
            ]);

        $connection->fill([
            'url' => MobilePayClient::API,
            'name' => $name,
            'config' => new MobilePayConfig($credentials, $ledger['ledger_id'], $ledger['currency']),
            'timezone' => $timezone,
            'last_error' => null,
            // Its sources are listed within a minute.
            'next_check_at' => null,
        ])->save();

        return $connection;
    }

    /**
     * Whether the connection reads the ledger; null when its keys were saved under another
     * APP_KEY and cannot say.
     */
    private function readsLedger(DataSourceConnection $connection, string $ledgerId): ?bool
    {
        try {
            return $connection->mobilePayConfig()->ledgerId === $ledgerId;
        } catch (DecryptException) {
            return null;
        }
    }
}
