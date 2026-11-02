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

    it('lets only that project\'s rules use it, and puts it in the rules that page', function () {
        $rule = AlertRule::factory()->for($this->shop)->create(['type' => AlertRuleType::Escalation]);
        $note = AlertRule::factory()->for($this->shop)->create(['type' => AlertRuleType::Note, 'enabled' => false]);
        $blogRule = AlertRule::factory()->for($this->blog)->create();

        $webhook = ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        $shared = ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);

        expect($rule->channels()->pluck('notification_channels.id')->all())->toBe([$webhook->id])
            ->and($note->channels()->count())->toBe(0);

        app(UpdateAlertRuleAction::class)->execute($blogRule, true, [$webhook->id, $shared->id], null);

        expect($blogRule->channels()->pluck('notification_channels.id')->all())->toBe([$shared->id]);
    });

    it('keeps a project\'s channels out of other projects created later', function () {
        ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        $shared = ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);

        $new = app(CreateProjectAction::class)->execute($this->team, 'Docs')['project'];

        expect($new->alertRules()->first()?->channels()->pluck('notification_channels.id')->all())->toBe([$shared->id]);
    });

    it('shows the team only its shared channels, and each project its own and its rules', function () {
        $admin = User::factory()->inTeam($this->team)->create();
        ($this->save)(ChannelType::Webhook, ['url' => 'https://93.184.215.14/hook'], $this->shop);
        ($this->save)(ChannelType::Mail, ['address' => 'ops@example.com']);
        AlertRule::factory()->for($this->shop)->create();

        $this->actingAs($admin)->get(route('alerts.index'))->assertInertia(fn (Assert $page) => $page
            ->has('channels', 1)->where('channels.0.type', 'mail')->where('types', ['mail', 'discord']));

        $this->actingAs($admin)->get(route('projects.settings', $this->shop))->assertInertia(fn (Assert $page) => $page
            ->has('alerts.channels', 1)->where('alerts.channels.0.type', 'webhook')
            ->has('alerts.available', 2)
            ->has('alerts.rules', 1));
    });

    it('adds a webhook for a project through the channel form', function () {
        $admin = User::factory()->inTeam($this->team)->create();

        $this->actingAs($admin)->post(route('alerts.channels.store'), [
            'type' => 'webhook', 'name' => 'Shop app', 'project' => $this->shop->uuid, 'config' => ['url' => 'https://93.184.215.14/hook'],
        ])->assertSessionHasNoErrors()->assertSessionHas('secret');

        expect(NotificationChannel::sole()->project_id)->toBe($this->shop->id);
    });
});
