<?php

declare(strict_types=1);

use App\Domains\Alerts\Enums\AlertRuleType;
use App\Domains\Alerts\Jobs\SendCustomAlert;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Alerts\Models\QueueAlertThreshold;
use App\Domains\Mcp\Data\McpAreaGrant;
use App\Domains\Mcp\Enums\McpArea;
use App\Domains\Mcp\Enums\McpLevel;
use App\Domains\Mcp\Models\McpToken;
use App\Domains\Mcp\Tools\Alerts\CreateAlertTopic;
use App\Domains\Mcp\Tools\Alerts\DeleteAlertTopic;
use App\Domains\Mcp\Tools\Alerts\ListAlertRules;
use App\Domains\Mcp\Tools\Alerts\ListAlertTopics;
use App\Domains\Mcp\Tools\Alerts\ListNotificationChannels;
use App\Domains\Mcp\Tools\Alerts\ResetAlertRule;
use App\Domains\Mcp\Tools\Alerts\SendProjectAlert;
use App\Domains\Mcp\Tools\Alerts\SendTestAlert;
use App\Domains\Mcp\Tools\Alerts\SetAlertRuleAddOns;
use App\Domains\Mcp\Tools\Alerts\SetQueueAlertThreshold;
use App\Domains\Mcp\Tools\Alerts\UpdateAlertRule;
use App\Domains\Mcp\Tools\Alerts\UpdateTeamAlertRule;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Mcp\CallsMcpTools;

covers(ListAlertRules::class, ListNotificationChannels::class, ListAlertTopics::class, UpdateAlertRule::class, ResetAlertRule::class, SetAlertRuleAddOns::class, SetQueueAlertThreshold::class, CreateAlertTopic::class, SendProjectAlert::class, SendTestAlert::class, UpdateTeamAlertRule::class, DeleteAlertTopic::class);

uses(CallsMcpTools::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['slug' => 'shop']);
    $this->team = $this->project->team;
    $this->user = User::factory()->inTeam($this->team)->create();
    $this->token = McpToken::factory()->for($this->team)->withToken('lj_mcp_secret')->allowing(McpArea::Alerts, McpLevel::Manage)->create(['user_id' => $this->user->id]);
    $this->token->projects()->attach($this->project);
    $this->shared = NotificationChannel::factory()->for($this->team)->create(['name' => '#ops']);
    $this->own = NotificationChannel::factory()->for($this->team)->create(['name' => '#shop', 'project_id' => $this->project->id]);
    $this->teamRule = AlertRule::query()->whereForTeam($this->team)->whereType(AlertRuleType::NewIssue)->firstOrFail();
    $this->teamRule->channels()->attach($this->shared);
    $this->withLevel = fn (McpLevel $level) => $this->token->update(['permissions' => $this->token->permissions->with(McpArea::Alerts, new McpAreaGrant($level))]);
});

describe('reading alerts', function () {
    it('lists the team\'s rules and a project\'s own next to them', function () {
        $team = $this->toolResult($this->callTool('lj_mcp_secret', 'list_alert_rules')->assertOk());
        expect(collect($team['rules'])->firstWhere('type', 'new_issue')['channels'])->toBe([$this->shared->id]);

        AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::NewIssue, 'enabled' => false]);
        $project = $this->toolResult($this->callTool('lj_mcp_secret', 'list_alert_rules', ['project' => 'shop'])->assertOk());

        expect(collect($project['rules'])->firstWhere('type', 'new_issue')['project']['enabled'])->toBeFalse();
    });

    it('lists channels and what sends to them, never their URL', function () {
        $response = $this->callTool('lj_mcp_secret', 'list_notification_channels', ['project' => 'shop'])->assertOk();
        $channels = collect($this->toolResult($response)['channels']);

        expect($channels->pluck('name')->all())->toEqualCanonicalizing(['#ops', '#shop'])
            ->and($channels->firstWhere('name', '#ops')['used_by'][0]['type'])->toBe('new_issue')
            ->and($response->getContent())->not->toContain('discord.com/api/webhooks');
    });

    it('lists the custom topics', function () {
        $topics = $this->toolResult($this->callTool('lj_mcp_secret', 'list_alert_topics')->assertOk())['topics'];

        expect(array_column($topics, 'topic'))->toBe(['default']);
    });
});

