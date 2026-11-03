<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Eloquent\Builders\AlertRuleBuilder;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Health\Actions\RecordHealthResultAction;
use App\Domains\Health\Data\HealthResultData;
use App\Domains\Health\Enums\HealthResultStatus;
use App\Domains\Health\Models\HealthCheck;
use App\Domains\Issues\Models\Issue;
use App\Domains\Jobs\Models\QueueSample;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;

covers(AlertRuleBuilder::class, CustomizeAlertRuleAction::class, AlertController::class);

beforeEach(function () {
    Http::fake(['*' => Http::response(null, 204)]);
    $this->team = Team::factory()->create();
    $this->shop = Project::factory()->for($this->team)->create(['name' => 'Shop']);
    $this->blog = Project::factory()->for($this->team)->create(['name' => 'Blog']);
    $this->channel = NotificationChannel::factory()->for($this->team)->create();
    $this->teamRule = fn (AlertRuleType $type): AlertRule => AlertRule::query()->whereForTeam($this->team)->whereType($type)->sole();
    $this->effective = fn (Project $project, AlertRuleType $type): ?AlertRule => AlertRule::query()->whereEffectiveFor($project)->whereType($type)->first();

    foreach (AlertRuleType::cases() as $type) {
        ($this->teamRule)($type)->channels()->attach($this->channel);
    }
});

describe('the rule that counts', function () {
    it('is the team\'s until the project customizes it, and the team\'s again once that goes', function () {
        $team = ($this->teamRule)(AlertRuleType::NewIssue);

        expect(($this->effective)($this->shop, AlertRuleType::NewIssue)?->is($team))->toBeTrue();

        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::NewIssue);

        expect(($this->effective)($this->shop, AlertRuleType::NewIssue)?->is($own))->toBeTrue()
            ->and(($this->effective)($this->blog, AlertRuleType::NewIssue)?->is($team))->toBeTrue()
            ->and(AlertRule::query()->whereEffectiveFor($this->shop)->count())->toBe(count(AlertRuleType::cases()));

        $own->delete();

        expect(($this->effective)($this->shop, AlertRuleType::NewIssue)?->is($team))->toBeTrue();
    });

    it('starts a customized rule as a copy of the team\'s', function () {
        ($this->teamRule)(AlertRuleType::QueueBacklog)->update(['enabled' => false, 'environments' => ['production'], 'settings' => ['size' => 5, 'age_minutes' => null, 'sustain_minutes' => 2]]);

        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::QueueBacklog);

        expect($own->project_id)->toBe($this->shop->id)
            ->and($own->team_id)->toBeNull()
            ->and($own->enabled)->toBeFalse()
            ->and($own->environments)->toBe(['production'])
            ->and($own->settings)->toBe(['size' => 5, 'age_minutes' => null, 'sustain_minutes' => 2])
            ->and($own->channels()->pluck('notification_channels.id')->all())->toBe([$this->channel->id]);
    });

    it('lets a rule belong to a team or a project, never both or neither', function () {
        AlertRule::query()->create(['team_id' => $this->team->id, 'project_id' => $this->shop->id, 'type' => AlertRuleType::Note]);
    })->throws(Illuminate\Database\QueryException::class);
});

