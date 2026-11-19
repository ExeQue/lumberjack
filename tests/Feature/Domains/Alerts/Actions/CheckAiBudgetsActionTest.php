<?php

declare(strict_types=1);

use App\Domains\Ai\Actions\SaveAiBudgetAction;
use App\Domains\Ai\Data\AiBudgetUsage;
use App\Domains\Ai\Enums\AiBudgetBasis;
use App\Domains\Ai\Enums\AiBudgetPayer;
use App\Domains\Ai\Enums\AiBudgetPeriod;
use App\Domains\Ai\Enums\AiBudgetScope;
use App\Domains\Ai\Models\AiBudget;
use App\Domains\Ai\Models\AiPrice;
use App\Domains\Ai\Models\AiUsageHourly;
use App\Domains\Ai\Queries\AiBudgetQuery;
use App\Domains\Ai\Queries\AiCostQuery;
use App\Domains\Ai\Support\AiPriceBook;
use App\Domains\Alerts\Actions\CheckAiBudgetsAction;
use App\Domains\Alerts\Data\AiBudgetAlert;
use App\Domains\Alerts\Data\AiBudgetSettings;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Jobs\SendAiBudgetAlert;
use App\Domains\Alerts\Models\AiBudgetState;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Teams\CreatesTeamMembers;

covers(CheckAiBudgetsAction::class, AiBudgetSettings::class, AiBudgetAlert::class, SendAiBudgetAlert::class);

uses(CreatesTeamMembers::class);

