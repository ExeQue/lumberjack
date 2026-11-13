<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Activity\Actions\RecordActivityAction;
use App\Domains\Activity\Models\Activity;
use App\Domains\Activity\Models\ActivityCount;
use Database\Seeders\Support\Bulk;
use Database\Seeders\Support\Showcase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The events the showcase apps record: a month of them, by group, with actors (a few busy
 * users and many quiet ones, a scheduler, some without one), subjects and payloads, some
 * on traces of the seeded requests. Their hourly counts go back a year, so widgets over
 * long periods show something. A project with events is skipped.
 */
class ShowcaseActivitySeeder extends Seeder
{
    /** Days of events; their counts go back COUNT_DAYS. */
    private const int DAYS = 30;

    private const int COUNT_DAYS = 365;

    /** Users an app's events are done by; a few of them do most. */
    private const int USERS = 50;

    /**
     * Per project: group => name => [events an hour at the busiest time, subject types, actor kind].
     * The actor kind is "user", "system" or "none".
     *
     * @var array<string, array<string, array<string, array{0: float, 1: list<string>, 2: string}>>>
     */
    private const array EVENTS = [
        'storefront' => [
            'billing' => [
                'order.placed' => [9, ['order', 'cart'], 'user'],
                'order.refunded' => [0.6, ['order'], 'user'],
                'payment.failed' => [0.9, ['order'], 'user'],
            ],
            'cart' => [
                'cart.created' => [22, ['cart'], 'user'],
                'cart.abandoned' => [7, ['cart'], 'none'],
            ],
        ],
        'orders-api' => [
            'auth' => [
                'user.signed_in' => [14, [], 'user'],
                'user.signed_up' => [1.5, [], 'user'],
            ],
            'teams' => [
                'team.created' => [0.8, ['team'], 'user'],
                'member.invited' => [2, ['team'], 'user'],
            ],
            'api' => [
                'token.created' => [0.7, ['token'], 'user'],
            ],
        ],
        'marketing-site' => [
            'default' => [
                'newsletter.subscribed' => [3, [], 'none'],
                'contact.submitted' => [0.8, [], 'none'],
            ],
        ],
        'fulfillment-worker' => [
            'imports' => [
                'import.started' => [1, ['import'], 'system'],
                'import.finished' => [0.95, ['import'], 'system'],
            ],
        ],
        'acme-booking' => [
            'bookings' => [
                'booking.created' => [4, ['booking', 'resource'], 'user'],
                'booking.cancelled' => [0.5, ['booking', 'resource'], 'user'],
            ],
        ],
        'customer-portal' => [
            'auth' => [
                'user.signed_in' => [8, [], 'user'],
            ],
            'documents' => [
                'document.downloaded' => [5, ['document', 'folder'], 'user'],
            ],
            // An upload is done to the image and to the gallery it lands in.
            'gallery' => [
                'image.uploaded' => [6, ['image', 'gallery'], 'user'],
                'image.deleted' => [0.8, ['image', 'gallery'], 'user'],
            ],
        ],
    ];

    public function run(): void
    {
        $bulk = new Bulk();
        $now = Showcase::now();

        foreach (Showcase::projects() as [$project, $spec]) {
            $catalog = self::EVENTS[$spec['slug']] ?? null;

            if ($catalog === null || Activity::query()->where('project_id', $project->id)->exists()) {
                continue;
            }

            $row = Bulk::row(Activity::factory()->forProject($project), ['environment' => 'production', 'payload' => null]);
            $traces = DB::table('request_runs')->where('project_id', $project->id)->whereNotNull('trace_id')
                ->where('occurred_at', '>=', $now->subDays(self::DAYS))->orderBy('id')->limit(500)->pluck('trace_id')->all();
            $shape = $spec['peak'] > 0 ? 'web' : 'worker';
            $hosts = $spec['hosts']['production'] ?? ['web-1'];
            $counts = [];
            $oversized = false;

            for ($hour = $now->subDays(self::DAYS)->startOfHour(); $hour < $now; $hour = $hour->addHour()) {
                $load = Showcase::load($hour, $shape);

                foreach ($catalog as $group => $events) {
                    foreach ($events as $name => [$peak, $subjectTypes, $actor]) {
                        for ($n = Showcase::poisson($peak * $load); $n > 0; $n--) {
                            $at = $hour->addSeconds(mt_rand(0, 3599));

                            if ($at >= $now) {
                                continue;
                            }

                            [$actorType, $actorId] = $this->actor($actor);
                            $payload = $this->payload($name);

                            // One payload too large to keep, to show how it is marked.
                            if (! $oversized && $payload !== null && Showcase::chance(0.01)) {
                                $payload = RecordActivityAction::TRUNCATED;
                                $oversized = true;
                            }

                            $bulk->push('activities', [
                                ...$row,
                                'group' => $group,
                                'name' => $name,
                                'actor_type' => $actorType,
                                'actor_id' => $actorId,
                                'subjects' => $subjectTypes !== [] ? json_encode(array_map($this->subject(...), $subjectTypes)) : null,
                                'payload' => $payload !== null ? json_encode($payload) : null,
                                'trace_id' => $traces !== [] && Showcase::chance(0.3) ? Showcase::any($traces) : null,
                                'release' => Showcase::release($spec, $at),
                                'host' => Showcase::any($hosts),
                                'occurred_at' => Showcase::ms($at),
                            ]);

                            $key = $group."\0".$name."\0".$hour->format('Y-m-d H:i:s');
                            $counts[$key] = ($counts[$key] ?? 0) + 1;
                        }
                    }
                }
            }

            $countRow = Bulk::row(ActivityCount::factory()->forProject($project), ['environment' => 'production']);

            foreach ($counts as $key => $count) {
                [$group, $name, $hour] = explode("\0", $key);
                $bulk->push('activity_counts', [...$countRow, 'group' => $group, 'name' => $name, 'hour' => $hour, 'count' => $count]);
            }

            // Before the events kept: one count a day, at noon, about what a day held.
            for ($day = self::DAYS + 1; $day <= self::COUNT_DAYS; $day++) {
                $noon = $now->subDays($day)->setTime(12, 0);

                foreach ($catalog as $group => $events) {
                    foreach ($events as $name => [$peak]) {
                        $count = Showcase::poisson($peak * 24 * 0.45 * Showcase::load($noon, $shape) * $this->growth($day));

                        if ($count > 0) {
                            $bulk->push('activity_counts', [...$countRow, 'group' => $group, 'name' => $name, 'hour' => $noon->format('Y-m-d H:i:s'), 'count' => $count]);
                        }
                    }
                }
            }
        }

        $bulk->flush();
    }

