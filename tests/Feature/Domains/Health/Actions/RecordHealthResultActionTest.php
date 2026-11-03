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
    $this->check = HealthCheck::factory()->for($this->project)->create(['degrade_after' => 2, 'recover_after' => 3]);
    // One result a minute, as the SDK's schedule sends them.
    $this->report = function (string $status, string $host = 'web-1'): void {
        $this->travel(1)->minutes();
        app(RecordHealthResultAction::class)->execute($this->check, new HealthResultData(HealthResultStatus::from($status), 'msg '.$status, host: $host, at: now()->toImmutable()));
    };
    $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
});

describe('the state machine', function () {
    it('goes down after degrade_after failures and recovers after recover_after successes', function () {
        ($this->report)('ok');
        ($this->report)('failed');

        expect($this->check->refresh()->status)->toBe(HealthStatus::Ok);

        ($this->report)('failed');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);

        ($this->report)('ok');
        ($this->report)('ok');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);

        ($this->report)('ok');
        expect($this->check->refresh()->status)->toBe(HealthStatus::Ok);
    });

    it('degrades on warnings instead of going down', function () {
        ($this->report)('warning');
        ($this->report)('warning');

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

describe('outages and uptime', function () {
    it('opens an outage when the check goes bad and closes it when it recovers', function () {
        ($this->report)('warning');
        ($this->report)('warning');
        ($this->report)('failed');

        $outage = HealthOutage::sole();
        expect($outage->status)->toBe(HealthStatus::Down)
            ->and($outage->first_message)->toBe('msg warning')
            ->and($outage->ended_at)->toBeNull();

        foreach (range(1, 3) as $i) {
            ($this->report)('ok');
        }

        expect($outage->refresh()->ended_at)->not->toBeNull()
            ->and((int) $outage->started_at->diffInMinutes($outage->ended_at))->toBe(4);
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

    it('sends nothing for a muted check', function () {
        $this->check->update(['muted' => true]);

        ($this->report)('failed');
        ($this->report)('failed');

        Http::assertNothingSent();
        expect($this->check->refresh()->status)->toBe(HealthStatus::Down);
    });
});
