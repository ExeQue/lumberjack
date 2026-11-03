<?php

declare(strict_types=1);

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Enums\HealthStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Health\Models\HealthOutage;
use App\Domains\Health\Queries\HealthChecksQuery;
use App\Domains\Projects\Models\Project;
use App\Domains\Shared\Enums\Period;
use Illuminate\Support\Facades\Http;

covers(RecordHealthResultAction::class, HealthChecksQuery::class);

beforeEach(function () {
    Http::fake(['*' => Http::response(null, 204)]);
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $channel = NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]);
    foreach ([AlertRuleType::HealthDegraded, AlertRuleType::HealthDown, AlertRuleType::HealthRecovered] as $type) {
        AlertRule::factory()->for($this->project)->create(['type' => $type])->channels()->attach($channel);
    }
    $this->check = HealthCheck::factory()->for($this->project)->create(['degrade_after' => 2, 'down_after' => 2, 'recover_after' => 3]);
    // One result a minute, as the SDK's schedule sends them.
    $this->report = function (string $status, string $host = 'web-1'): void {
        $this->travel(1)->minutes();
        app(RecordHealthResultAction::class)->execute($this->check, new HealthResultData(HealthResultStatus::from($status), 'msg '.$status, host: $host, at: now()->toImmutable()));
    };
    $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
});

describe('the state machine', function () {
    it('goes straight down when down_after equals degrade_after, and recovers after recover_after successes', function () {
        ($this->report)('ok');
        ($this->report)('failed');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Ok);

        ($this->report)('failed');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);

        ($this->report)('ok');
        ($this->report)('ok');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Recovering);

        ($this->report)('ok');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Ok);
    });

    it('degrades on warnings instead of going down', function () {
        ($this->report)('warning');
        ($this->report)('warning');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Degraded);

        // The warnings do not count toward down; it takes down_after failures of its own.
        ($this->report)('failed');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Degraded);

        ($this->report)('failed');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);
    });

    it('takes the worst of its hosts, so one failing server is not hidden by the others', function () {
        foreach (range(1, 3) as $i) {
            ($this->report)('failed', 'web-1');
            ($this->report)('ok', 'web-2');
        }

        $check = $this->check->refresh()->load('hosts');

        expect($check->status)->toBe(HealthStatus::Down)
            ->and($check->hosts->pluck('status', 'host')->map->value->all())->toBe(['web-1' => 'down', 'web-2' => 'ok']);
    });

    it('shows a check that stopped reporting as stale, not down, and leaves it out of uptime', function () {
        foreach (range(1, 4) as $i) {
            ($this->report)('ok');
        }

        $this->travel(2)->minutes();
        expect(HealthChecksQuery::state($this->check->refresh()->load('hosts')))->toBe('ok');

        // Results came a minute apart, so three minutes of quiet is stale.
        $this->travel(5)->minutes();
        $row = app(HealthChecksQuery::class)->rows($this->project, Period::Day)[0];

        expect($row['state'])->toBe('stale')->and($row['uptime'])->toBeNull();
    });
});

describe('a separate down threshold', function () {
    beforeEach(function () {
        $this->check->update(['down_after' => 5]);
        $this->statuses = function (array $results): array {
            return array_map(function (string $status): string {
                ($this->report)($status);

                return $this->check->refresh()->status->value ?? 'pending';
            }, $results);
        };
    });

    it('degrades after two failures and goes down after five', function () {
        expect(($this->statuses)(['ok', 'failed', 'failed', 'failed', 'failed', 'failed']))
            ->toBe(['ok', 'ok', 'degraded', 'degraded', 'degraded', 'down']);
    });

    it('stays degraded on warnings alone', function () {
        expect(($this->statuses)(array_fill(0, 10, 'warning')))->toBe(['pending', ...array_fill(0, 9, 'degraded')]);
    });

    it('starts counting failures again after a warning', function () {
        expect(($this->statuses)(['failed', 'failed', 'failed', 'failed', 'warning', 'failed', 'failed', 'failed', 'failed', 'failed']))
            ->toBe(['pending', 'degraded', 'degraded', 'degraded', 'degraded', 'degraded', 'degraded', 'degraded', 'degraded', 'down']);
    });

    it('clamps a down_after below degrade_after to degrade_after', function () {
        $this->check->update(['degrade_after' => 3, 'down_after' => 1]);

        expect($this->check->downAfter())->toBe(3)
            ->and(($this->statuses)(['failed', 'failed', 'failed']))->toBe(['pending', 'pending', 'down']);
    });

    it('uses five for a check without its own down_after', function () {
        $this->check->update(['down_after' => null]);

        expect($this->check->downAfter())->toBe(HealthCheck::DEFAULT_DOWN_AFTER);
    });

    it('sends a degraded alert and later a down alert for a failing check', function () {
        ($this->statuses)(['failed', 'failed']);
        expect(($this->titles)())->toBe(['Health check degraded in Shop']);

        ($this->statuses)(['failed', 'failed', 'failed']);
        expect(($this->titles)())->toBe(['Health check degraded in Shop', 'Health check down in Shop'])
            ->and(HealthOutage::sole()->status)->toBe(HealthStatus::Down);
    });
});

