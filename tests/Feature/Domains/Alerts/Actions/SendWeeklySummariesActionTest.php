<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\SendWeeklySummariesAction;
use App\Domains\Alerts\Actions\UpdateAlertRuleAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendWeeklySummary;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Teams\CreatesTeamMembers;

covers(SendWeeklySummariesAction::class, UpdateAlertRuleAction::class);

uses(CreatesTeamMembers::class);

beforeEach(function () {
    // A Monday, at the default time.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00'));
    Queue::fake([SendWeeklySummary::class]);
    $this->team = Team::factory()->create();
    $this->shop = Project::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->blog = Project::factory()->for($this->team)->create(['name' => 'Blog']);
    $this->ops = NotificationChannel::factory()->for($this->team)->create(['name' => 'Ops']);
    $this->rule = AlertRule::query()->whereForTeam($this->team)->whereType(AlertRuleType::WeeklySummary)->sole();
    $this->rule->channels()->attach($this->ops);
    $this->send = fn (): int => app(SendWeeklySummariesAction::class)->execute();
});

describe('when it sends', function () {
    it('is off by default', function () {
        expect($this->rule->enabled)->toBeFalse()
            ->and(($this->send)())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('stays due when the summaries cannot be queued', function () {
        $this->rule->update(['enabled' => true]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        expect(fn () => ($this->send)())->toThrow(RuntimeException::class)
            ->and($this->rule->refresh()->last_sent_at)->toBeNull();
    });

    it('saves its day and time, and refuses a time that is none', function () {
        ['user' => $owner] = $this->memberWithProject(TeamRole::Owner);
        $rule = AlertRule::query()->whereForTeam($owner->currentTeam)->whereType(AlertRuleType::WeeklySummary)->sole();

        expect($rule->weeklySummarySettings()->toArray())->toBe(['day' => 1, 'time' => '08:00']);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['day' => 5, 'time' => '17:30'],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->settings)->toBe(['day' => 5, 'time' => '17:30']);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['day' => 8, 'time' => '25:00'],
        ])->assertSessionHasErrors(['settings.day', 'settings.time']);
    });

    it('sends at its day and time, once that week', function () {
        $this->rule->update(['enabled' => true, 'settings' => ['day' => 1, 'time' => '09:00']]);

        expect(($this->send)())->toBe(0);

        $this->travel(1)->hours();
        expect(($this->send)())->toBe(1);

        $this->travel(1)->minutes();
        expect(($this->send)())->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00'));
        expect(($this->send)())->toBe(1);
        Queue::assertPushed(SendWeeklySummary::class, 2);
    });
});

describe('who gets what', function () {
    beforeEach(function () {
        $this->rule->update(['enabled' => true]);
    });

    it('sends every project of the team in one message per channel', function () {
        $dev = NotificationChannel::factory()->for($this->team)->create(['name' => 'Dev']);
        $this->rule->channels()->attach($dev);

        expect(($this->send)())->toBe(2);
        Queue::assertPushed(SendWeeklySummary::class, fn (SendWeeklySummary $job): bool => $job->channel->is($this->ops)
            && $job->rules === [$this->blog->id => $this->rule->id, $this->shop->id => $this->rule->id]);
        Queue::assertPushed(SendWeeklySummary::class, fn (SendWeeklySummary $job): bool => $job->channel->is($dev) && count($job->rules) === 2);
    });

    it('puts a project whose own rule sends elsewhere in that channel\'s message instead', function () {
        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::WeeklySummary);
        $shopChannel = NotificationChannel::factory()->for($this->team)->create(['name' => 'Shop team']);
        $own->channels()->sync([$shopChannel->id]);

        expect(($this->send)())->toBe(2);
        Queue::assertPushed(SendWeeklySummary::class, fn (SendWeeklySummary $job): bool => $job->channel->is($this->ops) && $job->rules === [$this->blog->id => $this->rule->id]);
        Queue::assertPushed(SendWeeklySummary::class, fn (SendWeeklySummary $job): bool => $job->channel->is($shopChannel) && $job->rules === [$this->shop->id => $own->id]);
    });

    it('leaves out a project that turned its own rule off', function () {
        app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::WeeklySummary)->update(['enabled' => false]);

        expect(($this->send)())->toBe(1);
        Queue::assertPushed(SendWeeklySummary::class, fn (SendWeeklySummary $job): bool => $job->rules === [$this->blog->id => $this->rule->id]);
    });

    it('does not send the week again to a project customized after it went out', function () {
        expect(($this->send)())->toBe(1);

        $this->travel(5)->minutes();
        app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::WeeklySummary);

        expect(($this->send)())->toBe(0);
    });
});
