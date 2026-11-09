<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Actions\UpdateAlertRuleAction;
use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Exceptions\WebhookNeedsProjectException;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Projects\Actions\CreateProjectAction;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

covers(SaveNotificationChannelAction::class, UpdateAlertRuleAction::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->shop = Project::factory()->for($this->team)->create(['slug' => 'shop']);
    $this->blog = Project::factory()->for($this->team)->create(['slug' => 'blog']);
    $this->save = fn (ChannelType $type, array $config, ?Project $project = null) => app(SaveNotificationChannelAction::class)->execute($this->team, $type, 'x', $config, project: $project);
});

describe('channels of one project', function () {
    it('needs a project for a webhook', function () {
        ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook']);
    })->throws(WebhookNeedsProjectException::class);

    it('lets only that project\'s rules use it, and adds it to no rule until it is picked', function () {
        $rule = AlertRule::factory()->for($this->shop)->create(['type' => AlertRuleType::Escalation]);
        $note = AlertRule::factory()->for($this->shop)->create(['type' => AlertRuleType::Note, 'enabled' => false]);
        $blogRule = AlertRule::factory()->for($this->blog)->create();

        $webhook = ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        $shared = ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);

        expect($rule->channels()->count())->toBe(0)
            ->and($note->channels()->count())->toBe(0)
            ->and(AlertRule::query()->whereForTeam($this->team)->whereType(AlertRuleType::NewIssue)->sole()->channels()->count())->toBe(0);

        app(UpdateAlertRuleAction::class)->execute($blogRule, true, [$webhook->id, $shared->id], null);

        expect($blogRule->channels()->pluck('notification_channels.id')->all())->toBe([$shared->id]);
    });

    it('keeps a project\'s channels out of other projects created later', function () {
        ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        $shared = ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);
        AlertRule::query()->whereForTeam($this->team)->whereType(AlertRuleType::NewIssue)->sole()->channels()->attach($shared);

        $new = app(CreateProjectAction::class)->execute($this->team, 'Docs')['project'];
        $rule = AlertRule::query()->whereEffectiveFor($new)->whereType(AlertRuleType::NewIssue)->sole();

        expect($rule->team_id)->toBe($this->team->id)
            ->and($rule->channels()->pluck('notification_channels.id')->all())->toBe([$shared->id]);
    });

    it('shows the team only its shared channels, and each project its own and its rules', function () {
        $admin = User::factory()->inTeam($this->team)->create();
        ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);

        $this->actingAs($admin)->get(route('alerts.index'))->assertInertia(fn (Assert $page) => $page
            ->has('channels', 1)->where('channels.0.type', 'mail')->where('types', ['mail', 'discord', 'slack', 'teams', 'telegram', 'ntfy', 'pushover'])
            ->has('rules', count(AlertRuleType::cases())));

        $this->actingAs($admin)->get(route('projects.settings', $this->shop))->assertInertia(fn (Assert $page) => $page
            ->has('alerts.channels', 1)->where('alerts.channels.0.type', 'webhook')
            ->has('alerts.available', 2)
            ->has('alerts.rules', count(AlertRuleType::cases()))
            ->where('alerts.rules.0.type', 'new_issue')->where('alerts.rules.0.project', null)->has('alerts.rules.0.add_ons', 0)
            ->where('alerts.rules.2.type', 'note')->where('alerts.rules.2.project', null)->has('alerts.rules.2.add_ons', 0));
    });

    it('adds a webhook for a project through the channel form', function () {
        $admin = User::factory()->inTeam($this->team)->create();

        $this->actingAs($admin)->post(route('alerts.channels.store'), [
            'type' => 'webhook', 'name' => 'Shop app', 'project' => $this->shop->uuid, 'config' => ['url' => 'https://93.184.215.14/hook'],
        ])->assertSessionHasNoErrors()->assertSessionHas('secret');

        expect(NotificationChannel::sole()->project_id)->toBe($this->shop->id);
    });

    it('names a wrong config field by its label', function () {
        $admin = User::factory()->inTeam($this->team)->create();

        $this->actingAs($admin)->post(route('alerts.channels.store'), [
            'type' => 'telegram', 'name' => 'Phones', 'config' => ['bot_token' => 'bad', 'chat_id' => '-100123'],
        ])->assertSessionHasErrors(['config.bot_token' => 'The bot token field format is invalid.']);
    });
});
