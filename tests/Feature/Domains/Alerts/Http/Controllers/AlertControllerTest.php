<?php

declare(strict_types=1);

use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Alerts\Models\NotificationChannel;
use App\Domains\Teams\Enums\TeamRole;
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
