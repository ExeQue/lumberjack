<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Actions;

use App\Domains\Connectors\MobilePay\Enums\MobilePaySource;
use App\Domains\Connectors\MobilePay\Exceptions\MobilePayRequestFailedException;
use App\Domains\Connectors\MobilePay\Support\MobilePayClient;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use Carbon\CarbonImmutable;

/**
 * One of a Vipps MobilePay ledger's sources over a range, as an app would answer for it.
 */
class PullMobilePaySourceAction
{
    public function __construct(
        private readonly MobilePayClient $client,
    ) {
    }

    /**
     * The ledger is booked per day, so a range is read as as many ledger dates, today
     * included: a day, a week or a month. A series has a point per day whatever the bucket.
     *
     * @return array{shape: string, data: mixed}
     *
     * @throws MobilePayRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $connection = $source->dataSourceConnection ?? throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unknown_source'));
        $kind = MobilePaySource::tryFrom((string) $source->key) ?? throw new MobilePayRequestFailedException(__('connectors.mobilepay.errors.unknown_source'));
        $config = $connection->mobilePayConfig();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $days = $this->client->days($config->credentials, $config->ledgerId, $today->subDays(max(1, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400)) - 1), $today);

        if ($kind->shape() === DataSourceShape::Number) {
            return ['shape' => DataSourceShape::Number->value, 'data' => $kind->figure(MobilePayClient::totals($days))];
        }

        $points = [];

        foreach ($days as $date => $figures) {
            $points[] = ['at' => CarbonImmutable::parse($date, $timezone)->getTimestamp(), 'value' => $kind->figure($figures)];
        }

        return ['shape' => DataSourceShape::Series->value, 'data' => $points];
    }
}
