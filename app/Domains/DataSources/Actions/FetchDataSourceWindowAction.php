<?php

declare(strict_types=1);

namespace App\Domains\DataSources\Actions;

use App\Domains\Connectors\Exceptions\ConnectorsExceptionInterface;
use App\Domains\Connectors\GitHub\Actions\PullGitHubSourceAction;
use App\Domains\Connectors\MobilePay\Actions\PullMobilePaySourceAction;
use App\Domains\Connectors\Mosparo\Actions\PullMosparoSourceAction;
use App\Domains\Connectors\Umami\Actions\PullUmamiSourceAction;
use App\Domains\DataSources\Enums\ConnectionKind;
use App\Domains\DataSources\Enums\DataSourceShape;
use App\Domains\DataSources\Models\DataSource;
use App\Domains\DataSources\Models\DataSourceWindow;
use App\Domains\Health\Actions\RunHealthCheckAction;
use App\Domains\Health\Exceptions\HealthExceptionInterface;
use App\Domains\Health\Exceptions\ProbeFailedException;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Shared\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Pulls one connected source for one period, in its own signed call, so a slow source
 * costs nobody else. Times are rounded to the step the source is pulled at, so the app's
 * cache sees the same range for as long as it keeps an answer.
 */
class FetchDataSourceWindowAction
{
    public const int EVERY_MINUTES = 5;

    /** A failing window is tried after 5, 10, 20, 40 and then every 80 minutes. */
    private const int MOST_DOUBLINGS = 4;

    /** However long an app caches, a source is asked at least this often. */
    public const int MAX_STEP_SECONDS = 86_400;

    /** The most one source can keep per period. */
    public const int MAX_STORED_BYTES = 262_144;

    public function __construct(
        private readonly SendSignedPullAction $send,
        private readonly PullUmamiSourceAction $pullUmami,
        private readonly PullMosparoSourceAction $pullMosparo,
        private readonly PullGitHubSourceAction $pullGitHub,
        private readonly PullMobilePaySourceAction $pullMobilePay,
        private readonly RunHealthCheckAction $runHealthCheckAction,
    ) {
    }

    /**
     * How often this source is asked for this period: as long as the app keeps an answer,
     * at least every five minutes' worth, and never less often than the period is long.
     */
    public static function stepSeconds(DataSource $source, Period $period): int
    {
        return min(max(self::EVERY_MINUTES * 60, (int) $source->cache_seconds), self::MAX_STEP_SECONDS, self::periodSeconds($period));
    }

    public function execute(int $id): void
    {
        $window = DataSourceWindow::query()->whereKey($id)->with('source.dataSourceConnection')->first();

        if ($window === null) {
            return;
        }

        $recorded = true;

        try {
            $this->store($window, $window->source->dataSourceConnection !== null && ! $window->source->dataSourceConnection->isFor(ConnectionKind::App) ? $this->pullFromService($window) : ($this->pull($window)[$window->source->key] ?? null));
        } catch (HealthExceptionInterface|ConnectorsExceptionInterface|ConnectionException $e) {
            $recorded = $this->failed($window, $e->getMessage());
        } catch (Throwable $e) {
            // Whatever an app answered, the failure is shown on the source.
            report($e);
            $recorded = $this->failed($window, __('data_sources.errors.bad_shape'));
        }

        // A pull whose answer no longer counts is not judged either.
        if ($recorded) {
            $this->runHealthChecks($window);
        }
    }

    /**
     * The health checks reading this period judge the pull, which also keeps the period
     * asked for while they exist. Only the period's own resolution: a chart's finer one is
     * the same figure again.
     */
    private function runHealthChecks(DataSourceWindow $window): void
    {
        if ($window->bucket !== Period::from($window->period)->bucketSeconds()) {
            return;
        }

        $checks = HealthCheck::query()->whereReadsDataSource($window->source)->whereReadsPeriod($window->period)->whereMonitored()->whereNotArchived()->get();

        foreach ($checks as $check) {
            $this->runHealthCheckAction->execute($check, afterFetch: true);
        }
    }

