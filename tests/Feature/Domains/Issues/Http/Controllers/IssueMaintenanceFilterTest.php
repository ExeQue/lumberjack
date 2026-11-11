<?php

declare(strict_types=1);

use App\Domains\Issues\Http\Controllers\IssueController;
use App\Domains\Issues\Models\Issue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Teams\CreatesTeamMembers;

covers(IssueController::class);

uses(CreatesTeamMembers::class);

describe('the maintenance filter of the issues list', function () {
    it('lists only issues that came in during maintenance', function () {
        ['user' => $user, 'project' => $project] = $this->memberWithProject();
        $flagged = Issue::factory()->for($project)->create(['under_maintenance' => true]);
        Issue::factory()->for($project)->create(['under_maintenance' => false]);

        $this->actingAs($user)->get(route('issues.index', [$project, 'maintenance' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.maintenance', true)
                ->has('issues.data', 1)
                ->where('issues.data.0.id', $flagged->uuid)
                ->where('issues.data.0.under_maintenance', true));

        $this->actingAs($user)->get(route('issues.index', $project))
            ->assertInertia(fn (Assert $page) => $page->where('filters.maintenance', false)->has('issues.data', 2));
    });
});
