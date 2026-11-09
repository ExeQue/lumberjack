<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\CheckQueueBacklogsAction;
use App\Domains\Alerts\Actions\CustomizeAlertRuleAction;
use App\Domains\Alerts\Actions\ResetAlertRuleAction;
use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Actions\SendIssueAlertsAction;
use App\Domains\Alerts\Actions\SetAlertRuleAddOnsAction;
use App\Domains\Alerts\Eloquent\Builders\AlertRuleBuilder;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
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

covers(AlertRuleBuilder::class, CustomizeAlertRuleAction::class, ResetAlertRuleAction::class, SetAlertRuleAddOnsAction::class, SaveNotificationChannelAction::class, AlertController::class);

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

describe('project add-ons on a team rule', function () {
    beforeEach(function () {
        // The shop's own Slack, added only to the team rules it uses.
        $this->addOn = NotificationChannel::factory()->for($this->team)->create(['project_id' => $this->shop->id, 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/2/shop']]);
        $this->hits = fn (): array => Http::recorded()->map(fn (array $pair) => $pair[0]->url())->unique()->values()->all();
        $this->addTo = fn (AlertRuleType $type) => app(SetAlertRuleAddOnsAction::class)->execute($this->shop, ($this->teamRule)($type), [$this->addOn->id]);
    });

    it('sends issue alerts to the add-on with the team\'s channels, and nothing once the team turns the rule off', function () {
        ($this->addTo)(AlertRuleType::Regression);
        $issue = Issue::factory()->for($this->shop)->create();

        expect(app(SendIssueAlertsAction::class)->execute($issue, AlertRuleType::Regression, 'production'))->toBe(2)
            ->and(app(SendIssueAlertsAction::class)->execute(Issue::factory()->for($this->blog)->create(), AlertRuleType::Regression, 'production'))->toBe(1);

        ($this->teamRule)(AlertRuleType::Regression)->update(['enabled' => false]);

        expect(app(SendIssueAlertsAction::class)->execute($issue, AlertRuleType::Regression, 'production'))->toBe(0);
    });

    it('sends queue backlog alerts to the add-on, even when the team rule has no channels', function () {
        $rule = ($this->teamRule)(AlertRuleType::QueueBacklog);
        $rule->channels()->detach();
        $rule->update(['settings' => ['size' => 100, 'age_minutes' => null, 'sustain_minutes' => 1]]);
        ($this->addTo)(AlertRuleType::QueueBacklog);

        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();
            QueueSample::query()->create(['project_id' => $this->shop->id, 'host' => 'web-1', 'connection' => 'redis', 'queue' => 'default', 'size' => 500, 'oldest_age_s' => 60, 'sampled_at' => now()]);
            app(CheckQueueBacklogsAction::class)->execute();
        }

        expect(($this->hits)())->toBe(['https://discord.com/api/webhooks/2/shop']);
    });

    it('sends health alerts to the add-on', function () {
        ($this->teamRule)(AlertRuleType::HealthDown)->channels()->detach();
        ($this->addTo)(AlertRuleType::HealthDown);
        $check = HealthCheck::factory()->for($this->shop)->create(['degrade_after' => 1, 'down_after' => 1]);

        $this->travel(1)->minutes();
        app(RecordHealthResultAction::class)->execute($check, new HealthResultData(HealthResultStatus::Failed, 'msg', host: 'web-1', at: now()->toImmutable()));

        expect(($this->hits)())->toBe(['https://discord.com/api/webhooks/2/shop']);
    });

    it('carries add-ons into a customized rule and turns project channels back into add-ons on reset', function () {
        ($this->addTo)(AlertRuleType::NewIssue);
        $team = ($this->teamRule)(AlertRuleType::NewIssue);

        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::NewIssue);

        expect($own->channels()->pluck('notification_channels.id')->sort()->values()->all())->toBe([$this->channel->id, $this->addOn->id])
            ->and($team->addOnsFor($this->shop)->count())->toBe(0);

        app(ResetAlertRuleAction::class)->execute($own);

        expect($this->shop->alertRules()->count())->toBe(0)
            ->and($team->addOnsFor($this->shop)->pluck('notification_channels.id')->all())->toBe([$this->addOn->id]);
    });

    it('adds a new project channel to no rule, customized or not', function () {
        $own = app(CustomizeAlertRuleAction::class)->execute($this->shop, AlertRuleType::Escalation);

        $webhook = app(SaveNotificationChannelAction::class)->execute($this->team, ChannelType::Webhook, 'Shop app', ['url' => 'https://93.184.215.14/hook'], project: $this->shop);

        expect($this->shop->alertRules()->count())->toBe(1)
            ->and($own->channels()->pluck('notification_channels.id')->all())->not->toContain($webhook->id)
            ->and(($this->teamRule)(AlertRuleType::NewIssue)->addOnsFor($this->shop)->count())->toBe(0);
    });

    it('lets an admin set add-ons to channels the project may use only', function () {
        $admin = User::factory()->inTeam($this->team, TeamRole::Admin)->create();
        $blogs = NotificationChannel::factory()->for($this->team)->create(['project_id' => $this->blog->id]);
        $rule = ($this->teamRule)(AlertRuleType::NewIssue);

        $this->actingAs($admin)->put(route('alerts.rules.add-ons', [$this->shop, $rule]), ['channels' => [$this->addOn->id, $blogs->id]])->assertSessionHasNoErrors();

        expect($rule->addOnsFor($this->shop)->pluck('notification_channels.id')->all())->toBe([$this->addOn->id]);
    });

    it('keeps members and outsiders from setting add-ons, and other teams\' rules out', function () {
        $rule = ($this->teamRule)(AlertRuleType::NewIssue);
        $admin = User::factory()->inTeam($this->team, TeamRole::Admin)->create();
        $other = AlertRule::query()->whereForTeam(Team::factory()->create())->whereType(AlertRuleType::NewIssue)->sole();

        $this->actingAs(User::factory()->inTeam($this->team, TeamRole::Member)->create())->put(route('alerts.rules.add-ons', [$this->shop, $rule]), ['channels' => [$this->addOn->id]])->assertForbidden();
        $this->actingAs(User::factory()->create())->put(route('alerts.rules.add-ons', [$this->shop, $rule]), ['channels' => [$this->addOn->id]])->assertForbidden();
        $this->actingAs($admin)->put(route('alerts.rules.add-ons', [$this->shop, $other]), ['channels' => [$this->addOn->id]])->assertNotFound();

        expect($rule->addOnsFor($this->shop)->count())->toBe(0);
    });
});