describe('a project\'s rules', function () {
    it('customizes a rule for the project only, and resets it', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'update_alert_rule', ['project' => 'shop', 'type' => 'new_issue', 'enabled' => true, 'channels' => [$this->own->id]])->assertOk());

        $own = $this->project->alertRules()->whereType(AlertRuleType::NewIssue)->firstOrFail();
        expect($result['rule']['channels'])->toBe([$this->own->id])
            ->and($own->channels->modelKeys())->toBe([$this->own->id])
            ->and($this->teamRule->channels()->pluck('notification_channels.id')->all())->toBe([$this->shared->id]);

        $this->callTool('lj_mcp_secret', 'reset_alert_rule', ['project' => 'shop', 'type' => 'new_issue'])->assertOk();

        expect($this->project->alertRules()->count())->toBe(0)
            ->and($this->teamRule->addOnsFor($this->project)->pluck('notification_channels.id')->all())->toBe([$this->own->id]);
    });

    it('adds channels to the team\'s rule for the project', function () {
        $this->callTool('lj_mcp_secret', 'set_alert_rule_add_ons', ['project' => 'shop', 'type' => 'new_issue', 'channels' => [$this->own->id]])->assertOk();

        expect($this->teamRule->addOnsFor($this->project)->pluck('notification_channels.id')->all())->toBe([$this->own->id]);
    });

    it('sets one queue\'s thresholds', function () {
        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'set_queue_alert_threshold', ['project' => 'shop', 'connection' => 'redis', 'queue' => 'mail', 'size' => 50, 'muted' => false])->assertOk());

        expect($result['threshold']['size'])->toBe(50)
            ->and(QueueAlertThreshold::query()->where('project_id', $this->project->id)->value('size'))->toBe(50);
    });

    it('refuses a read token and a maker who may not change the project', function (string $tool, array $arguments) {
        ($this->withLevel)(McpLevel::Read);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ['project' => 'shop', ...$arguments])))->toBeTrue();

        ($this->withLevel)(McpLevel::Write);
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, ['project' => 'shop', ...$arguments])))->toBeTrue()
            ->and($this->project->alertRules()->count())->toBe(0)
            ->and(QueueAlertThreshold::query()->count())->toBe(0);
    })->with([
        'update_alert_rule' => ['update_alert_rule', ['type' => 'new_issue', 'enabled' => false, 'channels' => []]],
        'set_alert_rule_add_ons' => ['set_alert_rule_add_ons', ['type' => 'new_issue', 'channels' => []]],
        'set_queue_alert_threshold' => ['set_queue_alert_threshold', ['connection' => 'redis', 'queue' => 'mail', 'muted' => true]],
    ]);
});