    /**
     * Who did it: a user (a few do most), the scheduler, or nobody.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function actor(string $kind): array
    {
        return match ($kind) {
            'user' => Showcase::chance(0.08) ? [null, null] : ['user', (string) (1 + (int) floor(self::USERS * Showcase::unit() ** 3))],
            'system' => ['system', 'scheduler'],
            default => [null, null],
        };
    }

    /**
     * A subject of this type: the few containers (galleries, folders, resources, teams) come
     * up again and again, the rest are new each time.
     *
     * @return array{type: string, id: string}
     */
    private function subject(string $type): array
    {
        $containers = ['gallery' => 12, 'folder' => 8, 'resource' => 6, 'team' => 20, 'cart' => 400];

        return ['type' => $type, 'id' => (string) (isset($containers[$type]) ? mt_rand(1, $containers[$type]) : mt_rand(1000, 9999))];
    }

    /**
     * A small payload of the kind each event carries.
     *
     * @return array<string, mixed>|null
     */
    private function payload(string $name): ?array
    {
        return match ($name) {
            'order.placed' => ['total' => mt_rand(99, 4999), 'currency' => 'DKK', 'items' => mt_rand(1, 6), 'shipping' => Showcase::any(['gls', 'postnord', 'dao'])],
            'order.refunded' => ['amount' => mt_rand(99, 1999), 'reason' => Showcase::any(['damaged', 'wrong_size', 'changed_mind'])],
            'payment.failed' => ['provider' => Showcase::any(['stripe', 'mobilepay']), 'code' => Showcase::any(['card_declined', 'insufficient_funds', 'expired_card'])],
            'cart.created' => ['items' => mt_rand(1, 4)],
            'cart.abandoned' => ['items' => mt_rand(1, 6), 'value' => mt_rand(99, 2999)],
            'user.signed_in' => ['method' => Showcase::any(['password', 'password', 'passkey', 'google'])],
            'user.signed_up' => ['plan' => Showcase::any(['free', 'free', 'pro', 'team']), 'source' => Showcase::any(['organic', 'ads', 'referral'])],
            'team.created', 'member.invited' => ['plan' => Showcase::any(['free', 'pro', 'team'])],
            'token.created' => ['abilities' => Showcase::any([['read'], ['read', 'write']])],
            'newsletter.subscribed' => ['list' => Showcase::any(['weekly', 'product-updates'])],
            'import.finished' => ['rows' => mt_rand(200, 5000), 'failed' => Showcase::chance(0.2) ? mt_rand(1, 12) : 0],
            'booking.created' => ['seats' => mt_rand(1, 8), 'resource' => Showcase::any(['meeting-room', 'desk', 'van'])],
            'document.downloaded' => ['format' => Showcase::any(['pdf', 'pdf', 'xlsx'])],
            'image.uploaded' => ['bytes' => mt_rand(200_000, 9_000_000), 'mime' => Showcase::any(['image/jpeg', 'image/jpeg', 'image/png', 'image/heic'])],
            default => null,
        };
    }

    /**
     * The apps grew over the year: a day further back had fewer events.
     */
    private function growth(int $daysAgo): float
    {
        return 0.55 + 0.45 * (1 - $daysAgo / self::COUNT_DAYS);
    }
}