    /**
     * @return array<mixed>
     */
    private function pull(DataSourceWindow $window): array
    {
        $source = $window->source;
        $token = $source->dataSourceConnection?->activeToken() ?? throw new ProbeFailedException(__('data_sources.errors.token_unreadable'));
        $period = Period::from($window->period);
        $step = self::stepSeconds($source, $period);
        $to = (int) (floor(now()->getTimestamp() / $step) * $step);

        $response = $this->send->execute($source->url, $token, ['bucket' => $window->bucket, 'from' => $to - self::periodSeconds($period), 'only' => (string) $source->key, 'timezone' => $source->dataSourceConnection->timezone, 'to' => $to]);

        // During a rotation that is the app having moved on: find out which token it holds now.
        if ($response->status() === 404 && $source->dataSourceConnection->uses_previous) {
            $source->dataSourceConnection->update(['next_check_at' => null]);
        }

        $answer = $this->send->json($response);

        return is_array($answer['sources'] ?? null) ? $answer['sources'] : throw new ProbeFailedException(__('data_sources.errors.not_json'));
    }

    /**
     * The same range an app would be asked for, read from the service's API instead.
     *
     * @return array{shape: string, data: mixed}
     */
    private function pullFromService(DataSourceWindow $window): array
    {
        $source = $window->source;
        $period = Period::from($window->period);
        $step = self::stepSeconds($source, $period);
        $to = (int) (floor(now()->getTimestamp() / $step) * $step);
        $from = CarbonImmutable::createFromTimestamp($to - self::periodSeconds($period));
        $until = CarbonImmutable::createFromTimestamp($to);
        $timezone = (string) $source->dataSourceConnection?->timezone;

        try {
            return match ($source->dataSourceConnection?->kind) {
                ConnectionKind::Umami => $this->pullUmami->execute($source, $from, $until, $window->bucket, $timezone),
                ConnectionKind::Mosparo => $this->pullMosparo->execute($source, $from, $until, $timezone),
                ConnectionKind::GitHub => $this->pullGitHub->execute($source, $from, $until),
                ConnectionKind::MobilePay => $this->pullMobilePay->execute($source, $from, $until, $timezone),
                default => throw new ProbeFailedException(__('data_sources.errors.no_answer')),
            };
        } catch (DecryptException) {
            throw new ProbeFailedException(__('connectors.secret_unreadable'));
        }
    }

    /** Rounded, as the two clock readings can fall either side of a second. */
    private static function periodSeconds(Period $period): int
    {
        return (int) (round((now()->getTimestamp() - $period->since()->getTimestamp()) / 300) * 300);
    }

    private function store(DataSourceWindow $window, mixed $answer): void
    {
        $source = $window->source;

        if (! is_array($answer)) {
            throw new ProbeFailedException(__('data_sources.errors.no_answer'));
        }

        if (is_string($answer['error'] ?? null)) {
            throw new ProbeFailedException(__('data_sources.errors.app', ['error' => mb_substr($answer['error'], 0, 100)]));
        }

        $shape = $source->shape;

        if (! $shape instanceof DataSourceShape || ($answer['shape'] ?? null) !== $shape->value || ! array_key_exists('data', $answer)) {
            throw new ProbeFailedException(__('data_sources.errors.bad_shape'));
        }

        $data = $shape->normalize($answer['data']);

        if (strlen((string) json_encode($data)) > self::MAX_STORED_BYTES) {
            throw new ProbeFailedException(__('data_sources.errors.too_large', ['max' => self::MAX_STORED_BYTES / 1024]));
        }

        $window->update(['data' => $data, 'has_data' => true, 'error' => null, 'failures' => 0, 'fetched_at' => now()]);
        $source->update(['last_fetched_at' => now(), 'last_ok_at' => now(), 'last_error' => null]);
    }

    /**
     * @return bool false when what it said no longer counts, and nothing was kept
     */
    private function failed(DataSourceWindow $window, string $message): bool
    {
        // The app moved while this was asking the old address: what that said no longer counts.
        if ($window->source->fresh()?->url !== $window->source->url) {
            return false;
        }

        $message = SendSignedPullAction::cleanMessage($message);

        // What was last pulled stays on screen, with the age it really has.
        $window->update(['error' => $message, 'failures' => $window->failures + 1, 'next_fetch_at' => now()->addMinutes(self::EVERY_MINUTES * 2 ** min($window->failures, self::MOST_DOUBLINGS))]);
        $window->source->update(['last_fetched_at' => now(), 'last_error' => $message]);

        return true;
    }
}
