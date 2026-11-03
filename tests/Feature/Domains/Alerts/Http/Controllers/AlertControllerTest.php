<?php

declare(strict_types=1);

use App\Domains\Alerts\Actions\SaveNotificationChannelAction;
use App\Domains\Alerts\Enums\ChannelType;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(AlertController::class);

uses(CreatesTeamMembers::class);

describe('editing a channel', function () {
    beforeEach(function () {
        ['user' => $this->admin] = $this->memberWithProject(TeamRole::Admin);
        $this->channel = NotificationChannel::factory()->for($this->admin->currentTeam)->create();
    });

    it('renames it and keeps the stored webhook when the field is left empty', function () {
        $this->actingAs($this->admin)->put(route('alerts.channels.update', $this->channel), [
            'type' => 'discord', 'name' => '#deploys', 'config' => ['webhook_url' => ''],
        ])->assertSessionHasNoErrors();

        $this->channel->refresh();
        expect($this->channel->name)->toBe('#deploys')
            ->and($this->channel->config['webhook_url'])->toBe('https://discord.com/api/webhooks/123/abc');
    });

    it('replaces the webhook when a new one is given', function () {
        $this->actingAs($this->admin)->put(route('alerts.channels.update', $this->channel), [
            'type' => 'discord', 'name' => '#alarms', 'config' => ['webhook_url' => 'https://discord.com/api/webhooks/999/new'],
        ])->assertSessionHasNoErrors();

        expect($this->channel->refresh()->config['webhook_url'])->toBe('https://discord.com/api/webhooks/999/new');
    });

    it('sets the sender name, clears it when emptied, and refuses a name Discord would reject', function () {
        $update = fn (string $sender) => $this->actingAs($this->admin)->put(route('alerts.channels.update', $this->channel), [
            'type' => 'discord', 'name' => '#alarms', 'config' => ['webhook_url' => '', 'sender_name' => $sender],
        ]);

        $update('Shop alerts')->assertSessionHasNoErrors();
        expect($this->channel->refresh()->config['sender_name'])->toBe('Shop alerts');

        $update('')->assertSessionHasNoErrors();
        expect($this->channel->refresh()->config)->not->toHaveKey('sender_name')
            ->and($this->channel->config['webhook_url'])->toBe('https://discord.com/api/webhooks/123/abc');

        $update('Discord bot')->assertSessionHasErrors('config.sender_name');
    });

    it('is closed to members', function () {
        ['user' => $member] = $this->memberWithProject(TeamRole::Member);
        $member->teams()->attach($this->admin->currentTeam, ['role' => 'member']);
        $member->forceFill(['current_team_id' => $this->admin->current_team_id])->save();

        $this->actingAs($member->fresh())->put(route('alerts.channels.update', $this->channel), [
            'type' => 'discord', 'name' => 'x', 'config' => [],
        ])->assertForbidden();
    });
});

describe('the alerts page', function () {
    it('shows members only where a channel delivers, not its secret parts', function () {
        ['user' => $member, 'project' => $project] = $this->memberWithProject(TeamRole::Member);
        $admin = User::factory()->inTeam($project->team, TeamRole::Admin)->create();
        NotificationChannel::factory()->for($project->team)->create(['type' => ChannelType::Ntfy, 'name' => 'Phones', 'config' => ['url' => 'https://ntfy.sh/ops-secret-topic']]);

        $this->actingAs($member)->get(route('alerts.index'))->assertInertia(fn (Assert $page) => $page->where('channels.0.target', 'ntfy.sh/••••'));
        $this->actingAs($admin)->get(route('alerts.index'))->assertInertia(fn (Assert $page) => $page->where('channels.0.target', 'https://ntfy.sh/ops-secret-topic'));
    });
});

describe('another team', function () {
    it('cannot change, delete or test a channel it does not administer, even as owner of its own team', function () {
        ['user' => $victim] = $this->memberWithProject(TeamRole::Admin);
        $channel = NotificationChannel::factory()->for($victim->currentTeam)->create();
        ['user' => $attacker] = $this->memberWithProject(TeamRole::Owner);

        $this->actingAs($attacker)->put(route('alerts.channels.update', $channel), [
            'type' => 'mail', 'name' => 'mine', 'config' => ['address' => 'attacker@example.com'],
        ])->assertForbidden();
        $this->actingAs($attacker)->delete(route('alerts.channels.destroy', $channel))->assertForbidden();
        $this->actingAs($attacker)->post(route('alerts.channels.test', $channel))->assertForbidden();

        expect($channel->refresh()->type)->toBe(ChannelType::Discord)
            ->and($channel->name)->not->toBe('mine');
    });
});

describe('sending a test', function () {
    it('says why a webhook test did not go through', function () {
        Http::fake(['93.184.215.14/*' => Http::response('nope', 405)]);
        ['user' => $admin, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $channel = app(SaveNotificationChannelAction::class)->execute($project->team, ChannelType::Webhook, 'Deploy hook', ['url' => 'https://93.184.215.14/hook'], project: $project);

        $this->actingAs($admin)->post(route('alerts.channels.test', $channel))
            ->assertSessionHasErrors(['channel' => 'Delivery to Deploy hook failed with HTTP 405.']);

        expect($channel->refresh()->last_delivery_status)->toBe('failed');
    });

    it('reports a test that went through', function () {
        Http::fake(['93.184.215.14/*' => Http::response(null, 204)]);
        ['user' => $admin, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $channel = app(SaveNotificationChannelAction::class)->execute($project->team, ChannelType::Webhook, 'Deploy hook', ['url' => 'https://93.184.215.14/hook'], project: $project);

        $this->actingAs($admin)->post(route('alerts.channels.test', $channel))->assertSessionHasNoErrors();

        expect($channel->refresh()->last_delivery_status)->toBe('sent');
    });
});
