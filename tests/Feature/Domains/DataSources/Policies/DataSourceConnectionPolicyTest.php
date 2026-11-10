<?php

declare(strict_types=1);

use App\Domains\DataSources\Models\DataSourceConnection;
use App\Domains\DataSources\Policies\DataSourceConnectionPolicy;
use App\Domains\Projects\Models\Project;
use App\Domains\Teams\Enums\TeamRole;
use App\Models\User;

covers(DataSourceConnectionPolicy::class);

beforeEach(function () {
    $this->project = Project::factory()->create();
    $this->connection = DataSourceConnection::factory()->umami()->for($this->project)->create();
});

describe('who may do what with a connection', function () {
    it('lets the team see it and only those administering the team change it', function (?TeamRole $role, bool $view, bool $update) {
        $user = $role === null ? User::factory()->create() : User::factory()->inTeam($this->project->team, $role)->create();

        expect($user->can('view', $this->connection))->toBe($view)
            ->and($user->can('update', $this->connection))->toBe($update);
    })->with([
        'an owner' => [TeamRole::Owner, true, true],
        'an admin' => [TeamRole::Admin, true, true],
        'a member' => [TeamRole::Member, true, false],
        'someone outside the team' => [null, false, false],
    ]);
});
