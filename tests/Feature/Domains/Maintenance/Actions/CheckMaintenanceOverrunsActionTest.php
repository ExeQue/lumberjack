<?php

declare(strict_types=1);

use App\Domains\Alerts\Data\MaintenanceOverrunAlert;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendMaintenanceOverrunAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Maintenance\Actions\CheckMaintenanceOverrunsAction;
use App\Domains\Maintenance\Actions\EndMaintenanceWindowAction;
use App\Domains\Maintenance\Actions\StartMaintenanceAction;
use App\Domains\Maintenance\Models\MaintenanceWindow;
use App\Domains\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

covers(CheckMaintenanceOverrunsAction::class, EndMaintenanceWindowAction::class, MaintenanceOverrunAlert::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
    Queue::fake([SendMaintenanceOverrunAlert::class]);
    $this->project = Project::factory()->create(['name' => 'Shop']);
    $this->window = MaintenanceWindow::factory()->of($this->project)->create(['starts_at' => now()->subHour(), 'expected_end_at' => now()->addMinutes(10)]);
    // One run a minute, as the schedule runs it.
    $this->check = fn (): int => app(CheckMaintenanceOverrunsAction::class)->execute();
});

describe('an overrun', function () {
    it('alerts at the expected end, with no grace period', function () {
        $this->travel(9)->minutes();
        expect(($this->check)())->toBe(0);
        Queue::assertNothingPushed();

        $this->travel(1)->minutes();
        expect(($this->check)())->toBe(1);
        Queue::assertPushed(SendMaintenanceOverrunAlert::class, fn (SendMaintenanceOverrunAlert $job): bool => $job->window->is($this->window) && $job->rule->type === AlertRuleType::MaintenanceOverrun);
        expect($this->window->refresh()->overrun_alerted_at?->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('repeats after the project\'s repeat interval, not before', function (?int $repeat, int $minutes) {
        if ($repeat !== null) {
            $this->project->update(['maintenance_repeat_minutes' => $repeat]);
        }

        $this->travel(10)->minutes();
        expect(($this->check)())->toBe(1);

        $this->travel($minutes - 1)->minutes();
        expect(($this->check)())->toBe(0);

        $this->travel(1)->minutes();
        expect(($this->check)())->toBe(1);
        Queue::assertPushed(SendMaintenanceOverrunAlert::class, 2);
    })->with([
        'the default of 30 minutes' => [null, 30],
        'a project\'s own 10 minutes' => [10, 10],
    ]);

    it('takes a reported window without an expected end to end after the project\'s default', function () {
        $this->window->delete();
        $this->project->update(['maintenance_expected_minutes' => 5]);
        app(StartMaintenanceAction::class)->execute($this->project, 'production', null, null, now()->toImmutable());

        $this->travel(4)->minutes();
        expect(($this->check)())->toBe(0);

        $this->travel(1)->minutes();
        expect(($this->check)())->toBe(1);
    });

    it('sends nothing when the rule is off', function () {
        AlertRule::query()->where('team_id', $this->project->team_id)->whereType(AlertRuleType::MaintenanceOverrun)->update(['enabled' => false]);

        $this->travel(15)->minutes();

        expect(($this->check)())->toBe(0)
            ->and($this->window->refresh()->overrun_alerted_at)->toBeNull();
        Queue::assertNothingPushed();
    });

    it('says how long it is past its expected end', function () {
        $this->travel(12)->minutes();
        $alert = new MaintenanceOverrunAlert($this->window->refresh(), 'https://lumberjack.test/health', ended: false);

        expect($alert->headline())->toBe('Maintenance overrun in Shop (production)')
            ->and($alert->summary()['body'][0])->toStartWith('Expected to end at ')->toContain('2 minutes ago, and still open')
            ->and($alert->urgency())->toBe('high');
    });
});

describe('the end of a window', function () {
    it('still sends an overrun queued before the window ended as an overrun', function () {
        $this->travel(10)->minutes();
        ($this->check)();
        $this->window->refresh()->update(['ended_at' => now()]);

        $alert = new MaintenanceOverrunAlert($this->window, 'https://lumberjack.test/health', ended: false);

        expect($alert->headline())->toBe('Maintenance overrun in Shop (production)')
            ->and($alert->urgency())->toBe('high');
    });

    it('tells once more, with how long it ran over, when it was alerted on', function () {
        $this->travel(10)->minutes();
        ($this->check)();
        $this->travel(7)->minutes();

        app(EndMaintenanceWindowAction::class)->execute($this->window->refresh());

        Queue::assertPushed(SendMaintenanceOverrunAlert::class, 2);
        Queue::assertPushed(SendMaintenanceOverrunAlert::class, fn (SendMaintenanceOverrunAlert $job): bool => ! $job->ended);
        Queue::assertPushed(SendMaintenanceOverrunAlert::class, fn (SendMaintenanceOverrunAlert $job): bool => $job->ended);

        $alert = new MaintenanceOverrunAlert($this->window->refresh(), 'https://lumberjack.test/health', ended: true);
        expect($alert->ended())->toBeTrue()
            ->and($alert->overrunMinutes())->toBe(7)
            ->and($alert->headline())->toBe('Maintenance over in Shop (production)')
            ->and($alert->summary()['body'][0])->toStartWith('Ended 7 minutes after its expected end at ')
            ->and($alert->urgency())->toBe('low');
    });

    it('sends nothing for a window never alerted on', function () {
        $this->travel(5)->minutes();

        app(EndMaintenanceWindowAction::class)->execute($this->window);

        expect($this->window->refresh()->ended_at?->getTimestamp())->toBe(now()->getTimestamp());
        Queue::assertNothingPushed();
    });

    it('stops the overrun alerts', function () {
        $this->travel(10)->minutes();
        ($this->check)();
        app(EndMaintenanceWindowAction::class)->execute($this->window->refresh());

        $this->travel(1)->hour();

        expect(($this->check)())->toBe(0);
    });
});
