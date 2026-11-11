<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Actions;

use App\Domains\Connectors\Mosparo\Enums\MosparoSource;
use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Connectors\Mosparo\Support\MosparoClient;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use Carbon\CarbonImmutable;

/**
 * One of a mosparo project's sources over a range, as an app would answer for it.
 */
class PullMosparoSourceAction
{
    public function __construct(
        private readonly MosparoClient $client,
    ) {
    }

    /**
     * mosparo counts whole days, so a range is read as as many days, today included: a day,
     * a week or a month. A series has a point per day whatever the bucket.
     *
     * @return array{shape: string, data: mixed}
     *
     * @throws MosparoRequestFailedException
     */
    public function execute(DataSource $source, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $connection = $source->dataSourceConnection ?? throw new MosparoRequestFailedException(__('connectors.mosparo.errors.not_mosparo'));
        $kind = MosparoSource::tryFrom((string) $source->key) ?? throw new MosparoRequestFailedException(__('connectors.mosparo.errors.unknown_source'));
        $kept = $this->client->statistics($connection->mosparoCredentials())['days'];
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $none = ['valid' => 0, 'spam' => 0, 'delayed' => 0, 'blocked' => 0];
        $days = [];

        for ($day = $today->subDays(max(1, (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400)) - 1); $day <= $today; $day = $day->addDay()) {
            $days[$day->getTimestamp()] = $kept[$day->format('Y-m-d')] ?? $none;
        }

        if ($kind->shape() === DataSourceShape::Number) {
            $totals = $none;

            foreach ($days as $numbers) {
                foreach ($totals as $key => $count) {
                    $totals[$key] = $count + $numbers[$key];
                }
            }

            return ['shape' => DataSourceShape::Number->value, 'data' => $kind->figure($totals)];
        }

        $points = [];

        foreach ($days as $at => $numbers) {
            $points[] = ['at' => $at, 'value' => $kind->figure($numbers)];
        }

        return ['shape' => DataSourceShape::Series->value, 'data' => $points];
    }
}
