<?php

declare(strict_types=1);

use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Models\Project;
use Tests\Support\Teams\CreatesTeamMembers;

covers(ProjectController::class);

uses(CreatesTeamMembers::class);

describe('creating a project', function () {
    it('takes the issue prefix chosen for it', function () {
        ['user' => $user] = $this->memberWithProject();

        $this->actingAs($user)->post(route('projects.store'), ['name' => 'Eazy Photo', 'issue_prefix' => 'ep'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('secret')
            ->assertRedirectContains('section=install');

        expect(Project::query()->where('name', 'Eazy Photo')->sole()->issuePrefix())->toBe('EP');
    });

    it('wants a prefix, of letters and digits, not taken in the team', function () {
        ['user' => $user, 'project' => $existing] = $this->memberWithProject();
        $existing->forceFill(['issue_prefix' => 'SHOP'])->save();

        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A'])->assertSessionHasErrors('issue_prefix');
        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A', 'issue_prefix' => 'shop'])->assertSessionHasErrors('issue_prefix');
        $this->actingAs($user)->post(route('projects.store'), ['name' => 'A', 'issue_prefix' => '9LIVES'])->assertSessionHasErrors('issue_prefix');

        expect(Project::query()->where('name', 'A')->exists())->toBeFalse();
    });
});
