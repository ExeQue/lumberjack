<?php

declare(strict_types=1);

use App\Domains\Logs\Models\LogChannel;
use App\Domains\Projects\Http\Controllers\ProjectSettingsController;
use App\Domains\Teams\Enums\TeamRole;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectSettingsController::class);

uses(CreatesTeamMembers::class);

describe('project administration', function () {
    it('is closed to members', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Member);

        $this->actingAs($user)->get(route('projects.settings', $project))->assertForbidden();
        $this->actingAs($user)->post(route('projects.rotate-token', $project))->assertForbidden();
    });

    it('rotates the server token and hands it out once', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject(TeamRole::Admin);
        $old = $project->token_hash;

        $this->actingAs($user)->post(route('projects.rotate-token', $project))->assertSessionHas('secret');

        expect($project->refresh()->token_hash)->not->toBe($old);
    });

    it('saves name, slug and allowed origins', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $this->actingAs($user)->put(route('projects.settings.update', $project), [
            'name' => 'Shop', 'slug' => 'shop', 'allowed_origins' => ['https://shop.test/'],
        ])->assertSessionHasNoErrors();

        expect($project->refresh()->allowed_origins)->toBe(['https://shop.test']);
    });
});

describe('log channels', function () {
    it('creates a kebab-case channel', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();

        $this->actingAs($user)->post(route('log-channels.store', $project), ['name' => 'example-log-channel'])->assertSessionHasNoErrors();

        expect($project->logChannels()->pluck('name'))->toContain('example-log-channel');
    });

    it('refuses names that are not kebab-case, taken or reserved', function (string $name) {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $project->logChannels()->create(['name' => 'payments']);

        $this->actingAs($user)->post(route('log-channels.store', $project), ['name' => $name])->assertSessionHasErrors('name');
    })->with([
        'upper case and spaces' => ['Mailchimp Sync'],
        'a trailing hyphen' => ['payments-'],
        'the reserved default' => ['default'],
        'an existing channel' => ['payments'],
    ]);

    it('never deletes the default channel', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $default = $project->logChannels()->where('name', LogChannel::DEFAULT)->sole();

        $this->actingAs($user)->delete(route('log-channels.destroy', [$project, $default]))->assertSessionHasErrors('channel');

        expect($default->fresh())->not->toBeNull();
    });
});
