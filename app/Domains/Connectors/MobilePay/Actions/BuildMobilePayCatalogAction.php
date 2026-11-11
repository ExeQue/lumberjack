<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Actions;

use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\DataSources\Models\DataSourceConnection;

/**
 * The sources a Vipps MobilePay ledger offers, as an app's index would list them. The
 * ledger is looked up once, so keys that no longer work show on the connection.
 */
class BuildMobilePayCatalogAction
{
    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * @return array{version: int, complete: bool, sources: list<array{key: string, label: string, shape: string, group: list<string>}>}
     *
     * @throws MobilePayRequestFailedException
     */
    public function execute(DataSourceConnection $connection): array
    {
        $config = $connection->mobilePayConfig();
        $this->client->ledger($config->credentials);

        return ['version' => 1, 'complete' => true, 'sources' => array_map(fn (MobilePaySource $source): array => [
            'key' => $source->value,
            'label' => $source->label($config->currency),
            'shape' => $source->shape()->value,
            'group' => [],
        ], MobilePaySource::cases())];
    }
}