describe('the team\'s rules and topics', function () {
    it('changes a team rule for every project', function () {
        $this->callTool('lj_mcp_secret', 'update_team_alert_rule', ['type' => 'new_issue', 'enabled' => false, 'channels' => [$this->shared->id, $this->own->id]])->assertOk();

        expect($this->teamRule->fresh()->enabled)->toBeFalse()
            ->and($this->teamRule->channels()->pluck('notification_channels.id')->all())->toBe([$this->shared->id]);
    });

    it('creates and deletes a topic, never the default one', function () {
        $this->callTool('lj_mcp_secret', 'create_alert_topic', ['name' => 'Deploys'])->assertOk();
        expect(AlertRule::query()->whereForTeam($this->team)->where('topic', 'deploys')->exists())->toBeTrue();

        $this->callTool('lj_mcp_secret', 'delete_alert_topic', ['topic' => 'deploys'])->assertOk();
        expect(AlertRule::query()->whereForTeam($this->team)->where('topic', 'deploys')->exists())->toBeFalse()
            ->and($this->wasRefused($this->callTool('lj_mcp_secret', 'delete_alert_topic', ['topic' => 'default'])))->toBeTrue();
    });

    it('refuses a write token and a maker who may not administer the team', function (string $tool, array $arguments) {
        AlertRule::query()->create(['team_id' => $this->team->id, 'type' => AlertRuleType::CustomAlert, 'topic' => 'deploys', 'enabled' => true]);
        ($this->withLevel)(McpLevel::Write);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue();

        ($this->withLevel)(McpLevel::Manage);
        Gate::before(fn ($user, string $ability) => $ability === 'administer' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue()
            ->and($this->teamRule->fresh()->enabled)->toBeTrue()
            ->and(AlertRule::query()->whereForTeam($this->team)->where('topic', 'deploys')->exists())->toBeTrue();
    })->with([
        'update_team_alert_rule' => ['update_team_alert_rule', ['type' => 'new_issue', 'enabled' => false, 'channels' => []]],
        'delete_alert_topic' => ['delete_alert_topic', ['topic' => 'deploys']],
    ]);

    it('refuses to create a topic for a read token and a maker who may not administer the team', function () {
        ($this->withLevel)(McpLevel::Read);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'create_alert_topic', ['name' => 'deploys'])))->toBeTrue();

        ($this->withLevel)(McpLevel::Write);
        Gate::before(fn ($user, string $ability) => $ability === 'administer' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'create_alert_topic', ['name' => 'deploys'])))->toBeTrue()
            ->and(AlertRule::query()->where('topic', 'deploys')->exists())->toBeFalse();
    });

    it('refuses to reset a rule for a read token and a maker who may not change the project', function () {
        AlertRule::factory()->for($this->project)->create(['type' => AlertRuleType::NewIssue]);

        ($this->withLevel)(McpLevel::Read);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'reset_alert_rule', ['project' => 'shop', 'type' => 'new_issue'])))->toBeTrue();

        ($this->withLevel)(McpLevel::Write);
        Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', 'reset_alert_rule', ['project' => 'shop', 'type' => 'new_issue'])))->toBeTrue()
            ->and($this->project->alertRules()->count())->toBe(1);
    });
});

describe('sending alerts', function () {
    it('queues a custom alert for the topic\'s channels', function () {
        Queue::fake();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'send_project_alert', ['project' => 'shop', 'title' => 'Deploy done', 'level' => 'success'])->assertOk());

        expect($result['topic'])->toBe('default')->and($result['suppressed'])->toBeNull();
        Queue::assertPushed(SendCustomAlert::class);
    });

    it('sends a test to a channel', function () {
        Http::fake();

        $result = $this->toolResult($this->callTool('lj_mcp_secret', 'send_test_alert', ['channel' => $this->shared->id])->assertOk());

        expect($result['delivered'])->toBeTrue();
        Http::assertSentCount(1);
    });

    it('refuses a read token and a maker the policy says no to', function (string $tool, string $ability) {
        Queue::fake();
        Http::fake();
        $arguments = $tool === 'send_test_alert' ? ['channel' => $this->shared->id] : ['project' => 'shop', 'title' => 'Hi'];

        ($this->withLevel)(McpLevel::Read);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue();

        ($this->withLevel)(McpLevel::Write);
        Gate::before(fn ($user, string $given) => $given === $ability ? false : null);
        expect($this->wasRefused($this->callTool('lj_mcp_secret', $tool, $arguments)))->toBeTrue();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    })->with([
        'send_project_alert' => ['send_project_alert', 'update'],
        'send_test_alert' => ['send_test_alert', 'administer'],
    ]);
});
