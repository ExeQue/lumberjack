<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Ai\Enums\AiCallSource;
use App\Domains\Ai\Enums\AiCallStatus;
use App\Domains\Ai\Enums\AiOperation;
use App\Domains\Ai\Models\AiCall;
use App\Domains\Ai\Models\AiKey;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageDaily;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The model calls the showcase apps make: a month of single calls, on the app's own keys
 * and on keys its tenants brought (BYOK), by a few busy users and many quiet ones, with
 * rate limits now and then and one tenant whose key stopped working. Their hourly and
 * daily totals go back AGGREGATE_DAYS, so usage over long periods shows something. A
 * project with calls is skipped.
 *
 * The team gets the providers' list prices (see PRICES), one of which changed this month.
 *
 * The keys are made up (see KEYS) but fingerprinted the way the server does it, so
 * looking one up by its key finds it.
 */
class ShowcaseAiSeeder extends Seeder
{
    /** Days of single calls; their totals go back AGGREGATE_DAYS. */
    private const int DAYS = 30;

    private const int AGGREGATE_DAYS = 400;

    /** Users a call can be made by; a few of them make most. */
    private const int USERS = 60;

    /**
     * Per project: the keys it calls with. A key: [made-up key, provider, label, owner, share
     * of the project's calls, models => share].
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: array{0: string, 1: string}|null, 4: float, 5: array<string, float>}>>
     */
    private const array KEYS = [
        'storefront' => [
            ['sk-ant-showcase-storefront-primary', 'anthropic', 'prod-primary', null, 0.85, ['claude-sonnet-4-5' => 0.7, 'claude-haiku-4-5' => 0.3]],
            ['sk-showcase-storefront-fallback', 'openai', 'fallback', null, 0.15, ['gpt-5-mini' => 1.0]],
        ],
        'orders-api' => [
            ['sk-showcase-orders-embeddings', 'openai', 'search-embeddings', null, 1.0, ['text-embedding-3-small' => 1.0]],
        ],
        'customer-portal' => [
            ['sk-ant-showcase-portal', 'anthropic', 'portal', null, 0.45, ['claude-sonnet-4-5' => 0.6, 'claude-haiku-4-5' => 0.4]],
            ['sk-showcase-tenant-7', 'openai', 'byok', ['tenant', '7'], 0.3, ['gpt-5' => 0.5, 'gpt-5-mini' => 0.5]],
            ['sk-ant-showcase-tenant-12', 'anthropic', 'byok', ['tenant', '12'], 0.2, ['claude-opus-4-5' => 0.3, 'claude-sonnet-4-5' => 0.7]],
            // Its tenant rotated it at the provider and never updated the app: it fails since.
            ['sk-showcase-tenant-19', 'openai', 'byok', ['tenant', '19'], 0.05, ['gpt-5-mini' => 1.0]],
        ],
    ];

    /**
     * Names given to some keys in Lumberjack, by made-up key: shown before the label.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'sk-ant-showcase-storefront-primary' => 'Shop assistant',
        'sk-showcase-tenant-7' => 'Acme (own key)',
    ];

    /** Per project: calls an hour at the busiest time. */
    private const array PEAK = [
        'storefront' => 9,
        'orders-api' => 24,
        'customer-portal' => 14,
    ];

    /**
     * Team-wide list prices: [provider, model, input, output, cache read, cache write], per
     * million tokens.
     *
     * @var list<array{0: string, 1: string, 2: float, 3: float, 4: float|null, 5: float|null}>
     */
    private const array PRICES = [
        ['anthropic', 'claude-opus-4-5', 5, 25, 0.5, 6.25],
        ['anthropic', 'claude-sonnet-4-5', 3, 15, 0.3, 3.75],
        ['anthropic', 'claude-haiku-4-5', 1, 5, 0.1, 1.25],
        ['openai', 'gpt-5', 1.25, 10, 0.125, null],
        ['openai', 'gpt-5-mini', 0.25, 2, 0.025, null],
        ['openai', 'text-embedding-3-*', 0.02, 0, null, null],
    ];