describe('recovering', function () {
    beforeEach(function () {
        ($this->report)('failed');
        ($this->report)('failed');
    });

    it('is recovering on good results until recover_after, then ok with one recovered alert', function () {
        ($this->report)('ok');
        ($this->report)('ok');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Recovering)
            ->and(HealthChecksQuery::state($this->check->load('hosts')))->toBe('recovering')
            ->and(HealthOutage::sole()->ended_at)->toBeNull()
            ->and(HealthOutage::sole()->status)->toBe(HealthStatus::Down)
            ->and(($this->titles)())->toBe(['Health check down in Shop']);

        ($this->report)('ok');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Ok)
            ->and(HealthOutage::sole()->ended_at)->not->toBeNull()
            ->and(($this->titles)())->toBe(['Health check down in Shop', 'Health check recovered in Shop']);
    });

    it('goes back down on a failure without a second down alert', function () {
        ($this->report)('ok');
        ($this->report)('failed');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Down)
            ->and(HealthOutage::count())->toBe(1)
            ->and(($this->titles)())->toBe(['Health check down in Shop']);
    });

    it('goes back to degraded, not down, when it was degraded', function () {
        $this->check->update(['down_after' => 5]);
        foreach (['ok', 'ok', 'ok', 'warning', 'warning', 'ok', 'warning'] as $status) {
            ($this->report)($status);
        }

        expect($this->check->refresh()->status)->toBe(HealthStatus::Degraded);
    });

    it('does not hide a down host behind a recovering one', function () {
        ($this->report)('failed', 'web-2');
        ($this->report)('failed', 'web-2');
        ($this->report)('ok');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);
    });
});

describe('outages and uptime', function () {
    it('opens an outage when the check goes bad and closes it when it recovers', function () {
        ($this->report)('warning');
        ($this->report)('warning');
        ($this->report)('failed');
        ($this->report)('failed');

        $outage = HealthOutage::sole();
        expect($outage->status)->toBe(HealthStatus::Down)
            ->and($outage->first_message)->toBe('msg warning')
            ->and($outage->ended_at)->toBeNull();

        foreach (range(1, 3) as $i) {
            ($this->report)('ok');
        }

        expect($outage->refresh()->ended_at)->not->toBeNull()
            ->and((int) $outage->started_at->diffInMinutes($outage->ended_at))->toBe(5);
    });

    it('counts down time against the period, degraded time not', function () {
        $this->travelTo(now()->startOfHour()->addMinutes(30));
        $this->check->forceFill(['created_at' => now()->subDay()])->save();
        HealthOutage::query()->create(['health_check_id' => $this->check->id, 'project_id' => $this->project->id, 'status' => HealthStatus::Down, 'started_at' => now()->subHours(6), 'ended_at' => now()->subHours(5)->subMinutes(48)]);
        HealthOutage::query()->create(['health_check_id' => $this->check->id, 'project_id' => $this->project->id, 'status' => HealthStatus::Degraded, 'started_at' => now()->subHours(3), 'ended_at' => now()->subHours(2)]);

        $row = app(HealthChecksQuery::class)->rows($this->project, Period::Day)[0];

        // 12 minutes down out of 24 hours.
        expect($row['uptime'])->toBe(round(1 - 12 / 1440, 5))
            ->and(collect($row['strip'])->pluck('s')->filter(fn ($s) => $s !== 'ok')->values()->all())->toBe(['down', 'degraded', 'degraded']);
    });
});

describe('alerts', function () {
    it('tells once when it goes down and once when it recovers', function () {
        ($this->report)('failed');
        ($this->report)('failed');
        ($this->report)('failed');

        expect(($this->titles)())->toBe(['Health check down in Shop']);

        foreach (range(1, 3) as $i) {
            ($this->report)('ok');
        }

        expect(($this->titles)())->toBe(['Health check down in Shop', 'Health check recovered in Shop']);
    });

    it('escalates from degraded to down once', function () {
        ($this->report)('warning');
        ($this->report)('warning');
        ($this->report)('failed');
        ($this->report)('failed');

        expect(($this->titles)())->toBe(['Health check degraded in Shop', 'Health check down in Shop']);
    });

    it('sends degraded, down and recovered through their own rules, each of which can be off', function () {
        AlertRule::query()->where('type', AlertRuleType::HealthDegraded)->update(['enabled' => false]);

        foreach (['warning', 'warning', 'failed', 'failed', 'ok', 'ok', 'ok'] as $status) {
            ($this->report)($status);
        }

        expect(($this->titles)())->toBe(['Health check down in Shop', 'Health check recovered in Shop']);
    });

    it('stays quiet when a flapping check goes down again within 30 minutes', function () {
        $flap = function (): void {
            ($this->report)('failed');
            ($this->report)('failed');
            foreach (range(1, 3) as $i) {
                ($this->report)('ok');
            }
        };

        $flap();
        $flap();
        $flap();

        expect(($this->titles)())->toBe(['Health check down in Shop', 'Health check recovered in Shop']);
    });

    it('takes the throttle window from the config', function () {
        config(['health.alert_throttle_minutes' => 3]);
        $flap = function (): void {
            foreach (['failed', 'failed', 'ok', 'ok', 'ok'] as $status) {
                ($this->report)($status);
            }
        };

        $flap();
        $this->travel(5)->minutes();
        $flap();

        expect(($this->titles)())->toBe(['Health check down in Shop', 'Health check recovered in Shop', 'Health check down in Shop', 'Health check recovered in Shop']);
    });

    it('sends nothing for a muted check', function () {
        $this->check->update(['muted' => true]);

        ($this->report)('failed');
        ($this->report)('failed');

        Http::assertNothingSent();
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);
    });
});
