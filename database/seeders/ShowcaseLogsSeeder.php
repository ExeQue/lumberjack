<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Logs\Enums\LogLevel;
use App\Domains\Logs\Models\LogChannel;
use App\Domains\Logs\Models\LogEntry;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Database\Seeders\Support\ShowcaseCatalog;
use Illuminate\Database\Seeder;

/**
 * Log lines of the showcase projects over the last days, per channel, from debug to
 * warning: what an app writes while it works. The error lines are the ones behind log
 * issues and come with them (ShowcaseIssuesSeeder). A project whose channels already
 * have lines below error level is skipped.
 */
class ShowcaseLogsSeeder extends Seeder
{
    /** Lines an hour per channel at the busiest time. */
    private const int PEAK_PER_HOUR = 36;

    /**
     * What each channel says: level, message (with {n}, {ms}, {id}, {hex} filled in) and context keys.
     *
     * @var array<string, list<array{0: LogLevel, 1: string, 2: list<string>}>>
     */
    private const array LINES = [
        'default' => [
            [LogLevel::Info, 'Order #{n} placed, total {amount} DKK', ['order_id', 'customer_id']],
            [LogLevel::Info, 'User {id} signed in', ['user_id', 'ip']],
            [LogLevel::Debug, 'Cache miss for product:{slug}', ['key']],
            [LogLevel::Notice, 'Slow request: {route} took {ms} ms', ['route', 'duration_ms']],
            [LogLevel::Warning, 'Rate limit at 80% for client {id}', ['client_id', 'limit']],
            [LogLevel::Info, 'Queued {n} notifications', ['count']],
            [LogLevel::Debug, 'Feature flag new-checkout evaluated to {bool}', ['flag', 'user_id']],
        ],
        'payments' => [
            [LogLevel::Info, 'Payment intent pi_{hex} succeeded', ['payment_intent', 'amount']],
            [LogLevel::Notice, 'Payment intent pi_{hex} requires action (3-D Secure)', ['payment_intent']],
            [LogLevel::Info, 'Refund re_{hex} issued for order #{n}', ['refund', 'order_id']],
            [LogLevel::Warning, 'Stripe answered in {ms} ms', ['duration_ms']],
        ],
        'search' => [
            [LogLevel::Info, 'Reindexed {n} products', ['count', 'duration_ms']],
            [LogLevel::Warning, 'Meilisearch answered in {ms} ms', ['duration_ms', 'query']],
            [LogLevel::Debug, 'Search for "{term}" returned {n} hits', ['query', 'hits']],
        ],
        'webhooks' => [
            [LogLevel::Info, 'Delivered order.created to endpoint {id} in {ms} ms', ['endpoint_id', 'duration_ms']],
            [LogLevel::Warning, 'Endpoint {id} answered 503, retrying in {n} s', ['endpoint_id', 'attempt']],
            [LogLevel::Debug, 'Signed payload for endpoint {id}', ['endpoint_id']],
        ],
        'carriers' => [
            [LogLevel::Info, 'Rates synced from {carrier}: {n} services', ['carrier', 'count']],
            [LogLevel::Warning, '{carrier} API slow: {ms} ms', ['carrier', 'duration_ms']],
            [LogLevel::Info, 'Label printed for parcel {hex}', ['parcel', 'carrier']],
        ],
    ];

    public function run(): void
    {
        $bulk = new Bulk();
        $now = Showcase::now();

        foreach (Showcase::projects() as [$project, $spec]) {
            if (LogEntry::query()->where('project_id', $project->id)->where('level', '<', LogLevel::Error->value)->exists()) {
                continue;
            }

            foreach (LogChannel::query()->where('project_id', $project->id)->get() as $channel) {
                $lines = self::LINES[$channel->name] ?? self::LINES['default'];
                $row = Bulk::row(LogEntry::factory()->for($channel, 'channel'), ['project_id' => $project->id, 'environment' => 'production']);
                $hosts = [...$spec['hosts']['production'], ...$spec['workers']['production']];

                for ($hour = $now->subDays(ShowcaseCatalog::LOG_DAYS); $hour < $now; $hour = $hour->addHour()) {
                    for ($n = Showcase::poisson(self::PEAK_PER_HOUR * Showcase::load($hour, $spec['peak'] > 0 ? 'web' : 'worker')); $n > 0; $n--) {
                        $at = $hour->addMilliseconds(mt_rand(0, 3_599_999));

                        if ($at >= $now) {
                            continue;
                        }

                        [$level, $message, $keys] = $lines[mt_rand(0, count($lines) - 1)];
                        $bulk->push('log_entries', [...$row,
                            'level' => $level->value,
                            'message' => $this->fill($message),
                            'context' => json_encode($this->context($keys)),
                            'release' => Showcase::release($spec, $at),
                            'host' => Showcase::any($hosts),
                            'occurred_at' => Showcase::ms($at),
                        ]);
                    }
                }
            }
        }

        $bulk->flush();
    }

    private function fill(string $message): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => match ($m[1]) {
            'n' => (string) mt_rand(3, 48_000),
            'amount' => number_format(mt_rand(9_900, 289_900) / 100, 2, '.', ''),
            'ms' => (string) mt_rand(900, 4_800),
            'id' => (string) mt_rand(100, 9_999),
            'hex' => substr(Showcase::traceId(), 0, 24),
            'slug' => Showcase::any(['oak-desk-lamp', 'linen-throw', 'walnut-shelf', 'wool-rug-grey']),
            'route' => Showcase::any(['GET /search', 'GET /products', 'GET /admin/reports/sales']),
            'bool' => Showcase::any(['true', 'false']),
            'term' => Showcase::any(['lamp', 'oak', 'gift card', 'rug']),
            'carrier' => Showcase::any(['PostNord', 'GLS', 'DAO']),
            default => $m[0],
        }, $message);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, int|string>
     */
    private function context(array $keys): array
    {
        $context = [];

        foreach ($keys as $key) {
            $context[$key] = str_ends_with($key, '_id') || in_array($key, ['count', 'hits', 'attempt', 'duration_ms', 'amount', 'limit'], true) ? mt_rand(1, 9_999) : $this->fill('{hex}');
        }

        return $context;
    }
}