    /** Days since gpt-5-mini got cheaper. */
    private const int PRICE_CHANGE_DAYS = 12;

    /** The key that stopped working, and how many days ago. */
    private const string BROKEN_KEY = 'sk-showcase-tenant-19';

    private const int BROKEN_DAYS = 3;

    public function run(): void
    {
        $bulk = new Bulk();
        $now = Showcase::now();

        foreach (Showcase::projects() as [$project, $spec]) {
            $keys = self::KEYS[$spec['slug']] ?? null;

            if ($keys === null || AiCall::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $peak = self::PEAK[$spec['slug']];
            $hosts = $spec['hosts']['production'] ?? ['web-1'];
            $traces = DB::table('request_runs')->where('project_id', $project->id)->whereNotNull('trace_id')
                ->where('occurred_at', '>=', $now->subDays(self::DAYS))->orderBy('id')->limit(500)->pluck('trace_id')->all();
            $callRow = Bulk::row(AiCall::factory()->forProject($project), ['environment' => 'production', 'source' => AiCallSource::LaravelAi]);
            $hourlyRow = Bulk::row(AiUsageHourly::factory()->forProject($project), ['environment' => 'production']);
            $dailyRow = Bulk::row(AiUsageDaily::factory()->forProject($project), ['environment' => 'production']);
            $hourly = [];
            $daily = [];

            foreach ($keys as [$secret, $provider, $label, $owner, $share, $models]) {
                $fingerprint = $this->fingerprint($project, $provider, $secret);

                $bulk->add(AiKey::factory()->forProject($project)->labeled($label), [
                        // Bulk skips model events, so the uuid is not made for it.
                        'uuid' => Showcase::uuid(),
                        'name' => self::NAMES[$secret] ?? null,
                        'owner_type' => $owner[0] ?? null,
                        'owner_id' => $owner[1] ?? null,
                        'fingerprint' => $fingerprint,
                        'provider' => $provider,
                        'driver' => $provider,
                        'first_seen_at' => $now->subDays(self::AGGREGATE_DAYS)->setTime(9, 0),
                        'last_seen_at' => $now->subMinutes(mt_rand(1, 30)),
                    ]);

                // The month of single calls, added to the totals as the server would.
                for ($hour = $now->subDays(self::DAYS)->startOfHour(); $hour < $now; $hour = $hour->addHour()) {
                    for ($n = Showcase::poisson($peak * $share * Showcase::load($hour)); $n > 0; $n--) {
                        $at = $hour->addSeconds(mt_rand(0, 3599));

                        if ($at >= $now) {
                            continue;
                        }

                        $model = (string) Showcase::pick($models);
                        $call = $this->simulateCall($provider, $model, $secret, $at, $now);
                        [$actorType, $actorId] = $this->actor();

                        $bulk->push('ai_calls', [
                            ...$callRow,
                            ...$call,
                            'fingerprint' => $fingerprint,
                            'provider' => $provider,
                            'driver' => $provider,
                            'model' => $model,
                            'actor_type' => $actorType,
                            'actor_id' => $actorId,
                            'owner_type' => $owner[0] ?? null,
                            'owner_id' => $owner[1] ?? null,
                            'invocation_id' => Showcase::uuid(),
                            'step' => 1,
                            'trace_id' => $traces !== [] && Showcase::chance(0.4) ? Showcase::any($traces) : null,
                            'release' => Showcase::release($spec, $at),
                            'host' => Showcase::any($hosts),
                            'occurred_at' => Showcase::ms($at),
                        ]);

                        $this->add($hourly, [$provider, $model, $fingerprint, $owner[0] ?? '', $owner[1] ?? '', $hour->format('Y-m-d H:i:s')], $call);
                        $this->add($daily, [$provider, $model, $fingerprint, $owner[0] ?? '', $owner[1] ?? '', $actorType ?? '', $actorId ?? '', $at->format('Y-m-d')], $call);
                    }
                }

                // Before the single calls kept: one total a day, at noon, about what a day held.
                for ($day = self::DAYS + 1; $day <= self::AGGREGATE_DAYS; $day++) {
                    $noon = $now->subDays($day)->setTime(12, 0);

                    foreach ($models as $model => $modelShare) {
                        for ($n = Showcase::poisson($peak * $share * $modelShare * 24 * 0.45 * Showcase::load($noon) * $this->growth($day)); $n > 0; $n--) {
                            $call = $this->simulateCall($provider, $model, $secret, $noon, $now);
                            [$actorType, $actorId] = $this->actor();

                            $this->add($hourly, [$provider, $model, $fingerprint, $owner[0] ?? '', $owner[1] ?? '', $noon->format('Y-m-d H:i:s')], $call);
                            $this->add($daily, [$provider, $model, $fingerprint, $owner[0] ?? '', $owner[1] ?? '', $actorType ?? '', $actorId ?? '', $noon->format('Y-m-d')], $call);
                        }
                    }
                }
            }

            foreach ($hourly as $key => $totals) {
                [$provider, $model, $fingerprint, $ownerType, $ownerId, $hour] = explode("\0", $key);
                $bulk->push('ai_usage_hourly', [...$hourlyRow, 'provider' => $provider, 'model' => $model, 'fingerprint' => $fingerprint, 'owner_type' => $ownerType, 'owner_id' => $ownerId, 'hour' => $hour, ...$totals]);
            }

            foreach ($daily as $key => $totals) {
                [$provider, $model, $fingerprint, $ownerType, $ownerId, $actorType, $actorId, $day] = explode("\0", $key);
                $bulk->push('ai_usage_daily', [
                    ...$dailyRow,
                    'provider' => $provider,
                    'model' => $model,
                    'fingerprint' => $fingerprint,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'day' => $day,
                    ...$totals,
                ]);
            }
        }

        $bulk->flush();

        foreach (Showcase::projects() as [$project]) {
            $this->prices($project, $now);
        }
    }

    /**
     * The providers' list prices for the whole team, per million tokens in US dollars, once
     * per team: gpt-5-mini got cheaper PRICE_CHANGE_DAYS ago, so a month spans two prices.
     */
    private function prices(Project $project, CarbonImmutable $now): void
    {
        if (AiPrice::query()->where('team_id', $project->team_id)->exists()) {
            return;
        }

        $since = $now->subDays(self::AGGREGATE_DAYS + 1)->startOfDay();

        foreach (self::PRICES as [$provider, $model, $input, $output, $cacheRead, $cacheWrite]) {
            AiPrice::factory()->forTeamOf($project)->model($model, $provider)->validFrom($since)
                ->costing($input, $output, $cacheRead, $cacheWrite)->create();
        }

        AiPrice::factory()->forTeamOf($project)->model('gpt-5-mini', 'openai')
            ->validFrom($now->subDays(self::PRICE_CHANGE_DAYS)->startOfDay())
            ->costing(0.2, 1.6, 0.02)->create();
    }

    /**
     * The fingerprint the server keeps for a key: an HMAC, with the project's secret, of the
     * SHA-256 the app sends.
     */
    private function fingerprint(Project $project, string $provider, string $key): string
    {
        return hash_hmac('sha256', hash('sha256', $provider.':'.$key), $project->ai_key_secret);
    }

    /**
     * What one call used and how it went: tokens by model, a long tail of durations, rate
     * limits now and then, and nothing but failures on the broken key once it broke.
     *
     * @return array<string, mixed>
     */
    private function simulateCall(string $provider, string $model, string $key, CarbonImmutable $at, CarbonImmutable $now): array
    {
        $embeddings = str_starts_with($model, 'text-embedding');
        $broken = $key === self::BROKEN_KEY && $at >= $now->subDays(self::BROKEN_DAYS);
        $failed = $broken || Showcase::chance(0.012);

        if ($failed) {
            return [
                'operation' => ($embeddings ? AiOperation::Embeddings : AiOperation::Text)->value,
                'input_tokens' => null,
                'output_tokens' => null,
                'cache_read_tokens' => null,
                'cache_write_tokens' => null,
                'reasoning_tokens' => null,
                'duration_ms' => round(Showcase::latency(180) / 1000, 1),
                'status' => AiCallStatus::Error->value,
                'error' => $broken ? 'AuthenticationException' : Showcase::any(['RateLimitedException', 'RateLimitedException', 'ProviderOverloadedException']),
            ];
        }

        if ($embeddings) {
            return [
                'operation' => AiOperation::Embeddings->value,
                'input_tokens' => (int) round(380 * exp(0.6 * Showcase::gauss())),
                'output_tokens' => null,
                'cache_read_tokens' => null,
                'cache_write_tokens' => null,
                'reasoning_tokens' => null,
                'duration_ms' => round(Showcase::latency(120) / 1000, 1),
                'status' => AiCallStatus::Ok->value,
                'error' => null,
            ];
        }

        // Bigger models get longer prompts and answer slower; a cached system prompt is read back often.
        $size = match (true) {
            str_contains($model, 'opus') || $model === 'gpt-5' => 2.2,
            str_contains($model, 'haiku') || str_contains($model, 'mini') => 0.6,
            default => 1.0,
        };
        $input = (int) round(1600 * $size * exp(0.7 * Showcase::gauss()));
        $cached = $provider === 'anthropic' && Showcase::chance(0.6) ? (int) round($input * (0.4 + 0.4 * Showcase::unit())) : 0;
        $written = $provider === 'anthropic' && $cached === 0 && Showcase::chance(0.15) ? (int) round($input * 0.5) : 0;

        return [
            'operation' => AiOperation::Text->value,
            'input_tokens' => $input,
            'output_tokens' => (int) round(320 * $size * exp(0.6 * Showcase::gauss())),
            'cache_read_tokens' => $provider === 'anthropic' ? $cached : null,
            'cache_write_tokens' => $provider === 'anthropic' ? $written : null,
            'reasoning_tokens' => $model === 'gpt-5' ? (int) round(600 * exp(0.8 * Showcase::gauss())) : null,
            'duration_ms' => round(Showcase::latency(1400 * $size) / 1000, 1),
            'status' => AiCallStatus::Ok->value,
            'error' => null,
        ];
    }

    /**
     * Adds a call to the total its key names.
     *
     * @param  array<string, array<string, int|float>>  $totals
     * @param  list<string>  $key
     * @param  array<string, mixed>  $call
     */
    private function add(array &$totals, array $key, array $call): void
    {
        $id = implode("\0", $key);
        $totals[$id] ??= ['calls' => 0, 'errors' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0, 'duration_ms_sum' => 0.0];
        $totals[$id]['calls']++;
        $totals[$id]['errors'] += $call['status'] === AiCallStatus::Error->value ? 1 : 0;
        $totals[$id]['input_tokens'] += $call['input_tokens'] ?? 0;
        $totals[$id]['output_tokens'] += $call['output_tokens'] ?? 0;
        $totals[$id]['cache_read_tokens'] += $call['cache_read_tokens'] ?? 0;
        $totals[$id]['cache_write_tokens'] += $call['cache_write_tokens'] ?? 0;
        $totals[$id]['reasoning_tokens'] += $call['reasoning_tokens'] ?? 0;
        $totals[$id]['duration_ms_sum'] += $call['duration_ms'] ?? 0;
    }

    /**
     * Who made it: a user (a few make most), or a scheduled job.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function actor(): array
    {
        return Showcase::chance(0.1)
            ? ['system', 'scheduler']
            : ['user', (string) (1 + (int) floor(self::USERS * Showcase::unit() ** 3))];
    }

    /**
     * The apps grew over the year: a day further back had fewer calls.
     */
    private function growth(int $daysAgo): float
    {
        return 0.4 + 0.6 * (1 - $daysAgo / self::AGGREGATE_DAYS);
    }
}
