<?php

declare(strict_types=1);

namespace App\Domains\Connectors\Mosparo\Queries;

use App\Domains\Connectors\Mosparo\Exceptions\MosparoRequestFailedException;
use App\Domains\Connectors\Mosparo\Support\MosparoClient;
use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\Shared\Enums\Period;
use App\Domains\Shared\Support\StatisticsCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * What the mosparo page shows: a project's submissions over the period, and every day
 * mosparo still keeps.
 */
class MosparoDashboardQuery
{
    public function __construct(
        private readonly MosparoClient $client,
    ) {
    }

    /**
     * Valid and spam submissions, the share that was spam, and the requests delayed or
     * blocked, over the period's days (today included) and as many days before. mosparo only
     * counts whole days, so a period is a number of them; days it no longer keeps count 0.
     *
     * @return array{current: array{valid: int, spam: int, spam_rate: float|null, delayed: int, blocked: int}, previous: array{valid: int, spam: int, spam_rate: float|null, delayed: int, blocked: int}, last_submission_at: string|null}
     *
     * @throws MosparoRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function summary(DataSourceConnection $connection, Period $period): array
    {
        $statistics = $this->kept($connection);
        $days = self::days($period);
        $today = CarbonImmutable::now($connection->timezone)->startOfDay();

        return [
            'current' => self::figures($statistics['days'], $today->subDays($days - 1), $today),
            'previous' => self::figures($statistics['days'], $today->subDays(2 * $days - 1), $today->subDays($days)),
            'last_submission_at' => $statistics['last_submission_at'],
        ];
    }

    /**
     * Each of the period's days, today included, oldest first.
     *
     * @return list<array{t: string, valid: int, spam: int}>
     *
     * @throws MosparoRequestFailedException
     * @throws DecryptException when the keys were saved under another APP_KEY
     */
    public function series(DataSourceConnection $connection, Period $period): array
    {
        $statistics = $this->kept($connection);
        $today = CarbonImmutable::now($connection->timezone)->startOfDay();
        $series = [];

        for ($day = $today->subDays(self::days($period) - 1); $day <= $today; $day = $day->addDay()) {
            $numbers = $statistics['days'][$day->format('Y-m-d')] ?? ['valid' => 0, 'spam' => 0];
            $series[] = ['t' => $day->toIso8601String(), 'valid' => $numbers['valid'], 'spam' => $numbers['spam']];
        }

        return $series;
    }

    /** How many days a period covers; mosparo has nothing finer than a day. */
    public static function days(Period $period): int
    {
        return match ($period) {
            Period::Month => 30,
            Period::Week => 7,
            default => 1,
        };
    }

    /**
     * Every day mosparo keeps, asked once for the page.
     *
     * @return array{last_submission_at: string|null, days: array<string, array{valid: int, spam: int, delayed: int, blocked: int}>}
     *
     * @throws MosparoRequestFailedException
     */
    private function kept(DataSourceConnection $connection): array
    {
        return StatisticsCache::remember('mosparo-kept', Period::Day, [$connection->id, $connection->url], function () use ($connection): array {
            $statistics = $this->client->statistics($connection->mosparoCredentials());

            return ['last_submission_at' => $statistics['last_submission_at'], 'days' => $statistics['days']];
        });
    }

    /**
     * @param  array<string, array{valid: int, spam: int, delayed: int, blocked: int}>  $days
     * @return array{valid: int, spam: int, spam_rate: float|null, delayed: int, blocked: int}
     */
    private static function figures(array $days, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sum = ['valid' => 0, 'spam' => 0, 'delayed' => 0, 'blocked' => 0];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            foreach ($sum as $key => $count) {
                $sum[$key] = $count + ($days[$day->format('Y-m-d')][$key] ?? 0);
            }
        }

        $submissions = $sum['valid'] + $sum['spam'];

        return [...$sum, 'spam_rate' => $submissions > 0 ? round($sum['spam'] / $submissions * 100, 1) : null];
    }
}