describe('AI budget alerts', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00'));
        Http::fake(['*' => Http::response(null, 204)]);
        $this->project = Project::factory()->create(['name' => 'Shop']);
        AiPrice::factory()->forTeamOf($this->project)->costing(1, 0)->create();
        $this->rule = AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::AiBudget, 'settings' => ['thresholds' => [50, 80, 100], 'projected' => false]]);
        $this->rule->channels()->attach(NotificationChannel::factory()->for($this->project->team)->create(['type' => ChannelType::Discord, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/x']]));
        $this->budget = AiBudget::factory()->forProject($this->project)->costing(10)->create();
        // Spend this much more of the team's currency, then run the check as the schedule does.
        $this->spend = function (float $cost): void {
            AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => (int) ($cost * 1_000_000), 'output_tokens' => 0, 'environment' => (string) microtime(true)]); // Its own row each time.
            $this->travel(5)->minutes();
            app(CheckAiBudgetsAction::class)->execute();
        };
        $this->titles = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->all();
    });

    it('tells about each threshold once a period, the highest when several are passed at once', function () {
        ($this->spend)(6);
        ($this->spend)(0);
        ($this->spend)(3);

        expect(($this->titles)())->toBe(['AI budget 50% used in Shop', 'AI budget 80% used in Shop']);

        ($this->spend)(2);
        ($this->spend)(5);

        expect(($this->titles)())->toBe(['AI budget 50% used in Shop', 'AI budget 80% used in Shop', 'AI budget 100% used in Shop']);

        // A new month starts over: everything at once tells only the highest.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00'));
        ($this->spend)(12);

        expect(($this->titles)())->toHaveCount(4)
            ->and(($this->titles)()[3])->toBe('AI budget 100% used in Shop');
    });

    it('warns once when it is on course to go over before the period ends', function () {
        $this->rule->update(['settings' => ['thresholds' => [100], 'projected' => true]]);

        // 6 of 10 half way through November: 12 by its end.
        ($this->spend)(6);
        ($this->spend)(0.5);

        expect(($this->titles)())->toBe(['AI budget on course to go over in Shop']);
    });

    it('tells nothing during maintenance, and what was passed once it ended', function () {
        $window = MaintenanceWindow::factory()->of($this->project, 'staging')->create(['expected_end_at' => now()->addHour()]);

        ($this->spend)(9);

        expect(($this->titles)())->toBe([]);

        app(EndMaintenanceWindowAction::class)->execute($window);
        ($this->spend)(0);

        expect(($this->titles)())->toBe(['AI budget 80% used in Shop']);
    });

    it('measures a period that ended once more to its end, after a grace for late calls', function (Closure $state, string $lastMinutes, string $next) {
        $this->budget->delete();
        $budget = $state(AiBudget::factory()->forProject($this->project)->costing(10))->create();
        $add = fn (float $cost) => AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'input_tokens' => (int) ($cost * 1_000_000), 'output_tokens' => 0, 'environment' => (string) microtime(true)]);
        $check = fn () => app(CheckAiBudgetsAction::class)->execute();

        $this->travelTo(CarbonImmutable::parse($lastMinutes));
        $add(6);
        $check();
        // Spent after the period's last check, then reported late.
        $this->travel(5)->minutes();
        $add(3);

        $this->travelTo(CarbonImmutable::parse($next)->addMinutes(3));
        $check();

        expect(($this->titles)())->toBe(['AI budget 50% used in Shop']);

        $this->travel(3)->minutes();
        $check();

        expect(($this->titles)())->toBe(['AI budget 50% used in Shop', 'AI budget 80% used in Shop'])
            ->and($budget->states()->whereNull('closed_at')->pluck('period_start')->map->toDateTimeString()->all())->toBe([$next]);

        // Its state is kept closed for the alert's job, and not looked at again.
        $this->travelTo(CarbonImmutable::parse($lastMinutes));
        $add(2);
        $this->travelTo(CarbonImmutable::parse($next)->addMinutes(15));
        $check();

        expect(($this->titles)())->toHaveCount(2)
            ->and($budget->states()->count())->toBe(2);
    })->with([
        'a day, past midnight' => [fn ($f) => $f->daily(), '2026-11-16 23:50:00', '2026-11-17 00:00:00'],
        'a month, into the next' => [fn ($f) => $f, '2026-11-30 23:50:00', '2026-12-01 00:00:00'],
    ]);

    it('sends the last look\'s alert when its job runs after that look, unless the rule is off, it is maintenance or the budget counts otherwise since', function (Closure $since, array $titles) {
        Queue::fake();
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-30 23:00:00', 'model' => 'claude-sonnet-4-5', 'input_tokens' => 12_000_000, 'output_tokens' => 0]);
        AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:06:00'));
        app(CheckAiBudgetsAction::class)->execute();
        // Its delivery waits on a retry or a lagging queue while the checks go on.
        $this->travel(10)->minutes();
        $since($this);
        app(CheckAiBudgetsAction::class)->execute();

        Queue::pushed(SendAiBudgetAlert::class)->each(fn (SendAiBudgetAlert $job) => app()->call([$job, 'handle']));

        expect(Queue::pushed(SendAiBudgetAlert::class))->toHaveCount(1)
            ->and(($this->titles)())->toBe($titles);
    })->with([
        'nothing changed' => [fn () => null, ['AI budget 100% used in Shop']],
        'the rule turned off' => [fn ($test) => $test->rule->update(['enabled' => false]), []],
        'maintenance begun' => [fn ($test) => MaintenanceWindow::factory()->of($test->project)->create(['expected_end_at' => now()->addHour()]), []],
        // A recount starts the budget over, the period just ended included.
        'another payer counted' => [fn ($test) => app(SaveAiBudgetAction::class)->execute($test->project, $test->budget, ['name' => null, 'scope' => AiBudgetScope::Project, 'targets' => [], 'period' => AiBudgetPeriod::Month, 'basis' => AiBudgetBasis::Cost, 'payer' => AiBudgetPayer::Us, 'amount' => 10.0]), []],
    ]);

    it('sends an alert of the period just ended when its job runs after the period\'s last look', function () {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-30 23:50:00'));
        ($this->spend)(12);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:06:00'));
        app(CheckAiBudgetsAction::class)->execute();

        Queue::pushed(SendAiBudgetAlert::class)->each(fn (SendAiBudgetAlert $job) => app()->call([$job, 'handle']));

        expect(($this->titles)())->toBe(['AI budget 100% used in Shop']);
    });

    it('tells nothing of a budget deleted before its alert went out', function () {
        $state = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);
        $job = new SendAiBudgetAlert($this->rule, $this->budget, 80, app(AiBudgetQuery::class)->measure($this->budget), $state->id);
        $this->budget->delete();

        dispatch($job);

        expect(($this->titles)())->toBe([]);
    });

    it('tells nothing while the rule is off, and starts over when it is on again', function () {
        ($this->spend)(9);
        $this->rule->update(['enabled' => false]);
        ($this->spend)(2);

        expect(($this->titles)())->toBe(['AI budget 80% used in Shop'])
            ->and($this->budget->states()->count())->toBe(0);

        $this->rule->update(['enabled' => true]);
        ($this->spend)(0);

        expect(($this->titles)())->toBe(['AI budget 80% used in Shop', 'AI budget 100% used in Shop']);
    });

    it('writes nothing for a budget changed while it was measured; the next run measures it again', function () {
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0]);
        // Someone saves the budget right after the check measured it.
        $this->app->instance(AiBudgetQuery::class, new class (app(AiCostQuery::class)) extends AiBudgetQuery {
            public function measure(AiBudget $budget, ?CarbonImmutable $at = null, ?AiPriceBook $book = null): AiBudgetUsage
            {
                $usage = parent::measure($budget, $at, $book);
                AiBudget::query()->whereKey($budget->id)->increment('version');

                return $usage;
            }
        });

        app(CheckAiBudgetsAction::class)->execute();

        expect(($this->titles)())->toBe([])
            ->and($this->budget->states()->count())->toBe(0);

        $this->app->forgetInstance(AiBudgetQuery::class);
        app(CheckAiBudgetsAction::class)->execute();

        expect(($this->titles)())->toBe(['AI budget 80% used in Shop']);
    });

    it('sends a queued alert only while the state that recorded it still holds it', function (array $change, ?int $threshold, array $titles) {
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0]);
        $state = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [50, 80], 'projected_fired_at' => now()]);
        $job = new SendAiBudgetAlert($this->rule, $this->budget, $threshold, app(AiBudgetQuery::class)->measure($this->budget), $state->id);
        $values = ['name' => null, 'scope' => AiBudgetScope::Project, 'targets' => [], 'period' => AiBudgetPeriod::Month, 'basis' => AiBudgetBasis::Cost, 'payer' => AiBudgetPayer::All, 'amount' => 10.0];

        app(SaveAiBudgetAction::class)->execute($this->project, $this->budget, [...$values, ...$change]);
        dispatch($job);

        expect(($this->titles)())->toBe($titles);
    })->with([
        // 9 of 11 is still past 80%.
        'an amount that keeps the threshold' => [['amount' => 11.0], 80, ['AI budget 80% used in Shop']],
        'an amount it is no longer past' => [['amount' => 20.0], 80, []],
        'an amount that clears the projected warning' => [['amount' => 11.0], null, []],
        'other owners' => [['scope' => AiBudgetScope::Owner, 'targets' => [['fingerprint' => null, 'ref_type' => 'tenant', 'ref_id' => '7']]], 80, []],
    ]);

    it('drops a queued alert when the rule is off, without a check in between, and tells it again once on', function () {
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0]);
        $state = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [50, 80]]);
        $job = new SendAiBudgetAlert($this->rule, $this->budget, 80, app(AiBudgetQuery::class)->measure($this->budget), $state->id);
        $this->rule->update(['enabled' => false]);

        dispatch($job);

        expect(($this->titles)())->toBe([])
            ->and($state->fresh()->fired_thresholds)->toBe([50]);

        $this->rule->update(['enabled' => true]);
        ($this->spend)(0);

        expect(($this->titles)())->toBe(['AI budget 80% used in Shop']);
    });

    it('drops a queued alert during maintenance and tells it once maintenance ended', function (?int $threshold, string $title) {
        $this->rule->update(['settings' => ['thresholds' => [50, 80, 100], 'projected' => true]]);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0]);
        $state = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [50, 80], 'projected_fired_at' => now()]);
        $job = new SendAiBudgetAlert($this->rule, $this->budget, $threshold, app(AiBudgetQuery::class)->measure($this->budget), $state->id);
        $window = MaintenanceWindow::factory()->of($this->project)->create(['expected_end_at' => now()->addHour()]);

        dispatch($job);

        expect(($this->titles)())->toBe([]);

        app(EndMaintenanceWindowAction::class)->execute($window);
        ($this->spend)(0);

        expect(($this->titles)())->toBe([$title]);
    })->with([
        'a threshold' => [80, 'AI budget 80% used in Shop'],
        'the projected warning' => [null, 'AI budget on course to go over in Shop'],
    ]);

    it('never warns of an overrun to come at a period\'s last look', function () {
        Queue::fake();
        $this->rule->update(['settings' => ['thresholds' => [100], 'projected' => true]]);
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-11-30 23:00:00', 'input_tokens' => 12_000_000, 'output_tokens' => 0]);
        AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [100]]);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:06:00'));

        app(CheckAiBudgetsAction::class)->execute();

        Queue::assertNotPushed(SendAiBudgetAlert::class);
    });

    it('deletes a closed state once the period after it ended', function () {
        $closed = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [], 'closed_at' => '2026-12-01 00:05:00']);
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:00:00'));
        app(CheckAiBudgetsAction::class)->execute();

        expect($closed->fresh())->not->toBeNull();

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:01:00'));
        app(CheckAiBudgetsAction::class)->execute();

        expect($closed->fresh())->toBeNull();
    });

    it('drops a queued alert once the rule was turned off', function () {
        $state = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);
        $job = new SendAiBudgetAlert($this->rule, $this->budget, 80, app(AiBudgetQuery::class)->measure($this->budget), $state->id);
        $this->rule->update(['enabled' => false]);
        app(CheckAiBudgetsAction::class)->execute();

        dispatch($job);

        expect(($this->titles)())->toBe([]);
    });

    it('does not send twice when an alert still being retried was recorded again after a recount', function () {
        Queue::fake();
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => now()->startOfHour(), 'model' => 'claude-sonnet-4-5', 'input_tokens' => 9_000_000, 'output_tokens' => 0]);
        app(CheckAiBudgetsAction::class)->execute();
        // Its delivery failed; it will be retried. Meanwhile the budget counts another payer, and the check tells again.
        $values = ['name' => null, 'scope' => AiBudgetScope::Project, 'targets' => [], 'period' => AiBudgetPeriod::Month, 'basis' => AiBudgetBasis::Cost, 'payer' => AiBudgetPayer::Us, 'amount' => 10.0];
        app(SaveAiBudgetAction::class)->execute($this->project, $this->budget, $values);
        app(CheckAiBudgetsAction::class)->execute();

        Queue::pushed(SendAiBudgetAlert::class)->each(fn (SendAiBudgetAlert $job) => app()->call([$job, 'handle']));

        expect(Queue::pushed(SendAiBudgetAlert::class))->toHaveCount(2)
            ->and(($this->titles)())->toBe(['AI budget 80% used in Shop']);
    });

    it('does not tell a threshold below one it told', function () {
        AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-11-01 00:00:00', 'fired_thresholds' => [80]]);

        ($this->spend)(6);

        expect(($this->titles)())->toBe([]);
    });

    it('drops a period older than the one just ended without a last look', function () {
        $stale = AiBudgetState::query()->create(['ai_budget_id' => $this->budget->id, 'period_start' => '2026-10-01 00:00:00', 'fired_thresholds' => []]);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:10:00'));
        AiUsageHourly::factory()->forProject($this->project)->create(['hour' => '2026-10-15 00:00:00', 'input_tokens' => 20_000_000, 'output_tokens' => 0]);

        app(CheckAiBudgetsAction::class)->execute();

        expect(($this->titles)())->toBe([])
            ->and($stale->fresh())->toBeNull();
    });
});

describe('the rule\'s settings', function () {
    it('saves them, and refuses what they cannot be', function () {
        ['user' => $owner] = $this->memberWithProject(TeamRole::Owner);
        $rule = AlertRule::query()->whereForTeam($owner->currentTeam)->whereType(AlertRuleType::AiBudget)->sole();

        expect($rule->aiBudgetSettings()->toArray())->toBe(['thresholds' => [80, 100], 'projected' => true]);

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['thresholds' => [50], 'projected' => false],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->settings)->toBe(['thresholds' => [50], 'projected' => false]);

        // A budget counts every environment, so its rule keeps none.
        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'environments' => ['production'],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->environments)->toBeNull();

        $this->actingAs($owner)->put(route('alerts.rules.update', $rule), [
            'enabled' => true, 'channels' => [], 'settings' => ['thresholds' => [70], 'projected' => 'maybe'],
        ])->assertSessionHasErrors(['settings.thresholds.0', 'settings.projected']);
    });
});