describe('sending through the rule that counts', function () {
    it('sends issue alerts by the team rule, and not once the project turned its own off', function () {
        $issue = Issue::factory()->for($this->shop)->create();

        expect(app(SendIssueAlertsAction::class)->execute($issue, AlertRuleType::Regression, 'production'))->toBe(1);

        app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::Regression)->update(['enabled' => false]);

        expect(app(SendIssueAlertsAction::class)->execute($issue, AlertRuleType::Regression, 'production'))->toBe(0);
    });

    it('watches the queues of every project by the team rule, each on its own', function () {
        ($this->teamRule)(AlertRuleType::QueueBacklog)->update(['settings' => ['size' => 100, 'age_minutes' => null, 'sustain_minutes' => 1]]);

        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();

            foreach ([$this->shop, $this->blog] as $project) {
                QueueSample::query()->create(['project_id' => $project->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'default', 'size' => 500, 'oldest_age_s' => 60, 'sampled_at' => now()]);
            }

            app(CheckQueueBacklogsAction::class)->execute();
        }

        expect(Http::recorded()->map(fn (array $pair) => $pair[0]['embeds'][0]['title'])->sort()->values()->all())
            ->toBe(['Queue backlog in Blog', 'Queue backlog in Shop']);
    });

    it('sends health alerts by the team rule, and by the project\'s own once customized', function () {
        $check = HealthCheck::factory()->for($this->shop)->create(['degrade_after' => 1, 'recover_after' => 1]);
        $report = function (string $status) use ($check): void {
            $this->travel(1)->minutes();
            app(RecordHealthResultAction::class)->execute($check->refresh(), new HealthResultData(HealthResultStatus::from($status), 'msg', host: 'web-1', at: now()->toImmutable()));
        };

        $report('failed');
        expect(Http::recorded())->toHaveCount(1);

        app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::HealthRecovered)->channels()->detach();
        $report('ok');

        expect(Http::recorded())->toHaveCount(1);
    });
});

describe('editing rules', function () {
    beforeEach(function () {
        $this->admin = User::factory()->inTeam($this->team, TeamRole::Admin)->create();
        $this->member = User::factory()->inTeam($this->team, TeamRole::Member)->create();
        $this->outsider = User::factory()->create();
        $this->projectChannel = NotificationChannel::factory()->for($this->team)->create(['project_id' => $this->shop->id]);
    });

    it('lets an admin change a team rule, with shared channels only', function () {
        $rule = ($this->teamRule)(AlertRuleType::NewIssue);

        $this->actingAs($this->admin)->put(route('alerts.rules.update', $rule), [
            'enabled' => false, 'channels' => [$this->channel->id, $this->projectChannel->id], 'environments' => ['production'],
        ])->assertSessionHasNoErrors();

        expect($rule->refresh()->enabled)->toBeFalse()
            ->and($rule->channels()->pluck('notification_channels.id')->all())->toBe([$this->channel->id]);
    });

    it('lets a customized rule use the project\'s own channels too', function () {
        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::NewIssue);

        $this->actingAs($this->admin)->put(route('alerts.rules.update', $own), [
            'enabled' => true, 'channels' => [$this->channel->id, $this->projectChannel->id], 'environments' => null,
        ])->assertSessionHasNoErrors();

        expect($own->channels()->pluck('notification_channels.id')->sort()->values()->all())->toBe([$this->channel->id, $this->projectChannel->id]);
    });

    it('customizes a rule for a project and goes back to the team\'s', function () {
        $this->actingAs($this->admin)->post(route('alerts.rules.customize', [$this->shop, AlertRuleType::Escalation->value]))->assertRedirect();

        $own = $this->shop->alertRules()->sole();
        expect($own->type)->toBe(AlertRuleType::Escalation);

        $this->actingAs($this->admin)->delete(route('alerts.rules.reset', $own))->assertRedirect();

        expect($this->shop->alertRules()->count())->toBe(0);
    });

    it('does not reset a team rule', function () {
        $this->actingAs($this->admin)->delete(route('alerts.rules.reset', ($this->teamRule)(AlertRuleType::NewIssue)))->assertNotFound();
    });

    it('keeps members and outsiders from changing team rules or customizing', function (string $who) {
        $user = $this->{$who};
        $rule = ($this->teamRule)(AlertRuleType::NewIssue);

        $this->actingAs($user)->put(route('alerts.rules.update', $rule), ['enabled' => false, 'channels' => []])->assertForbidden();
        $this->actingAs($user)->post(route('alerts.rules.customize', [$this->shop, AlertRuleType::NewIssue->value]))->assertForbidden();

        expect($rule->refresh()->enabled)->toBeTrue()
            ->and($this->shop->alertRules()->count())->toBe(0);
    })->with(['member', 'outsider']);
});
